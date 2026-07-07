<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class StarPromoBannerController extends Controller
{
    public function show()
    {
        $settings = SiteSetting::current();

        return response()->json([
            'banner' => $settings->starPromoBannerPayload(),
        ], 200);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'published' => 'nullable|boolean',
            'line1' => 'nullable|string|max:40',
            'line2' => 'nullable|string|max:40',
            'link_url' => 'nullable|string|max:500',
            'background_color' => 'nullable|string|max:32',
            'text_color' => 'nullable|string|max:32',
            'expires_at' => 'nullable|date',
        ]);

        $settings = SiteSetting::current();

        if (array_key_exists('published', $data)) {
            $settings->star_banner_published = (bool) $data['published'];
        }
        if (array_key_exists('line1', $data)) {
            $settings->star_banner_line1 = $data['line1'];
        }
        if (array_key_exists('line2', $data)) {
            $settings->star_banner_line2 = $data['line2'];
        }
        if (array_key_exists('link_url', $data)) {
            $settings->star_banner_link_url = $data['link_url'];
        }
        if (array_key_exists('background_color', $data)) {
            $settings->star_banner_background_color = $data['background_color'] ?: '#D4AF37';
        }
        if (array_key_exists('text_color', $data)) {
            $settings->star_banner_text_color = $data['text_color'] ?: '#FFFFFF';
        }
        if (array_key_exists('expires_at', $data)) {
            $settings->star_banner_expires_at = $data['expires_at'];
        }

        if ($settings->isDirty()) {
            $settings->save();
        }

        $settings->touch();

        return response()->json([
            'message' => 'Star promo banner updated',
            'banner' => $settings->fresh()->starPromoBannerPayload(),
        ], 200);
    }
}
