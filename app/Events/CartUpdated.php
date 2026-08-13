<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow (not ShouldBroadcast): this is a real-time POS → customer
// display event, so it must broadcast in-process. Queuing it (ShouldBroadcast)
// pushed a BroadcastEvent job onto the database queue, which failed because the
// `jobs` table only exists in tenant DBs — crashing every cart update. Broadcast
// is best-effort here anyway; the display's load-bearing path is the cached
// last-cart that polling clients read.
class CartUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var array */
    public $cart;

    /** @var bool */
    public $completed;

    /** @var string Screen ID for multi-display support (e.g. '1', '2') */
    public $screenId;

    /**
     * Create a new event instance.
     */
    public function __construct(array $cart, bool $completed = false, string $screenId = '1')
    {
        $this->cart = $cart;
        $this->completed = $completed;
        $this->screenId = $screenId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('pos-cart.'.$this->screenId);
    }

    public function broadcastAs()
    {
        return 'CartUpdated';
    }
}
