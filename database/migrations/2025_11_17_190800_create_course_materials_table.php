<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_materials', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('course_id');

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('lesson');
            $table->string('resource_url')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->foreign('course_id')
                ->references('id')->on('courses')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_materials');
    }
};
