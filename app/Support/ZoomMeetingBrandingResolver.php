<?php

namespace App\Support;

use App\Models\LiveZoomCohort;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Services\ZoomService;

class ZoomMeetingBrandingResolver
{
    public function __construct(
        private readonly ZoomService $zoomService,
    ) {
    }

    /**
     * @return array{
     *     host: array{name: string, email: string|null, avatar_url: string|null},
     *     company: array{name: string},
     *     institution?: array<string, mixed>,
     *     use_institution_logo?: bool
     * }
     */
    public function resolve(
        ?string $actorEmail = null,
        ?int $platformInstitutionId = null,
        ?LiveZoomCohort $cohort = null,
        bool $allowActorInstitutionFallback = true,
    ): array {
        $institution = $this->resolveInstitution(
            $actorEmail,
            $platformInstitutionId,
            $cohort,
            $allowActorInstitutionFallback,
        );
        $useInstitutionBranding = $this->shouldUseInstitutionBranding(
            $actorEmail,
            $institution,
            $cohort,
            $platformInstitutionId,
            $allowActorInstitutionFallback,
        );
        $brandingInstitutionId = $institution?->id ?? $platformInstitutionId;
        $actorUser = $this->resolveActorUser($actorEmail);
        $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
            $brandingInstitutionId ? (int) $brandingInstitutionId : null,
            $actorUser?->id ? (int) $actorUser->id : null,
            $actorEmail,
        );

        $companyName = $this->platformCompanyName();
        $avatarUrl = $zoomHost['avatar_url'];
        $hostName = $zoomHost['name'];

        if ($institution && $useInstitutionBranding) {
            $companyName = $institution->name ?: $companyName;
            $avatarUrl = $this->institutionLogoUrl($institution);
        }

        $payload = [
            'host' => [
                'name' => $hostName,
                'email' => $zoomHost['email'],
                'avatar_url' => $avatarUrl,
            ],
            'company' => [
                'name' => $companyName,
            ],
        ];

        if ($institution) {
            $institutionPayload = $institution->toPublicArray();
            $logoUrl = $this->institutionLogoUrl($institution);
            if ($logoUrl) {
                $institutionPayload['logo_url'] = $logoUrl;
            }
            $payload['institution'] = $institutionPayload;
            if ($useInstitutionBranding) {
                $payload['use_institution_logo'] = true;
            }
        }

