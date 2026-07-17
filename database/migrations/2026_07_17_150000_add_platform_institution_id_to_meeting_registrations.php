<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        if (!Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->unsignedBigInteger('platform_institution_id')->nullable()->after('available_schedule_id');
                $table->index('platform_institution_id');
            });
        }

        // Backfill from schedule tenant when available.
        if (
            Schema::hasColumn('meeting_registrations', 'platform_institution_id')
            && Schema::hasTable('available_schedules')
            && Schema::hasColumn('available_schedules', 'platform_institution_id')
        ) {
            DB::statement(
                'UPDATE meeting_registrations mr
                 INNER JOIN available_schedules asched ON asched.id = mr.available_schedule_id
                 SET mr.platform_institution_id = asched.platform_institution_id
                 WHERE mr.platform_institution_id IS NULL
                   AND asched.platform_institution_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('meeting_registrations')) {
            return;
        }

        if (Schema::hasColumn('meeting_registrations', 'platform_institution_id')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                $table->dropColumn('platform_institution_id');
            });
        }
    }
};
