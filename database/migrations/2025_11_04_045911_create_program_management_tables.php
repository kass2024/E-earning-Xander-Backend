<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1️⃣ Destinations
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 2️⃣ Institutions
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('destination_id')->constrained('destinations')->onDelete('cascade');
            $table->timestamps();
        });

        // 3️⃣ Program Levels
        Schema::create('program_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // 4️⃣ Program Level Categories
        Schema::create('program_level_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // 5️⃣ Fields of Study
        Schema::create('fields_of_study', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // 6️⃣ Intakes
        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // 7️⃣ Junction: institutions_program_levels
        Schema::create('institution_program_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->onDelete('cascade');
            $table->foreignId('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->timestamps();
        });

        // 8️⃣ Junction: program_level_fields
        Schema::create('program_level_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->foreignId('field_id')->constrained('fields_of_study')->onDelete('cascade');
            $table->timestamps();
        });

        // 9️⃣ Junction: program_level_categories_levels
        Schema::create('program_level_categories_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('program_level_categories')->onDelete('cascade');
            $table->timestamps();
        });

        // 🔟 Junction: program_level_intakes
        Schema::create('program_level_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_level_id')->constrained('program_levels')->onDelete('cascade');
            $table->foreignId('intake_id')->constrained('intakes')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_level_intakes');
        Schema::dropIfExists('program_level_categories_levels');
        Schema::dropIfExists('program_level_fields');
        Schema::dropIfExists('institution_program_levels');
        Schema::dropIfExists('intakes');
        Schema::dropIfExists('fields_of_study');
        Schema::dropIfExists('program_level_categories');
        Schema::dropIfExists('program_levels');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('destinations');
    }
};
