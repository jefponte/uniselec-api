<?php

namespace App\Services;

use App\Models\ApplicationOutcome;
use App\Models\ConvocationList;
use App\Models\ConvocationListApplication;
use Illuminate\Support\Facades\DB;

class ApplicationGeneratorService
{
    /**
     * Gera registros em convocation_list_applications:
     * - agrupa por curso e categoria
     * - ordena por final_score / average_score
     * - define general e category ranking
     * - inicializa convocation_status e response_status:
     *     * se em lista anterior já saiu de pending ou called_out_of_quota → skipped + declined_other_list
     *     * senão → pending
     *
     * @param  ConvocationList  $list
     * @return int  Total de linhas inseridas
     */
    public function generate(ConvocationList $list): int
    {
        $ps           = $list->processSelection;
        $psId         = $ps->id;
        // quotas por curso/categoria
        $coursesById  = collect($ps->courses)->keyBy('id');
        // ids das listas anteriores
        $prevListIds  = $ps->convocationLists()
                          ->where('id', '<', $list->id)
                          ->pluck('id')
                          ->all();

        // 1) outcomes aprovados e ainda não gerados nesta lista
        $outcomes = ApplicationOutcome::with('application')
            ->where('status', 'approved')
            ->whereHas('application', fn($q) => $q->where('process_selection_id', $psId))
            ->whereNotExists(function($sub) use($list) {
                $sub->selectRaw('1')
                    ->from('convocation_list_applications as cla')
                    ->whereColumn('cla.application_id', 'application_outcomes.application_id')
                    ->where('cla.convocation_list_id', $list->id);
            })
            ->get();

        // 2) busca histórico de statuses por application_id em listas anteriores
        $prevStatuses = ConvocationListApplication::query()
            ->whereIn('convocation_list_id', $prevListIds)
            ->get(['application_id', 'convocation_status'])
            ->groupBy('application_id')
            ->map(fn($group) => $group->pluck('convocation_status')->unique()->all());

        // 3) agrupa em memória por curso e nome de categoria
        $grouped = [];
        foreach ($outcomes as $out) {
            $data     = $out->application->form_data;
            $courseId = $data['position']['id'] ?? null;
            if (! $courseId) {
                continue;
            }
            foreach ($data['admission_categories'] ?? [] as $cat) {
                $catName = $cat['name'];
                $grouped[$courseId][$catName][] = $out;
            }
        }

        $rows       = [];
        $globalRank = 0;

        // 4) percorre cada grupo, ordena e monta cada linha
        foreach ($grouped as $courseId => $byCat) {
            foreach ($byCat as $catName => $chunk) {
                // ordena por final_score, average_score
                usort($chunk, function($a, $b) {
                    if ($a->final_score !== $b->final_score) {
                        return $b->final_score <=> $a->final_score;
                    }
                    return $b->average_score <=> $a->average_score;
                });

                // cota para esta categoria
                $quota = $coursesById[$courseId]['vacanciesByCategory'][$catName] ?? 0;

                foreach ($chunk as $idx => $out) {
                    $globalRank++;
                    $categoryRank = $idx + 1;
                    $appId        = $out->application_id;

                    // checa se, em listas anteriores, já saiu de pending ou called_out_of_quota
                    $history = $prevStatuses[$appId] ?? [];
                    $leftPendings = collect($history)
                        ->contains(fn($st) => ! in_array($st, ['pending', 'called_out_of_quota']));

                    if ($leftPendings) {
                        $convStatus = 'skipped';
                        $respStatus = 'declined_other_list';
                    } else {
                        $convStatus = 'pending';
                        $respStatus = 'pending';
                    }

                    // encontra o admission_category_id original via form_data
                    $catList = $out->application->form_data['admission_categories'];
                    $pos     = array_search($catName, array_column($catList, 'name'));
                    $catId   = $catList[$pos]['id'] ?? null;

                    $rows[] = [
                        'convocation_list_id'   => $list->id,
                        'application_id'        => $appId,
                        'course_id'             => $courseId,
                        'admission_category_id' => $catId,
                        'general_ranking'       => $globalRank,
                        'category_ranking'      => $categoryRank,
                        'convocation_status'    => $convStatus,
                        'result_status'         => ($categoryRank <= $quota) ? 'classified' : 'classifiable',
                        'response_status'       => $respStatus,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ];
                }
            }
        }

        // 5) insere tudo de uma vez
        DB::transaction(function() use($rows) {
            if (! empty($rows)) {
                ConvocationListApplication::insert($rows);
            }
        });

        return count($rows);
    }
}
