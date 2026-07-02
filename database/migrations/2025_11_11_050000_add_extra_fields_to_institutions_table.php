<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('city')->nullable()->after('destination_id');
            $table->string('tuition')->nullable()->after('city');
            $table->string('application_fee')->nullable()->after('tuition');
            $table->string('duration')->nullable()->after('application_fee');
            $table->string('can_take_loan')->nullable()->after('duration');
            $table->json('tags')->nullable()->after('can_take_loan');
            $table->string('success_chance')->nullable()->after('tags');
            $table->string('success_details')->nullable()->after('success_chance');
            $table->string('logo_path')->nullable()->after('success_details');
            $table->string('logo_url')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'city','tuition','application_fee','duration','can_take_loan','tags','success_chance','success_details','logo_path','logo_url'
            ]);
        });
    }
};
