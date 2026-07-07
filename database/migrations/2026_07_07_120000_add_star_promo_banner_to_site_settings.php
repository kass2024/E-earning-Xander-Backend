<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->boolean('star_banner_published')->default(false)->after('promo_banner_show_coupon');
            $table->string('star_banner_line1', 40)->nullable()->after('star_banner_published');
            $table->string('star_banner_line2', 40)->nullable()->after('star_banner_line1');
            $table->string('star_banner_link_url', 500)->nullable()->after('star_banner_line2');
            $table->string('star_banner_background_color', 32)->default('#D4AF37')->after('star_banner_link_url');
            $table->string('star_banner_text_color', 32)->default('#FFFFFF')->after('star_banner_background_color');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'star_banner_published',
                'star_banner_line1',
                'star_banner_line2',
                'star_banner_link_url',
                'star_banner_background_color',
                'star_banner_text_color',
            ]);
        });
    }
};
