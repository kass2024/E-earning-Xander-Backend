<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_program_level_field_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->onDelete('cascade');
            $table->foreignId('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->foreignId('field_id')->constrained('fields_of_study')->onDelete('cascade');
            $table->foreignId('intake_id')->constrained('intakes')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['institution_id', 'program_level_id', 'field_id', 'intake_id'], 'iplfi_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_program_level_field_intakes');
    }
};
