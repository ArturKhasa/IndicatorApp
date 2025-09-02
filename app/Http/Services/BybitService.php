<?php

namespace App\Http\Services;

use Illuminate\Support\Facades\Http;

class BybitService
{
    protected string $base;
    protected string $apiKey;
    protected string $apiSecret;
    protected int    $recvWindow;

    public function __construct()
    {
        $this->base       = 'https://api.bybit.com';
        $this->apiKey     = config('indicatorsecrets.bybit_key');
        $this->apiSecret  = config('indicatorsecrets.bybit_secret');
        $this->recvWindow = 5000;
    }

    public function getKlines(string $symbol, string $interval = '15', string $category = 'spot', int $limit = 200): array
    {

        $resp = Http::get($this->base .'/v5/market/kline', [
            'category' => $category,   // spot | linear | inverse | option
            'symbol'   => $symbol,     // напр. BTCUSDT
            'interval' => $interval,   // 1,3,5,15,30,60,240, etc.
            'limit'    => $limit,
        ])->throw()->json();

        return $resp['result']['list'] ?? [];
    }
    /** Подпись для POST: timestamp + apiKey + recvWindow + jsonBodyString (HMAC SHA256, hex) */
    protected function signPost(array $body, int $ts): string
    {
        $plain = $ts.$this->apiKey.$this->recvWindow.json_encode($body, JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $plain, $this->apiSecret);
    }

    /** Подпись для GET: timestamp + apiKey + recvWindow + queryString (HMAC SHA256, hex) */
    protected function signGet(array $query, int $ts): string
    {
        ksort($query);
        $plain = $ts.$this->apiKey.$this->recvWindow.http_build_query($query);
        return hash_hmac('sha256', $plain, $this->apiSecret);
    }

    protected function headers(string $sign, int $ts): array
    {
        return [
            'X-BAPI-API-KEY'     => $this->apiKey,
            'X-BAPI-SIGN'        => $sign,
            'X-BAPI-TIMESTAMP'   => $ts,
            'X-BAPI-RECV-WINDOW' => $this->recvWindow,
            'Content-Type'       => 'application/json',
        ];
    }

    // --- Market helpers ---

    public function getTopByVolume(int $limit = 10, string $category = 'spot'): array
    {
        $resp = Http::get($this->base.'/v5/market/tickers', [
            'category' => $category,
        ])->throw()->json();

        $list = $resp['result']['list'] ?? [];

        // сортируем по объёму 24ч
        usort($list, function ($a, $b) {
            return (float)$b['volume24h'] <=> (float)$a['volume24h'];
        });

        return array_map(fn ($row) => $row['symbol'], array_slice($list, 0, $limit));
    }

    /** Информация об инструменте (для точности цены/кол-ва) */
    public function getInstrumentInfo(string $symbol): array
    {
        $ts    = (int) (microtime(true) * 1000);
        $query = ['category' => 'spot', 'symbol' => $symbol];
        $sign  = $this->signGet($query, $ts);

        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->get($this->base.'/v5/market/instruments-info', $query)
            ->throw()->json();

        return $resp['result']['list'][0] ?? [];
    }

    /** Баланс кошелька (ищем USDT) */
    public function getWalletBalance(string $coin = 'USDT'): float
    {
        $ts    = (int) (microtime(true) * 1000);
        $query = ['accountType' => 'UNIFIED'];
        $sign  = $this->signGet($query, $ts);

        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->get($this->base.'/v5/account/wallet-balance', $query)
            ->throw()->json();

        $list = $resp['result']['list'][0]['coin'] ?? [];
        foreach ($list as $row) {
            if (strcasecmp($row['coin'], $coin) === 0) {
                // Используем доступный к торговле баланс
                return (float)($row['walletBalance'] ?? 0);
            }
        }
        return 0.0;
    }

    /** Размещение SPOT MARKET BUY. qty — сумма в котируемой валюте (USDT). */
    public function placeSpotMarketBuy(string $symbol, string $quoteAmount, ?string $orderLinkId = null): array
    {
        $body = [
            'category'    => 'spot',
            'symbol'      => $symbol,
            'side'        => 'Buy',
            'orderType'   => 'Market',
            'qty'         => $quoteAmount, // сумма в QUOTE (например, USDT)
            'timeInForce' => 'ImmediateOrCancel', // для market можно не задавать; оставим дефолт/по желанию
        ];
        if ($orderLinkId) {
            $body['orderLinkId'] = $orderLinkId;
        }

        $ts   = (int) (microtime(true) * 1000);
        $sign = $this->signPost($body, $ts);

        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->post($this->base.'/v5/order/create', $body)
            ->throw()
            ->json();

        return $resp;
    }


    /** Продажа SPOT MARKET BUY. qty — сумма в котируемой валюте (USDT). */
    public function placeSpotMarketSell(string $symbol, string $baseQty, ?string $orderLinkId = null): array
    {
        $body = [
            'category'    => 'spot',
            'symbol'      => $symbol,
            'side'        => 'Sell',
            'orderType'   => 'Market',
            'qty'         => $baseQty, // КОЛ-ВО БАЗОВОЙ (BTC)
            'timeInForce' => 'ImmediateOrCancel',
        ];
        if ($orderLinkId) $body['orderLinkId'] = $orderLinkId;

        $ts   = (int) (microtime(true) * 1000);
        $sign = $this->signPost($body, $ts);

        $resp = \Illuminate\Support\Facades\Http::withHeaders($this->headers($sign, $ts))
            ->post($this->base.'/v5/order/create', $body)
            ->throw()
            ->json();

        return $resp;
    }

    // Вспомогалки округления по спецификации
    public static function roundToStep(float $value, string $step): string
    {
        // step типа "0.001" => определим необходимое число знаков
        $decimals = max(0, strpos(strrev(rtrim($step, '0')), '.') ?: 0);
        return number_format(floor($value / (float)$step) * (float)$step, $decimals, '.', '');
    }

    /** Последняя цена по тикеру (spot) */
    public function getLastPrice(string $symbol): float
    {
        $resp = Http::get($this->base.'/v5/market/tickers', [
            'category' => 'spot',
            'symbol'   => $symbol,
        ])->throw()->json();

        $row = $resp['result']['list'][0] ?? null;
        return $row ? (float)$row['lastPrice'] : 0.0;
    }

    public function getExecutionsByOrder(string $orderLinkId, string $category = 'spot'): array
    {
        $ts = (int) (microtime(true) * 1000);
        $query = ['category' => $category, 'orderLinkId' => $orderLinkId];
        $sign  = $this->signGet($query, $ts);

        $resp = \Illuminate\Support\Facades\Http::withHeaders($this->headers($sign, $ts))
            ->get($this->base.'/v5/execution/list', $query)
            ->throw()->json();

        return $resp['result']['list'] ?? [];
    }

    public function getOpenClosedOrderByLinkId(string $orderLinkId, string $category = 'spot'): array
    {
        $ts = (int) (microtime(true) * 1000);
        $query = ['category' => $category, 'orderLinkId' => $orderLinkId, 'openOnly' => 0];
        $sign  = $this->signGet($query, $ts);

        $resp = \Illuminate\Support\Facades\Http::withHeaders($this->headers($sign, $ts))
            ->get($this->base.'/v5/order/realtime', $query) // Open & Closed Orders
            ->throw()->json();

        return $resp['result']['list'][0] ?? [];
    }
}
