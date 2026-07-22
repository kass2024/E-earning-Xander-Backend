<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('site_settings')) {
            return;
        }

        Schema::table('site_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('site_settings', 'momo_receiver_phone')) {
                $table->string('momo_receiver_phone', 32)->nullable();
            }
            if (!Schema::hasColumn('site_settings', 'momo_receiver_name')) {
                $table->string('momo_receiver_name', 120)->nullable();
            }
            if (!Schema::hasColumn('site_settings', 'momo_whatsapp_phone')) {
                $table->string('momo_whatsapp_phone', 32)->nullable();
            }
        });

        // Xander uses its own single receiver — set via admin Settings or MOPAY_RECEIVER_ACCOUNT_NO.
        // Do not seed another product's MoMo number.
    }

    public function down(): void
    {
        if (!Schema::hasTable('site_settings')) {
            return;
        }

        Schema::table('site_settings', function (Blueprint $table) {
            foreach (['momo_whatsapp_phone', 'momo_receiver_name', 'momo_receiver_phone'] as $col) {
                if (Schema::hasColumn('site_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
