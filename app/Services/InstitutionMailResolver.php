<?php

namespace App\Services;

use App\Models\PlatformInstitution;
use App\Support\InstitutionEmailBranding;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

class InstitutionMailResolver
{
    public function institutionUsesCustomSmtp(?PlatformInstitution $institution): bool
    {
        if (!$institution) {
            return false;
        }

        return (bool) $institution->mail_use_custom
            && trim((string) ($institution->mail_host ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function smtpConfigForInstitution(?PlatformInstitution $institution): array
    {
        if (!$this->institutionUsesCustomSmtp($institution)) {
            return (array) config('mail.mailers.smtp', []);
        }

        $encryption = strtolower(trim((string) ($institution->mail_encryption ?? '')));
        $port = (int) ($institution->mail_port ?: 465);
        $scheme = $encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp';

        $password = $this->decryptPassword($institution->mail_password);

        return [
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => (string) $institution->mail_host,
            'port' => $port,
            'username' => $institution->mail_username,
            'password' => $password,
            'timeout' => (int) config('mail.mailers.smtp.timeout', 30),
            'local_domain' => $institution->mail_ehlo_domain
                ?: $this->domainFromEmail($institution->mail_from_address)
                ?: config('mail.mailers.smtp.local_domain'),
            'verify_peer' => config('mail.mailers.smtp.verify_peer', true),
        ];
    }

    /**
     * From identity for institution mail.
     * Shared SMTP keeps the hub deliverable address but brands the display name.
     *
     * @return array{address: string, name: string}
     */
    public function fromForInstitution(?PlatformInstitution $institution): array
    {
        $hubAddress = trim((string) config('mail.from.address'));
        $hubName = trim((string) config('mail.from.name')) ?: (string) config('app.name', 'Xander Global Scholars');

        if (!$institution) {
            return ['address' => $hubAddress, 'name' => $hubName];
        }

        $name = trim((string) ($institution->mail_from_name ?? ''))
            ?: trim((string) ($institution->name ?? ''))
            ?: $hubName;

        if ($this->institutionUsesCustomSmtp($institution)) {
            $address = trim((string) ($institution->mail_from_address ?? ''));
            if ($address !== '') {
                return ['address' => $address, 'name' => $name];
            }
        }

        // Same SMTP as hub: keep deliverable from-address, brand the visible name.
        return [
            'address' => $hubAddress !== '' ? $hubAddress : (string) config('mail.from.address'),
            'name' => $name,
        ];
    }

    public function sendForInstitution(
        ?int $platformInstitutionId,
        string $to,
        Mailable $mailable,
        array $context = [],
    ): bool {
        $institution = $platformInstitutionId
            ? PlatformInstitution::find($platformInstitutionId)
            : null;

        $brand = InstitutionEmailBranding::for($institution);
        $from = $this->fromForInstitution($institution);

        $this->shareBrand($brand);

        try {
            $mailable->from($from['address'], $from['name']);
            // Ensure blade templates always see institution branding (even if mailable rebuilds with()).
            $mailable->with([
                'companyName' => $brand['companyName'],
                'emailBrand' => $brand,
                'brandPrimary' => $brand['primaryColor'],
                'brandAccent' => $brand['accentColor'],
                'brandHeaderFrom' => $brand['headerFrom'],
                'brandHeaderTo' => $brand['headerTo'],
                'brandLogoUrl' => $brand['logoUrl'],
                'meetBrand' => $brand['meetBrand'],
            ]);

            if ($this->institutionUsesCustomSmtp($institution)) {
                $mailerKey = 'institution_' . $institution->id;
                Config::set("mail.mailers.{$mailerKey}", $this->smtpConfigForInstitution($institution));
                Mail::mailer($mailerKey)->to($to)->send($mailable);
            } else {
                Mail::to($to)->send($mailable);
            }

            Log::info('Institution email sent', array_merge($context, [
                'to' => $to,
                'institution_id' => $platformInstitutionId,
                'from_name' => $from['name'],
                'from_address' => $from['address'],
                'custom_smtp' => $this->institutionUsesCustomSmtp($institution),
            ]));

            return true;
        } catch (\Throwable $e) {
            Log::error('Institution email failed', array_merge($context, [
                'to' => $to,
                'institution_id' => $platformInstitutionId,
                'error' => $e->getMessage(),
            ]));

            return false;
        } finally {
            $this->shareBrand(InstitutionEmailBranding::for(null));
        }
    }

    /**
     * @param  callable(\Illuminate\Mail\Message): void  $callback
     */
    public function sendViewForInstitution(
        ?int $platformInstitutionId,
        string $view,
        array $data,
        callable $callback,
        array $context = [],
    ): bool {
        $institution = $platformInstitutionId
            ? PlatformInstitution::find($platformInstitutionId)
            : null;

        $brand = InstitutionEmailBranding::for($institution);
        $from = $this->fromForInstitution($institution);
        $this->shareBrand($brand);

        // Brand wins over caller data so shared-SMTP institutions cannot be overwritten by hub defaults.
        $data = array_merge($data, [
            'companyName' => $brand['companyName'],
            'emailBrand' => $brand,
            'brandPrimary' => $brand['primaryColor'],
            'brandAccent' => $brand['accentColor'],
            'brandHeaderFrom' => $brand['headerFrom'],
            'brandHeaderTo' => $brand['headerTo'],
            'brandLogoUrl' => $brand['logoUrl'],
            'meetBrand' => $brand['meetBrand'],
            'appName' => $brand['companyName'],
        ]);

        try {
            $wrapped = function ($message) use ($callback, $from) {
                $message->from($from['address'], $from['name']);
                $callback($message);
            };

            if ($this->institutionUsesCustomSmtp($institution)) {
                $mailerKey = 'institution_' . $institution->id;
                Config::set("mail.mailers.{$mailerKey}", $this->smtpConfigForInstitution($institution));
                Mail::mailer($mailerKey)->send($view, $data, $wrapped);
            } else {
                Mail::send($view, $data, $wrapped);
            }

            Log::info('Institution email view sent', array_merge($context, [
                'institution_id' => $platformInstitutionId,
                'from_name' => $from['name'],
                'view' => $view,
                'custom_smtp' => $this->institutionUsesCustomSmtp($institution),
            ]));

            return true;
        } catch (\Throwable $e) {
            Log::error('Institution email view failed', array_merge($context, [
                'institution_id' => $platformInstitutionId,
                'view' => $view,
                'error' => $e->getMessage(),
            ]));

            return false;
        } finally {
            $this->shareBrand(InstitutionEmailBranding::for(null));
        }
    }

    public function encryptPassword(?string $plain): ?string
    {
        $plain = trim((string) $plain);
        if ($plain === '') {
            return null;
        }

        return Crypt::encryptString($plain);
    }

    public function decryptPassword(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    /**
     * @param  array<string, mixed>  $brand
     */
    private function shareBrand(array $brand): void
    {
        View::share('emailBrand', $brand);
        View::share('companyName', $brand['companyName']);
        View::share('brandPrimary', $brand['primaryColor']);
        View::share('brandAccent', $brand['accentColor']);
        View::share('brandHeaderFrom', $brand['headerFrom']);
        View::share('brandHeaderTo', $brand['headerTo']);
        View::share('brandLogoUrl', $brand['logoUrl']);
        View::share('meetBrand', $brand['meetBrand']);
    }

    private function domainFromEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return substr(strrchr($email, '@'), 1) ?: null;
    }
}
