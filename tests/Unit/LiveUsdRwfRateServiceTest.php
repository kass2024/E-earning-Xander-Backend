<?php

namespace Tests\Unit;

use App\Services\LiveUsdRwfRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveUsdRwfRateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_converts_usd_using_live_open_er_api_rate(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response([
                'result' => 'success',
                'base_code' => 'USD',
                'time_last_update_utc' => 'Sun, 20 Sep 2026 00:02:31 +0000',
                'rates' => ['RWF' => 1481.5561],
            ], 200),
        ]);

        $svc = app(LiveUsdRwfRateService::class);
        $quote = $svc->quote();

        $this->assertTrue($quote['live']);
        $this->assertSame('open.er-api.com', $quote['source']);
        $this->assertEqualsWithDelta(1481.5561, $quote['rate'], 0.0001);
        $this->assertSame(148156, $svc->convertUsdToRwf(100));
    }

    public function test_falls_back_to_second_source_when_primary_fails(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'error'], 500),
            'cdn.jsdelivr.net/*' => Http::response([
                'date' => '2026-09-20',
                'usd' => ['rwf' => 1475.2],
            ], 200),
        ]);

        $quote = app(LiveUsdRwfRateService::class)->quote();

        $this->assertTrue($quote['live']);
        $this->assertSame('currency-api', $quote['source']);
        $this->assertEqualsWithDelta(1475.2, $quote['rate'], 0.0001);
    }

    public function test_rejects_out_of_range_rates(): void
    {
        $this->assertFalse(app(LiveUsdRwfRateService::class)->validRate(12));
        $this->assertFalse(app(LiveUsdRwfRateService::class)->validRate(9000));
        $this->assertTrue(app(LiveUsdRwfRateService::class)->validRate(1481));
    }
}
