<?php

namespace App\Support;

use App\Models\PlatformInstitution;

/**
 * Branding payload for outbound emails (shared or custom SMTP).
 */
class InstitutionEmailBranding
{
    /**
     * @return array{
     *   companyName: string,
     *   meetBrand: string,
     *   primaryColor: string,
     *   accentColor: string,
     *   headerFrom: string,
     *   headerTo: string,
     *   logoUrl: string|null,
     *   supportEmail: string|null,
     *   portalHomeUrl: string,
     *   bookMeetingUrl: string,
     *   isInstitution: bool,
     *   institutionId: int|null,
     *   institutionSlug: string|null
     * }
     */
    public static function for(?PlatformInstitution $institution): array
    {
        $hubName = trim((string) config('app.name', 'Xander Global Scholars')) ?: 'Xander Global Scholars';
        $base = rtrim(FrontendUrl::base(), '/');

        if (!$institution) {
            return [
                'companyName' => $hubName,
                'meetBrand' => $hubName . ' meet',
                'primaryColor' => '#1d4ed8',
                'accentColor' => '#0f172a',
                'headerFrom' => '#0f172a',
                'headerTo' => '#1d4ed8',
                'logoUrl' => null,
                'supportEmail' => (string) config('mail.from.address'),
                'portalHomeUrl' => $base,
                'bookMeetingUrl' => $base . '/meeting-registration',
                'isInstitution' => false,
                'institutionId' => null,
                'institutionSlug' => null,
            ];
        }

        $portal = $institution->portalContentPayload();
        $name = trim((string) ($institution->name ?? '')) ?: $hubName;
        $primary = self::hexOr($portal['primary_color'] ?? null, '#012F6B');
        $accent = self::hexOr($portal['accent_color'] ?? null, '#E01C21');
        $slug = trim((string) ($institution->slug ?? ''));

        return [
            'companyName' => $name,
            'meetBrand' => $name . ' meet',
            'primaryColor' => $primary,
            'accentColor' => $accent,
            'headerFrom' => self::darken($primary, 0.25),
            'headerTo' => $primary,
            'logoUrl' => $institution->logo_url ?: null,
            'supportEmail' => trim((string) ($institution->contact_email ?? '')) ?: (string) config('mail.from.address'),
            'portalHomeUrl' => $slug !== '' ? $base . '/i/' . rawurlencode($slug) : $base,
            'bookMeetingUrl' => $slug !== ''
                ? $base . '/i/' . rawurlencode($slug) . '/meeting-registration'
                : $base . '/meeting-registration',
            'isInstitution' => true,
            'institutionId' => (int) $institution->id,
            'institutionSlug' => $slug !== '' ? $slug : null,
        ];
    }

    public static function forInstitutionId(?int $institutionId): array
    {
        if (!$institutionId || $institutionId <= 0) {
            return self::for(null);
        }

        $institution = PlatformInstitution::query()->find($institutionId);

        return self::for($institution);
    }

    private static function hexOr(?string $value, string $fallback): string
    {
        $color = strtoupper(trim((string) $value));
        if ($color !== '' && preg_match('/^#[0-9A-F]{3}([0-9A-F]{3})?$/i', $color)) {
            return $color;
        }

        return $fallback;
    }

    private static function darken(string $hex, float $amount): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return '#' . $hex;
        }

        $r = max(0, (int) round(hexdec(substr($hex, 0, 2)) * (1 - $amount)));
        $g = max(0, (int) round(hexdec(substr($hex, 2, 2)) * (1 - $amount)));
        $b = max(0, (int) round(hexdec(substr($hex, 4, 2)) * (1 - $amount)));

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}
