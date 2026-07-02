<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes queue table indexes when 140000 failed on MySQL identifier length limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        if (!$this->indexExists('livezoom_cohort_queue_entries', 'lz_queue_cohort_status_idx')) {
            Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
                $table->index(['livezoom_cohort_id', 'status'], 'lz_queue_cohort_status_idx');
            });
        }

        if (!$this->indexExists('livezoom_cohort_queue_entries', 'lz_queue_cohort_student_idx')) {
            Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
                $table->index(['livezoom_cohort_id', 'student_id'], 'lz_queue_cohort_student_idx');
            });
        }
    }

    public function down(): void
    {
        // no-op
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