        return $payload;
    }

    /**
     * Apply Zoom vs institution host avatar/name for SDK join responses.
     *
     * @param  array{name: string, email: string|null, avatar_url: string|null}  $zoomHostContext
     */
    public function finalizeHostSdkBranding(
        array $branding,
        array $zoomHostContext,
        ?User $actorUser,
    ): array {
        $isMainPlatformHost = $actorUser && (
            PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)
            || (
                !$useInstitutionBranding
                && in_array(strtolower(trim((string) ($actorUser->role ?? ''))), ['admin', 'staff'], true)
            )
        );
        $actorEmail = $actorUser?->email;
        $isConfiguredZoomHost = $this->isConfiguredZoomHostActor($zoomHostContext, $actorEmail);
        $useInstitutionBranding = (bool) ($branding['use_institution_logo'] ?? false);

        if ($isMainPlatformHost) {
            unset($branding['use_institution_logo'], $branding['institution']);
            // Main platform hosts always appear as Xander Learning Hub (not personal Zoom name).
            $branding['host']['name'] = $this->platformCompanyName();
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['company']['name'] = $this->platformCompanyName();
            $branding['is_main_platform_host'] = true;
            $branding['use_hub_branding'] = true;
        } elseif ($useInstitutionBranding) {
            $institutionName = trim((string) ($branding['institution']['name'] ?? ''));
            if ($institutionName !== '') {
                $branding['company']['name'] = $institutionName;
                // Host tile / Daily userName = institution name when camera is off.
                $branding['host']['name'] = $institutionName;
            }
            if (!empty($branding['institution']['logo_url'])) {
                $branding['host']['avatar_url'] = $branding['institution']['logo_url'];
            }
        } elseif ($isConfiguredZoomHost || !$useInstitutionBranding) {
            unset($branding['use_institution_logo'], $branding['institution']);
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['host']['name'] = $this->platformCompanyName();
            $branding['company']['name'] = $this->platformCompanyNameForResponse();
            $branding['use_hub_branding'] = true;
            $branding['is_main_platform_host'] = $actorUser
                && in_array(strtolower(trim((string) ($actorUser->role ?? ''))), ['admin', 'staff'], true);
        }

        if (empty($branding['host']['email'] ?? null) && !empty($zoomHostContext['email'])) {
            $branding['host']['email'] = $zoomHostContext['email'];
        }

        return $branding;
    }

    private function institutionLogoUrl(PlatformInstitution $institution): ?string
    {
        if (!empty($institution->logo_path)) {
            $fromPath = PublicStorageUrl::toApiAbsoluteUrl((string) $institution->logo_path);
            if ($fromPath) {
                return $fromPath;
            }
        }

        $raw = !empty($institution->logo_url) ? (string) $institution->logo_url : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return PublicStorageUrl::toApiAbsoluteUrl($raw) ?? $raw;
    }

    private function shouldUseInstitutionBranding(
        ?string $actorEmail,
        ?PlatformInstitution $institution,
        ?LiveZoomCohort $cohort,
        ?int $platformInstitutionId,
        bool $allowActorInstitutionFallback = true,
    ): bool {
        if (!$institution) {
            return false;
        }

        $actorUser = $this->resolveActorUser($actorEmail);
        if ($actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
            return false;
        }

        // Hub hosting session: no tenant id in the request — never brand as another institution.
        if (!$allowActorInstitutionFallback && !$platformInstitutionId && !$cohort) {
            return false;
        }

        if ($cohort && !empty($cohort->platform_institution_id)) {
            return true;
        }

        if ($platformInstitutionId) {
            return true;
        }

        if ($actorUser && !PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
            if (!empty($actorUser->platform_institution_id)) {
                return true;
            }

            $role = strtolower(trim((string) ($actorUser->role ?? '')));
            if ($role === 'instructor' && $platformInstitutionId) {
                return true;
            }
        }

        return false;
    }

    private function resolveActorUser(?string $actorEmail): ?User
    {
        if (!$actorEmail) {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($actorEmail))])
            ->first();
    }

    private function resolveInstitution(
        ?string $actorEmail,
        ?int $platformInstitutionId,
        ?LiveZoomCohort $cohort,
        bool $allowActorInstitutionFallback = true,
    ): ?PlatformInstitution {
        if ($cohort && !empty($cohort->platform_institution_id)) {
            return PlatformInstitution::find($cohort->platform_institution_id);
        }

        if ($platformInstitutionId) {
            return PlatformInstitution::find($platformInstitutionId);
        }

        if (!$allowActorInstitutionFallback) {
            return null;
        }

        if ($actorEmail) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])
                ->first();

            return PlatformInstitutionHelper::resolveForUser($user);
        }

        return null;
    }

    public function platformCompanyNameForResponse(): string
    {
        return $this->platformCompanyName();
    }

    private function platformCompanyName(): string
    {
        // Always present the main platform as Xander Learning Hub in meetings/UI.
        $hub = 'Xander Learning Hub';
        $name = trim((string) config('app.hub_name', $hub));
        if ($name === '') {
            $name = $hub;
        }

        // Strip legacy Parrot labels if APP_NAME still carries them.
        $name = (string) preg_replace('/parrot\s*global\s*study\s*academy/i', $hub, $name);
        $name = (string) preg_replace('/parrotglobalstudyacademy/i', $hub, $name);
        $name = trim($name);

        return $name !== '' ? $name : $hub;
    }

    private function isConfiguredZoomHostActor(array $zoomHostContext, ?string $actorEmail): bool
    {
        $zoomEmail = strtolower(trim((string) ($zoomHostContext['email'] ?? '')));
        $actor = strtolower(trim((string) ($actorEmail ?? '')));

        return $zoomEmail !== '' && $actor !== '' && $zoomEmail === $actor;
    }
}
