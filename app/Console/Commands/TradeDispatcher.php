<?php

namespace App\Console\Commands;

use App\Http\Services\AiShortBotService;
use App\Http\Services\BybitService;
use App\Jobs\BuildFeaturesJob;
use App\Models\Trade;
use Illuminate\Console\Command;

class TradeDispatcher extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:trade-dispatcher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(AiShortBotService $aiShortBotService, BybitService $bybitService)
    {
        $coins = $aiShortBotService->getShortCandidates(10);
        $trades = Trade::query()->where('status', 'open')->pluck('symbol')->toArray();

        $diff = array_diff($coins, $trades);

        foreach ($diff as $coin) {
            Trade::query()->create([
                'symbol'      => $coin['symbol'],
                'side'        => 'Sell',
                'leverage' =>  5,
                'entry_price' => $bybitService->getLastPrice($coin['symbol']),
                'qty'         => 100,
                'status'      => 'open',
                'opened_at'   => now(),
                'source'      => 'ChatGPT',
            ]);
        }
    }
}
