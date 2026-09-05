<?php

namespace App\Console\Commands;

use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One-time-safe backfill: assign a random default avatar
 * (default_avatar_1..4.png, see app/Support/avatar_helpers.php) to existing
 * TENANT users who have no avatar of their own.
 *
 * "No avatar of their own" = users.avatar is NULL, '' or the legacy
 * 'no_avatar.png' placeholder (needs_default_tenant_avatar_assignment()).
 * A user with a real uploaded avatar, OR who already has one of the 4
 * defaults, is left completely untouched — running this command twice never
 * re-randomizes an already-processed user (idempotent).
 *
 * Central/Super Admin (CentralUser) is architecturally separate and never
 * touched — this only ever operates on the `users` table inside each
 * tenant's own database (see tenancy()->initialize()).
 *
 * Writes via the query builder (never Eloquent) so `updated_at` is not
 * touched for a purely cosmetic backfill.
 */
class ProdexBackfillDefaultAvatars extends Command
{
    protected $signature = 'prodex:backfill-default-avatars
        {--tenants=* : Tenant IDs to process. Defaults to all tenants.}';

    protected $description = 'Assign a random default avatar to existing tenant users who have no avatar of their own (idempotent).';

    public function handle(): int
    {
        $tenantIds = array_filter((array) $this->option('tenants'));
        $query = Tenant::query()->orderBy('id');
        if ($tenantIds) {
            $query->whereIn('id', $tenantIds);
        }

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');
            return self::SUCCESS;
        }

        $summary = ['tenants' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->info("Tenant {$tenant->id}");

            try {
                tenancy()->initialize($tenant);

                if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'avatar')) {
                    $this->warn('  Skipping: no users.avatar column.');
                    continue;
                }

                [$updated, $skipped] = static::backfillCurrentConnection();

                $summary['tenants']++;
                $summary['updated'] += $updated;
                $summary['skipped'] += $skipped;
                $this->line("  Updated: {$updated}, Skipped: {$skipped}");
            } catch (Throwable $e) {
                $summary['errors']++;
                $this->error('  Failed: '.$e->getMessage());
            } finally {
                if (function_exists('tenancy') && tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d tenants processed, %d users updated, %d skipped, %d errors.',
            $summary['tenants'], $summary['updated'], $summary['skipped'], $summary['errors']
        ));

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Runs the actual backfill against whatever connection `users` currently
     * resolves to (the tenant connection once tenancy()->initialize() has
     * run). Extracted as its own method — with no tenancy/IO of its own —
     * purely so it can be exercised directly against a plain test database
     * without needing a real multi-tenant DB switch.
     *
     * @return array{0:int,1:int} [$updated, $skipped]
     */
    public static function backfillCurrentConnection(): array
    {
        return DB::transaction(function () {
            $updated = 0;
            $skipped = 0;

            DB::table('users')
                ->whereNull('deleted_at')
                ->select('id', 'avatar')
                ->orderBy('id')
                ->chunkById(200, function ($users) use (&$updated, &$skipped) {
                    // Group by the random pick so each distinct value is one
                    // UPDATE ... WHERE id IN (...) instead of one query per row.
                    $buckets = [];
                    foreach ($users as $user) {
                        if (needs_default_tenant_avatar_assignment($user->avatar)) {
                            $buckets[random_default_tenant_avatar_filename()][] = $user->id;
                        } else {
                            $skipped++;
                        }
                    }

                    foreach ($buckets as $avatar => $ids) {
                        DB::table('users')->whereIn('id', $ids)->update(['avatar' => $avatar]);
                        $updated += count($ids);
                    }
                });

            return [$updated, $skipped];
        });
    }
}
