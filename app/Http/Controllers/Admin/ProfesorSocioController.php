<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class ProfesorSocioController extends Controller
{
    /**
     * GET /api/admin/profesores
     */
    public function profesores(Request $request)
    {
        $q = User::query()->where('is_professor', true);

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('name')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /api/admin/socios
     * socios = users.user_type = 'api'
     */
    public function socios(Request $request)
    {
        $q = User::query()->where('user_type', 'api');

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->orderBy('apellido')->orderBy('nombre')->paginate((int) $request->get('per_page', 20)),
        ]);
    }

    /**
     * GET /api/admin/profesores/{profesor}/socios
     */
    public function sociosPorProfesor(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $q = $profesor->sociosAsignados();

        if ($search = trim((string) $request->get('search'))) {
            $q->where(function ($w) use ($search) {
                $w->where('dni', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'ok' => true,
            'data' => $q->paginate((int) $request->get('per_page', 50)),
        ]);
    }

    /**
     * POST /api/admin/profesores/{profesor}/socios
     * body: { socio_ids: number[] }
     * SYNC total
     */
    public function syncSocios(Request $request, User $profesor)
    {
        abort_unless($profesor->is_professor, 404);

        $data = $request->validate([
            'socio_ids'   => 'array',
            'socio_ids.*' => 'integer|exists:users,id',
        ]);

        $ids = $data['socio_ids'] ?? [];

        // Asegurar que sean socios API
        $validSocios = User::query()
            ->whereIn('id', $ids)
            ->where('user_type', 'api')
            ->pluck('id')
            ->all();

        $pairs = [];
        $adminId = auth()->id();

        foreach ($validSocios as $sid) {
            $pairs[$sid] = ['assigned_by' => $adminId];
        }

        $result = DB::transaction(function () use ($profesor, $pairs, $validSocios, $adminId) {
            // Sync pivot
            $profesor->sociosAsignados()->sync($pairs);

            $today = Carbon::today();
            $professorId = $profesor->id;

            // Activar o crear assignments
            $assignmentsSynced = [];
            foreach ($validSocios as $studentId) {
                $assignment = ProfessorStudentAssignment::where('professor_id', $professorId)
                    ->where('student_id', $studentId)
                    ->first();
                if ($assignment) {
                    if ($assignment->status !== 'active') {
                        $assignment->status = 'active';
                        $assignment->start_date = $today;
                        $assignment->end_date = null;
                        $assignment->admin_notes = null;
                        $assignment->assigned_by = $adminId;
                        $assignment->save();
                        $assignmentsSynced[] = ['id' => $assignment->id, 'student_id' => $studentId, 'action' => 'reactivated'];
                    } else {
                        $assignmentsSynced[] = ['id' => $assignment->id, 'student_id' => $studentId, 'action' => 'unchanged'];
                    }
                } else {
                    $new = ProfessorStudentAssignment::create([
                        'professor_id' => $professorId,
                        'student_id' => $studentId,
                        'assigned_by' => $adminId,
                        'start_date' => $today,
                        'end_date' => null,
                        'status' => 'active',
                        'admin_notes' => null,
                    ]);
                    $assignmentsSynced[] = ['id' => $new->id, 'student_id' => $studentId, 'action' => 'created'];
                }
            }

            // Cancelar assignments de students removidos
            $currentStudentIds = ProfessorStudentAssignment::where('professor_id', $professorId)
                ->where('status', 'active')
                ->pluck('student_id')->toArray();
            $removed = array_diff($currentStudentIds, $validSocios);
            foreach ($removed as $studentId) {
                $assignment = ProfessorStudentAssignment::where('professor_id', $professorId)
                    ->where('student_id', $studentId)
                    ->where('status', 'active')
                    ->first();
                if ($assignment) {
                    $assignment->status = 'cancelled';
                    $assignment->end_date = $today;
                    $assignment->save();
                    $assignmentsSynced[] = ['id' => $assignment->id, 'student_id' => $studentId, 'action' => 'cancelled'];
                }
            }

            return $assignmentsSynced;
        });

        return response()->json([
            'ok' => true,
            'assigned_count' => count($validSocios),
            'assignments_sync' => $result,
        ]);
    }
}
