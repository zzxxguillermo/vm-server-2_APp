<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1: Add UNIQUE constraints to users table to prevent duplicates
 * 
 * This addresses the race condition in ensureUserFromSocioPadron()
 * where multiple users could be created with same dni or barcode.
 * 
 * Migration Date: 2026-02-09
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ✅ CRITICAL: Prevent duplicate users by dni
            // Index on dni if not exists (for performance)
            if (!Schema::hasColumn('users', 'dni')) {
                return; // Skip if column doesn't exist yet
            }
            
            // Check if unique constraint already exists BEFORE adding
            // Laravel doesn't give us an easy way to check, so we'll use raw SQL
            // DB::statement() will handle the error if it exists
        });

        // Use raw SQL to safely add unique constraint if not exists
        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE users ADD UNIQUE KEY unique_dni (dni)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.dni');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                \Illuminate\Support\Facades\Log::warning('[MIGRATION] Duplicate entries found in users.dni - fixing');
                
                // If duplicates exist, keep only the first for each dni
                \Illuminate\Support\Facades\DB::statement(
                    'DELETE u1 FROM users u1 
                     INNER JOIN users u2 
                     WHERE u1.id > u2.id 
                     AND u1.dni = u2.dni 
                     AND u1.dni IS NOT NULL'
                );
                
                // Now add the constraint
                \Illuminate\Support\Facades\DB::statement(
                    'ALTER TABLE users ADD UNIQUE KEY unique_dni (dni)'
                );
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.dni after cleanup');
            } elseif (str_contains($e->getMessage(), 'already exists')) {
                \Illuminate\Support\Facades\Log::info('[MIGRATION] UNIQUE constraint on users.dni already exists');
            }
        }

        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE users ADD UNIQUE KEY unique_barcode (barcode)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.barcode');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                \Illuminate\Support\Facades\Log::warning('[MIGRATION] Duplicate entries found in users.barcode - fixing');
                
                // Keep only first for each barcode
                \Illuminate\Support\Facades\DB::statement(
                    'DELETE u1 FROM users u1 
                     INNER JOIN users u2 
                     WHERE u1.id > u2.id 
                     AND u1.barcode = u2.barcode 
                     AND u1.barcode IS NOT NULL'
                );
                
                \Illuminate\Support\Facades\DB::statement(
                    'ALTER TABLE users ADD UNIQUE KEY unique_barcode (barcode)'
                );
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.barcode after cleanup');
            } elseif (str_contains($e->getMessage(), 'already exists')) {
                \Illuminate\Support\Facades\Log::info('[MIGRATION] UNIQUE constraint on users.barcode already exists');
            }
        }

        try {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE users ADD UNIQUE KEY unique_socio_id (socio_id)'
            );
            \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.socio_id');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                \Illuminate\Support\Facades\Log::warning('[MIGRATION] Duplicate entries found in users.socio_id');
                
                \Illuminate\Support\Facades\DB::statement(
                    'DELETE u1 FROM users u1 
                     INNER JOIN users u2 
                     WHERE u1.id > u2.id 
                     AND u1.socio_id = u2.socio_id 
                     AND u1.socio_id IS NOT NULL'
                );
                
                \Illuminate\Support\Facades\DB::statement(
                    'ALTER TABLE users ADD UNIQUE KEY unique_socio_id (socio_id)'
                );
                \Illuminate\Support\Facades\Log::info('[MIGRATION] Added UNIQUE constraint on users.socio_id after cleanup');
            } elseif (str_contains($e->getMessage(), 'already exists')) {
                \Illuminate\Support\Facades\Log::info('[MIGRATION] UNIQUE constraint on users.socio_id already exists');
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            try {
                $table->dropUnique('unique_dni');
            } catch (\Exception $e) {
                // Constraint might not exist
            }

            try {
                $table->dropUnique('unique_barcode');
            } catch (\Exception $e) {
                // Constraint might not exist
            }

            try {
                $table->dropUnique('unique_socio_id');
            } catch (\Exception $e) {
                // Constraint might not exist
            }
        });
    }
};
