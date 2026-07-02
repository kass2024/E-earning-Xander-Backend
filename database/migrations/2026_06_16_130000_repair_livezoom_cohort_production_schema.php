<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety-net migration for production servers where an earlier migration failed
 * and left livezoom_cohort / queue tables partially updated.
 */
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
                    $table->string('session_status', 20)->default('idle')->after('notes');
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
                if (!Schema::hasColumn('livezoom_cohort', 'zoom_meeting_id')) {
                    $table->string('zoom_meeting_id', 64)->nullable()->after('zoom_link');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'zoom_start_url')) {
                    $table->string('zoom_start_url', 2048)->nullable()->after('zoom_meeting_id');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'zoom_password')) {
                    $table->string('zoom_password', 64)->nullable()->after('zoom_start_url');
                }
                if (!Schema::hasColumn('livezoom_cohort', 'zoom_description')) {
                    $table->text('zoom_description')->nullable()->after('zoom_password');
                }
            });
        }

        if (!Schema::hasTable('livezoom_cohort_queue_entries') && Schema::hasTable('livezoom_cohort')) {
            Schema::create('livezoom_cohort_queue_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('livezoom_cohort_id')->constrained('livezoom_cohort')->cascadeOnDelete();
                $table->unsignedBigInteger('student_id')->nullable();
                $table->string('guest_token', 64)->nullable();
                $table->string('guest_email', 190)->nullable();
                $table->string('guest_phone', 30)->nullable();
                $table->string('display_name');
                $table->string('status', 20)->default('waiting');
                $table->unsignedInteger('queue_position')->default(1);
                $table->timestamp('joined_at');
                $table->timestamp('admitted_at')->nullable();
                $table->timestamp('attended_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->index(['livezoom_cohort_id', 'status'], 'lz_queue_cohort_status_idx');
                $table->index(['livezoom_cohort_id', 'student_id'], 'lz_queue_cohort_student_idx');
                $table->index(['livezoom_cohort_id', 'guest_token'], 'lz_queue_cohort_guest_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op: repair migration must not drop production data.
    }
};
