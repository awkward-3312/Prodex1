<?php

namespace Database\Seeders\Central;

use App\Models\Central\Plan;
use Illuminate\Database\Seeder;

class PlanLimitsSyncSeeder extends Seeder
{
    /**
     * Backfill newly introduced limit keys onto existing plans.
     *
     * Plans created before a limit key existed (e.g. max_whatsapp_messages)
     * have no entry for it, which getLimit() treats as unlimited (-1). This
     * seeder makes that implicit value explicit so the key appears on the
     * super-admin plan form and can be capped per plan. Behaviour is
     * unchanged: a missing key was already unlimited.
     *
     * Idempotent — only adds keys that are absent, never overwrites values.
     */
    public function run(): void
    {
        foreach (Plan::all() as $plan) {
            $limits = $plan->limits ?? [];
            $changed = false;

            foreach (array_keys(Plan::AVAILABLE_LIMITS) as $key) {
                if (! array_key_exists($key, $limits)) {
                    $limits[$key] = -1;
                    $changed = true;
                }
            }

            if ($changed) {
                $plan->update(['limits' => $limits]);
            }
        }
    }
}
