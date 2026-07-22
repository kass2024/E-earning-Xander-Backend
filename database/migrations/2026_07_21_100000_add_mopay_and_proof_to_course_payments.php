<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('course_payments')) {
            return;
        }

        Schema::table('course_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('course_payments', 'external_reference')) {
                $table->string('external_reference')->nullable()->after('stripe_payment_intent_id');
                $table->index('external_reference');
            }
            if (!Schema::hasColumn('course_payments', 'msisdn')) {
                $table->string('msisdn', 32)->nullable()->after('external_reference');
            }
            if (!Schema::hasColumn('course_payments', 'proof_path')) {
                $table->string('proof_path')->nullable()->after('msisdn');
            }
            if (!Schema::hasColumn('course_payments', 'proof_note')) {
                $table->text('proof_note')->nullable()->after('proof_path');
            }
            if (!Schema::hasColumn('course_payments', 'promo_code')) {
                $table->string('promo_code', 64)->nullable()->after('proof_note');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_payments')) {
            return;
        }

        Schema::table('course_payments', function (Blueprint $table) {
            foreach (['external_reference', 'msisdn', 'proof_path', 'proof_note', 'promo_code'] as $col) {
                if (Schema::hasColumn('course_payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
