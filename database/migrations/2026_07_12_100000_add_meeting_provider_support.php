<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_institutions') && !Schema::hasColumn('platform_institutions', 'meeting_provider')) {
            Schema::table('platform_institutions', function (Blueprint $table) {
                $table->string('meeting_provider', 16)->default('zoom')->after('zoom_host_user_id');
                $table->index('meeting_provider');
            });
        }

        if (Schema::hasTable('livezoom_cohort') && !Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            Schema::table('livezoom_cohort', function (Blueprint $table) {
                $table->string('meeting_provider', 16)->nullable()->after('zoom_host_user_id');
                $table->string('external_meeting_id', 255)->nullable()->after('meeting_provider');
                $table->string('daily_room_url', 512)->nullable()->after('external_meeting_id');
            });
        }

        if (Schema::hasTable('webinar_settings')) {
            Schema::table('webinar_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('webinar_settings', 'meeting_provider')) {
                    $table->string('meeting_provider', 16)->nullable()->after('zoom_host_user_id');
                }
                if (!Schema::hasColumn('webinar_settings', 'external_meeting_id')) {
                    $table->string('external_meeting_id', 255)->nullable()->after('meeting_provider');
                }
                if (!Schema::hasColumn('webinar_settings', 'daily_room_url')) {
                    $table->string('daily_room_url', 512)->nullable()->after('external_meeting_id');
                }
                if (!Schema::hasColumn('webinar_settings', 'recording_status')) {
                    $table->string('recording_status', 32)->nullable()->after('recording_enabled');
                }
            });
        }

        if (Schema::hasTable('platform_institutions') && Schema::hasColumn('platform_institutions', 'meeting_provider')) {
            DB::table('platform_institutions')
                ->whereNull('meeting_provider')
                ->orWhere('meeting_provider', '')
                ->update(['meeting_provider' => 'zoom']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_institutions') && Schema::hasColumn('platform_institutions', 'meeting_provider')) {
            Schema::table('platform_institutions', function (Blueprint $table) {
                $table->dropIndex(['meeting_provider']);
                $table->dropColumn('meeting_provider');
            });
        }

        if (Schema::hasTable('livezoom_cohort')) {
            Schema::table('livezoom_cohort', function (Blueprint $table) {
                foreach (['daily_room_url', 'external_meeting_id', 'meeting_provider'] as $col) {
                    if (Schema::hasColumn('livezoom_cohort', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('webinar_settings')) {
            Schema::table('webinar_settings', function (Blueprint $table) {
                foreach (['recording_status', 'daily_room_url', 'external_meeting_id', 'meeting_provider'] as $col) {
                    if (Schema::hasColumn('webinar_settings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
