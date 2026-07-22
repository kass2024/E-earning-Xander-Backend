<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        Schema::table('platform_institutions', function (Blueprint $table) {
            if (!Schema::hasColumn('platform_institutions', 'momo_receiver_phone')) {
                $table->string('momo_receiver_phone', 32)->nullable();
            }
            if (!Schema::hasColumn('platform_institutions', 'momo_receiver_name')) {
                $table->string('momo_receiver_name', 120)->nullable();
            }
            if (!Schema::hasColumn('platform_institutions', 'momo_whatsapp_phone')) {
                $table->string('momo_whatsapp_phone', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        Schema::table('platform_institutions', function (Blueprint $table) {
            foreach (['momo_whatsapp_phone', 'momo_receiver_name', 'momo_receiver_phone'] as $col) {
                if (Schema::hasColumn('platform_institutions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
