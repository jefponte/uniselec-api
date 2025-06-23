<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationOutcome;
use App\Models\EnemScore;

class ProcessApplicationOutcome
{
    public function __construct(private int $processSelectionId) {}

    public function process()
    {
        $this->ensureAllApplicationsHaveOutcomes();
        $this->processEnemScores();
        $this->markDuplicateApplications();
    }

    private function ensureAllApplicationsHaveOutcomes()
    {
        $applications = Application::where('process_selection_id', $this->processSelectionId)
            ->doesntHave('applicationOutcome')->get();

        foreach ($applications as $application) {
            $this->createOrUpdateOutcomeForApplication($application, 'pending', 'Resultado Não Processado');
        }
    }

    private function processEnemScores()
    {
        $enemScores = EnemScore::with('application')
            ->whereHas(
                'application',
                fn($q) =>
                $q->where('process_selection_id', $this->processSelectionId)
            )->get();
        $processedApplicationIds = [];

        foreach ($enemScores as $enemScore) {
            $application = $enemScore->application;
            $processedApplicationIds[] = $application->id;

            if (strpos($enemScore->original_scores, 'Candidato não encontrado') !== false) {
                $this->createOrUpdateOutcomeForApplication($application, 'rejected', 'Inscrição do ENEM não Identificada');
                continue;
            }

            $averageScore = $this->calculateAverageScore($enemScore->scores);

            if (isset($application->form_data['bonus'])) {
                $finalScore = $this->applyBonus($averageScore, $application->form_data['bonus']);
            } else {
                $finalScore = $averageScore;
            }

            $reasons = [];

            if ($enemScore->scores['cpf'] !== $application->form_data['cpf']) {
                $reasons[] = 'Inconsistência no CPF';
            }

            if ($this->normalizeString($enemScore->scores['name']) !== $this->normalizeString($application->form_data['name'])) {
                $reasons[] = 'Inconsistência no Nome';
            }

            $birthdateInconsistency = false;
            if (isset($application->form_data['birthdate']) && isset($enemScore->scores['birthdate'])) {
                $applicationBirthdate = \DateTime::createFromFormat('Y-m-d', $application->form_data['birtdate']);
                $enemScoreBirthdate = \DateTime::createFromFormat('d/m/Y', $enemScore->scores['birthdate']);

                if (!$applicationBirthdate || !$enemScoreBirthdate || $applicationBirthdate->format('Y-m-d') !== $enemScoreBirthdate->format('Y-m-d')) {
                    $reasons[] = 'Inconsistência na Data de Nascimento';
                    $birthdateInconsistency = true;
                }
            } else {
                $reasons[] = 'Data de Nascimento ausente ou inconsistente';
            }

            if (count($reasons) === 3) {
                $this->createOrUpdateOutcomeForApplication($application, 'rejected', implode('; ', $reasons), $averageScore, $finalScore);
            } elseif (count($reasons) === 1 && $birthdateInconsistency) {
                $this->createOrUpdateOutcomeForApplication($application, 'approved', null, $averageScore, $finalScore);
            } elseif (!empty($reasons)) {
                $this->createOrUpdateOutcomeForApplication($application, 'pending', implode('; ', $reasons), $averageScore, $finalScore);
            } else {
                $this->createOrUpdateOutcomeForApplication($application, 'approved', null, $averageScore, $finalScore);
            }
        }

        $applicationsWithoutEnemScore = Application::whereNotIn('id', $processedApplicationIds)->get();
        foreach ($applicationsWithoutEnemScore as $application) {
            $this->createOrUpdateOutcomeForApplication($application, 'rejected', 'Inscrição do ENEM não Identificada');
        }
    }



    private function markDuplicateApplications()
    {
        $usersWithMultipleApplications = Application::select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($usersWithMultipleApplications as $user) {
            $applications = Application::where('user_id', $user->user_id)
                ->orderBy('created_at', 'asc')
                ->get();


            for ($i = 0; $i < count($applications) - 1; $i++) {
                $this->createOrUpdateOutcomeForApplication($applications[$i], 'rejected', 'Inscrição duplicada');
            }
        }
    }

    private function calculateAverageScore($scores)
    {
        $totalScore = $scores['science_score'] + $scores['humanities_score'] + $scores['language_score'] + $scores['math_score'] + $scores['writing_score'];
        return $totalScore / 5;
    }

    private function applyBonus($averageScore, $bonuses)
    {
        $finalScore = $averageScore;
        // foreach ($bonuses as $bonus) {
        //     if (strpos($bonus, '10%') !== false) {
        //         $finalScore *= 1.10;
        //     } elseif (strpos($bonus, '20%') !== false) {
        //         $finalScore *= 1.20;
        //     }
        // }
        return $finalScore;
    }

    private function createOrUpdateOutcomeForApplication($application, $status, $reason = null, $averageScore = '0.00', $finalScore = '0.00')
    {
        ApplicationOutcome::updateOrCreate(
            ['application_id' => $application->id],
            [
                'status' => $status,
                'classification_status' => $status === 'approved' ? 'classifiable' : null,
                'average_score' => $averageScore,
                'final_score' => $finalScore,
                'reason' => $reason,
            ]
        );
    }

    private function normalizeString($string)
    {
        if ($string !== mb_convert_encoding(mb_convert_encoding($string, 'UTF-32', 'UTF-8'), 'UTF-8', 'UTF-32'))
            $string = mb_convert_encoding($string, 'UTF-8', mb_detect_encoding($string));
        $string = htmlentities($string, ENT_NOQUOTES, 'UTF-8');
        $string = preg_replace('`&([a-z]{1,2})(acute|uml|circ|grave|ring|cedil|slash|tilde|caron|lig);`i', '\1', $string);
        $string = html_entity_decode($string, ENT_NOQUOTES, 'UTF-8');
        $string = preg_replace(array('`[^a-z0-9]`i', '`[-]+`'), ' ', $string);
        $string = preg_replace('/( ){2,}/', '$1', $string);
        $string = strtoupper(trim($string));
        return $string;
    }
}
