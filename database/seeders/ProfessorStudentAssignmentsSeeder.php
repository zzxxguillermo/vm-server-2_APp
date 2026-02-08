<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Gym\ProfessorStudentAssignment;
use Carbon\Carbon;

class ProfessorStudentAssignmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Buscar un admin válido para assigned_by
        $adminId = User::where('is_admin', 1)->value('id') ?? 1;

        // Profesores: users donde is_professor=1
        $professors = User::where('is_professor', 1)->get();
        // Estudiantes: users donde is_admin=0 AND is_professor=0
        $students = User::where('is_admin', 0)->where('is_professor', 0)->get();

        $today = Carbon::today()->toDateString();
        $count = 0;

        foreach ($professors as $professor) {
            // Asignar 3 estudiantes aleatorios a cada profesor, evitando self-assign
            $eligibleStudents = $students->where('id', '!=', $professor->id);
            if ($eligibleStudents->count() === 0) {
                continue;
            }
            $assigned = $eligibleStudents->random(min(3, $eligibleStudents->count()));
            foreach ($assigned as $student) {
                // Buscar si ya existe la asignación
                $existing = ProfessorStudentAssignment::where('professor_id', $professor->id)
                    ->where('student_id', $student->id)
                    ->first();
                if ($existing) {
                    if ($existing->status !== 'active') {
                        // Reactivar y actualizar fechas
                        $existing->update([
                            'status' => 'active',
                            'start_date' => $today,
                            'end_date' => null,
                            'assigned_by' => $adminId,
                            'admin_notes' => null,
                        ]);
                        $count++;
                    }
                    // Si ya está active, no hacer nada
                } else {
                    // Crear nueva asignación
                    ProfessorStudentAssignment::create([
                        'professor_id' => $professor->id,
                        'student_id' => $student->id,
                        'assigned_by' => $adminId,
                        'start_date' => $today,
                        'end_date' => null,
                        'status' => 'active',
                        'admin_notes' => null,
                    ]);
                    $count++;
                }
            }
        }
        Log::info("Seeder: {$count} asignaciones profesor-estudiante creadas o reactivadas.");
    }
}
