<?php

namespace App\Http\Services;

use App\Models\Trade;

class PromptService
{
    public static function profitableTradesExamples(int $limit = 5): string
    {
        $trades = Trade::query()
            ->where('status', 'closed')
            ->where('profit_quote', '>', 0)
            ->orderByDesc('ts_exit')
            ->limit($limit)
            ->get(['symbol','qty_base','price_entry','price_exit','profit_quote','profit_pct','reason_exit','ts_entry','ts_exit']);

        if ($trades->isEmpty()) {
            return "";
        }

        $lines = ["Примеры прибыльных сделок (история проекта):"];
        foreach ($trades as $i => $t) {
            $lines[] = sprintf(
                "%d) %s | вход=%.12f, выход=%.12f, qty=%.8f, PnL=%.2f USDT (%.2f%%)%s",
                $i+1,
                $t->symbol,
                (float)$t->price_entry,
                (float)$t->price_exit,
                (float)$t->qty_base,
                (float)$t->profit_quote,
                (float)$t->profit_pct,
                $t->reason_exit ? " | причина выхода: ".self::short($t->reason_exit) : ""
            );
        }
        return implode("\n", $lines);
    }

    /** Обрезаем длинные тексты, чтобы не раздувать промпт */
    protected static function short(?string $s, int $max = 160): string
    {
        if (!$s) return '';
        $s = trim($s);
        return mb_strlen($s) > $max ? (mb_substr($s, 0, $max-1).'…') : $s;
    }
}
