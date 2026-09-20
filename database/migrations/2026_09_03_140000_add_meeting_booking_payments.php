<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_settings')) {
            Schema::table('site_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('site_settings', 'meeting_fee_usd')) {
                    $table->decimal('meeting_fee_usd', 10, 2)->nullable()->after('momo_whatsapp_phone');
                }
                if (!Schema::hasColumn('site_settings', 'meeting_fee_rwf')) {
                    $table->unsignedInteger('meeting_fee_rwf')->nullable()->after('meeting_fee_usd');
                }
                if (!Schema::hasColumn('site_settings', 'meeting_payment_required')) {
                    $table->boolean('meeting_payment_required')->default(true)->after('meeting_fee_rwf');
                }
            });
        }

        if (Schema::hasTable('meeting_registrations')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                if (!Schema::hasColumn('meeting_registrations', 'payment_status')) {
                    $table->string('payment_status', 32)->nullable()->after('status');
                }
                if (!Schema::hasColumn('meeting_registrations', 'payment_provider')) {
                    $table->string('payment_provider', 32)->nullable()->after('payment_status');
                }
                if (!Schema::hasColumn('meeting_registrations', 'payment_reference')) {
                    $table->string('payment_reference', 191)->nullable()->after('payment_provider');
                }
                if (!Schema::hasColumn('meeting_registrations', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('payment_reference');
                }
            });
        }

        if (!Schema::hasTable('meeting_payments')) {
            Schema::create('meeting_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meeting_registration_id')->index();
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 10)->default('usd');
                $table->string('provider', 32);
                $table->string('stripe_session_id')->nullable()->index();
                $table->string('external_reference')->nullable()->index();
                $table->string('msisdn', 32)->nullable();
                $table->string('status', 32)->default('pending');
                $table->json('metadata')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meeting_payments')) {
            Schema::dropIfExists('meeting_payments');
        }

        if (Schema::hasTable('meeting_registrations')) {
            Schema::table('meeting_registrations', function (Blueprint $table) {
                foreach (['payment_status', 'payment_provider', 'payment_reference', 'paid_at'] as $col) {
                    if (Schema::hasColumn('meeting_registrations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('site_settings')) {
            Schema::table('site_settings', function (Blueprint $table) {
                foreach (['meeting_fee_usd', 'meeting_fee_rwf', 'meeting_payment_required'] as $col) {
                    if (Schema::hasColumn('site_settings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
