<?php

namespace App\Services;

use App\Enums\MeetingProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PlatformSettingsService
{
    protected const CACHE_KEY = 'platform_settings.all';

    public function mainPlatformMeetingProvider(): MeetingProvider
    {
        $stored = $this->get('main_platform_meeting_provider');
        if (is_string($stored) && trim($stored) !== '') {
            return MeetingProvider::fromStringOrDefault($stored);
        }

        return MeetingProvider::fromStringOrDefault(
            (string) config('daily.main_platform_meeting_provider', 'daily'),
            MeetingProvider::Daily,
        );
    }

    public function setMainPlatformMeetingProvider(MeetingProvider $provider): void
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('platform_settings table is missing');
        }

        $this->set('main_platform_meeting_provider', $provider->value);

        // Keep partner institution rows in sync so legacy readers stay consistent.
        if (Schema::hasTable('platform_institutions') && Schema::hasColumn('platform_institutions', 'meeting_provider')) {
            \Illuminate\Support\Facades\DB::table('platform_institutions')->update([
                'meeting_provider' => $provider->value,
                'updated_at' => now(),
            ]);
        }
    }

    public function isReady(): bool
    {
        return $this->tableReady();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->tableReady()) {
            return $default;
        }

        $all = $this->all();
        if (!array_key_exists($key, $all)) {
            return $default;
        }

        return $all[$key];
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->tableReady()) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, 300, function () {
            return \Illuminate\Support\Facades\DB::table('platform_settings')
                ->pluck('value', 'key')
                ->map(static fn ($value) => is_string($value) ? $value : null)
                ->all();
        });
    }

    protected function tableReady(): bool
    {
        return Schema::hasTable('platform_settings');
    }
}
