<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('external_course_payments')) {
            return;
        }

        Schema::create('external_course_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->string('course_title')->nullable();
            $table->unsignedInteger('course_price_rwf')->default(0);
            $table->unsignedInteger('amount_rwf');
            $table->string('currency', 8)->default('RWF');
            $table->string('payer_name')->nullable();
            $table->string('payer_email');
            $table->string('payer_phone', 32);
            $table->string('msisdn', 32)->nullable();
            $table->string('mno', 16)->default('mtn');
            $table->string('external_reference')->unique();
            $table->string('provider', 32)->default('mopay');
            $table->string('status', 32)->default('processing')->index();
            $table->string('receipt_path')->nullable();
            $table->boolean('receipt_emailed')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_course_payments');
    }
};
