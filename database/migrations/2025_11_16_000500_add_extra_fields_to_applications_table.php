<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('institution_name')->nullable();
            $table->date('start_date')->nullable();
            $table->string('recruitment_partner_name')->nullable();
            $table->string('requirements_status')->nullable();
            $table->string('current_stage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'institution_name',
                'start_date',
                'recruitment_partner_name',
                'requirements_status',
                'current_stage',
            ]);
        });
    }
};
