<?php

namespace App\Services;

use App\Models\PlatformInstitution;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Discovers licensed Zoom hosts from the account API and assigns one per partner institution.
 */
class ZoomHostAssignmentService
{
    public function __construct(
        private readonly ZoomService $zoom,
        private readonly ZoomHostResolver $hostResolver,
    ) {}

    public function ensureInstitutionHost(PlatformInstitution $institution): ?string
    {
        $existing = trim((string) ($institution->zoom_host_user_id ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return $this->assignHostToInstitution($institution);
    }

    public function assignHostToInstitution(PlatformInstitution $institution, bool $force = false): ?string
    {
        if (!$force) {
            $existing = trim((string) ($institution->zoom_host_user_id ?? ''));
            if ($existing !== '') {
                return $existing;
            }
        }

        if (!Schema::hasTable('platform_institutions')
            || !Schema::hasColumn('platform_institutions', 'zoom_host_user_id')) {
            return null;
        }

        $host = $this->pickHostForInstitution($institution);
        if ($host === null || trim($host) === '') {
            return null;
        }

        $institution->zoom_host_user_id = $host;
        $institution->save();
        $this->zoom->invalidateHostUserCache((int) $institution->id);

        return $host;
    }

    /**
     * @return list<string>
     */
    public function availableInstitutionHosts(?int $excludeInstitutionId = null): array
    {
        $candidates = $this->institutionHostCandidates();
        $reserved = $this->reservedHostKeys($excludeInstitutionId);

        return array_values(array_filter(
            $candidates,
            fn (string $host) => !isset($reserved[$this->normalizeHostKey($host)]),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function getHostInventory(?int $forInstitutionId = null): array
    {
        $assignments = $this->institutionAssignments();
        $assignmentByKey = [];
        foreach ($assignments as $row) {
            $assignmentByKey[$this->normalizeHostKey((string) $row['zoom_host_user_id'])] = $row;
        }

        $zoomUsers = $this->zoom->listLicensedAccountUsers();
        $accountUsers = [];
        $seenKeys = [];

        foreach ($zoomUsers as $user) {
            $email = trim((string) ($user['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $key = $this->normalizeHostKey($email);
            $seenKeys[$key] = true;
            $assigned = $assignmentByKey[$key] ?? null;

            $accountUsers[] = [
                'id' => $user['id'] ?? null,
                'email' => $email,
                'display_name' => trim((string) ($user['display_name'] ?? '')),
                'type' => $user['type'] ?? null,
                'assigned_to' => $assigned ? [
                    'institution_id' => $assigned['institution_id'],
                    'institution_name' => $assigned['institution_name'],
                ] : null,
                'available' => $assigned === null,
            ];
        }

        foreach ($this->institutionHostCandidates() as $host) {
            $key = $this->normalizeHostKey($host);
            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $assigned = $assignmentByKey[$key] ?? null;
            $accountUsers[] = [
                'id' => null,
                'email' => $host,
                'display_name' => '',
                'type' => null,
                'assigned_to' => $assigned ? [
                    'institution_id' => $assigned['institution_id'],
                    'institution_name' => $assigned['institution_name'],
                ] : null,
                'available' => $assigned === null,
                'source' => 'env',
            ];
        }

        $availableHosts = $this->availableInstitutionHosts($forInstitutionId);
        $currentHost = null;
        if ($forInstitutionId) {
            $current = PlatformInstitution::query()->find($forInstitutionId);
            $currentHost = trim((string) ($current?->zoom_host_user_id ?? ''));
        }

        $assignable = array_values(array_unique(array_filter(array_merge(
            $availableHosts,
            $currentHost !== '' ? [$currentHost] : [],
            array_map(static fn (array $u) => (string) ($u['email'] ?? ''), $accountUsers),
        ))));

        return [
            'default_host' => $this->hostResolver->defaultHostEmail(),
            'host_pool' => $this->hostResolver->institutionHostPool(),
            'platform_host_pool' => $this->hostResolver->platformHostPool(),
            'assignable_hosts' => $assignable,
            'available_hosts' => $availableHosts,
            'zoom_account_users' => $accountUsers,
            'institution_assignments' => $assignments,
            'multi_host_enabled' => count($assignable) > 1,
            'zoom_api_connected' => $this->zoom->isConfigured(),
            'zoom_users_discovered' => count($zoomUsers) > 0,
            'auto_assign_enabled' => true,
        ];
    }

    /**
     * @return list<array{institution_id: int, institution_name: string, zoom_host_user_id: string}>
     */
    public function institutionAssignments(): array
    {
        if (!Schema::hasTable('platform_institutions')
            || !Schema::hasColumn('platform_institutions', 'zoom_host_user_id')) {
            return [];
        }

        return PlatformInstitution::query()
            ->whereNotNull('zoom_host_user_id')
            ->where('zoom_host_user_id', '!=', '')
            ->orderBy('id')
            ->get(['id', 'name', 'zoom_host_user_id'])
            ->map(static fn (PlatformInstitution $inst) => [
                'institution_id' => (int) $inst->id,
                'institution_name' => (string) $inst->name,
                'zoom_host_user_id' => (string) $inst->zoom_host_user_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, assigned?: string|null, would_assign?: string|null}>
     */
    public function backfillMissingHosts(bool $dryRun = false): array
    {
        if (!Schema::hasTable('platform_institutions')) {
            return [];
        }

        $results = [];
        PlatformInstitution::query()
            ->where(function ($query) {
                $query->whereNull('zoom_host_user_id')->orWhere('zoom_host_user_id', '');
            })
            ->orderBy('id')
            ->each(function (PlatformInstitution $institution) use ($dryRun, &$results) {
                if ($dryRun) {
                    $results[] = [
                        'id' => (int) $institution->id,
                        'name' => (string) $institution->name,
                        'would_assign' => $this->pickHostForInstitution($institution),
                    ];

                    return;
                }

                $results[] = [
                    'id' => (int) $institution->id,
                    'name' => (string) $institution->name,
                    'assigned' => $this->assignHostToInstitution($institution),
                ];
            });

        return $results;
    }

    protected function pickHostForInstitution(PlatformInstitution $institution): ?string
    {
        $available = $this->availableInstitutionHosts((int) $institution->id);
        if ($available !== []) {
            return $available[((int) $institution->id - 1) % count($available)];
        }

        $pooled = trim($this->hostResolver->configuredHostEmail((int) $institution->id));
        if ($pooled !== '' && $pooled !== 'me') {
            return $pooled;
        }

        return null;
    }

    /**
     * Licensed Zoom users from the account API, plus optional env pool entries.
     *
     * @return list<string>
     */
    protected function institutionHostCandidates(): array
    {
        $fromZoom = array_map(
            static fn (array $user) => trim((string) ($user['email'] ?? '')),
            $this->zoom->listLicensedAccountUsers(),
        );

        $fromEnv = $this->hostResolver->institutionHostPool();
        $platformOnly = array_map(
            fn (string $host) => $this->normalizeHostKey($host),
            array_filter($this->hostResolver->platformHostPool()),
        );

        $merged = [];
        foreach (array_merge($fromZoom, $fromEnv) as $host) {
            $host = trim((string) $host);
            if ($host === '' || $host === 'me') {
                continue;
            }
            if (in_array($this->normalizeHostKey($host), $platformOnly, true)) {
                continue;
            }
            $merged[$this->normalizeHostKey($host)] = $host;
        }

        return array_values($merged);
    }

    /**
     * @return array<string, int|true>
     */
    protected function reservedHostKeys(?int $excludeInstitutionId = null): array
    {
        $keys = [];

        if (Schema::hasTable('platform_institutions')
            && Schema::hasColumn('platform_institutions', 'zoom_host_user_id')) {
            PlatformInstitution::query()
                ->when($excludeInstitutionId, fn ($q) => $q->where('id', '!=', $excludeInstitutionId))
                ->whereNotNull('zoom_host_user_id')
                ->where('zoom_host_user_id', '!=', '')
                ->pluck('zoom_host_user_id')
                ->each(function ($host) use (&$keys) {
                    $keys[$this->normalizeHostKey((string) $host)] = true;
                });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'zoom_host_user_id')) {
            User::query()
                ->whereNotNull('zoom_host_user_id')
                ->where('zoom_host_user_id', '!=', '')
                ->pluck('zoom_host_user_id')
                ->each(function ($host) use (&$keys) {
                    $keys[$this->normalizeHostKey((string) $host)] = true;
                });
        }

        return $keys;
    }

    protected function normalizeHostKey(string $host): string
    {
        return strtolower(trim($host));
    }
}
