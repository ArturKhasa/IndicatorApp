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
        $list = array_filter($list, fn($row) => str_ends_with($row['symbol'], 'USDT'));

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

    public function getWalletCoin(string $coin) : float | null
    {
        $ts = (int)(microtime(true) * 1000);
        $query = ['accountType' => 'UNIFIED'];
        $sign  = $this->signGet($query, $ts);

        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->get($this->base.'/v5/account/wallet-balance', $query)
            ->throw()->json();

        foreach (($resp['result']['list'][0]['coin'] ?? []) as $row) {
            if (strcasecmp($row['coin'], $coin) === 0) {
                return $row['walletBalance'] ?? null; // здесь есть walletBalance/availableToTrade и т.д.
            }
        }
        return null;
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


    /**
     * Открывает фьючерсный SHORT по рынку.
     *
     * @param string $symbol   Например 'BTCUSDT'
     * @param float  $qty      Кол-во контрактов (например 0.001)
     * @param float  $leverage Плечо (по умолчанию 5x)
     * @param string|null $orderLinkId  Уникальный ID ордера (для трекинга)
     * @return array Ответ API
     */
    public function placeLinearShort(string $symbol, float $qty, float $leverage = 5, ?string $orderLinkId = null): array
    {
        // 1️⃣ Получаем текущее время (в миллисекундах)
        //    Это нужно для подписи запроса (Bybit проверяет "свежесть" запроса).
        $ts = (int)(microtime(true) * 1000);

        // 2️⃣ Формируем тело запроса для установки плеча.
        //    На Bybit для каждой пары нужно указать buyLeverage и sellLeverage.
        $leverageBody = [
            'category' => 'linear',
            'symbol' => $symbol,
            'buyLeverage' => (string)$leverage,
            'sellLeverage' => (string)$leverage,
        ];

        // 3️⃣ Создаём подпись для POST-запроса.
        //    Это HMAC SHA256: timestamp + apiKey + recvWindow + jsonBody.
        $leverageSign = $this->signPost($leverageBody, $ts);

        // 4️⃣ Отправляем POST-запрос на установку плеча.
        Http::withHeaders($this->headers($leverageSign, $ts))
            ->post($this->base . '/v5/position/set-leverage', $leverageBody)
            ->throw(); // если ошибка — Laravel выбросит исключение
        // 5️⃣ Теперь создаём тело ордера для SHORT позиции.
        $body = [
            'category'    => 'linear',          // тип рынка
            'symbol'      => $symbol,           // например BTCUSDT
            'side'        => 'Sell',            // значит SHORT
            'orderType'   => 'Market',          // рыночный ордер (сразу исполнится)
            'qty'         => (string)$qty,      // объём контракта
            'timeInForce' => 'GoodTillCancel',  // можно "ImmediateOrCancel"
        ];

        // 6️⃣ Если передали orderLinkId (уникальный ID ордера) — добавим.
        //    Это удобно, чтобы потом искать ордер по этому ID.
        if ($orderLinkId) {
            $body['orderLinkId'] = $orderLinkId;
        }

        // 7️⃣ Повторно генерируем timestamp и подпись.
        $ts   = (int)(microtime(true) * 1000);
        $sign = $this->signPost($body, $ts);

        // 8️⃣ Отправляем запрос на создание ордера (SHORT).
        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->post($this->base . '/v5/order/create', $body)
            ->throw()
            ->json();

        // 9️⃣ Возвращаем JSON-ответ от Bybit API.
        return $resp;
    }

    public function closeLinearShort(string $symbol, float $qty): array
    {
        $body = [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'side'        => 'Buy',          // обратная операция: закрывает шорт
            'orderType'   => 'Market',
            'qty'         => (string)$qty,
            'timeInForce' => 'GoodTillCancel',
        ];

        $ts   = (int)(microtime(true) * 1000);
        $sign = $this->signPost($body, $ts);

        $resp = Http::withHeaders($this->headers($sign, $ts))
            ->post($this->base . '/v5/order/create', $body)
            ->throw()
            ->json();

        return $resp;
    }
}
