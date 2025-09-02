<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id            Уникальный ID сигнала от AI
 * @property string $symbol        Торговая пара (например, BTCUSDT)
 * @property string $tf            Таймфрейм сигнала (например, 15m)
 * @property int    $ts            Временная метка (мс) момента сигнала
 * @property string $action        Действие: buy или hold
 * @property float  $confidence    Уверенность AI [0..1]
 * @property int    $horizon_min   Горизонт прогноза (в минутах)
 * @property float  $max_risk_pct  Максимальный риск по сигналу (% от депозита)
 * @property string $reasoning     Обоснование рекомендации
 * @property array  $features      Фичи/индикаторы (json)
 */
class AiSignal extends Model
{
    protected $fillable = [
        'symbol','tf','ts','action','confidence','horizon_min','max_risk_pct','reasoning','features'
    ];
    protected $casts = [
        'features' => 'array',
    ];
}
