<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\Request;

class PaymentSettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'payment_receiver' => SiteSetting::current()->paymentReceiverPayload(),
        ], 200);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'momo_receiver_phone' => 'required|string|min:9|max:32',
            'momo_receiver_name' => 'nullable|string|max:120',
            'momo_whatsapp_phone' => 'nullable|string|max:32',
        ]);

        $digits = preg_replace('/\D+/', '', $data['momo_receiver_phone']) ?: '';
        if (strlen($digits) < 9) {
            return response()->json([
                'message' => 'Enter a valid Mobile Money number (at least 9 digits).',
            ], 422);
        }

        $settings = SiteSetting::current();
        $settings->momo_receiver_phone = $data['momo_receiver_phone'];
        $settings->momo_receiver_name = $data['momo_receiver_name'] ?? $settings->momo_receiver_name;
        if (array_key_exists('momo_whatsapp_phone', $data)) {
            $settings->momo_whatsapp_phone = $data['momo_whatsapp_phone'] ?: null;
        }
        $settings->save();

        return response()->json([
            'message' => 'Mobile Money receive number updated',
            'payment_receiver' => $settings->fresh()->paymentReceiverPayload(),
        ], 200);
    }
}
