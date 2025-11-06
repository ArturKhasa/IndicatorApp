<?php

namespace App\Console\Commands;

use App\Http\Services\BybitService;
use App\Models\Trade;
use Illuminate\Console\Command;

class UpdateTrades extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-trades';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(BybitService $bybit)
    {
        $openTrades = Trade::query()->where('status', 'open')->get();
        if ($openTrades->isEmpty()) {
            $this->info('Нет открытых сделок.');
            return;
        }

        foreach ($openTrades as $trade) {
            try {
                $symbol = $trade->symbol;
                $currentPrice = $bybit->getLastPrice($symbol);
                if ($currentPrice <= 0) continue;

                $entry  = (float)$trade->entry_price;
                $qty    = (float)$trade->qty;
                $lev    = (float)($trade->leverage ?? 1);
                $currentPrice = $bybit->getLastPrice($trade->symbol);

                $pnlAbs = $trade->side === 'Sell'
                    ? ($entry - $currentPrice) * $qty
                    : ($currentPrice - $entry) * $qty;

                $pnlPercent = $trade->side === 'Sell'
                    ? (($entry - $currentPrice) / $entry) * $lev * 100
                    : (($currentPrice - $entry) / $entry) * $lev * 100;

                // расчёт реальной доходности на маржу
                $marginUsed = ($entry * $qty) / $lev;
                $pnlOnMargin = $marginUsed > 0 ? ($pnlAbs / $marginUsed) * 100 : 0;

                $trade->update([
                    'pnl_usd'       => round($pnlAbs, 4),
                    'pnl_percent'   => round($pnlPercent, 2),
                    'pnl_on_margin' => round($pnlOnMargin, 2),
                ]);



                $this->info("{$symbol}: PnL={$pnlAbs} USDT ({$pnlPercent}%)");
            } catch (\Throwable $e) {
                $this->warn("Ошибка для {$trade->symbol}: ".$e->getMessage());
            }
        }
    }
}
