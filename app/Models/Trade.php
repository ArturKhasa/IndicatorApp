<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $fillable = [
        'symbol', 'side', 'entry_price', 'exit_price',
        'qty', 'pnl_usd', 'status', 'opened_at', 'closed_at', 'source', 'pnl_percent', 'pnl_on_margin'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}

