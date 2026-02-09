<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1: Add UNIQUE constraint to professor_student_assignments
 * 
 * Current issue: Can create multiple PSA for same professor+student pair
 * This creates confusion when resolving IDs and fetching assignments.
 * 
 * FIX: Ensure only ONE PSA per (professor_id, student_id) pair
 * 
 * Note: Existing unique(['student_id', 'status']) allows multiple per status
 * We need unique(['professor_id', 'student_id']) instead
 * 
 * Migration Date: 2026-02-09
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE professor_student_assignments 
                 ADD UNIQUE KEY unique_prof_student (professor_id, student_id)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on professor_student_assignments(professor_id, student_id)');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                \Illuminate\Support\Facades\Log::warning('[MIGRATION] Duplicate PSA entries found - fixing');
                
                // Keep only the MOST RECENT active one for each professor+student
                \Illuminate\Support\Facades\DB::statement(
                    'DELETE psa1 FROM professor_student_assignments psa1
                     INNER JOIN (
                         SELECT professor_id, student_id, MAX(id) as keep_id
                         FROM professor_student_assignments
                         WHERE status = "active"
                         GROUP BY professor_id, student_id
                         HAVING COUNT(*) > 1
                     ) psa2 ON psa1.professor_id = psa2.professor_id 
                         AND psa1.student_id = psa2.student_id
                         AND psa1.id < psa2.keep_id'
                );
                
                // For non-active duplicates, delete all but the first
                \Illuminate\Support\Facades\DB::statement(
                    'DELETE psa1 FROM professor_student_assignments psa1
                     INNER JOIN professor_student_assignments psa2 ON 
                         psa1.professor_id = psa2.professor_id
                         AND psa1.student_id = psa2.student_id
                         AND psa1.id > psa2.id'
                );
                
                \Illuminate\Support\Facades\DB::statement(
                    'ALTER TABLE professor_student_assignments 
                     ADD UNIQUE KEY unique_prof_student (professor_id, student_id)'
                );
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint after cleanup');
            } elseif (str_contains($e->getMessage(), 'already exists')) {
                \Illuminate\Support\Facades\Log::info('[MIGRATION] UNIQUE constraint on professor_student_assignments already exists');
            }
        }

        // Drop the old unique constraint that was causing issues
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE professor_student_assignments DROP INDEX unique_active_student'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Dropped old unique_active_student constraint');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), "can't DROP")) {
                \Illuminate\Support\Facades\Log::info('[MIGRATION] unique_active_student constraint not found');
            }
        }
    }

    public function down(): void
    {
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE professor_student_assignments DROP INDEX unique_prof_student'
            );
        } catch (\Exception $e) {
            // Constraint might not exist
        }

        // Restore the old constraint if needed
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE professor_student_assignments 
                 ADD UNIQUE KEY unique_active_student (student_id, status)'
            );
        } catch (\Exception $e) {
            // Already exists
        }
    }
};
