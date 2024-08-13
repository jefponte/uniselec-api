<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasicCrudController;
use App\Http\Resources\ApplicationOutcomeResource;
use App\Models\Application;
use App\Models\ApplicationOutcome;
use App\Models\EnemScore;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class ApplicationOutcomeController extends BasicCrudController
{
    private $rules = [
        "application_id" => 'required',
        "status" => 'required',
        "classification_status" => 'required',
        "average_score" => 'required',
        "final_score" => 'required',
        "ranking" => 'required',
        "reason" => 'required',
    ];

    public function queryBuilder(): Builder
    {
        return parent::queryBuilder()->with('application');
    }

    public function index(Request $request)
    {
        return parent::index($request);
    }
    public function store(Request $request)
    {
        return response()->json([
            'error' => 'O método store não é permitido para ApplicationOutcome. Por favor, utilize a rota de processamento para criar ou atualizar ApplicationOutcomes.'
        ], 403);
    }

    public function show($id)
    {
        return parent::show($id);
    }

    public function update(Request $request, $id)
    {
        return parent::update($request, $id);
    }

    public function destroy($id)
    {
        return parent::destroy($id);
    }

    protected function model()
    {
        return ApplicationOutcome::class;
    }

    protected function rulesStore()
    {
        return $this->rules;
    }

    protected function rulesUpdate()
    {
        return $this->rules;
    }

    protected function resourceCollection()
    {
        return $this->resource();
    }

    protected function resource()
    {
        return ApplicationOutcomeResource::class;
    }
}
