<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_material_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_material_id')->constrained('course_materials')->cascadeOnDelete();
            $table->string('content_hash', 64)->index();
            $table->json('knowledge_map')->nullable();
            $table->json('chunks')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('analysis_provider', 32)->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['course_material_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_material_analyses');
    }
};
