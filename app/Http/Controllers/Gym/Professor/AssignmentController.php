    /**
     * Listar assignments de un alumno asignado al profesor autenticado
     */
    public function studentTemplateAssignments($studentId, Request $request): \Illuminate\Http\JsonResponse
    {
        // Validar que sea integer y exista en users
        $validator = \Validator::make(['student_id' => $studentId], [
            'student_id' => 'required|integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'studentId inválido',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verificar asignación activa
        $assignment = \App\Models\Gym\ProfessorStudentAssignment::where('professor_id', auth()->id())
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->first();
        if (!$assignment) {
            return response()->json([
                'ok' => false,
                'message' => 'Estudiante no asignado o inactivo'
            ], 403);
        }

        $filters = method_exists($this, 'buildFilters') ? $this->buildFilters($request) : [];
        $assignments = $this->assignmentService->getStudentTemplateAssignments($studentId, $filters);

        return response()->json([
            'ok' => true,
            'data' => $assignments
        ]);
    }
<?php

namespace App\Http\Controllers\Gym\Professor;

use App\Http\Controllers\Controller;
use App\Services\Gym\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

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
            $students = $this->assignmentService->getProfessorStudents(
                auth()->id(),
                $this->buildFilters($request)
            );

            return response()->json($students);
        } catch (\Exception $e) {
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
                'professor_student_assignment_id' => 'required|exists:professor_student_assignments,id',
                'daily_template_id' => 'required|exists:gym_daily_templates,id',
                'start_date' => 'required|date|after_or_equal:today',
                'end_date' => 'nullable|date|after:start_date',
                'frequency' => 'required|array|min:1',
                'frequency.*' => 'integer|between:0,6', // 0=Domingo, 6=Sábado
                'professor_notes' => 'nullable|string|max:1000'
            ]);

            $validated['assigned_by'] = auth()->id();

            $assignment = $this->assignmentService->assignTemplateToStudent($validated);

            return response()->json([
                'message' => 'Plantilla asignada exitosamente',
                'data' => $assignment
            ], 201);

        } catch (\Exception $e) {
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
            $assignment = \App\Models\Gym\TemplateAssignment::with([
                'dailyTemplate.exercises.exercise',
                'dailyTemplate.exercises.sets',
                'professorStudentAssignment.student',
                'progress' => function($query) {
                    $query->orderBy('scheduled_date');
                }
            ])->findOrFail($assignmentId);

            // Verificar que el profesor autenticado sea el dueño
            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para ver esta asignación'
                ], 403);
            }

            return response()->json($assignment);
        } catch (\Exception $e) {
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

            $assignment = \App\Models\Gym\TemplateAssignment::findOrFail($assignmentId);

            // Verificar permisos
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

        } catch (\Exception $e) {
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
            $assignment = \App\Models\Gym\TemplateAssignment::findOrFail($assignmentId);

            // Verificar permisos
            if ($assignment->professorStudentAssignment->professor_id !== auth()->id()) {
                return response()->json([
                    'message' => 'No tienes permisos para eliminar esta asignación'
                ], 403);
            }

            // Guardar info para respuesta
            $studentName = $assignment->professorStudentAssignment->student->name;
            $templateTitle = $assignment->dailyTemplate->title;

            // Eliminar asignación (cascade eliminará progreso automáticamente)
            $assignment->delete();

            return response()->json([
                'message' => "Plantilla '{$templateTitle}' desasignada exitosamente de {$studentName}",
                'student_name' => $studentName,
                'template_title' => $templateTitle
            ]);

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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
            // Verificar que el estudiante esté asignado al profesor
            $assignment = \App\Models\Gym\ProfessorStudentAssignment::where('professor_id', auth()->id())
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->first();

            if (!$assignment) {
                return response()->json([
                    'message' => 'Estudiante no asignado o inactivo'
                ], 403);
            }

            $assignments = $this->assignmentService->getStudentTemplateAssignments(
                $studentId,
                $this->buildFilters($request)
            );

            return response()->json($assignments);

        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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
            
            $sessions = \App\Models\Gym\AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
            ->whereHas('templateAssignment.professorStudentAssignment', function($query) {
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

        } catch (\Exception $e) {
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

            $sessions = \App\Models\Gym\AssignmentProgress::with([
                'templateAssignment.dailyTemplate',
                'templateAssignment.professorStudentAssignment.student'
            ])
            ->whereHas('templateAssignment.professorStudentAssignment', function($query) {
                $query->where('professor_id', auth()->id());
            })
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->orderBy('scheduled_date')
            ->get()
            ->groupBy('scheduled_date');

            return response()->json([
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'sessions_by_date' => $sessions
            ]);

        } catch (\Exception $e) {
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
        ]);
    }
}
