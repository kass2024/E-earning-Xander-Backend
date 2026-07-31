<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meet_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('max_participants')->default(25);
            $table->unsignedBigInteger('storage_mb')->default(10240);
            $table->unsignedBigInteger('monthly_credits')->default(5000);
            $table->unsignedInteger('price_usd_cents')->default(2900);
            $table->unsignedInteger('price_rwf')->default(35000);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('meet_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->constrained('meet_subscription_plans');
            $table->string('status')->default('pending');
            $table->string('billing_provider')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->string('stripe_customer_id')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['platform_institution_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('meet_usage_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('meet_subscriptions')->cascadeOnDelete();
            $table->unsignedBigInteger('credits_allocated')->default(0);
            $table->unsignedBigInteger('credits_used')->default(0);
            $table->unsignedBigInteger('storage_mb_allocated')->default(0);
            $table->unsignedBigInteger('storage_mb_used')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->boolean('is_exhausted')->default(false);
            $table->timestamps();

            $table->index(['subscription_id', 'period_start']);
        });

        Schema::create('meet_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('meet_subscriptions')->cascadeOnDelete();
            $table->foreignId('usage_credit_id')->nullable()->constrained('meet_usage_credits')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->unsignedInteger('credits_consumed')->default(0);
            $table->unsignedInteger('storage_mb_delta')->default(0);
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->string('meeting_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });

        Schema::create('meet_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('meet_subscriptions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('meet_subscription_plans');
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('provider')->default('stripe');
            $table->string('status')->default('pending');
            $table->string('external_reference')->nullable()->unique();
            $table->string('stripe_session_id')->nullable();
            $table->string('msisdn')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meet_subscription_payments');
        Schema::dropIfExists('meet_usage_logs');
        Schema::dropIfExists('meet_usage_credits');
        Schema::dropIfExists('meet_subscriptions');
        Schema::dropIfExists('meet_subscription_plans');
    }
};
