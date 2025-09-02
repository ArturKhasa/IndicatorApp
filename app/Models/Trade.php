<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $symbol           Торговая пара (BTCUSDT и т.п.)
 * @property string $side             Направление сделки (buy/sell)
 * @property float  $qty_base         Количество базовой монеты
 * @property float  $price_entry      Цена входа
 * @property int    $ts_entry         Время входа (мс)
 * @property string $entry_order_id   ID ордера на вход (Bybit)
 * @property string $entry_order_link_id Пользовательский ID ордера на вход
 * @property string $status           Статус сделки (open/closed)
 * @property float  $price_exit       Цена выхода
 * @property int    $ts_exit          Время выхода (мс)
 * @property string $exit_order_id    ID ордера на выход (Bybit)
 * @property string $exit_order_link_id Пользовательский ID ордера на выход
 * @property float  $profit_quote     Профит/убыток в котируемой валюте
 * @property float  $profit_pct       Профит/убыток в %
 * @property string $reason_entry     Обоснование входа
 * @property string $reason_exit      Обоснование выхода
 * @property string $last_ai_comment  Последний комментарий ChatGPT из TradeMonitorJob
 */
class Trade extends Model
{
    protected $fillable = [
        'symbol','side','qty_base','price_entry','ts_entry','entry_order_id','entry_order_link_id',
        'status','price_exit','ts_exit','exit_order_id','exit_order_link_id',
        'profit_quote','profit_pct','reason_entry','reason_exit',
    ];

    protected $casts = [
        'qty_base'     => 'decimal:12',
        'price_entry'  => 'decimal:12',
        'price_exit'   => 'decimal:12',
        'profit_quote' => 'decimal:12',
        'profit_pct'   => 'decimal:4',
    ];
}
