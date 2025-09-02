<?php

namespace App\Jobs;

use App\Http\Services\BybitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
//App\Jobs\BuildFeaturesJob::dispatchSync();
class BuildFeaturesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $symbol = 'BTCUSDT', public string $tf = '15') {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(BybitService $bybit): void
    {
        $klines = $bybit->getKlines($this->symbol, $this->tf, 'spot', 300);

        // Преобразуем свечи в OHLCV
        $rows = collect($klines)->map(fn($r) => [
            'ts'    => (int)$r[0],
            'open'  => (float)$r[1],
            'high'  => (float)$r[2],
            'low'   => (float)$r[3],
            'close' => (float)$r[4],
            'vol'   => (float)$r[5],
        ])->sortBy('ts')->values();

        // EMA(20), EMA(50), RSI(14) — простая имплементация
        $closes = $rows->pluck('close')->all();
        $ema20  = $this->ema($closes, 20);
        $ema50  = $this->ema($closes, 50);
        $rsi14  = $this->rsi($closes, 14);

        $last = $rows->last();
        $features = [
            'symbol'        => $this->symbol,
            'tf'            => $this->tf,
            'price'         => $last['close'],
            'ema20'         => end($ema20),
            'ema50'         => end($ema50),
            'rsi14'         => end($rsi14),
            'ema_trend'     => (end($ema20) > end($ema50)) ? 'bull' : 'bear',
            'rsi_state'     => (end($rsi14) < 30) ? 'oversold' : ((end($rsi14) > 70) ? 'overbought' : 'neutral'),
            // добавьте волатильность, ATR и т.п.
        ];

        AiSignalJob::dispatchSync($features);
    }

    private function ema(array $data, int $period): array
    {
        $k = 2 / ($period + 1);
        $ema = [];
        foreach ($data as $i => $price) {
            if ($i === 0) $ema[$i] = $price;
            else $ema[$i] = $price * $k + $ema[$i-1] * (1 - $k);
        }
        return $ema;
    }

    private function rsi(array $data, int $period): array
    {
        $rsi = [];
        $g = $l = 0;
        for ($i=1; $i<count($data); $i++) {
            $ch = $data[$i] - $data[$i-1];
            $gain = max($ch,0); $loss = max(-$ch,0);
            if ($i <= $period) { $g += $gain; $l += $loss; $rsi[$i] = null; continue; }
            if ($i == $period+1) { $avgG = $g/$period; $avgL = $l/$period; }
            else { $avgG = ($avgG*($period-1)+$gain)/$period; $avgL = ($avgL*($period-1)+$loss)/$period; }
            $rs = $avgL == 0 ? 100 : $avgG / $avgL;
            $rsi[$i] = 100 - (100 / (1 + $rs));
        }
        return $rsi;
    }
}
