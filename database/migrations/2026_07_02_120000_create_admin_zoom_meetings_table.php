<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_zoom_meetings')) {
            return;
        }

        Schema::create('admin_zoom_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('zoom_meeting_id', 64)->unique();
            $table->string('zoom_uuid', 255)->nullable();
            $table->string('topic');
            $table->timestamp('start_time')->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->text('join_url')->nullable();
            $table->string('password', 64)->nullable();
            $table->text('agenda')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('start_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_zoom_meetings');
    }
};
