<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\BasicCrudController;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\ProcessSelection;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;
use EloquentFilter\Filterable;
use ReflectionClass;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class ApplicationController extends BasicCrudController
{
    private $rules = [
        'user_id' => 'required|integer',
        'data' => 'required|array',
    ];

    public function changeAdminPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $admin = $request->user();


        if (!Hash::check($request->current_password, $admin->password)) {
            return response()->json(['error' => 'Senha atual incorreta.'], 403);
        }

        // Atualização da senha
        $admin->password = Hash::make($request->new_password);
        $admin->save();

        return response()->json(['message' => 'Senha alterada com sucesso.'], 200);
    }
    public function indexAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', $this->defaultPerPage);
        $hasFilter = in_array(Filterable::class, class_uses($this->model()));

        $query = $this->queryBuilder();

        if ($hasFilter) {
            $query = $query->filter($request->all());
        }

        $data = $query->orderBy('id', 'desc')->paginate($perPage);

        if ($data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return ApplicationResource::collection($data->items())->additional([
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'last_page' => $data->lastPage(),
                ],
            ]);
        }

        return ApplicationResource::collection($data);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = (int) $request->get('per_page', $this->defaultPerPage);
        $hasFilter = in_array(Filterable::class, class_uses($this->model()));

        $query = $this->queryBuilder();

        if ($hasFilter) {
            $query = $query->filter($request->all());
        }


        if (!$user->can('admin')) {
            $query->where('user_id', $user->id);
        }

        $data = $query->orderBy('id', 'desc')->paginate($perPage);

        if ($data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            return ApplicationResource::collection($data->items())->additional([
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'last_page' => $data->lastPage(),
                ],
            ]);
        }

        return ApplicationResource::collection($data);
    }


    public function store(Request $request)
    {

        $validatedData = $request->validate([
            'data'  => 'required',
            'process_selection_id'   => 'required',
        ]);


        $processSelection = ProcessSelection::where('id', $validatedData['process_selection_id'])
            ->where('status', 'active')
            ->firstOrFail();
        $processSelectionId = $processSelection->id;
        $start = $processSelection->start_date;
        $end = $processSelection->end_date;
        $now = now();
        if ($now->lt($start) || $now->gt($end)) {
            return response()->json([
                'message' => 'Inscrições estão fechadas. O período de inscrição é de ' . $start->format('d/m/Y H:i') . ' até ' . $end->format('d/m/Y H:i') . '.',
            ], 403);
        }

        $userId = $request->user()->id;

        $existingApplication = Application::where('user_id', $userId)
            ->where('process_Selection_id', $processSelectionId)
            ->first();


        // if($existingApplication) {
        //     return response()->json([
        //         'message' => 'Já tem uma inscrição para este candidato neste processo.'
        //     ], 422);
        // }


        // $applicationData = $validatedData['data'];
        $applicationData = $request->all();

        $applicationData['user_id'] = $userId;

        $currentTimestamp = now()->toDateTimeString();
        if (!isset($applicationData['data'])) {
            $applicationData['data'] = [];
        }

        $applicationData['data']['updated_at'] = $currentTimestamp;
        $applicationData['process_selection_id'] = $processSelectionId;
        if (isset($request->data)) {
            $applicationData['verification_code'] = md5(json_encode($applicationData['data']));
        }

        if ($existingApplication) {
            $existingApplication->update($applicationData);
            return response()->json([
                'message' => 'Inscrição atualizada com sucesso.',
                'application' => $existingApplication
            ], 200);
        }

        $request->merge(['user_id' => $userId]);
        $application = Application::create($applicationData);

        return response()->json([
            'message' => 'Inscrição criada com sucesso.',
            'application' => $application
        ], 201);
    }


    public function show($id)
    {

        $userId = request()->user()->id;
        $application = $this->model()::where('id', $id)
            ->where('user_id', $userId)
            ->first();
        if (!$application) {
            return response()->json(['message' => 'Application not found or not authorized'], 404);
        }
        return new ApplicationResource($application);
    }

    public function update(Request $request, $id)
    {
        $start = Carbon::parse(env('REGISTRATION_START', '2024-08-02 08:00:00'));
        $end = Carbon::parse(env('REGISTRATION_END', '2024-08-03 23:59:00'));
        $now = now();

        if ($now->lt($start) || $now->gt($end)) {
            return response()->json([
                'message' => 'Inscrições estão fechadas. O período de inscrição é de ' . $start->format('d/m/Y H:i') . ' até ' . $end->format('d/m/Y H:i') . '.',
            ], 403);
        }

        $user = $request->user();
        $application = Application::find($id);

        if (!$application || $application->user_id !== $user->id) {
            return response()->json(['error' => 'Você não tem permissão para atualizar esta inscrição.'], 403);
        }

        return parent::update($request, $id);
    }

    /**
     * Método `destroy` removido conforme solicitado
     */
    public function destroy($id)
    {
        return response()->json(['error' => 'Method not allowed.'], 405);
    }

    protected function model()
    {
        return Application::class;
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
        return ApplicationResource::collection($this->model()::all());
    }

    protected function resource()
    {
        return ApplicationResource::class;
    }
}
