<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')) {
                $table->string('guest_token', 64)->nullable()->after('student_id');
            }
        });

        if (
            Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')
            && !$this->indexExists('livezoom_cohort_queue_entries', 'lz_queue_cohort_guest_idx')
        ) {
            Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
                $table->index(['livezoom_cohort_id', 'guest_token'], 'lz_queue_cohort_guest_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_token')) {
                if ($this->indexExists('livezoom_cohort_queue_entries', 'lz_queue_cohort_guest_idx')) {
                    $table->dropIndex('lz_queue_cohort_guest_idx');
                }
                $table->dropColumn('guest_token');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
