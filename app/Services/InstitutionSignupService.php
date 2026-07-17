<?php

namespace App\Services;

use App\Models\InstitutionPayment;
use App\Models\InstitutionPromoCode;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Mail\InstitutionWelcomeMail;
use App\Support\FrontendUrl;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformUserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

class InstitutionSignupService
{
    public function __construct(
        private readonly StripePaymentService $stripePaymentService,
        private readonly MailDeliveryService $mailDelivery,
        private readonly ZoomHostAssignmentService $zoomHostAssignment,
    ) {}

    public function signupFeeCents(): int
    {
        return (int) config('institution.signup_fee_cents', 9900);
    }

    public function register(array $data, ?string $promoCode = null): array
    {
        $email = strtolower(trim((string) ($data['contact_email'] ?? '')));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'An account with this email already exists.'];
        }

        $promo = null;
        if ($promoCode) {
            $promo = InstitutionPromoCode::whereRaw('UPPER(code) = ?', [strtoupper(trim($promoCode))])->first();
            if (!$promo || !$promo->isRedeemable()) {
                return ['ok' => false, 'status' => 422, 'message' => 'Invalid or expired promo code.'];
            }
        }

        return DB::transaction(function () use ($data, $email, $promo) {
            $feeCents = $this->signupFeeCents();
            $institution = PlatformInstitution::create([
                'name' => trim((string) $data['institution_name']),
                'slug' => PlatformInstitutionHelper::uniqueSlug((string) $data['institution_name']),
                'contact_email' => $email,
                'contact_phone' => $data['contact_phone'] ?? null,
                'website' => $data['website'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => 'pending_approval',
                'payment_status' => $promo ? 'promo' : 'unpaid',
                'signup_fee_cents' => $feeCents,
                'currency' => config('institution.signup_currency', 'usd'),
                'promo_code_id' => $promo?->id,
            ]);

            $plainPassword = $this->generateOwnerPassword();
            $user = User::create([
                'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'email' => $email,
                'password' => $plainPassword,
                'role' => 'partner_company',
                'status' => $promo ? 'Unpaid' : 'Pending',
                'phone' => $data['contact_phone'] ?? '',
                'platform_institution_id' => $institution->id,
            ]);

            $institution->owner_user_id = $user->id;
            $institution->save();

            $this->sendOwnerCredentials($institution->fresh(), $user, $plainPassword);

            if ($promo) {
                $promo->increment('uses_count');
                return [
                    'ok' => true,
                    'institution' => $institution->fresh(),
                    'requires_payment' => false,
                    'message' => 'Registration submitted. Login credentials have been emailed to you. Pending admin approval.',
                ];
            }

            $checkout = $this->createSignupCheckout($institution);
            if (!$checkout['ok']) {
                throw new \RuntimeException($checkout['message'] ?? 'Stripe checkout failed');
            }

            return [
                'ok' => true,
                'institution' => $institution->fresh(),
                'requires_payment' => true,
                'checkout_url' => $checkout['checkout_url'],
                'message' => 'Registration started. Login credentials have been emailed to you. Complete payment to continue.',
            ];
        });
    }

    /**
     * Main-admin creates a partner institution (no public signup / Stripe).
     *
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status?: int, message: string, institution?: PlatformInstitution, password?: string}
     */
    public function createByAdmin(array $data): array
    {
        $email = strtolower(trim((string) ($data['contact_email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 422, 'message' => 'A valid login email is required.'];
        }

        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'An account with this email already exists.'];
        }

        if (PlatformInstitution::query()->whereRaw('LOWER(contact_email) = ?', [$email])->exists()) {
            return ['ok' => false, 'status' => 422, 'message' => 'An institution with this contact email already exists.'];
        }

        $name = trim((string) ($data['institution_name'] ?? $data['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'Institution name is required.'];
        }

        $autoApprove = (bool) ($data['auto_approve'] ?? true);
        $sendCredentials = (bool) ($data['send_credentials'] ?? true);
        $ownerName = trim((string) ($data['owner_name'] ?? ''));
        if ($ownerName === '') {
            $ownerName = $name . ' Admin';
        }

        return DB::transaction(function () use ($data, $email, $name, $autoApprove, $sendCredentials, $ownerName) {
            $feeCents = $this->signupFeeCents();
            $provider = app(\App\Services\PlatformSettingsService::class)
                ->mainPlatformMeetingProvider()
                ->value;

            $attrs = [
                'name' => $name,
                'slug' => PlatformInstitutionHelper::uniqueSlug($name),
                'contact_email' => $email,
                'contact_phone' => $data['contact_phone'] ?? null,
                'website' => $data['website'] ?? null,
                'address' => $data['address'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
                'status' => $autoApprove ? 'active' : 'pending_approval',
                'payment_status' => 'promo',
                'signup_fee_cents' => $feeCents,
                'currency' => config('institution.signup_currency', 'usd'),
            ];
            if (Schema::hasColumn('platform_institutions', 'meeting_provider')) {
                $attrs['meeting_provider'] = $provider;
            }
            if ($autoApprove) {
                $attrs['approved_at'] = now();
            }

            $institution = PlatformInstitution::create($attrs);

            $plainPassword = trim((string) ($data['password'] ?? ''));
            if ($plainPassword === '') {
                $plainPassword = $this->generateOwnerPassword();
            }

            $user = User::create([
                'name' => $ownerName,
                'email' => $email,
                'password' => $plainPassword,
                'role' => 'partner_company',
                'status' => $autoApprove ? 'Active' : 'Pending',
                'phone' => $data['contact_phone'] ?? '',
                'platform_institution_id' => $institution->id,
            ]);

            $institution->owner_user_id = $user->id;
            $institution->save();

            // Partners use shared main-admin Zoom/Daily settings — do not assign per-institution Zoom hosts.

            if ($sendCredentials) {
                $this->sendOwnerCredentials($institution->fresh(), $user, $plainPassword);
            }

            return [
                'ok' => true,
                'message' => $autoApprove
                    ? 'Institution created and activated.'
                    : 'Institution created and pending approval.',
                'institution' => $institution->fresh(),
                'password' => $plainPassword,
            ];
        });
    }

    public function generateOwnerPassword(): string
    {
        return Str::password(12);
    }

    public function sendOwnerCredentials(PlatformInstitution $institution, User $user, string $plainPassword, bool $isResend = false): bool
    {
        return $this->mailDelivery->sendToForInstitution(
            $institution->id,
            $user->email,
            new InstitutionWelcomeMail(
                $institution,
                $user->email,
                $plainPassword,
                $this->institutionLoginUrl($institution),
                $isResend,
            ),
        );
    }

    public function resendOwnerCredentials(PlatformInstitution $institution): array
    {
        return $this->resetOwnerPassword($institution, null, true);
    }

    public function institutionLoginUrl(PlatformInstitution $institution): string
    {
        $base = rtrim(FrontendUrl::base(), '/');
        $slug = strtolower(trim((string) $institution->slug));

        return $slug !== ''
            ? $base . '/login/' . rawurlencode($slug)
            : $base . '/login';
    }

    /** Ensure a partner owner account exists for institution login. */
    public function ensureOwnerAccount(PlatformInstitution $institution): User
    {
        $institution->load('owner');
        $email = strtolower(trim((string) $institution->contact_email));
        if ($email === '') {
            throw new \InvalidArgumentException('Institution contact email is required.');
        }

        $user = $institution->owner;
        if ($user && strtolower(trim((string) $user->email)) === $email) {
            $dirty = false;
            if ((int) $user->platform_institution_id !== (int) $institution->id) {
                $user->platform_institution_id = $institution->id;
                $dirty = true;
            }
            if (strtolower(trim((string) ($user->role ?? ''))) !== 'partner_company') {
                $user->role = 'partner_company';
                $dirty = true;
            }
            if ($dirty) {
                $user->save();
            }

            return $user->fresh();
        }

        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
        if ($user) {
            $user->role = 'partner_company';
            $user->platform_institution_id = $institution->id;
            $user->status = $this->ownerStatusForInstitution($institution);
            if (trim((string) $user->name) === '') {
                $user->name = trim($institution->name) . ' Admin';
            }
            $user->save();
        } else {
            $user = User::create([
                'name' => trim($institution->name) . ' Admin',
                'email' => $email,
                'password' => Hash::make($this->generateOwnerPassword()),
                'role' => 'partner_company',
                'status' => $this->ownerStatusForInstitution($institution),
                'phone' => $institution->contact_phone ?? '',
                'platform_institution_id' => $institution->id,
            ]);
        }

        $institution->owner_user_id = $user->id;
        $institution->save();

        return $user->fresh();
    }

    /**
     * Reset (or create) the institution owner password. Main admin does not need the old password.
     */
    public function resetOwnerPassword(
        PlatformInstitution $institution,
        ?string $plainPassword = null,
        bool $sendEmail = true,
    ): array {
        $institution = $institution->fresh();
        $user = $this->ensureOwnerAccount($institution);

        $plain = trim((string) ($plainPassword ?? ''));
        if ($plain === '') {
            $plain = $this->generateOwnerPassword();
        }
        if (strlen($plain) < 8) {
            return ['ok' => false, 'status' => 422, 'message' => 'Password must be at least 8 characters.'];
        }

        PlatformUserService::setUserPassword($user, $plain);
        $user->status = $this->ownerStatusForInstitution($institution->fresh());
        $user->save();

        $loginUrl = $this->institutionLoginUrl($institution->fresh());

        if ($sendEmail) {
            $sent = $this->sendOwnerCredentials($institution->fresh(), $user->fresh(), $plain, true);
            if (!$sent) {
                return [
                    'ok' => true,
                    'message' => 'Password updated but the email could not be sent. Copy the password below.',
                    'login_url' => $loginUrl,
                    'owner_email' => $user->email,
                    'password' => $plain,
                ];
            }

            return [
                'ok' => true,
                'message' => 'New login credentials emailed to ' . $user->email,
                'login_url' => $loginUrl,
                'owner_email' => $user->email,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Owner password reset.',
            'login_url' => $loginUrl,
            'owner_email' => $user->email,
            'password' => $plain,
        ];
    }

    private function ownerStatusForInstitution(PlatformInstitution $institution): string
    {
        if ($institution->status !== 'active') {
            return 'Pending';
        }

        return strtolower((string) $institution->payment_status) === 'unpaid' ? 'Unpaid' : 'Active';
    }

    public function createSignupCheckout(PlatformInstitution $institution): array
    {
        $ready = $this->stripePaymentService->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $payment = InstitutionPayment::create([
            'platform_institution_id' => $institution->id,
            'amount_cents' => $institution->signup_fee_cents ?: $this->signupFeeCents(),
            'currency' => $institution->currency ?: 'usd',
            'type' => 'signup',
            'status' => 'pending',
        ]);

        $frontend = rtrim(FrontendUrl::base(), '/');
        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'customer_email' => $institution->contact_email,
            'line_items' => [[
                'price_data' => [
                    'currency' => $payment->currency,
                    'unit_amount' => $payment->amount_cents,
                    'product_data' => [
                        'name' => config('institution.signup_product_name', 'Partner Institution Platform Access'),
                        'description' => $institution->name,
                    ],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $frontend . '/institution-signup/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend . '/institution-signup?cancelled=1',
            'metadata' => [
                'type' => 'institution_signup',
                'platform_institution_id' => (string) $institution->id,
                'institution_payment_id' => (string) $payment->id,
            ],
        ]);

        $payment->stripe_session_id = $session->id;
        $payment->save();

        return ['ok' => true, 'checkout_url' => $session->url];
    }

    public function completeSignupPayment(string $sessionId): ?PlatformInstitution
    {
        $ready = $this->stripePaymentService->assertReady();
        if (!$ready['ok']) {
            return null;
        }

        Stripe::setApiKey(config('services.stripe.secret'));
        $session = StripeCheckoutSession::retrieve($sessionId);

        if (($session->payment_status ?? '') !== 'paid') {
            return null;
        }

        $institutionId = (int) ($session->metadata['platform_institution_id'] ?? 0);
        $paymentId = (int) ($session->metadata['institution_payment_id'] ?? 0);
        $institution = PlatformInstitution::find($institutionId);
        $payment = InstitutionPayment::find($paymentId);

        if (!$institution || !$payment) {
            return null;
        }

        $payment->status = 'paid';
        $payment->stripe_payment_intent_id = $session->payment_intent ?? null;
        $payment->paid_at = now();
        $payment->save();

        $institution->payment_status = 'paid';
        $institution->stripe_customer_id = $session->customer ?? $institution->stripe_customer_id;
        $institution->save();

        if ($institution->owner_user_id) {
            User::where('id', $institution->owner_user_id)->update(['status' => 'Pending']);
        }

        return $institution->fresh();
    }
}
