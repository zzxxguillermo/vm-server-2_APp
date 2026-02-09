<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 1: Add UNIQUE constraints to enforce data integrity
 * 
 * - professor_student_assignments: unique(professor_id, student_id)
 * - professor_socio: unique(professor_id, socio_id)
 * 
 * Migration Date: 2026-02-09
 */
return new class extends Migration
{
    public function up(): void
    {
        // UNIQUE(professor_id, student_id) on professor_student_assignments
        try {
            Schema::table('professor_student_assignments', function (Blueprint $table) {
                $table->unique(['professor_id', 'student_id'], 'unique_prof_student');
            });
            logger()->info('[MIGRATION] Added UNIQUE(professor_id, student_id) to professor_student_assignments');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                logger()->warning('[MIGRATION] Duplicate entries in PSA; cleaning duplicates (keeping first active)');
                DB::statement(
                    'DELETE psa1 FROM professor_student_assignments psa1
                     INNER JOIN professor_student_assignments psa2 
                     WHERE psa1.professor_id = psa2.professor_id 
                       AND psa1.student_id = psa2.student_id 
                       AND psa1.id > psa2.id'
                );
                Schema::table('professor_student_assignments', function (Blueprint $table) {
                    $table->unique(['professor_id', 'student_id'], 'unique_prof_student');
                });
                logger()->info('[MIGRATION] Added UNIQUE after cleanup');
            }
        }

        // UNIQUE(professor_id, socio_id) on professor_socio if not exists
        if (Schema::hasTable('professor_socio')) {
            try {
                Schema::table('professor_socio', function (Blueprint $table) {
                    $table->unique(['professor_id', 'socio_id'], 'unique_prof_socio');
                });
                logger()->info('[MIGRATION] Added UNIQUE(professor_id, socio_id) to professor_socio');
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    logger()->info('[MIGRATION] UNIQUE constraint on professor_socio already exists');
                }
            }
        }
    }

    public function down(): void
    {
        try {
            Schema::table('professor_student_assignments', function (Blueprint $table) {
                $table->dropUnique('unique_prof_student');
            });
        } catch (\Exception $e) {
            // Constraint might not exist
        }

        if (Schema::hasTable('professor_socio')) {
            try {
                Schema::table('professor_socio', function (Blueprint $table) {
                    $table->dropUnique('unique_prof_socio');
                });
            } catch (\Exception $e) {
                // Constraint might not exist
            }
        }
    }
};
