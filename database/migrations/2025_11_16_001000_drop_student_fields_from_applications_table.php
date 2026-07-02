<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (Schema::hasColumn('applications', 'first_name')) {
                $table->dropColumn('first_name');
            }
            if (Schema::hasColumn('applications', 'last_name')) {
                $table->dropColumn('last_name');
            }
            if (Schema::hasColumn('applications', 'recruitment_partner_name')) {
                $table->dropColumn('recruitment_partner_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            if (!Schema::hasColumn('applications', 'first_name')) {
                $table->string('first_name')->nullable();
            }
            if (!Schema::hasColumn('applications', 'last_name')) {
                $table->string('last_name')->nullable();
            }
            if (!Schema::hasColumn('applications', 'recruitment_partner_name')) {
                $table->string('recruitment_partner_name')->nullable();
            }
        });
    }
};
