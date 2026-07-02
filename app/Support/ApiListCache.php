<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class ApiListCache
{
    public static function remember(string $bucket, string $key, int $seconds, callable $callback): mixed
    {
        $cacheKey = 'api_list_' . $bucket . '_v' . self::version($bucket) . '_' . $key;

        return Cache::remember($cacheKey, $seconds, $callback);
    }

    public static function bump(string $bucket): void
    {
        Cache::put('api_list_version_' . $bucket, self::version($bucket) + 1, now()->addDays(7));
    }

    protected static function version(string $bucket): int
    {
        return (int) Cache::get('api_list_version_' . $bucket, 1);
    }
}
