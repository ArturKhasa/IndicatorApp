<?php

use App\Console\Commands\TradeDispatcher;
use App\Console\Commands\UpdateTrades;
use App\Jobs\TradeMonitorJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command(TradeDispatcher::class)->everyTenMinutes();
Schedule::command(UpdateTrades::class)->everyFiveMinutes();
//Schedule::job(new TradeMonitorJob())->daily();
