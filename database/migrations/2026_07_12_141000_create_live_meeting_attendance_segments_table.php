<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('live_meeting_attendance_segments')) {
            Schema::create('live_meeting_attendance_segments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('platform_institution_id')->nullable();
                $table->unsignedBigInteger('course_id')->nullable();
                $table->unsignedBigInteger('course_material_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider', 32);
                $table->string('provider_session_id', 128)->nullable();
                $table->string('provider_participant_id', 128)->nullable();
                $table->string('provider_user_id', 64)->nullable();
                $table->string('role', 32)->nullable();
                $table->string('source', 32)->default('webhook');
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['course_material_id', 'user_id']);
                $table->index(['provider', 'provider_session_id']);
                $table->index(['provider_user_id', 'course_material_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_meeting_attendance_segments');
    }
};
