<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'zoom_host_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('zoom_host_user_id', 255)->nullable()->after('platform_institution_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'zoom_host_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('zoom_host_user_id');
            });
        }
    }
};
