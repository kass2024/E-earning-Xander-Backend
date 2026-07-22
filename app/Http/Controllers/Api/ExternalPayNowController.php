<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalPayNowService;
use Illuminate\Http\Request;

class ExternalPayNowController extends Controller
{
    public function __construct(
        private readonly ExternalPayNowService $payNow
    ) {
    }

    public function courses()
    {
        $main = app(\App\Services\PaymentReceiverService::class)->resolve(null);

        return response()->json([
            'courses' => $this->payNow->listPayableCourses(),
            'receiver_phone' => $main['display_momo_phone'] ?? null,
            'receiver_name' => $main['momo_receiver_name'] ?? null,
            'brand_name' => $main['brand_name'] ?? null,
            'allows_partial_amount' => true,
            'currency' => strtoupper((string) config('services.mopay.default_currency', 'RWF')),
            'configured' => app(\App\Services\Mopay\MopayGatewayClient::class)->isConfigured(),
        ]);
    }

    public function request(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'amount' => 'required|numeric|min:1',
            'phone' => 'required|string|min:9|max:20',
            'email' => 'required|email|max:190',
            'payer_name' => 'nullable|string|max:120',
            'mno' => 'nullable|string|in:mtn,airtel',
        ]);

        $result = $this->payNow->requestPayment(
            (int) $validated['course_id'],
            (int) floor((float) $validated['amount']),
            $validated['phone'],
            $validated['email'],
            $validated['payer_name'] ?? null,
            $validated['mno'] ?? 'mtn'
        );

        if (empty($result['ok'])) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to start payment.',
                'max_amount' => $result['max_amount'] ?? null,
                'payment' => $result['payment'] ?? null,
            ], $result['status'] ?? 500);
        }

        return response()->json($result);
    }

    public function status(string $reference)
    {
        $result = $this->payNow->syncPaymentFromGateway($reference);
        if (empty($result['ok'])) {
            return response()->json(['message' => $result['message'] ?? 'Payment not found.'], 404);
        }

        return response()->json([
            'payment' => $result['payment'] ?? null,
            'message' => $result['message'] ?? null,
        ]);
    }
}
