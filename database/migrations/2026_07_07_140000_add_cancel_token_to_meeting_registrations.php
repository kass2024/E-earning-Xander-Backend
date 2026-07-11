<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('meeting_registrations', 'cancel_token')) {
                $table->string('cancel_token', 80)->nullable()->unique()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('meeting_registrations', 'cancel_token')) {
                $table->dropColumn('cancel_token');
            }
        });
    }
};
