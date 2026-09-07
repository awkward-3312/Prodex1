<?php

namespace Tests\Unit;

use App\Http\Middleware\ApplyTenantTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The middleware aligns the process clock with the tenant's own timezone, so a
 * POS sale created in the evening in a UTC-negative country and the Dashboard
 * date filters that later read it agree on which business day it belongs to.
 *
 * Tests\TestCase::refreshTestDatabase() already creates a minimal `settings`
 * table with a `timezone` column (default 'UTC').
 */
class ApplyTenantTimezoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('settings')->insert(['id' => 1, 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now()]);
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    private function pass(): void
    {
        (new ApplyTenantTimezone)->handle(Request::create('/api/x', 'GET'), fn ($r) => response('ok'));
    }

    public function test_it_applies_a_valid_tenant_timezone_to_the_process(): void
    {
        DB::table('settings')->where('id', 1)->update(['timezone' => 'America/Tegucigalpa']);

        $this->pass();

        $this->assertSame('America/Tegucigalpa', config('app.timezone'));
        $this->assertSame('America/Tegucigalpa', date_default_timezone_get());
        // now() and the raw date() reference are the same wall clock.
        $this->assertSame(Carbon::now()->toDateString(), date('Y-m-d'));
        // ...and that clock is 6h behind UTC.
        $this->assertSame(-6 * 3600, Carbon::now()->getOffset());
    }

    public function test_a_sale_date_and_a_dashboard_window_now_share_the_same_business_day(): void
    {
        DB::table('settings')->where('id', 1)->update(['timezone' => 'America/Tegucigalpa']);

        // 03:30 UTC == 21:30 the previous day in Tegucigalpa.
        Carbon::setTestNow(Carbon::parse('2026-09-07 03:30:00', 'UTC'));

        $this->pass();

        // POS writes Sale.date = now()->toDateString(); Dashboard default window
        // end = now()->toDateString(). Both must be the local business day.
        $this->assertSame('2026-09-06', Carbon::now()->toDateString());

        Carbon::setTestNow();
    }

    public function test_it_is_a_no_op_for_a_tenant_left_on_utc(): void
    {
        // settings.timezone stays 'UTC'
        $this->pass();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_it_ignores_an_invalid_timezone_string(): void
    {
        DB::table('settings')->where('id', 1)->update(['timezone' => 'Not/AZone']);

        $this->pass();

        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_it_ignores_an_empty_timezone(): void
    {
        DB::table('settings')->where('id', 1)->update(['timezone' => '']);

        $this->pass();

        $this->assertSame('UTC', config('app.timezone'));
    }
}
