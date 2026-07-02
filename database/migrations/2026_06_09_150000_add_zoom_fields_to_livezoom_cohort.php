<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::table('livezoom_cohort', function (Blueprint $table) {
            if (!Schema::hasColumn('livezoom_cohort', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable();
            }
            if (!Schema::hasColumn('livezoom_cohort', 'zoom_start_url')) {
                $table->text('zoom_start_url')->nullable();
            }
            if (!Schema::hasColumn('livezoom_cohort', 'zoom_password')) {
                $table->string('zoom_password')->nullable();
            }
            if (!Schema::hasColumn('livezoom_cohort', 'zoom_description')) {
                $table->text('zoom_description')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::table('livezoom_cohort', function (Blueprint $table) {
            foreach (['zoom_description', 'zoom_password', 'zoom_start_url', 'zoom_meeting_id'] as $column) {
                if (Schema::hasColumn('livezoom_cohort', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
