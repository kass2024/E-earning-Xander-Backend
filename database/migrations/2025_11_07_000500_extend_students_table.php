<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Personal
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('middle_name')->nullable()->after('last_name');
            $table->date('dob')->nullable()->after('middle_name');
            $table->string('nationality')->nullable()->after('country');
            $table->string('passport_number')->nullable()->after('nationality');
            $table->date('passport_expiry')->nullable()->after('passport_number');
            $table->string('gender')->nullable()->after('passport_expiry');

            // Lead/Recruitment
            $table->string('referral_source')->nullable()->after('status');
            $table->string('recruitment_partner')->nullable()->after('referral_source');
            $table->string('recruitment_type')->nullable()->after('recruitment_partner');
            $table->string('education')->nullable()->after('recruitment_type');

            // Arrays / JSON
            $table->json('country_of_interest')->nullable()->after('education');
            $table->json('services_of_interest')->nullable()->after('country_of_interest');
            $table->text('services_other_text')->nullable()->after('services_of_interest');
            $table->json('application_stats')->nullable()->after('applications');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'first_name','last_name','middle_name','dob','nationality','passport_number','passport_expiry','gender',
                'referral_source','recruitment_partner','recruitment_type','education',
                'country_of_interest','services_of_interest','services_other_text','application_stats'
            ]);
        });
    }
};
