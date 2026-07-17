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
            if (!Schema::hasColumn('platform_institutions', 'portal_accent_color')) {
                $table->string('portal_accent_color', 16)->nullable()->after('portal_primary_color');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_hero_bg_color')) {
                $table->string('portal_hero_bg_color', 16)->nullable()->after('portal_accent_color');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_button_bg_color')) {
                $table->string('portal_button_bg_color', 16)->nullable()->after('portal_hero_bg_color');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_button_text_color')) {
                $table->string('portal_button_text_color', 16)->nullable()->after('portal_button_bg_color');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('platform_institutions')) {
            return;
        }

        Schema::table('platform_institutions', function (Blueprint $table) {
            foreach ([
                'portal_accent_color',
                'portal_hero_bg_color',
                'portal_button_bg_color',
                'portal_button_text_color',
            ] as $col) {
                if (Schema::hasColumn('platform_institutions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
