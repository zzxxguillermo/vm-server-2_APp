<?php

namespace App\Http\Controllers\Gym\Student;

use App\Http\Controllers\Controller;
use App\Models\Gym\ProfessorStudentAssignment;
use App\Models\Gym\TemplateAssignment;
use App\Models\Gym\AssignmentProgress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class AssignmentController extends Controller
{
    /**
     * Obtener mis plantillas asignadas (vista semanal)
     */
    public function myTemplates(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Buscar asignación profesor-estudiante
            $professorAssignment = ProfessorStudentAssignment::where('student_id', $user->id)
                                                             ->where('status', 'active')
                                                             ->with('professor')
                                                             ->first();
            
            if (!$professorAssignment) {
                return response()->json([
                    'message' => 'No tienes un profesor asignado',
                    'data' => [
                        'professor' => null,
                        'templates' => [],
                    ],
                ]);
            }
            
            // Obtener plantillas asignadas
            $templateAssignments = TemplateAssignment::where('professor_student_assignment_id', $professorAssignment->id)
                                                    ->where('status', 'active')
                                                    ->with([
                                                        'dailyTemplate.exercises.exercise',
                                                        'dailyTemplate.exercises.sets',
                                                        'professorStudentAssignment.professor'
                                                    ])
                                                    ->orderBy('start_date', 'asc')
                                                    ->get();
            
            $response = [
                'professor' => [
                    'id' => $professorAssignment->professor->id,
                    'name' => $professorAssignment->professor->name,
                    'email' => $professorAssignment->professor->email
                ],
                'templates' => $templateAssignments->map(function ($assignment) {
                    return [
                        'id' => $assignment->id,
                        'daily_template' => [
                            'id' => $assignment->dailyTemplate->id,
                            'title' => $assignment->dailyTemplate->title,
                            'goal' => $assignment->dailyTemplate->goal,
                            'level' => $assignment->dailyTemplate->level,
                            'estimated_duration_min' => $assignment->dailyTemplate->estimated_duration_min,
                            'tags' => $assignment->dailyTemplate->tags,
                            'exercises_count' => $assignment->dailyTemplate->exercises->count()
                        ],
                        'start_date' => $assignment->start_date->toDateString(),
                        'end_date' => $assignment->end_date ? $assignment->end_date->toDateString() : null,
                        'frequency' => $assignment->frequency,
                        'frequency_days' => $this->formatFrequencyDays($assignment->frequency),
                        'professor_notes' => $assignment->professor_notes,
                        'status' => $assignment->status,
                        'assigned_by' => [
                            'id' => $assignment->professorStudentAssignment->professor->id,
                            'name' => $assignment->professorStudentAssignment->professor->name
                        ],
                        'created_at' => $assignment->created_at->toISOString()
                    ];
                })
            ];
            
            return response()->json([
                'message' => 'Plantillas obtenidas exitosamente',
                'data' => $response
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener plantillas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener detalles de una plantilla específica con ejercicios
     */
    public function templateDetails($templateAssignmentId): JsonResponse
    {
        try {
            $user = auth()->user();

            $templateAssignment = TemplateAssignment::with([
                'dailyTemplate.exercises.exercise',
                'dailyTemplate.exercises.sets',
                'professorStudentAssignment.professor',
                'professorStudentAssignment.student'
            ])->find($templateAssignmentId);

            if (!$templateAssignment) {
                return response()->json([
                    'message' => 'Asignación no encontrada',
                ], 404);
            }

            $psa = $templateAssignment->professorStudentAssignment;
            if (!$psa || (int) $psa->student_id !== (int) $user->id) {
                return response()->json([
                    'message' => 'No tienes acceso a esta asignación',
                ], 403);
            }

            $template = $templateAssignment->dailyTemplate;
            $templateExercises = $template ? $template->exercises : collect();
            $professor = $psa ? $psa->professor : null;
            $frequency = $templateAssignment->frequency;
            
            $response = [
                'assignment_info' => [
                    'id' => $templateAssignment->id,
                    'start_date' => $templateAssignment->start_date ? $templateAssignment->start_date->toDateString() : null,
                    'end_date' => $templateAssignment->end_date ? $templateAssignment->end_date->toDateString() : null,
                    'frequency' => $frequency,
                    'frequency_days' => $this->formatFrequencyDays($frequency ?? []),
                    'professor_notes' => $templateAssignment->professor_notes,
                    'status' => $templateAssignment->status,
                    'assigned_by' => [
                        'id' => $professor ? $professor->id : null,
                        'name' => $professor ? $professor->name : null,
                        'email' => $professor ? $professor->email : null
                    ]
                ],
                'template' => $template ? [
                    'id' => $template->id,
                    'title' => $template->title,
                    'goal' => $template->goal,
                    'level' => $template->level,
                    'estimated_duration_min' => $template->estimated_duration_min,
                    'tags' => $template->tags,
                    'created_at' => $template->created_at ? $template->created_at->toISOString() : null
                ] : null,
                'exercises' => $templateExercises->map(function ($templateExercise) {
                    return [
                        'id' => $templateExercise->id,
                        'order' => $templateExercise->order,
                        'exercise' => [
                            'id' => $templateExercise->exercise ? $templateExercise->exercise->id : null,
                            'name' => $templateExercise->exercise ? $templateExercise->exercise->name : null,
                            'description' => $templateExercise->exercise ? $templateExercise->exercise->description : null,
                            'target_muscle_groups' => $templateExercise->exercise ? $templateExercise->exercise->target_muscle_groups : null,
                            'equipment' => $templateExercise->exercise ? $templateExercise->exercise->equipment : null,
                            'difficulty_level' => $templateExercise->exercise ? $templateExercise->exercise->difficulty_level : null,
                            'instructions' => $templateExercise->exercise ? $templateExercise->exercise->instructions : null
                        ],
                        'sets' => $templateExercise->sets->map(function ($set) {
                            return [
                                'id' => $set->id,
                                'set_number' => $set->set_number,
                                'reps_min' => $set->reps_min,
                                'reps_max' => $set->reps_max,
                                'weight_min' => $set->weight_min,
                                'weight_max' => $set->weight_max,
                                'weight_target' => $set->weight_target,
                                'rpe_target' => $set->rpe_target,
                                'rest_seconds' => $set->rest_seconds,
                                'notes' => $set->notes
                            ];
                        }),
                        'notes' => $templateExercise->notes
                    ];
                })
            ];
            
            return response()->json([
                'message' => 'Detalles de plantilla obtenidos exitosamente',
                'data' => $response
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener detalles de plantilla',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener mi calendario semanal de entrenamientos
     */
    public function myWeeklyCalendar(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $date = $request->input('date', now()->toDateString());
            $startOfWeek = Carbon::parse($date)->startOfWeek();
            $endOfWeek = Carbon::parse($date)->endOfWeek();
            
            // Buscar asignación profesor-estudiante
            $professorAssignment = ProfessorStudentAssignment::where('student_id', $user->id)
                                                             ->where('status', 'active')
                                                             ->first();
            
            if (!$professorAssignment) {
                return response()->json([
                    'message' => 'No tienes un profesor asignado',
                    'data' => $this->getEmptyWeek($startOfWeek, $endOfWeek)
                ]);
            }
            
            // Obtener plantillas activas
            $templateAssignments = TemplateAssignment::where('professor_student_assignment_id', $professorAssignment->id)
                                                    ->where('status', 'active')
                                                    ->where('start_date', '<=', $endOfWeek)
                                                    ->where(function($query) use ($startOfWeek) {
                                                        $query->whereNull('end_date')
                                                              ->orWhere('end_date', '>=', $startOfWeek);
                                                    })
                                                    ->with([
                                                        'dailyTemplate',
                                                        'professorStudentAssignment.professor'
                                                    ])
                                                    ->get();
            
            // Generar calendario semanal
            $weekDays = [];
            for ($i = 0; $i < 7; $i++) {
                $currentDate = $startOfWeek->copy()->addDays($i);
                $dayOfWeek = $currentDate->dayOfWeek; // 0=Sunday, 1=Monday, etc.
                
                $dayAssignments = $templateAssignments->filter(function($assignment) use ($dayOfWeek) {
                    return in_array($dayOfWeek, $assignment->frequency ?? []);
                });
                
                $weekDays[] = [
                    'date' => $currentDate->toDateString(),
                    'day_name' => $currentDate->format('l'),
                    'day_short' => $currentDate->format('D'),
                    'day_number' => $currentDate->day,
                    'has_workouts' => $dayAssignments->count() > 0,
                    'assignments' => $dayAssignments->map(function($assignment) {
                        $dailyTemplate = $assignment->dailyTemplate;
                        $professor = $assignment->professorStudentAssignment
                            ? $assignment->professorStudentAssignment->professor
                            : null;

                        return [
                            'id' => $assignment->id,
                            'template_assignment_id' => $assignment->id,
                            'daily_template' => $dailyTemplate ? [
                                'id' => $dailyTemplate->id,
                                'title' => $dailyTemplate->title,
                                'goal' => $dailyTemplate->goal,
                                'level' => $dailyTemplate->level,
                                'estimated_duration_min' => $dailyTemplate->estimated_duration_min
                            ] : null,
                            'professor_notes' => $assignment->professor_notes,
                            'assigned_by' => [
                                'id' => $professor ? $professor->id : null,
                                'name' => $professor ? $professor->name : null
                            ]
                        ];
                    })->values()
                ];
            }
            
            return response()->json([
                'message' => 'Calendario semanal obtenido exitosamente',
                'data' => [
                    'week_start' => $startOfWeek->toDateString(),
                    'week_end' => $endOfWeek->toDateString(),
                    'days' => $weekDays
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener calendario semanal',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Formatear días de frecuencia a nombres legibles
     */
    private function formatFrequencyDays(array $frequency): array
    {
        $days = [
            0 => 'Domingo',
            1 => 'Lunes', 
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado'
        ];
        
        return array_map(function($day) use ($days) {
            return $days[$day] ?? "Día $day";
        }, $frequency);
    }
    
    /**
     * Generar semana vacía
     */
    private function getEmptyWeek(Carbon $startOfWeek, Carbon $endOfWeek): array
    {
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $currentDate = $startOfWeek->copy()->addDays($i);
            $weekDays[] = [
                'date' => $currentDate->toDateString(),
                'day_name' => $currentDate->format('l'),
                'day_short' => $currentDate->format('D'),
                'day_number' => $currentDate->day,
                'has_workouts' => false,
                'assignments' => []
            ];
        }
        
        return [
            'week_start' => $startOfWeek->toDateString(),
            'week_end' => $endOfWeek->toDateString(),
            'days' => $weekDays
        ];
    }
}
