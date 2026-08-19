<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::connection('central')->table('plans')->get(['id', 'features']);

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?: '[]', true);
            $features = is_array($features) ? $features : [];

            if (! in_array('knowledge_base', $features, true)) {
                $features[] = 'knowledge_base';

                DB::connection('central')->table('plans')
                    ->where('id', $plan->id)
                    ->update([
                        'features' => json_encode(array_values(array_unique($features))),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Manual PRODEX is intentionally treated as a core help resource.
        // Do not remove it automatically on rollback because a plan may have
        // enabled knowledge_base independently before this migration.
    }
};
