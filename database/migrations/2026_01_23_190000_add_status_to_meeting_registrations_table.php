<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'status')) {
                $table->string('status')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_registrations', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
