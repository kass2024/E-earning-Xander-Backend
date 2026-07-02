<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('livezoom_cohort')) {
            Schema::table('livezoom_cohort', function (Blueprint $table) {
                if (!Schema::hasColumn('livezoom_cohort', 'zoom_link')) {
                    $table->string('zoom_link', 2048)->nullable()->after('notes');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'session_status')) {
                    $after = Schema::hasColumn('livezoom_cohort', 'zoom_link') ? 'zoom_link' : 'notes';
                    $table->string('session_status', 20)->default('idle')->after($after);
                }
                if (!Schema::hasColumn('livezoom_cohort', 'session_started_at')) {
                    $table->timestamp('session_started_at')->nullable()->after('session_status');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'session_ended_at')) {
                    $table->timestamp('session_ended_at')->nullable()->after('session_started_at');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'current_queue_entry_id')) {
                    $table->unsignedBigInteger('current_queue_entry_id')->nullable()->after('session_ended_at');
                }
            });
        }

        if (!Schema::hasTable('livezoom_cohort_queue_entries') && Schema::hasTable('livezoom_cohort')) {
            Schema::create('livezoom_cohort_queue_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('livezoom_cohort_id')->constrained('livezoom_cohort')->cascadeOnDelete();
                $table->unsignedBigInteger('student_id')->nullable();
                $table->string('display_name');
                $table->string('status', 20)->default('waiting');
                $table->unsignedInteger('queue_position')->default(1);
                $table->timestamp('joined_at');
                $table->timestamp('admitted_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->index(['livezoom_cohort_id', 'status'], 'lz_queue_cohort_status_idx');
                $table->index(['livezoom_cohort_id', 'student_id'], 'lz_queue_cohort_student_idx');
            });
        }

        $this->ensureQueueIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('livezoom_cohort_queue_entries');

        if (!Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::table('livezoom_cohort', function (Blueprint $table) {
            foreach (['current_queue_entry_id', 'session_ended_at', 'session_started_at', 'session_status', 'zoom_link'] as $column) {
                if (Schema::hasColumn('livezoom_cohort', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function ensureQueueIndexes(): void
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

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }
};
