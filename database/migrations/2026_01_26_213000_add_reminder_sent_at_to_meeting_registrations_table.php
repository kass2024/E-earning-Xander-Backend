<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('zoom_start_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_registrations', 'reminder_sent_at')) {
                $table->dropColumn('reminder_sent_at');
            }
        });
    }
};
