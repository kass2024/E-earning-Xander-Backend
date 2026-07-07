<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class PromoBannerController extends Controller
{
    public function show()
    {
        $settings = SiteSetting::current();

        return response()->json([
            'banner' => $settings->promoBannerPayload(),
        ], 200);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'published' => 'nullable|boolean',
            'headline' => 'nullable|string|max:120',
            'offer_text' => 'nullable|string|max:255',
            'coupon_code' => 'nullable|string|max:64',
            'link_url' => 'nullable|string|max:500',
            'background_color' => 'nullable|string|max:32',
            'countdown_ends_at' => 'nullable|date',
            'show_countdown' => 'nullable|boolean',
            'show_coupon' => 'nullable|boolean',
        ]);

        $settings = SiteSetting::current();

        if (array_key_exists('published', $data)) {
            $settings->promo_banner_published = (bool) $data['published'];
        }
        if (array_key_exists('headline', $data)) {
            $settings->promo_banner_headline = $data['headline'];
        }
        if (array_key_exists('offer_text', $data)) {
            $settings->promo_banner_offer_text = $data['offer_text'];
        }
        if (array_key_exists('coupon_code', $data)) {
            $settings->promo_banner_coupon_code = $data['coupon_code'];
        }
        if (array_key_exists('link_url', $data)) {
            $settings->promo_banner_link_url = $data['link_url'];
        }
        if (array_key_exists('background_color', $data)) {
            $settings->promo_banner_background_color = $data['background_color'] ?: '#254D81';
        }
        if (array_key_exists('countdown_ends_at', $data)) {
            $settings->promo_banner_countdown_ends_at = $data['countdown_ends_at'];
        }
        if (array_key_exists('show_countdown', $data)) {
            $settings->promo_banner_show_countdown = (bool) $data['show_countdown'];
        }
        if (array_key_exists('show_coupon', $data)) {
            $settings->promo_banner_show_coupon = (bool) $data['show_coupon'];
        }

        if ($settings->isDirty()) {
            $settings->save();
        }

        // Always bump updated_at so republishing resets client dismiss state (revision).
        $settings->touch();

        return response()->json([
            'message' => 'Promo banner updated',
            'banner' => $settings->fresh()->promoBannerPayload(),
        ], 200);
    }
}
