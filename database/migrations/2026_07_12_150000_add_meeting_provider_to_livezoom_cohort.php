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
            if (!Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
                $table->string('meeting_provider', 16)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('livezoom_cohort', 'daily_room_name')) {
                $table->string('daily_room_name', 255)->nullable()->after('zoom_password');
            }
            if (!Schema::hasColumn('livezoom_cohort', 'daily_room_url')) {
                $table->string('daily_room_url', 500)->nullable()->after('daily_room_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('livezoom_cohort')) {
            return;
        }

        Schema::table('livezoom_cohort', function (Blueprint $table) {
            if (Schema::hasColumn('livezoom_cohort', 'daily_room_url')) {
                $table->dropColumn('daily_room_url');
            }
            if (Schema::hasColumn('livezoom_cohort', 'daily_room_name')) {
                $table->dropColumn('daily_room_name');
            }
            if (Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
                $table->dropColumn('meeting_provider');
            }
        });
    }
};
