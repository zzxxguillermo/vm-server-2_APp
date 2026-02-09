<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1: Add query optimization indexes
 * 
 * These indexes improve performance for common queries in AssignmentController:
 * - Finding users by dni, barcode, socio_id (used in ensureUserFromSocioPadron)
 * - Finding active assignments for professor+student
 * - Finding daily_assignments for display
 * 
 * Migration Date: 2026-02-09
 */
return new class extends Migration
{
    public function up(): void
    {
        // ✅ Index for fast lookups by dni, barcode, socio_id
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE users ADD INDEX idx_dni_barcode (dni, barcode)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_dni_barcode');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_dni_barcode: ' . $e->getMessage());
        }

        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE users ADD INDEX idx_socio_id (socio_id)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_socio_id');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_socio_id: ' . $e->getMessage());
        }

        // ✅ Index for finding active PSA by professor+student
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE professor_student_assignments 
                 ADD INDEX idx_prof_student_status (professor_id, student_id, status)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_prof_student_status');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_prof_student_status: ' . $e->getMessage());
        }

        // ✅ Index for finding daily assignments by template status
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE daily_assignments 
                 ADD INDEX idx_template_status (daily_template_id, status)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_template_status');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_template_status: ' . $e->getMessage());
        }

        // ✅ Index for finding assignments by created date (for updates)
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE daily_assignments 
                 ADD INDEX idx_created_at (created_at)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_created_at to daily_assignments');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_created_at: ' . $e->getMessage());
        }

        // ✅ Index for socios_padron lookups
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE socios_padron 
                 ADD INDEX idx_dni_sid (dni, sid)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added idx_dni_sid to socios_padron');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('[MIGRATION] idx_dni_sid: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP INDEX idx_dni_barcode');
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP INDEX idx_socio_id');
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE professor_student_assignments DROP INDEX idx_prof_student_status');
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE daily_assignments DROP INDEX idx_template_status');
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE daily_assignments DROP INDEX idx_created_at');
        } catch (\Exception $e) {
            // Index might not exist
        }

        try {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE socios_padron DROP INDEX idx_dni_sid');
        } catch (\Exception $e) {
            // Index might not exist
        }
    }
};
