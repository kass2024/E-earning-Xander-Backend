<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_email')) {
                $table->string('guest_email', 190)->nullable()->after('guest_token');
            }
            if (!Schema::hasColumn('livezoom_cohort_queue_entries', 'guest_phone')) {
                $table->string('guest_phone', 30)->nullable()->after('guest_email');
            }
            if (!Schema::hasColumn('livezoom_cohort_queue_entries', 'attended_at')) {
                $table->timestamp('attended_at')->nullable()->after('admitted_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('livezoom_cohort_queue_entries')) {
            return;
        }

        Schema::table('livezoom_cohort_queue_entries', function (Blueprint $table) {
            foreach (['attended_at', 'guest_phone', 'guest_email'] as $column) {
                if (Schema::hasColumn('livezoom_cohort_queue_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
