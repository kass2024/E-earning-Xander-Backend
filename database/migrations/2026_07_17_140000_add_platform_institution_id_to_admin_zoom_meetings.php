<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_zoom_meetings')) {
            return;
        }

        Schema::table('admin_zoom_meetings', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_zoom_meetings', 'platform_institution_id')) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('created_by_user_id');
                $table->index('platform_institution_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_zoom_meetings')) {
            return;
        }

        Schema::table('admin_zoom_meetings', function (Blueprint $table) {
            if (Schema::hasColumn('admin_zoom_meetings', 'platform_institution_id')) {
                $table->dropColumn('platform_institution_id');
            }
        });
    }
};
