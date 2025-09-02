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
        Schema::create('trades', function (Blueprint $t) {
            $t->id();
            $t->string('symbol')->comment('Торговая пара, напр. BTCUSDT');
            $t->enum('side', ['buy','sell'])->comment('Направление сделки (для открытия позиции = buy)');

            $t->decimal('qty_base', 28, 12)->comment('Количество базовой монеты (BTC, ETH и т.п.)');
            $t->decimal('price_entry', 28, 12)->comment('Цена входа в котируемой валюте (USDT и др.)');
            $t->unsignedBigInteger('ts_entry')->comment('Время входа (timestamp, мс)');
            $t->string('entry_order_id')->nullable()->comment('ID ордера на вход (от Bybit)');
            $t->string('entry_order_link_id')->nullable()->comment('Собственный orderLinkId на вход');

            $t->enum('status', ['open','closed'])->default('open')->comment('Статус сделки: открыта или закрыта');
            $t->decimal('price_exit', 28, 12)->nullable()->comment('Цена выхода в котируемой валюте');
            $t->unsignedBigInteger('ts_exit')->nullable()->comment('Время выхода (timestamp, мс)');
            $t->string('exit_order_id')->nullable()->comment('ID ордера на выход (от Bybit)');
            $t->string('exit_order_link_id')->nullable()->comment('Собственный orderLinkId на выход');

            $t->decimal('profit_quote', 28, 12)->nullable()->comment('Фиксированный профит/убыток в котируемой валюте');
            $t->decimal('profit_pct', 10, 4)->nullable()->comment('Доходность сделки в процентах');

            $t->text('reason_entry')->nullable()->comment('Обоснование входа (решение модели/правила)');
            $t->text('reason_exit')->nullable()->comment('Обоснование выхода (решение модели/правила)');
            $t->text('last_ai_comment')->nullable()->comment('Последний комментарий ChatGPT из TradeMonitorJob');

            $t->timestamps();
            $t->index(['symbol','status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
