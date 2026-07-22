<?php

namespace App\Services;

use App\Models\Course;
use App\Models\SiteSetting;

/**
 * Resolves Xander's single MoMo receive number from SiteSetting (admin Settings),
 * then env MOPAY_RECEIVER_ACCOUNT_NO. Independent from F&R / other products —
 * do not share MoPay credentials or receiver numbers across projects.
 */
class PaymentReceiverService
{
    public function mainBrandName(): string
    {
        $name = trim((string) config('app.name', ''));
        if ($name !== '' && strcasecmp($name, 'Laravel') !== 0) {
            return $name;
        }

        return 'Xander Global Academy';
    }

    /**
     * @return array{
     *   source: 'main',
     *   brand_name: string,
     *   brand_logo_url: ?string,
     *   brand_primary_color: string,
     *   platform_institution_id: ?int,
     *   momo_receiver_phone: string,
     *   momo_receiver_name: string,
     *   momo_whatsapp_phone: string,
     *   display_momo_phone: string,
     *   display_whatsapp_phone: string,
     *   receiver_account_no: string
     * }
     */
    public function resolve(?Course $course = null): array
    {
        unset($course); // One platform receiver for all courses on this product.
        $settings = SiteSetting::current();
        $payload = $settings->paymentReceiverPayload();

        return [
            'source' => 'main',
            'brand_name' => $this->mainBrandName(),
            'brand_logo_url' => null,
            'brand_primary_color' => '#012F6B',
            'platform_institution_id' => null,
            'momo_receiver_phone' => (string) ($payload['momo_receiver_phone'] ?? ''),
            'momo_receiver_name' => (string) ($payload['momo_receiver_name'] ?? ''),
            'momo_whatsapp_phone' => (string) ($payload['momo_whatsapp_phone'] ?? ''),
            'display_momo_phone' => (string) ($payload['display_momo_phone'] ?? ''),
            'display_whatsapp_phone' => (string) ($payload['display_whatsapp_phone'] ?? ''),
            'receiver_account_no' => $settings->resolvedMomoReceiverPhone(),
        ];
    }

    public function receiverAccountNo(?Course $course = null): string
    {
        $resolved = $this->resolve($course);
        $digits = preg_replace('/\D+/', '', (string) ($resolved['receiver_account_no'] ?? '')) ?: '';
        if ($digits !== '') {
            return $digits;
        }

        return trim((string) config('services.mopay.receiver_account_no', ''));
    }
}
