<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live USD → RWF mid-market rate for Mobile Money conversion.
 * Caches for an hour; keeps the last good quote if a fetch fails.
 */
class LiveUsdRwfRateService
{
    public const CACHE_KEY = 'forex:usd_rwf:live';
    public const LAST_GOOD_KEY = 'forex:usd_rwf:last_good';
    public const CACHE_SECONDS = 3600;

    /** @return array{rate:float,source:string,as_of:?string,live:bool} */
    public function quote(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $this->validRate($cached['rate'] ?? null)) {
            return [
                'rate' => (float) $cached['rate'],
                'source' => (string) ($cached['source'] ?? 'cache'),
                'as_of' => isset($cached['as_of']) ? (string) $cached['as_of'] : null,
                'live' => (bool) ($cached['live'] ?? true),
            ];
        }

        foreach ($this->fetchers() as $fetcher) {
            try {
                $quote = $fetcher();
                if ($quote && $this->validRate($quote['rate'] ?? null)) {
                    $normalized = [
                        'rate' => (float) $quote['rate'],
                        'source' => (string) ($quote['source'] ?? 'live'),
                        'as_of' => isset($quote['as_of']) ? (string) $quote['as_of'] : now()->toIso8601String(),
                        'live' => true,
                    ];
                    Cache::put(self::CACHE_KEY, $normalized, self::CACHE_SECONDS);
                    Cache::forever(self::LAST_GOOD_KEY, $normalized);

                    return $normalized;
                }
            } catch (\Throwable $e) {
                Log::warning('USD/RWF forex fetch failed', ['error' => $e->getMessage()]);
            }
        }

        $last = Cache::get(self::LAST_GOOD_KEY);
        if (is_array($last) && $this->validRate($last['rate'] ?? null)) {
            return [
                'rate' => (float) $last['rate'],
                'source' => (string) ($last['source'] ?? 'cache') . '_cached',
                'as_of' => isset($last['as_of']) ? (string) $last['as_of'] : null,
                'live' => false,
            ];
        }

        $fallback = (float) config('services.meeting_booking.usd_rwf_fallback', 1450);

        return [
            'rate' => $fallback,
            'source' => 'fallback',
            'as_of' => null,
            'live' => false,
        ];
    }

    public function convertUsdToRwf(float $usd): int
    {
        if ($usd <= 0) {
            return 0;
        }

        return max(1, (int) round($usd * $this->quote()['rate']));
    }

    public function validRate(mixed $rate): bool
    {
        $n = (float) $rate;

        return $n >= 500 && $n <= 4000;
    }

    /** @return array<int, callable(): (?array)> */
    private function fetchers(): array
    {
        return [
            fn () => $this->fromOpenErApi(),
            fn () => $this->fromCurrencyApi(),
        ];
    }

    private function httpGet(string $url): ?array
    {
        $response = Http::timeout(8)
            ->connectTimeout(5)
            ->acceptJson()
            ->get($url);

        if (!$response->ok()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /** @return array{rate:float,source:string,as_of:?string}|null */
    private function fromOpenErApi(): ?array
    {
        $json = $this->httpGet('https://open.er-api.com/v6/latest/USD');
        if (!$json || ($json['result'] ?? '') !== 'success') {
            return null;
        }
        $rate = (float) data_get($json, 'rates.RWF', 0);
        if (!$this->validRate($rate)) {
            return null;
        }

        $asOf = $json['time_last_update_utc'] ?? $json['time_last_update_unix'] ?? null;

        return [
            'rate' => $rate,
            'source' => 'open.er-api.com',
            'as_of' => is_numeric($asOf)
                ? date('c', (int) $asOf)
                : (is_string($asOf) ? $asOf : null),
        ];
    }

    /** @return array{rate:float,source:string,as_of:?string}|null */
    private function fromCurrencyApi(): ?array
    {
        $json = $this->httpGet('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.min.json');
        if (!$json) {
            return null;
        }
        $rate = (float) data_get($json, 'usd.rwf', 0);
        if (!$this->validRate($rate)) {
            return null;
        }

        $date = $json['date'] ?? null;

        return [
            'rate' => $rate,
            'source' => 'currency-api',
            'as_of' => is_string($date) ? $date : null,
        ];
    }
}
