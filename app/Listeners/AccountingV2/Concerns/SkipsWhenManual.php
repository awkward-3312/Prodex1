<?php

namespace App\Listeners\AccountingV2\Concerns;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

trait SkipsWhenManual
{
    protected function shouldSkipAutomation(): bool
    {
        return ! $this->accountingAutomationEnabled();
    }

    /**
     * Whether journals should be generated automatically from operational
     * events. The tenant "settings" row is authoritative once the column
     * exists; otherwise we fall back to the config/env default.
     */
    protected function accountingAutomationEnabled(): bool
    {
        try {
            if (Schema::hasColumn('settings', 'accounting_auto_generate_journals')) {
                $setting = Setting::query()->first();

                if ($setting !== null) {
                    return (bool) $setting->accounting_auto_generate_journals;
                }
            }
        } catch (\Throwable $e) {
            // Settings table not available (e.g. during boot/migrations) —
            // fall through to the config default below.
        }

        return (bool) Config::get('accounting_v2.auto_generate_journals', false);
    }
}
