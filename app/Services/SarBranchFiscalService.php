<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\SarBranchSetting;
use App\Models\SarPointOfIssue;
use Illuminate\Support\Facades\DB;

/**
 * PRODEX manages the technical SAR structure so the tenant never has to create a
 * "point of issue" by hand.
 *
 *   Perfil fiscal habilitado
 *     -> cada sucursal activa aparece en Facturación SAR
 *   Sucursal con "Facturación SAR habilitada"
 *     -> 1 SarPointOfIssue gestionado por PRODEX (is_auto_managed)
 *     -> cubre las cajas físicas activas de esa sucursal (pivot sar_point_cash_drawers)
 *   El tenant sólo escribe los datos autorizados por el SAR
 *     (establishment_code, point_code, CAI, rango, fecha límite).
 *
 * Idempotente: nunca duplica puntos ni filas de pivot.
 */
class SarBranchFiscalService
{
    /**
     * Called when the fiscal profile is first enabled: every active branch must
     * show up in Facturación SAR. Branches start disabled (the admin enables
     * each one and fills its authorised data). Existing enabled branches are
     * (re)synced.
     */
    public function syncAllActiveBranches(): void
    {
        Branch::query()->where('is_active', true)->orderBy('id')->get()->each(function (Branch $branch) {
            $setting = SarBranchSetting::firstOrCreate(
                ['branch_id' => $branch->id],
                ['enabled' => false]
            );
            if ($setting->enabled) {
                $this->syncBranch($branch);
            }
        });
    }

    public function setBranchEnabled(int $branchId, bool $enabled): SarBranchSetting
    {
        $branch = Branch::whereKey($branchId)->firstOrFail();

        $setting = SarBranchSetting::updateOrCreate(
            ['branch_id' => $branch->id],
            ['enabled' => $enabled]
        );

        if ($enabled) {
            $this->syncBranch($branch);
        }

        return $setting->refresh();
    }

    /**
     * Ensure the branch has exactly one managed point of issue and that it
     * covers every active cash drawer of the branch that is not already claimed
     * by another point. Safe to call repeatedly.
     */
    public function syncBranch(Branch $branch): SarPointOfIssue
    {
        return DB::transaction(function () use ($branch) {
            $point = SarPointOfIssue::where('branch_id', $branch->id)
                ->where('is_auto_managed', true)
                ->orderBy('id')
                ->first();

            if (! $point) {
                // Adopt a legacy manual point for this branch if there is a
                // single one, so existing configs are not duplicated.
                $legacy = SarPointOfIssue::where('branch_id', $branch->id)
                    ->where('is_auto_managed', false)
                    ->get();

                if ($legacy->count() === 1) {
                    $point = $legacy->first();
                    $point->is_auto_managed = true;
                    $point->save();
                } else {
                    $point = SarPointOfIssue::create([
                        'branch_id' => $branch->id,
                        'inventory_location_id' => $branch->default_inventory_location_id,
                        'establishment_code' => null,
                        'point_code' => null,
                        'name' => 'Facturación '.$branch->name,
                        'address' => (string) ($branch->address ?? $branch->name),
                        'active' => false,
                        'is_auto_managed' => true,
                    ]);
                }
            }

            $this->syncDrawers($point, $branch);

            // A managed point is only "active" once it carries its authorised
            // codes and covers at least one drawer.
            $covers = DB::table('sar_point_cash_drawers')->where('sar_point_of_issue_id', $point->id)->count();
            $shouldBeActive = $point->hasCodes() && $covers > 0;
            if ((bool) $point->active !== $shouldBeActive) {
                $point->active = $shouldBeActive;
                $point->save();
            }

            return $point->refresh();
        });
    }

    /**
     * Link every active cash drawer of the branch to this point, unless the
     * drawer already belongs to another point. Drawers whose branch changed away
     * are unlinked.
     */
    public function syncDrawers(SarPointOfIssue $point, Branch $branch): void
    {
        $activeDrawerIds = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('branch_id', $branch->id)
            ->pluck('id');

        // unlink drawers that no longer belong to this branch / are inactive
        DB::table('sar_point_cash_drawers')
            ->where('sar_point_of_issue_id', $point->id)
            ->whereNotIn('cash_drawer_id', $activeDrawerIds->all() ?: [0])
            ->delete();

        foreach ($activeDrawerIds as $drawerId) {
            $claimedElsewhere = DB::table('sar_point_cash_drawers')
                ->where('cash_drawer_id', $drawerId)
                ->where('sar_point_of_issue_id', '<>', $point->id)
                ->exists();
            if ($claimedElsewhere) {
                continue;
            }
            DB::table('sar_point_cash_drawers')->updateOrInsert(
                ['cash_drawer_id' => $drawerId],
                ['sar_point_of_issue_id' => $point->id, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    /**
     * Hook for CashDrawerController: a new / re-branched drawer in a fiscally
     * enabled branch becomes available for its branch's point automatically.
     */
    public function syncCashDrawer(CashDrawer $drawer): void
    {
        if (! $drawer->branch_id) {
            return;
        }
        $setting = SarBranchSetting::where('branch_id', $drawer->branch_id)->first();
        if (! $setting || ! $setting->enabled) {
            return;
        }
        $branch = Branch::whereKey($drawer->branch_id)->first();
        if ($branch) {
            $this->syncBranch($branch);
        }
    }
}
