<?php

namespace App\Models\Central;

use App\Traits\HasCentralTranslations;
use Illuminate\Database\Eloquent\Model;

class LandingRefundPolicy extends Model
{
    use HasCentralTranslations;

    protected $connection = 'central';

    protected $table = 'landing_refund_policy';

    protected array $translatable = [
        'overview',
        'subscriptions_trials',
        'cancellations',
        'billing_errors',
        'refund_eligibility',
        'chargebacks',
        'how_to_request',
        'payment_processor',
    ];

    protected $fillable = [
        'overview',
        'subscriptions_trials',
        'cancellations',
        'billing_errors',
        'refund_eligibility',
        'chargebacks',
        'how_to_request',
        'payment_processor',
        'last_updated',
        'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_updated' => 'date',
    ];
}
