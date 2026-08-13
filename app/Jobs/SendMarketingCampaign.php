<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMarketingCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;

    public $tries = 1;

    protected $campaignId;

    public function __construct($campaignId)
    {
        $this->campaignId = $campaignId;
    }

    public function handle(MarketingDispatcher $dispatcher)
    {
        $campaign = MarketingCampaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        // Only dispatch campaigns that are queued to send.
        if (! in_array($campaign->status, ['draft', 'scheduled', 'sending'])) {
            return;
        }

        try {
            $dispatcher->dispatchCampaign($campaign);
        } catch (\Throwable $e) {
            Log::error('SendMarketingCampaign failed for campaign '.$this->campaignId.': '.$e->getMessage());
            $campaign->update(['status' => 'failed']);
            throw $e;
        }
    }
}
