<?php

namespace App\Jobs;

use App\Models\AiSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use OpenAI;

class AiSignalJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $features) {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = OpenAI::client(config('indicatorsecrets.openai_key'));
        $schema = [
            'type' => 'object',
            'properties' => [
                'action' => ['type'=>'string','enum'=>['buy','hold']],
                'confidence' => ['type'=>'number','minimum'=>0,'maximum'=>1],
                'horizon_min' => ['type'=>'integer','minimum'=>1],
                'max_risk_pct' => ['type'=>'number','minimum'=>0,'maximum'=>10],
                'reasoning' => ['type'=>'string'],
            ],
            'required' => ['action','confidence','horizon_min','max_risk_pct','reasoning'],
            'additionalProperties' => false,
        ];

        $prompt = "Ты — аналитик по крипторынку. Дай краткую рекомендацию на основе технических фич. Ограничения:- Не фантазируй: используй только предоставленные данные.- Учитывай шум: не сигналь при смешанных сигналах (action=hold).Выведи только JSON по схеме. Фичи:{$this->pretty($this->features)}";

        $resp = $client->chat()->create([
            'model' => 'gpt-5-mini', // или актуальная модель
            'response_format' =>
                ['type' => 'json_schema',
                    'json_schema' => [
                'name' => 'TradeSignal',
                'schema' => $schema
            ]],
            'messages' => [
                ['role'=>'system','content'=>'Ты выдаёшь только валидный JSON по заданной схеме.'],
                ['role'=>'user','content'=>$prompt],
            ],
        ]);

        $data = json_decode($resp->choices[0]->message->content, true);

        $this->assertValid($data);

        $signal = AiSignal::query()->updateOrCreate(
            [
                'symbol' => (string)($this->features['symbol'] ?? 'UNKNOWN'),
                'tf'     => (string)($this->features['tf'] ?? 'UNKNOWN'),
                'ts'     => (int)(microtime(true) * 1000)
            ],
            [
                'action'       => $data['action'],
                'confidence'   => (float)$data['confidence'],
                'horizon_min'  => (int)$data['horizon_min'],
                'max_risk_pct' => (float)$data['max_risk_pct'],
                'reasoning'    => (string)$data['reasoning'],
                'features'     => $this->features,
            ]
        );

        if($signal->action == 'buy' && (float)$data['confidence'] >= 0.7) {
            ExecuteAutoBuyJob::dispatch($signal->symbol, '10.0', $signal->reasoning);
        }
    }

    private function pretty(array $a): string {
        return json_encode($a, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }

    private function assertValid(array $d): void
    {
        $req = ['action','confidence','horizon_min','max_risk_pct','reasoning'];
        foreach ($req as $k) {
            if (!array_key_exists($k, $d)) {
                throw new \InvalidArgumentException("Нет обязательного поля: {$k}");
            }
        }
        if (!in_array($d['action'], ['buy','hold'], true)) {
            throw new \InvalidArgumentException("action должен быть buy|hold");
        }
        if (!is_numeric($d['confidence']) || $d['confidence'] < 0 || $d['confidence'] > 1) {
            throw new \InvalidArgumentException("confidence должен быть [0..1]");
        }
        if (!is_int($d['horizon_min']) && !(is_numeric($d['horizon_min']) && (int)$d['horizon_min']==$d['horizon_min'])) {
            throw new \InvalidArgumentException("horizon_min должен быть integer");
        }
        if (!is_numeric($d['max_risk_pct']) || $d['max_risk_pct'] < 0 || $d['max_risk_pct'] > 10) {
            throw new \InvalidArgumentException("max_risk_pct должен быть [0..10]");
        }
        if (!is_string($d['reasoning'])) {
            throw new \InvalidArgumentException("reasoning должен быть string");
        }
    }
}
