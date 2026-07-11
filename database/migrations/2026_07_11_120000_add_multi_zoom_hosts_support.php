<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_institutions') && !Schema::hasColumn('platform_institutions', 'zoom_host_user_id')) {
            Schema::table('platform_institutions', function (Blueprint $table) {
                $table->string('zoom_host_user_id', 255)->nullable()->after('portal_cta_label');
            });
        }

        if (Schema::hasTable('livezoom_cohort') && !Schema::hasColumn('livezoom_cohort', 'zoom_host_user_id')) {
            Schema::table('livezoom_cohort', function (Blueprint $table) {
                $table->string('zoom_host_user_id', 255)->nullable()->after('zoom_password');
            });
        }

        if (Schema::hasTable('webinar_settings')) {
            Schema::table('webinar_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('webinar_settings', 'platform_institution_id')) {
                    $table->unsignedBigInteger('platform_institution_id')->nullable()->after('id');
                    $table->index('platform_institution_id');
                }
                if (!Schema::hasColumn('webinar_settings', 'zoom_host_user_id')) {
                    $table->string('zoom_host_user_id', 255)->nullable()->after('zoom_start_url');
                }
            });
        }

        if (Schema::hasTable('available_schedules') && !Schema::hasColumn('available_schedules', 'platform_institution_id')) {
            Schema::table('available_schedules', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('id');
                $table->index('platform_institution_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_institutions') && Schema::hasColumn('platform_institutions', 'zoom_host_user_id')) {
            Schema::table('platform_institutions', function (Blueprint $table) {
                $table->dropColumn('zoom_host_user_id');
            });
        }

        if (Schema::hasTable('livezoom_cohort') && Schema::hasColumn('livezoom_cohort', 'zoom_host_user_id')) {
            Schema::table('livezoom_cohort', function (Blueprint $table) {
                $table->dropColumn('zoom_host_user_id');
            });
        }

        if (Schema::hasTable('webinar_settings')) {
            Schema::table('webinar_settings', function (Blueprint $table) {
                if (Schema::hasColumn('webinar_settings', 'platform_institution_id')) {
                    $table->dropColumn('platform_institution_id');
                }
                if (Schema::hasColumn('webinar_settings', 'zoom_host_user_id')) {
                    $table->dropColumn('zoom_host_user_id');
                }
            });
        }

        if (Schema::hasTable('available_schedules') && Schema::hasColumn('available_schedules', 'platform_institution_id')) {
            Schema::table('available_schedules', function (Blueprint $table) {
                $table->dropColumn('platform_institution_id');
            });
        }
    }
};
