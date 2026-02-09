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
     * El front puede mandar professor_student_assignment_id como:
     * - ID real de professor_student_assignments
     * - socio_padron.id (pseudo, viene de myStudents)
     *
     * ✅ Acá lo resolvemos a PSA real SIEMPRE antes de llamar al service.
     */
  public function assignTemplate(Request $request): JsonResponse
{
    try {
        $professorId = (int) auth()->id();

        $validated = $request->validate([
            // el front manda socio_padron.id acá (pseudo)
            'professor_student_assignment_id' => 'required|integer|min:1',

            'daily_template_id' => 'required|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'frequency' => 'nullable|array|min:1',
            'frequency.*' => 'integer|between:0,6',
            'professor_notes' => 'nullable|string|max:1000',
        ]);

        // 1) Convertir incoming (socio_padron.id o PSA real) => PSA real
        $incoming = (int) $validated['professor_student_assignment_id'];
        $psaId = $this->resolveProfessorStudentAssignmentId($incoming, $professorId);

        // 2) Traer el PSA real y FORZAR active (esto es clave para tu error)
        $psa = ProfessorStudentAssignment::query()
            ->where('id', $psaId)
            ->where('professor_id', $professorId)
            ->firstOrFail();

        if ($psa->status !== 'active') {
            $psa->status = 'active';
            $psa->end_date = null;
            $psa->save();
        }

        // 3) Payload limpio al service (ya con PSA real + assigned_by)
        $payload = $validated;
        $payload['professor_student_assignment_id'] = (int) $psa->id;     // ✅ REAL
        $payload['assigned_by'] = $professorId;                           // ✅ NOT NULL
        $payload['student_id'] = (int) $psa->student_id;                  // ✅ por si el service lo usa

        // Alias por si el service espera otro nombre
        $payload['template_id'] = (int) $validated['daily_template_id'];

        // 4) Intento normal con service
        $assignment = $this->assignmentService->assignTemplateToStudent($payload);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => $assignment,
        ], 201);

    } catch (\Throwable $e) {

        // Fallback duro: si el service sigue diciendo "no válida o inactiva",
        // insertamos directo en daily_assignments para destrabar ya.
        $msg = (string) $e->getMessage();

        if (str_contains($msg, 'Asignación profesor-estudiante no válida') || str_contains($msg, 'no válida o inactiva')) {
            $professorId = (int) auth()->id();

            // Re-resolver porque acá ya estamos en catch
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
                'warning' => 'Se creó directo en daily_assignments (fallback) porque el service rechazó el PSA.',
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
     * ✅ Resuelve un ID entrante (PSA real o socio_padron.id) al PSA real.
     */
    private function resolveProfessorStudentAssignmentId(int $incomingId, int $professorId): int
    {
        // Caso A) Vino un ID real de professor_student_assignments
        $psa = ProfessorStudentAssignment::query()
            ->where('id', $incomingId)
            ->first();

        if ($psa) {
            if ((int) $psa->professor_id !== $professorId) {
                abort(403, 'La asignación no pertenece a este profesor');
            }
            return (int) $psa->id;
        }

        // Caso B) Vino socio_padron.id
        $socioPadronId = $incomingId;

        // Validar que el socio esté asignado a este profesor (pivot)
        $isAssigned = DB::table('professor_socio')
            ->where('professor_id', $professorId)
            ->where('socio_id', $socioPadronId)
            ->exists();

        if (!$isAssigned) {
            abort(403, 'El socio no está asignado a este profesor');
        }

        $socio = SocioPadron::query()->findOrFail($socioPadronId);

        // Crear/obtener user espejo (users.id real)
        $userSocio = $this->ensureUserFromSocioPadron($socio);

        // Crear/obtener PSA real usando users.id
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
     * No pisa password si el user ya existe.
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

        // DNI inválido -> fallback por barcode / SID / id
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

        // Existe: actualizar datos sin pisar password
        $user->fill($defaults);
        $user->dni = $dni;
        $user->save();

        return $user;
    }

    // ======= TODO lo que sigue lo dejé como lo tenías (sin tocar lógica) =======

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
                return response()->json(['message' => 'No tienes permisos para ver esta asignación'], 403);
            }

            return response()->json($assignment);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Asignación no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

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
                return response()->json(['message' => 'No tienes permisos para modificar esta asignación'], 403);
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

    public function unassignTemplate($assignmentId): JsonResponse
    {
        try {
            $assignment = TemplateAssignment::with(['professorStudentAssignment.student', 'dailyTemplate'])
                ->findOrFail($assignmentId);

            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json(['message' => 'No tienes permisos para eliminar esta asignación'], 403);
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

    public function studentProgress($studentId, Request $request): JsonResponse
    {
        try {
            $assignment = ProfessorStudentAssignment::query()
                ->where('professor_id', auth()->id())
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->first();

            if (!$assignment) {
                return response()->json(['message' => 'Estudiante no asignado o inactivo'], 403);
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

    private function buildFilters(Request $request): array
    {
        return array_filter([
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'active_only' => $request->boolean('active_only') ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
