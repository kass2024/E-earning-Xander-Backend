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
    ): array {
        $institution = $this->resolveInstitution($actorEmail, $platformInstitutionId, $cohort);
        $useInstitutionBranding = $this->shouldUseInstitutionBranding($actorEmail, $institution, $cohort, $platformInstitutionId);
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
        $isMainPlatformHost = $actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser);
        $actorEmail = $actorUser?->email;
        $isConfiguredZoomHost = $this->isConfiguredZoomHostActor($zoomHostContext, $actorEmail);
        $useInstitutionBranding = (bool) ($branding['use_institution_logo'] ?? false);

        if ($isMainPlatformHost) {
            unset($branding['use_institution_logo'], $branding['institution']);
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['host']['name'] = $zoomHostContext['name'] ?? $branding['host']['name'];
            $branding['company']['name'] = $this->platformCompanyNameForResponse();
            $branding['is_main_platform_host'] = true;
        } elseif ($useInstitutionBranding) {
            $institutionName = trim((string) ($branding['institution']['name'] ?? ''));
            if ($institutionName !== '') {
                $branding['company']['name'] = $institutionName;
            }
        } elseif ($isConfiguredZoomHost || !$useInstitutionBranding) {
            unset($branding['use_institution_logo']);
            $branding['host']['avatar_url'] = $zoomHostContext['avatar_url'] ?? null;
            $branding['host']['name'] = $zoomHostContext['name'] ?? $branding['host']['name'];
            $branding['company']['name'] = $this->platformCompanyNameForResponse();
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
    ): bool {
        if (!$institution) {
            return false;
        }

        $actorUser = $this->resolveActorUser($actorEmail);
        if ($actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
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
    ): ?PlatformInstitution {
        if ($cohort && !empty($cohort->platform_institution_id)) {
            return PlatformInstitution::find($cohort->platform_institution_id);
        }

        if ($platformInstitutionId) {
            return PlatformInstitution::find($platformInstitutionId);
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
        $name = (string) config('app.name', 'parrotglobalstudyacademy Learning');
        $name = (string) preg_replace('/xander\s*(global\s*)?scholars?/i', 'parrotglobalstudyacademy', $name);
        $name = (string) preg_replace('/xander\s*learning\s*hub/i', 'parrotglobalstudyacademy Learning', $name);

        return trim($name) !== '' ? trim($name) : 'parrotglobalstudyacademy Learning';
    }

    private function isConfiguredZoomHostActor(array $zoomHostContext, ?string $actorEmail): bool
    {
        $zoomEmail = strtolower(trim((string) ($zoomHostContext['email'] ?? '')));
        $actor = strtolower(trim((string) ($actorEmail ?? '')));

        return $zoomEmail !== '' && $actor !== '' && $zoomEmail === $actor;
    }
}
