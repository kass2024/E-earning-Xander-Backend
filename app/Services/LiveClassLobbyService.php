<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LiveClassLobbyService
{
    private const TTL_MINUTES = 180;

    public function recordCheckIn(int $materialId, int $studentId, string $displayName, ?string $email = null): void
    {
        if ($materialId <= 0 || $studentId <= 0) {
            return;
        }

        $key = $this->materialKey($materialId);
        $entries = Cache::get($key, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[(string) $studentId] = [
            'student_id' => $studentId,
            'display_name' => trim($displayName) !== '' ? trim($displayName) : 'Learner',
            'email' => $email,
            'checked_in_at' => now()->toIso8601String(),
        ];

        Cache::put($key, $entries, now()->addMinutes(self::TTL_MINUTES));
    }

    /**
     * @return list<array{student_id: int, display_name: string, email: ?string, checked_in_at: string}>
     */
    public function listForMaterial(int $materialId): array
    {
        $entries = Cache::get($this->materialKey($materialId), []);
        if (!is_array($entries)) {
            return [];
        }

        $rows = array_values($entries);
        usort($rows, fn ($a, $b) => strcmp((string) ($b['checked_in_at'] ?? ''), (string) ($a['checked_in_at'] ?? '')));

        return $rows;
    }

    public function clearMaterial(int $materialId): void
    {
        Cache::forget($this->materialKey($materialId));
    }

    public function removeStudent(int $materialId, int $studentId): void
    {
        if ($materialId <= 0 || $studentId <= 0) {
            return;
        }

        $key = $this->materialKey($materialId);
        $entries = Cache::get($key, []);
        if (!is_array($entries)) {
            return;
        }

        unset($entries[(string) $studentId]);
        Cache::put($key, $entries, now()->addMinutes(self::TTL_MINUTES));
    }

    private function materialKey(int $materialId): string
    {
        return 'live_class_lobby:' . $materialId;
    }
}
