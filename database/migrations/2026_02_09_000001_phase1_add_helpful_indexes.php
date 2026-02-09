<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 1: Add helpful indexes for common queries
 * 
 * - daily_assignments: improve professor_student_assignment_id lookups
 * - daily_assignments: improve daily_template_id lookups
 * - professor_student_assignments: composite index for prof + student + status
 * 
 * Migration Date: 2026-02-09
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_assignments')) {
            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->index('professor_student_assignment_id', 'idx_psa_id');
                });
                logger()->info('[MIGRATION] Added idx_psa_id to daily_assignments');
            } catch (\Exception $e) {
                logger()->warning('[MIGRATION] idx_psa_id: ' . $e->getMessage());
            }

            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->index('daily_template_id', 'idx_template_id');
                });
                logger()->info('[MIGRATION] Added idx_template_id to daily_assignments');
            } catch (\Exception $e) {
                logger()->warning('[MIGRATION] idx_template_id: ' . $e->getMessage());
            }

            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->index(['professor_student_assignment_id', 'status'], 'idx_psa_status');
                });
                logger()->info('[MIGRATION] Added idx_psa_status to daily_assignments');
            } catch (\Exception $e) {
                logger()->warning('[MIGRATION] idx_psa_status: ' . $e->getMessage());
            }
        }

        if (Schema::hasTable('professor_student_assignments')) {
            try {
                Schema::table('professor_student_assignments', function (Blueprint $table) {
                    $table->index(['professor_id', 'student_id', 'status'], 'idx_prof_student_status');
                });
                logger()->info('[MIGRATION] Added idx_prof_student_status to professor_student_assignments');
            } catch (\Exception $e) {
                logger()->warning('[MIGRATION] idx_prof_student_status: ' . $e->getMessage());
            }
        }

        if (Schema::hasTable('socios_padron')) {
            try {
                Schema::table('socios_padron', function (Blueprint $table) {
                    $table->index(['dni', 'sid', 'barcode'], 'idx_socios_identity');
                });
                logger()->info('[MIGRATION] Added idx_socios_identity to socios_padron');
            } catch (\Exception $e) {
                logger()->warning('[MIGRATION] idx_socios_identity: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('daily_assignments')) {
            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->dropIndex('idx_psa_id');
                });
            } catch (\Exception $e) {
                // Index might not exist
            }

            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->dropIndex('idx_template_id');
                });
            } catch (\Exception $e) {
                // Index might not exist
            }

            try {
                Schema::table('daily_assignments', function (Blueprint $table) {
                    $table->dropIndex('idx_psa_status');
                });
            } catch (\Exception $e) {
                // Index might not exist
            }
        }

        if (Schema::hasTable('professor_student_assignments')) {
            try {
                Schema::table('professor_student_assignments', function (Blueprint $table) {
                    $table->dropIndex('idx_prof_student_status');
                });
            } catch (\Exception $e) {
                // Index might not exist
            }
        }

        if (Schema::hasTable('socios_padron')) {
            try {
                Schema::table('socios_padron', function (Blueprint $table) {
                    $table->dropIndex('idx_socios_identity');
                });
            } catch (\Exception $e) {
                // Index might not exist
            }
        }
    }
};
