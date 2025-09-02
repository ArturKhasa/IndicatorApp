<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_signals', function (Blueprint $t) {
            $t->id();
            $t->string('symbol')
                ->comment('Торговая пара, напр. BTCUSDT');

            $t->string('tf', 16)
                ->comment('Таймфрейм сигнала (например, 15m)');

            $t->unsignedBigInteger('ts')
                ->comment('Временная метка (мс) момента формирования сигнала');

            $t->enum('action', ['buy','hold'])
                ->comment('Действие, предложенное AI: buy или hold');

            $t->float('confidence')
                ->comment('Уверенность модели в решении [0..1]');

            $t->unsignedInteger('horizon_min')
                ->comment('Горизонт прогноза в минутах, на который рассчитан сигнал');

            $t->float('max_risk_pct')
                ->comment('Максимальный допустимый риск в % от депозита по этому сигналу');

            $t->text('reasoning')
                ->comment('Обоснование, которое дал AI для сигнала');

            $t->json('features')
                ->nullable()
                ->comment('Сырые технические фичи/индикаторы, на основе которых принят сигнал');
            $t->timestamps();
            $t->unique(['symbol','tf','ts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_signals');
    }
};
