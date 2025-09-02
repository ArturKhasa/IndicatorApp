<?php

namespace App\Jobs;

use App\Http\Services\BybitService;
use App\Models\Trade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class ExecuteAutoBuyJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $symbol,
        public string $amountQuote,
        public ?string $aiReason = null,
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(BybitService $bybit): void
    {
        // 1) Ставим маркет-покупку (qty = сумма в котировке)
        $orderLinkId = 'autobuy-'.Str::uuid()->toString();
        $resp = $bybit->placeSpotMarketBuy($this->symbol, $this->amountQuote, $orderLinkId);

        $orderId = (string)($resp['result']['orderId'] ?? '');
        // 2) Пуллим статус ордера/исполнения c backoff
        [$qtyBase, $avgPrice, $finalOrderId] = $this->waitForFills($bybit, $orderLinkId);

        // 3) Если fills не пришли — подстрахуемся оценкой
        if ($qtyBase <= 0 || $avgPrice <= 0) {
            $last = $bybit->getLastPrice($this->symbol);
            if ($last > 0) {
                $qtyBase = (float)$this->amountQuote / $last;
                $avgPrice = $last;
            }
        }

        // 4) Записываем сделку в trades
        $trade = Trade::query()->create([
            'symbol'              => $this->symbol,
            'side'                => 'buy',
            'qty_base'            => $qtyBase,             // реальное купленное количество базы
            'price_entry'         => $avgPrice,            // средняя фактическая цена
            'ts_entry'            => (int)(microtime(true) * 1000),
            'entry_order_id'      => $finalOrderId ?: $orderId,
            'entry_order_link_id' => $orderLinkId,
            'status'              => 'open',
            'reason_entry'        => $this->aiReason ?: 'AI buy',
            'last_ai_comment'     => $this->aiReason,
        ]);

        if ($finalOrderId === '' || $qtyBase <= 0) {
            dd($trade->toArray());
//            sleep(3);
//            SyncTradeEntryJob::dispatchSync($trade->id);
//            SyncTradeEntryJob::dispatch($trade->id)->delay(now()->addSeconds(5));
        }
    }

    protected function waitForFills(BybitService $bybit, string $orderLinkId): array
    {
        $attempts = 6;              // ~ суммарно до ~1+2+3+4+5+6 = ~21 сек ожидания
        $sleepSec = 1;

        for ($i = 0; $i < $attempts; $i++) {
            // Сначала пробуем агрегированный статус ордера
            $order = $bybit->getOpenClosedOrderByLinkId($orderLinkId, 'spot');
            $cum  = isset($order['cumExecQty']) ? (float)$order['cumExecQty'] : 0.0;
            $avg  = isset($order['avgPrice'])   ? (float)$order['avgPrice']   : 0.0;
            $oid  = (string)($order['orderId'] ?? '');

            if ($cum > 0 && $avg > 0) {
                return [$cum, $avg, $oid];
            }

            // Если в ордере ещё пусто — пробуем post-trade executions
            $fills = $bybit->getExecutionsByOrder($orderLinkId, 'spot');
            if (!empty($fills)) {
                $sumQty = 0.0; $sumTurnover = 0.0; $firstOid = '';
                foreach ($fills as $idx => $f) {
                    $sumQty      += (float)$f['execQty'];
                    $sumTurnover += (float)$f['execValue']; // в котировке (USDT)
                    if ($idx === 0) $firstOid = (string)($f['orderId'] ?? '');
                }
                if ($sumQty > 0 && $sumTurnover > 0) {
                    $avg = $sumTurnover / $sumQty;
                    return [$sumQty, $avg, $firstOid];
                }
            }

            sleep($sleepSec);
            $sleepSec++; // линейный/экспо-бэкофф
        }

        // Не дождались фактики — вернём нули, дальше подстрахуемся lastPrice
        return [0.0, 0.0, ''];
    }
}
