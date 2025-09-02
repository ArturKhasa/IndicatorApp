<?php

namespace App\Console\Commands;

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
//        ExecuteAutoBuyJob::dispatchSync('RATSUSDT', '5.0', 'Тестовая');
    }
}
