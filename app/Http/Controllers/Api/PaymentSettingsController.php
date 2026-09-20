<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\LiveUsdRwfRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
        $rules = [
            'momo_receiver_phone' => 'required|string|min:9|max:32',
            'momo_receiver_name' => 'nullable|string|max:120',
            'momo_whatsapp_phone' => 'nullable|string|max:32',
        ];

        if (Schema::hasColumn('site_settings', 'meeting_fee_usd')) {
            $rules['meeting_fee_usd'] = 'nullable|numeric|min:0|max:99999';
        }
        if (Schema::hasColumn('site_settings', 'meeting_payment_required')) {
            $rules['meeting_payment_required'] = 'nullable|boolean';
        }

        $data = $request->validate($rules);

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
        if (Schema::hasColumn('site_settings', 'meeting_fee_usd') && array_key_exists('meeting_fee_usd', $data)) {
            $settings->meeting_fee_usd = $data['meeting_fee_usd'];
            if (Schema::hasColumn('site_settings', 'meeting_fee_rwf')) {
                $settings->meeting_fee_rwf = app(LiveUsdRwfRateService::class)
                    ->convertUsdToRwf((float) $data['meeting_fee_usd']);
            }
        }
        if (Schema::hasColumn('site_settings', 'meeting_payment_required') && array_key_exists('meeting_payment_required', $data)) {
            $settings->meeting_payment_required = (bool) $data['meeting_payment_required'];
        }
        $settings->save();

        return response()->json([
            'message' => 'Payment settings updated',
            'payment_receiver' => $settings->fresh()->paymentReceiverPayload(),
        ], 200);
    }
}
