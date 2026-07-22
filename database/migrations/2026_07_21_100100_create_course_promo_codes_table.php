<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('course_promo_codes')) {
            return;
        }

        Schema::create('course_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label')->nullable();
            $table->unsignedInteger('max_uses')->default(100);
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_promo_codes');
    }
};
