<?php

namespace App\Models;

use App\Support\PublicStorageUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformInstitution extends Model
{
    protected $fillable = [
        'name', 'slug', 'contact_email', 'contact_phone', 'website', 'address',
        'logo_path', 'logo_url', 'status', 'payment_status', 'signup_fee_cents',
        'currency', 'stripe_customer_id', 'owner_user_id', 'promo_code_id',
        'approved_at', 'approved_by', 'admin_notes',
        'mail_use_custom', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name', 'mail_ehlo_domain',
        'portal_tagline', 'portal_hero_title', 'portal_hero_subtitle', 'portal_about',
        'portal_primary_color', 'portal_features', 'portal_hero_image_path', 'portal_cta_label',
    ];

    protected $hidden = [
        'mail_password',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'mail_use_custom' => 'boolean',
            'portal_features' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(InstitutionPromoCode::class, 'promo_code_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstitutionPayment::class, 'platform_institution_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'platform_institution_id');
    }

    public function getLogoUrlAttribute($value): ?string
    {
        if (!empty($this->attributes['logo_path'])) {
            return PublicStorageUrl::fromPath($this->attributes['logo_path']);
        }

        return PublicStorageUrl::normalize($value);
    }

    public function portalHeroImageUrl(): ?string
    {
        if (!empty($this->portal_hero_image_path)) {
            return PublicStorageUrl::fromPath($this->portal_hero_image_path);
        }

        return null;
    }

    /** @return list<array{title: string, description: string}> */
    public function defaultPortalFeatures(): array
    {
        return [
            [
                'title' => 'Expert-led programs',
                'description' => 'Structured learning paths designed for real-world skills and career growth.',
            ],
            [
                'title' => 'Live online classes',
                'description' => 'Join interactive sessions with experienced instructors from anywhere.',
            ],
            [
                'title' => 'Track your progress',
                'description' => 'A personal dashboard for courses, quizzes, live classes, and certificates.',
            ],
        ];
    }

    public function portalContentPayload(): array
    {
        $name = (string) $this->name;
        $about = trim((string) ($this->portal_about ?? ''));

        if ($about === '') {
            $parts = ["{$name} is a partner institution on our learning platform."];
            if (!empty($this->address)) {
                $parts[] = 'Located at ' . trim((string) $this->address) . '.';
            }
            if (!empty($this->website)) {
                $parts[] = 'Visit us online for more information.';
            }
            $about = implode(' ', $parts);
        }

        $features = $this->portal_features;
        if (!is_array($features) || count($features) === 0) {
            $features = $this->defaultPortalFeatures();
        }

        return [
            'tagline' => trim((string) ($this->portal_tagline ?? '')) ?: "Study. Learn. Succeed with {$name}.",
            'hero_title' => trim((string) ($this->portal_hero_title ?? '')) ?: "Welcome to {$name}",
            'hero_subtitle' => trim((string) ($this->portal_hero_subtitle ?? ''))
                ?: 'Explore programs, live classes, and expert-led training built for your success.',
            'about' => $about,
            'primary_color' => $this->portal_primary_color ?: null,
            'features' => array_values(array_map(static function ($item) {
                return [
                    'title' => (string) ($item['title'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                ];
            }, $features)),
            'hero_image_url' => $this->portalHeroImageUrl(),
            'cta_label' => trim((string) ($this->portal_cta_label ?? '')) ?: 'Start enrollment',
        ];
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'website' => $this->website,
            'address' => $this->address,
            'logo_path' => $this->logo_path,
            'logo_url' => $this->logo_url,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'mail_use_custom' => (bool) $this->mail_use_custom,
            'mail_from_address' => $this->mail_from_address,
            'mail_from_name' => $this->mail_from_name,
            'portal' => $this->portalContentPayload(),
        ];
    }

    public function toAdminArray(): array
    {
        return array_merge($this->toPublicArray(), [
            'mail_host' => $this->mail_host,
            'mail_port' => $this->mail_port,
            'mail_username' => $this->mail_username,
            'mail_encryption' => $this->mail_encryption,
            'mail_ehlo_domain' => $this->mail_ehlo_domain,
            'mail_password_set' => trim((string) ($this->mail_password ?? '')) !== '',
            'admin_notes' => $this->admin_notes,
        ]);
    }
}
