<?php

namespace App\Console\Commands;

use App\Http\Services\AiShortBotService;
use App\Http\Services\BybitService;
use App\Jobs\BuildFeaturesJob;
use App\Jobs\ExecuteAutoBuyJob;
use App\Jobs\TradeMonitorJob;
use App\Models\Trade;
use Illuminate\Console\Command;

class TestAiSignal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-ai-signal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(BybitService $bybitService)
    {
        $service = new AiShortBotService();
        dd($service->getShortCandidates());
//        $response = $bybitService->placeLinearShort('BTCUSDT', 0.001, 5);
//        $response = $bybitService->closeLinearShort('BTCUSDT', 0.001);
//        if ($response['retCode'] === 0) {
//            echo "✅ Шорт открыт успешно!";
//        } else {
//            echo "❌ Ошибка: " . $response['retMsg'];
//        }
//        ExecuteAutoBuyJob::dispatchSync('RATSUSDT', '5.0', 'Тестовая');
    }
}
