<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

// Models
use App\Models\User;
use App\Models\SocioPadron;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\AssignmentProgress;

class AssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * Obtener mis estudiantes asignados (desde professor_socio + socios_padron)
     */
    public function myStudents(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 20);
            $perPage = max(1, min(200, $perPage));
            $page    = (int) $request->query('page', 1);
            $search  = trim((string) $request->query('search', $request->query('q', '')));

            $professorId = (int) auth()->id();

            $baseQuery = SocioPadron::query()
                ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
                ->where('professor_socio.professor_id', $professorId)
                ->select([
                    'socios_padron.id',
                    'socios_padron.dni',
                    'socios_padron.sid',
                    'socios_padron.apynom',
                    'socios_padron.barcode',
                    'socios_padron.saldo',
                    'socios_padron.semaforo',
                    'socios_padron.hab_controles',
                ])
                ->orderBy('socios_padron.apynom')
                ->orderBy('socios_padron.dni');

            if ($search !== '') {
                $baseQuery->where(function ($w) use ($search) {
                    $w->where('socios_padron.dni', 'like', "%{$search}%")
                        ->orWhere('socios_padron.sid', 'like', "%{$search}%")
                        ->orWhere('socios_padron.apynom', 'like', "%{$search}%");
                });
            }

            $paginator = $baseQuery->paginate($perPage, ['*'], 'page', $page);

            $data = $paginator->getCollection()->map(function ($socio) use ($professorId) {
                return [
                    // ⚠️ id pseudo: socio_padron.id (el front lo usa como "assignment id")
                    'id' => (int) $socio->id,
                    'professor_id' => $professorId,
                    'student_id' => (int) $socio->id, // pseudo
                    'status' => 'active',
                    'start_date' => null,
                    'end_date' => null,
                    'admin_notes' => null,
                    'created_at' => null,
                    'updated_at' => null,

                    'student' => [
                        'id' => (int) $socio->id, // socio_padron.id
                        'dni' => $socio->dni,
                        'name' => (string) ($socio->apynom ?? ''),
                        'email' => null,
                        'user_type' => 'socio',
                        'type_label' => 'Socio',
                        'socio_id' => (string) ($socio->sid ?? null),
                        'socio_n' => (string) ($socio->sid ?? null),
                        'barcode' => $socio->barcode,
                        'saldo' => $socio->saldo,
                        'semaforo' => $socio->semaforo,
                        'hab_controles' => $socio->hab_controles,
                        'foto_url' => null,
                        'avatar_path' => null,
                    ],

                    'template_assignments' => [],
                ];
            })->values();

            $out = new LengthAwarePaginator(
                $data,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );

            return response()->json($out);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estudiantes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar plantilla a estudiante
     *
     * El front manda professor_student_assignment_id como socio_padron.id (pseudo).
     * ✅ Lo resolvemos a PSA real y asignamos vía Service; si el Service falla por PSA, fallback directo a daily_assignments.
     */
    public function assignTemplate(Request $request): JsonResponse
    {
        try {
            $professorId = (int) auth()->id();

            $validated = $request->validate([
                'professor_student_assignment_id' => 'required|integer|min:1', // puede venir socio_padron.id
                'daily_template_id' => 'required|integer|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'frequency' => 'nullable|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
            ]);

            // 1) Resolver incoming => PSA real
            $incoming = (int) $validated['professor_student_assignment_id'];
            $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

            // 2) Forzar active
            $psa = ProfessorStudentAssignment::query()
                ->where('id', $psaId)
                ->where('professor_id', $professorId)
                ->firstOrFail();

            if ($psa->status !== 'active') {
                $psa->status = 'active';
                $psa->end_date = null;
                $psa->save();
            }

            // 3) Payload al service
            $payload = $validated;
            $payload['professor_student_assignment_id'] = (int) $psa->id; // REAL
            $payload['assigned_by'] = $professorId; // NOT NULL en daily_assignments
            $payload['student_id'] = (int) $psa->student_id; // por si el service lo usa
            $payload['template_id'] = (int) $validated['daily_template_id']; // alias

            $assignment = $this->assignmentService->assignTemplateToStudent($payload);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $assignment,
            ], 201);

        } catch (\Throwable $e) {
            $msg = (string) $e->getMessage();

            // Fallback: si el Service rechaza PSA, insert directo en daily_assignments
            if (str_contains($msg, 'Asignación profesor-estudiante no válida') || str_contains($msg, 'no válida o inactiva')) {
                $professorId = (int) auth()->id();

                $incoming = (int) $request->input('professor_student_assignment_id');
                $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

                $start = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : now()->startOfDay();
                $end   = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : null;

                $id = DB::table('daily_assignments')->insertGetId([
                    'professor_student_assignment_id' => $psaId,
                    'daily_template_id' => (int) $request->input('daily_template_id'),
                    'start_date' => $start,
                    'end_date' => $end,
                    'frequency' => $request->has('frequency') ? json_encode($request->input('frequency')) : null,
                    'professor_notes' => $request->input('professor_notes'),
                    'status' => 'active',
                    'assigned_by' => $professorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('daily_assignments')->where('id', $id)->first();

                return response()->json([
                    'success' => true,
                    'message' => 'ok',
                    'data' => $row,
                    'warning' => 'Fallback: se creó directo en daily_assignments porque el service rechazó el PSA.',
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al asignar plantilla',
                'error' => $msg,
            ], 422);
        }
    }

    /**
     * ✅ Traer plantillas asignadas del alumno
     * FIX: busca por TODOS los PSA IDs del profe para ese alumno -> evita “asigna pero no trae”
     */
    public function studentTemplateAssignments(Request $request, int $studentId): JsonResponse
    {
        try {
            $professorId = (int) auth()->id();

            // studentId puede venir como users.id o socios_padron.id
            $user = User::find($studentId);

            if (!$user) {
                $socio = SocioPadron::find($studentId);
                if (!$socio) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alumno no encontrado (ni user ni socio padron)'
                    ], 404);
                }

                $isAssigned = DB::table('professor_socio')
                    ->where('professor_id', $professorId)
                    ->where('socio_id', (int) $socio->id)
                    ->exists();

                if (!$isAssigned) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alumno no asignado a este profesor'
                    ], 403);
                }

                $user = $this->ensureUserFromSocioPadron($socio);
            }

            // Asegurar al menos un PSA y además cubrir duplicados
            ProfessorStudentAssignment::query()->firstOrCreate(
                [
                    'professor_id' => $professorId,
                    'student_id' => (int) $user->id,
                ],
                [
                    'assigned_by' => $professorId,
                    'status' => 'active',
                    'start_date' => now(),
                    'end_date' => null,
                    'admin_notes' => null,
                ]
            );

            $psaIds = ProfessorStudentAssignment::query()
                ->where('professor_id', $professorId)
                ->where('student_id', (int) $user->id)
                ->pluck('id')
                ->map(fn($v) => (int) $v)
                ->values()
                ->all();

            $rows = DB::table('daily_assignments as da')
                ->leftJoin('daily_templates as dt', 'dt.id', '=', 'da.daily_template_id')
                ->whereIn('da.professor_student_assignment_id', $psaIds)
                ->orderByDesc('da.start_date')
                ->select([
                    'da.*',
                    DB::raw('dt.title as daily_template_title'),
                ])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $rows,
                'meta' => [
                    'student_user_id' => (int) $user->id,
                    'psa_ids_used' => $psaIds,
                    'count' => $rows->count(),
                ],
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener plantillas del alumno',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ver detalles de una asignación de plantilla
     * (ojo: esto sigue mirando TemplateAssignment; si tu front usa daily_assignments, este endpoint quizá no aplica)
     */
    public function show($assignmentId): JsonResponse
    {
        try {
            $assignment = TemplateAssignment::with([
                'dailyTemplate.exercises.exercise',
                'dailyTemplate.exercises.sets',
                'professorStudentAssignment.student',
                'progress' => function ($query) {
                    $query->orderBy('scheduled_date');
                }
            ])->findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para ver esta asignación'
                ], 403);
            }

            return response()->json($assignment);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Asignación no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar asignación de plantilla
     */
    public function updateAssignment(Request $request, $assignmentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'end_date' => 'nullable|date|after:start_date',
                'frequency' => 'sometimes|array|min:1',
                'frequency.*' => 'integer|between:0,6',
                'professor_notes' => 'nullable|string|max:1000',
                'status' => 'sometimes|in:active,paused,completed,cancelled'
            ]);

            $assignment = TemplateAssignment::findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para modificar esta asignación'
                ], 403);
            }

            $assignment->update($validated);

            return response()->json([
                'message' => 'Asignación actualizada exitosamente',
                'data' => $assignment->fresh(['dailyTemplate', 'professorStudentAssignment.student'])
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al actualizar asignación',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Desasignar/Eliminar plantilla de estudiante
     */
    public function unassignTemplate($assignmentId): JsonResponse
    {
        try {
            $assignment = TemplateAssignment::with(['professorStudentAssignment.student', 'dailyTemplate'])
                ->findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para eliminar esta asignación'
                ], 403);
            }

            $studentName = $assignment->professorStudentAssignment->student->name ?? 'Alumno';
            $templateTitle = $assignment->dailyTemplate->title ?? 'Plantilla';

            $assignment->delete();

            return response()->json([
                'message' => "Plantilla '{$templateTitle}' desasignada exitosamente de {$studentName}",
                'student_name' => $studentName,
                'template_title' => $templateTitle
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al desasignar plantilla',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agregar feedback a una sesión completada
     */
    public function addFeedback(Request $request, $progressId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'professor_feedback' => 'required|string|max:1000',
                'overall_rating' => 'nullable|numeric|between:1,5'
            ]);

            $progress = $this->assignmentService->addProfessorFeedback(
                $progressId,
                $validated['professor_feedback'],
                $validated['overall_rating'] ?? null
            );

            return response()->json([
                'message' => 'Feedback agregado exitosamente',
                'data' => $progress
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al agregar feedback',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Obtener progreso de un estudiante específico
     */
    public function studentProgress($studentId, Request $request): JsonResponse
    {
        try {
            $assignment = ProfessorStudentAssignment::query()
                ->where('professor_id', auth()->id())
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->first();

            if (!$assignment) {
                return response()->json([
                    'message' => 'Estudiante no asignado o inactivo'
                ], 403);
            }

            $assignments = $this->assignmentService->getStudentTemplateAssignments(
                (int) $studentId,
                $this->buildFilters($request)
            );

            return response()->json($assignments);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener progreso del estudiante',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas del profesor
     */
    public function myStats(): JsonResponse
    {
        try {
            $stats = $this->assignmentService->getProfessorStats(auth()->id());
            return response()->json($stats);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener sesiones pendientes de hoy
     */
    public function todaySessions(): JsonResponse
    {
        try {
            $today = now()->toDateString();

            $sessions = AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
                ->whereHas('templateAssignment.professorStudentAssignment', function ($query) {
                    $query->where('professor_id', auth()->id());
                })
                ->where('scheduled_date', $today)
                ->orderBy('status')
                ->get();

            return response()->json([
                'date' => $today,
                'sessions' => $sessions,
                'total' => $sessions->count(),
                'completed' => $sessions->where('status', 'completed')->count(),
                'pending' => $sessions->where('status', 'pending')->count()
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener sesiones de hoy',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener calendario semanal
     */
    public function weeklyCalendar(Request $request): JsonResponse
    {
        try {
            $startDate = $request->has('start_date')
                ? Carbon::parse($request->input('start_date'))
                : now()->startOfWeek();

            $endDate = $startDate->copy()->endOfWeek();

            $sessions = AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
                ->whereHas('templateAssignment.professorStudentAssignment', function ($query) {
                    $query->where('professor_id', auth()->id());
                })
                ->whereBetween('scheduled_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('scheduled_date')
                ->get()
                ->groupBy('scheduled_date');

            return response()->json([
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'sessions_by_date' => $sessions
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al obtener calendario semanal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Resuelve incoming (PSA real o socio_padron.id) => PSA real
     */
    private function resolveProfessorStudentAssignmentId(int $incomingId, int $professorId): int
    {
        // Caso A: vino un PSA real
        $psa = ProfessorStudentAssignment::query()->where('id', $incomingId)->first();
        if ($psa) {
            if ((int) $psa->professor_id !== $professorId) {
                abort(403, 'La asignación no pertenece a este profesor');
            }
            return (int) $psa->id;
        }

        // Caso B: vino socio_padron.id
        $socioPadronId = $incomingId;

        $isAssigned = DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->where('socio_id', $socioPadronId)
            ->exists();

        if (!$isAssigned) {
            abort(403, 'El socio no está asignado a este profesor');
        }

        $socio = SocioPadron::query()->findOrFail($socioPadronId);
        $userSocio = $this->ensureUserFromSocioPadron($socio);

        $psa = ProfessorStudentAssignment::query()->firstOrCreate(
            [
                'professor_id' => $professorId,
                'student_id'   => (int) $userSocio->id,
            ],
            [
                'assigned_by'  => $professorId,
                'status'       => 'active',
                'start_date'   => now(),
                'end_date'     => null,
                'admin_notes'  => null,
            ]
        );

        if ($psa->status !== 'active') {
            $psa->status = 'active';
            $psa->end_date = null;
            $psa->save();
        }

        return (int) $psa->id;
    }

    /**
     * Crea/actualiza un User espejo a partir de SocioPadron.
     * No pisa password si ya existe.
     */
    private function ensureUserFromSocioPadron(SocioPadron $socio): User
    {
        $dniRaw = (string) ($socio->dni ?? '');
        $dni = preg_replace('/\D+/', '', trim($dniRaw));

        $name = trim((string) ($socio->apynom ?? 'Socio'));
        $sid  = $socio->sid ? (string) $socio->sid : null;

        $defaults = [
            'is_admin' => 0,
            'is_professor' => 0,
            'account_status' => 'active',
            'name' => $name !== '' ? $name : 'Socio',
            'email' => null,
            'socio_id' => $sid,
            'socio_n'  => $sid,
            'barcode'  => $socio->barcode,
            'saldo'    => $socio->saldo ?? '0.00',
            'semaforo' => $socio->semaforo ?? 1,
            'estado_socio' => null,
            'avatar_path' => null,
            'foto_url' => null,
        ];

        // DNI inválido: fallback por barcode / SID
        if ($dni === '' || strtolower(trim($dniRaw)) === 'dni') {
            $key = $socio->barcode ?: ('SID-' . (string)($socio->sid ?? $socio->id));

            $user = User::query()->where('barcode', $key)->first();
            if ($user) {
                $user->fill($defaults);
                $user->barcode = $key;
                $user->save();
                return $user;
            }

            $syntheticDni = 'SOCIO-' . (string) $socio->id;

            $create = $defaults;
            $create['dni'] = $syntheticDni;
            $create['barcode'] = $key;
            $create['password'] = Hash::make($syntheticDni);

            return User::create($create);
        }

        // Caso normal: match por dni
        $user = User::query()->where('dni', $dni)->first();
        if (!$user) {
            $create = array_merge($defaults, [
                'dni' => $dni,
                'password' => Hash::make($dni),
            ]);
            return User::create($create);
        }

        // Existe: actualizar sin tocar password
        $user->fill($defaults);
        $user->dni = $dni;
        $user->save();

        return $user;
    }

    /**
     * Construir filtros desde request
     */
    private function buildFilters(Request $request): array
    {
        return array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'active_only' => $request->boolean('active_only') ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
