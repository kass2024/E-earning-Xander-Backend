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
            if (!Schema::hasColumn('platform_institutions', 'portal_tagline')) {
                $table->string('portal_tagline')->nullable()->after('address');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_hero_title')) {
                $table->string('portal_hero_title')->nullable()->after('portal_tagline');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_hero_subtitle')) {
                $table->text('portal_hero_subtitle')->nullable()->after('portal_hero_title');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_about')) {
                $table->text('portal_about')->nullable()->after('portal_hero_subtitle');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_primary_color')) {
                $table->string('portal_primary_color', 16)->nullable()->after('portal_about');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_features')) {
                $table->json('portal_features')->nullable()->after('portal_primary_color');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_hero_image_path')) {
                $table->string('portal_hero_image_path')->nullable()->after('portal_features');
            }
            if (!Schema::hasColumn('platform_institutions', 'portal_cta_label')) {
                $table->string('portal_cta_label', 120)->nullable()->after('portal_hero_image_path');
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
                'portal_tagline',
                'portal_hero_title',
                'portal_hero_subtitle',
                'portal_about',
                'portal_primary_color',
                'portal_features',
                'portal_hero_image_path',
                'portal_cta_label',
            ] as $column) {
                if (Schema::hasColumn('platform_institutions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
