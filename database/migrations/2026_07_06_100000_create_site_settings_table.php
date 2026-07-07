<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_settings')) {
            return;
        }

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('promo_banner_published')->default(false);
            $table->string('promo_banner_headline')->nullable();
            $table->string('promo_banner_offer_text')->nullable();
            $table->string('promo_banner_coupon_code', 64)->nullable();
            $table->string('promo_banner_link_url', 500)->nullable();
            $table->string('promo_banner_background_color', 32)->default('#254D81');
            $table->timestamp('promo_banner_countdown_ends_at')->nullable();
            $table->boolean('promo_banner_show_countdown')->default(true);
            $table->boolean('promo_banner_show_coupon')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
