<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch "Facturación SAR habilitada" flag. A branch without a row is
 * treated as disabled.
 */
class SarBranchSetting extends Model
{
    protected $table = 'sar_branch_settings';

    protected $fillable = ['branch_id', 'enabled'];

    protected $casts = [
        'branch_id' => 'integer',
        'enabled' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
