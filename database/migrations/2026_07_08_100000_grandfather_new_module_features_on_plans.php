<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contracts, Projects & Tasks, Bookings and Assets used to be available to
 * every tenant unconditionally. They are now plan features gated by the
 * `tenant.feature` middleware, so every pre-existing plan must be granted
 * the new keys or its tenants would silently lose access. Super admins can
 * untick them per plan afterwards.
 */
return new class extends Migration
{
    private const NEW_FEATURES = ['contracts', 'projects', 'bookings', 'assets'];

    public function up(): void
    {
        $plans = DB::connection('central')->table('plans')->select('id', 'features')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];

            $merged = array_values(array_unique(array_merge($features, self::NEW_FEATURES)));

            if ($merged !== $features) {
                DB::connection('central')->table('plans')
                    ->where('id', $plan->id)
                    ->update(['features' => json_encode($merged)]);
            }
        }
    }

    public function down(): void
    {
        $plans = DB::connection('central')->table('plans')->select('id', 'features')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];

            $stripped = array_values(array_diff($features, self::NEW_FEATURES));

            if ($stripped !== $features) {
                DB::connection('central')->table('plans')
                    ->where('id', $plan->id)
                    ->update(['features' => json_encode($stripped)]);
            }
        }
    }
};
