<?php

namespace App\Services;

use App\Models\PlatformInstitution;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves which Zoom host user (email or user id) should create/host meetings.
 * Partner institutions and platform admins/instructors each get distinct licensed
 * hosts so sessions can run concurrently on one Zoom account.
 */
class ZoomHostResolver
{
    public function defaultHostEmail(): string
    {
        return trim((string) config('services.zoom.host_user_id', 'me'));
    }

    /**
     * Licensed hosts reserved for partner institutions.
     *
     * @return list<string>
     */
    public function institutionHostPool(): array
    {
        return $this->parsePool((string) config('services.zoom.host_pool', ''));
    }

    /**
     * Licensed hosts for main-platform admins, staff, and instructors.
     * Falls back to institution pool, then default host.
     *
     * @return list<string>
     */
    public function platformHostPool(): array
    {
        $platform = $this->parsePool((string) config('services.zoom.platform_host_pool', ''));
        if ($platform !== []) {
            return $platform;
        }

        return $this->institutionHostPool();
    }

    /** @return list<string> */
    public function hostPool(): array
    {
        return $this->institutionHostPool();
    }

    /**
     * @return list<string>
     */
    public function listAssignableHosts(): array
    {
        $merged = array_filter([
            $this->defaultHostEmail(),
            ...$this->institutionHostPool(),
            ...$this->platformHostPool(),
        ], static fn ($h) => trim((string) $h) !== '' && trim((string) $h) !== 'me');

        return array_values(array_unique($merged));
    }

    public function configuredHostEmail(
        ?int $platformInstitutionId = null,
        ?int $platformUserId = null,
        ?string $actorEmail = null,
    ): string {
        if ($platformInstitutionId && $platformInstitutionId > 0) {
            $institutionHost = $this->resolveInstitutionHostEmail($platformInstitutionId);
            if ($institutionHost !== null) {
                return $institutionHost;
            }
        }

        $platformHost = $this->resolvePlatformActorHostEmail($platformUserId, $actorEmail);
        if ($platformHost !== null) {
            return $platformHost;
        }

        return $this->defaultHostEmail();
    }

    public function institutionHasDedicatedHost(?int $platformInstitutionId): bool
    {
        if (!$platformInstitutionId || !Schema::hasTable('platform_institutions')) {
            return false;
        }

        $institution = PlatformInstitution::query()->find($platformInstitutionId);

        return $institution && trim((string) ($institution->zoom_host_user_id ?? '')) !== '';
    }

    protected function resolveInstitutionHostEmail(int $platformInstitutionId): ?string
    {
        // Partners share the main-admin Zoom host configuration (ZOOM_HOST_USER_ID / platform pool).
        // Per-institution zoom_host_user_id is no longer used for meeting creation.
        $platform = $this->platformHostPool();
        if ($platform !== []) {
            return $this->hostFromPool($platform, $platformInstitutionId) ?? $platform[0];
        }

        $default = $this->defaultHostEmail();

        return $default !== '' ? $default : null;
    }

    protected function resolvePlatformActorHostEmail(?int $platformUserId, ?string $actorEmail): ?string
    {
        $user = $this->resolveActorUser($platformUserId, $actorEmail);
        if ($user) {
            $explicit = trim((string) ($user->zoom_host_user_id ?? ''));
            if ($explicit !== '') {
                return $explicit;
            }

            // Partner-company admins use their institution host when set.
            if (!empty($user->platform_institution_id)) {
                $institutionHost = $this->resolveInstitutionHostEmail((int) $user->platform_institution_id);
                if ($institutionHost !== null) {
                    return $institutionHost;
                }
            }

            $pooled = $this->hostFromPool($this->platformHostPool(), (int) $user->id);
            if ($pooled !== null) {
                return $pooled;
            }
        }

        $email = strtolower(trim($actorEmail ?? $user?->email ?? ''));
        if ($email !== '') {
            foreach ($this->listAssignableHosts() as $host) {
                if (strtolower(trim($host)) === $email) {
                    return trim($host);
                }
            }
        }

        if ($user) {
            return $this->hostFromPool($this->platformHostPool(), (int) $user->id);
        }

        if ($email !== '') {
            return $this->hostFromPool($this->platformHostPool(), (int) sprintf('%u', crc32($email)));
        }

        return null;
    }

    protected function resolveActorUser(?int $platformUserId, ?string $actorEmail): ?User
    {
        if ($platformUserId && $platformUserId > 0 && Schema::hasTable('users')) {
            $byId = User::query()->find($platformUserId);
            if ($byId) {
                return $byId;
            }
        }

        $email = strtolower(trim((string) $actorEmail));
        if ($email === '' || !Schema::hasTable('users')) {
            return null;
        }

        return User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
    }

    /**
     * @param  list<string>  $pool
     */
    protected function hostFromPool(array $pool, int $slotKey): ?string
    {
        if ($pool === [] || $slotKey <= 0) {
            return null;
        }

        return $pool[($slotKey - 1) % count($pool)];
    }

    /** @return list<string> */
    protected function parsePool(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $parts
        ), static fn ($v) => $v !== '' && $v !== 'me')));
    }
}
