<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
                $table->foreignId('available_schedule_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('available_schedules')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_registrations', 'available_schedule_id')) {
                $table->dropConstrainedForeignId('available_schedule_id');
            }
        });
    }
};
