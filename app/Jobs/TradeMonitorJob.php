<?php

namespace App\Jobs;

use App\Http\Services\BybitService;
use App\Http\Services\PromptService;
use App\Models\Trade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI;

class TradeMonitorJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public float $minConfidence = 0.55)
    {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(BybitService $bybit): void
    {
        $client = OpenAI::client(config('indicatorsecrets.openai_key'));

        // 1) Собираем примеры (последние прибыльные сделки)
        $examples = PromptService::profitableTradesExamples(5);


        // Берём все открытые сделки
        $openTrades = Trade::query()->where('status','open')->get();
        foreach ($openTrades as $t) {
            $last = $bybit->getLastPrice($t->symbol);
            if ($last <= 0) {
                continue;
            }

            // Текущее «бумажное» PnL (для контекста модели)
            $uPnL = ($last - (float)$t->price_entry) * (float)$t->qty_base;
            $uPct = ($last - (float)$t->price_entry) / (float)$t->price_entry * 100;

            // Схема решения от ChatGPT
            $schema = [
                'type' => 'object',
                'properties' => [
                    'action' => ['type'=>'string','enum'=>['sell','hold']],
                    'confidence' => ['type'=>'number','minimum'=>0,'maximum'=>1],
                    'reasoning' => ['type'=>'string'],
                ],
                'required' => ['action','confidence','reasoning'],
                'additionalProperties' => false,
            ];

            $prompt = "Ты трейд-ассистент. У нас открыта спот-позиция.Позиция:- symbol: {$t->symbol}- side: {$t->side}- qty_base: {$t->qty_base}- entry_price: {$t->price_entry}- now_price: {$last}- unrealized_pnl_quote: {$uPnL}- unrealized_pnl_pct: {$uPct}Правила:- Если сигнал слабый или противоречивый — выбирай hold.- Возвращай строго JSON по схеме. Вопрос:- Продавать сейчас (sell) или держать (hold)?";
            // 3) Строим промпт:
            $system = "Ты — трейдинг-ассистент. Отвечай только JSON согласно схеме. Правила:- Используй только переданные фичи и контекст.- Если сигналы слабые/противоречивые — HOLD.- Отвечай строго JSON по схеме.- Ориентируйся на примеры прибыльных сделок, если текущая ситуация похожа. $examples";
            $resp = $client->chat()->create([
                'model' => 'gpt-5',
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'ExitDecision',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
                'messages' => [
                    ['role'=>'system','content'=>$system],
                    ['role'=>'user', 'content'=>$prompt],
                ],
            ]);

            $json = json_decode($resp->choices[0]->message->content ?? '', true);

            if (!is_array($json)) { Log::channel('trade')->warning('LLM non-JSON'); continue; }

            $action = $json['action'] ?? 'hold';
            $conf   = (float)($json['confidence'] ?? 0);

            if ($action === 'sell' && $conf >= $this->minConfidence) {
                // Готовим market sell на ВСЮ базовую позицию
                $orderLinkId = 'autosell-'.Str::uuid()->toString();

                // Округление количества можно сделать по lotSizeFilter (если хочешь, добавь сюда запрос instruments-info)
                $baseQty = number_format((float)$t->qty_base, 8, '.', '');
                $sellResp = $bybit->placeSpotMarketSell($t->symbol, $baseQty, $orderLinkId);

                // Для простоты возьмём last как цену выхода (точную fill price можно вытянуть отдельным запросом)
                $exitPrice = $last;
                $pnlQuote  = ($exitPrice - (float)$t->price_entry) * (float)$t->qty_base;
                $pnlPct    = (($exitPrice / (float)$t->price_entry) - 1) * 100;

                $t->update([
                    'status'            => 'closed',
                    'price_exit'        => $exitPrice,
                    'ts_exit'           => (int)(microtime(true)*1000),
                    'exit_order_id'     => (string)($sellResp['result']['orderId'] ?? ''),
                    'exit_order_link_id'=> $orderLinkId,
                    'profit_quote'      => $pnlQuote,
                    'profit_pct'        => $pnlPct,
                    'reason_exit'       => $json['reasoning'] ?? '',
                ]);

            }else {
                $t->last_ai_comment = $json['reasoning'];
                $t->save();
            }
        }
    }
}
