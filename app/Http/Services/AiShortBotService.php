<?php

namespace App\Http\Services;

class AiShortBotService
{
    public function getShortCandidates(int $universe = 100, int $top = 10): array
    {
        $bybit = new BybitService();

        // 1) Возьми универс (top по объёму)
        $symbols = $bybit->getTopByVolume($universe, 'spot'); // ['BTCUSDT', 'ETHUSDT', ...]
        $candidates = [];

        foreach ($symbols as $sym) {
            try {
                // Быстрые фильтры
                $lastPrice = $bybit->getLastPrice($sym);
                if ($lastPrice <= 0) continue;

                // Получим 1h и 15m свечи (200 периодов, чтобы считать EMA/RSI)
                $klines1h = $bybit->getKlines($sym, '60', 'spot', 200); // интервал '60'
                $klines15m = $bybit->getKlines($sym, '15', 'spot', 200);

                if (count($klines1h) < 50 || count($klines15m) < 50) continue;

                // Вычислим простые метрики: last returns, avg volume, rsi14 (на 15m)

                $closePrices15 = array_map(fn($k) => (float)$k[4], $klines15m);
                $volumes15 = array_map(fn($k) => (float)$k[5], $klines15m);

                // helper functions ниже (implementations provided after)
                $rsi14 = $this->rsi($closePrices15, 14);
                $ema20 = $this->ema($closePrices15, 20);
                $ema200 = $this->ema(array_map(fn($k) => (float)$k[4], $klines1h), 200);

                $avgVol = array_sum($volumes15) / max(1, count($volumes15));
                $volNow = $volumes15[array_key_last($volumes15)] ?? $avgVol;

                $ret1h = ($closePrices15[array_key_last($closePrices15)] - $closePrices15[max(0, count($closePrices15)-5)]) / max(1e-8, $closePrices15[max(0, count($closePrices15)-5)]);
                $ret24h = ($lastPrice - $klines1h[array_key_first($klines1h)][1]) / max(1e-8, $klines1h[array_key_first($klines1h)][1] ?? $lastPrice);

                // Normalize signals to 0..1
                $s_rsi = $rsi14 > 65 ? ($rsi14 - 65) / (100 - 65) : 0; // 0..1
                $s_price_drop = $ret24h < 0 ? min(1, abs($ret24h) / 0.5) : 0; // if down 50% -> 1
                $s_ema200 = $lastPrice < $ema200 ? 1 : 0;
                $s_vol_spike = ($volNow / max(1e-8, $avgVol)) > 1 ? min(1, ($volNow / max(1e-8, $avgVol) - 1) / 4) : 0; // scale
                $s_recent_down = $ret1h < 0 ? min(1, abs($ret1h) / 0.1) : 0; // 10% drop in 1h => 1

                // Weighted score
                $score = 0.20 * $s_rsi
                    + 0.20 * $s_price_drop
                    + 0.20 * $s_ema200
                    + 0.15 * $s_vol_spike
                    + 0.25 * $s_recent_down;

                // quick liquidity filter
                if ($avgVol < 50) continue; // min volume threshold (USDT)

                $candidates[] = [
                    'symbol' => $sym,
                    'score' => $score,
                    'rsi14' => $rsi14,
                    'ema20' => $ema20,
                    'ema200' => $ema200,
                    'volNow' => $volNow,
                    'avgVol' => $avgVol,
                    'ret1h' => $ret1h,
                    'ret24h' => $ret24h,
                    'lastPrice' => $lastPrice,
                ];
            } catch (\Throwable $e) {
                // log и skip
//                logger()->warning("Candidate error for $sym: ".$e->getMessage());
                dump("Candidate error for $sym: ".$e->getMessage(). $e->getLine());
                continue;
            }
        }

        // sort by score desc
        usort($candidates, fn($a,$b) => $b['score'] <=> $a['score']);

        // optionally ask ChatGPT to re-rank top N (see below)
        return array_slice($candidates, 0, $top);
    }

    protected function ema(array $prices, int $period): float
    {
        $k = 2 / ($period + 1);
        $ema = (float)$prices[0];
        foreach ($prices as $p) {
            $ema = ($p * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    protected function rsi(array $prices, int $period): float
    {
        // простая реализация (Wilder) — возьмём delta
        $gains = 0.0; $losses = 0.0;
        for ($i = 1; $i < count($prices); $i++) {
            $delta = $prices[$i] - $prices[$i-1];
            if ($i <= $period) {
                if ($delta > 0) $gains += $delta;
                else $losses += abs($delta);
            }
        }
        if ($gains + $losses == 0) return 50.0;
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        if ($avgLoss == 0) return 100.0;
        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }


}
