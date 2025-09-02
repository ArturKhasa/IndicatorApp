<?php

namespace App\Jobs;

use App\Http\Services\BybitService;
use App\Models\Trade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTradeEntryJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $tradeId)
    {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(BybitService $bybit): void
    {
        $t = Trade::query()->findOrFail($this->tradeId);
        if ($t->status !== 'open') return;

        $order = $bybit->getOpenClosedOrderByLinkId($t->entry_order_link_id, 'spot');
        if (!empty($order['cumExecQty'])) {
            $qty = (float)$order['cumExecQty'];
            $avg = isset($order['avgPrice']) ? (float)$order['avgPrice'] : $t->price_entry;
            $t->update(['qty_base' => $qty, 'price_entry' => $avg]);
        }
    }
}
