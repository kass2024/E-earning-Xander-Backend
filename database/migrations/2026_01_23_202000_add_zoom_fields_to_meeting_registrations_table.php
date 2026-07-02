<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable()->after('rejected_reason');
            }
            if (!Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
                $table->text('zoom_join_url')->nullable()->after('zoom_meeting_id');
            }
            if (!Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $table->dateTime('zoom_start_time')->nullable()->after('zoom_join_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_registrations', 'zoom_start_time')) {
                $table->dropColumn('zoom_start_time');
            }
            if (Schema::hasColumn('meeting_registrations', 'zoom_join_url')) {
                $table->dropColumn('zoom_join_url');
            }
            if (Schema::hasColumn('meeting_registrations', 'zoom_meeting_id')) {
                $table->dropColumn('zoom_meeting_id');
            }
        });
    }
};
