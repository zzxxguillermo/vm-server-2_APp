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
        // Buscar profesores y estudiantes
        $professors = User::where('is_professor', true)->get();
        $students = User::where('student_gym', true)->get();

        $now = Carbon::now();
        $count = 0;

        foreach ($professors as $professor) {
            // Asignar 3 estudiantes aleatorios a cada profesor
            $assigned = $students->random(min(3, $students->count()));
            foreach ($assigned as $student) {
                ProfessorStudentAssignment::updateOrCreate([
                    'professor_id' => $professor->id,
                    'student_id' => $student->id,
                ], [
                    'status' => 'active',
                    'assigned_at' => $now,
                ]);
                $count++;
            }
        }
        Log::info("Seeder: {$count} asignaciones profesor-estudiante creadas.");
    }
}
