<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // 👨‍💼 Usuario Administrador - Acceso completo al sistema
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@villamitre.com',
            'dni' => '11111111',
            'password' => 'admin123',
            'user_type' => 'local',
            'is_admin' => true,
            'is_professor' => false,
            'permissions' => [
                'user_management',      // Gestión de usuarios
                'gym_admin',           // Administración del gimnasio
                'system_settings',     // Configuración del sistema
                'reports_access',      // Acceso a reportes
                'audit_logs',          // Logs de auditoría
                'super_admin'          // Permisos de super administrador
            ],
            'account_status' => 'active',
        ]);

        // 👨‍🏫 Usuario Profesor - Solo panel del gimnasio
        User::factory()->create([
            'name' => 'Profesor Juan Pérez',
            'email' => 'profesor@villamitre.com',
            'dni' => '22222222',
            'password' => 'profesor123',
            'user_type' => 'local',
            'is_admin' => false,
            'is_professor' => true,
            'professor_since' => now(),
            'permissions' => [
                'gym_admin',           // Acceso al panel del gimnasio
                'create_templates',    // Crear plantillas
                'assign_routines',     // Asignar rutinas a estudiantes
            ],
            'account_status' => 'active',
        ]);

        // 👨‍🎓 Usuario Estudiante - Solo API móvil
        User::factory()->create([
            'name' => 'Estudiante María García',
            'email' => 'estudiante@villamitre.com',
            'dni' => '33333333',
            'password' => 'estudiante123',
            'user_type' => 'local',
            'is_admin' => false,
            'is_professor' => false,
            'permissions' => [],
            'account_status' => 'active',
        ]);

        // 👤 Usuario de Prueba - Usuario normal (mantener para compatibilidad)
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'dni' => '12345678',
            'password' => 'password123',
            'user_type' => 'local',
            'is_professor' => true, // Para poder probar el panel admin
            'account_status' => 'active',
        ]);

        // Seed catálogo de gimnasio y 20 plantillas diarias prefijadas
        $this->call([
            GymExerciseSeeder::class,
            GymDailyTemplatesSeeder::class,
            ProfessorStudentAssignmentsSeeder::class,
        ]);
    }
}
