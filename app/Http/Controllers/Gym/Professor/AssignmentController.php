<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

// Models
use App\Models\User;
use App\Models\SocioPadron;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\AssignmentProgress;


class AssignmentController extends Controller
{
    public function __construct(
        private AssignmentService $assignmentService
    ) {}

    /**
     * Obtener mis estudiantes asignados
     */
   public function myStudents(Request $request): JsonResponse
{
    try {
        $perPage = (int) $request->query('per_page', 20);
        $perPage = max(1, min(200, $perPage));
        $page    = (int) $request->query('page', 1);
        $search  = trim((string) $request->query('search', $request->query('q', '')));

        // SOCIOS asignados (professor_socio + socios_padron)
        $baseQuery = SocioPadron::query()
            ->join('professor_socio', 'professor_socio.socio_id', '=', 'socios_padron.id')
            ->where('professor_socio.professor_id', auth()->id())
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

        // Convertimos cada socio a "assignment" con estructura compatible:
        $data = $paginator->getCollection()->map(function ($socio) {
            return [
                'id' => (int) $socio->id, // id del socio_padron (pseudo id assignment)
                'professor_id' => (int) auth()->id(),
                'student_id' => (int) $socio->id, // pseudo
                'status' => 'active',
                'start_date' => null,
                'end_date' => null,
                'admin_notes' => null,
                'created_at' => null,
                'updated_at' => null,

                // el front usa assignment.student.xxx -> armamos un "student" compatible
                'student' => [
                    'id' => (int) $socio->id,
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

                // para que no rompa si espera template_assignments
                'template_assignments' => [],
            ];
        })->values();

        // Devolvemos paginator "clásico" igual que Laravel
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
            'message' => 'Error al obtener estudiantes',
            'error' => $e->getMessage()
        ], 500);
    }
}



    /**
     * Asignar plantilla a estudiante
     */
    public function assignTemplate(Request $request): JsonResponse
    {
        try {
          $validated = $request->validate([
  'professor_student_assignment_id' => 'required|integer|min:1', // 👈 NO exists acá
  'daily_template_id' => 'required|integer|min:1',
  'start_date' => 'nullable|date',
  'end_date' => 'nullable|date|after_or_equal:start_date',
  'frequency' => 'nullable|array|min:1',
  'frequency.*' => 'integer|between:0,6',
  'professor_notes' => 'nullable|string|max:1000',
]);

            $validated['assigned_by'] = auth()->id();

            $assignment = $this->assignmentService->assignTemplateToStudent($validated);

            return response()->json([
                'message' => 'Plantilla asignada exitosamente',
                'data' => $assignment
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al asignar plantilla',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Ver detalles de una asignación de plantilla
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
