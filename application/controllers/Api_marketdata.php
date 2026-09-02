<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Api_marketdata extends Api_controller
{
    private const MARKET_CLASSES = ['forex', 'crypto', 'stock', 'etf', 'commodity', 'futures', 'options', 'indices', 'bonds'];
    private const TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h', '1d'];

    /**
     * One symbol per market class used by the live probe. Each is chosen so it
     * can only be served by a REAL provider — the synthetic fallback is never
     * mistaken for a live connection.
     */
    private const PROBE_SYMBOLS = [
        'crypto' => ['symbol' => 'BTCUSDT', 'timeframe' => '1h'],
        'forex' => ['symbol' => 'EURUSD', 'timeframe' => '1d'],
        'stock' => ['symbol' => 'AAPL', 'timeframe' => '1d'],
    ];

    public function candles()
    {
        $symbol = strtoupper((string)$this->input->get('symbol'));
        $timeframe = (string)($this->input->get('timeframe') ?: '1h');
        $limit = (int)($this->input->get('limit') ?: 200);
        $marketClass = (string)($this->input->get('marketClass') ?: $this->inferClass($symbol));
        if (strlen($symbol) < 2) return $this->jsonError('symbol required');
        if (!in_array($timeframe, ['1m', '5m', '15m', '1h', '4h', '1d'], true)) return $this->jsonError('invalid timeframe');
        if (!in_array($marketClass, self::MARKET_CLASSES, true)) return $this->jsonError('invalid marketClass');
        $limit = max(30, min(5000, $limit));
        try {
            $series = $this->platform->providers->getCandleSeries($symbol, $marketClass, $timeframe, $limit);
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 502);
        }
        $this->json($series);
    }

    public function quote()
    {
        $symbol = strtoupper((string)$this->input->get('symbol'));
        if (strlen($symbol) < 2) return $this->jsonError('symbol required');
        try {
            $this->json($this->platform->providers->getQuote($symbol));
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 502);
        }
    }

    /**
     * Live chart feed: candles + quote + an explicit live verdict.
     *
     * The UI polls this to keep the candlestick chart moving. It refuses to
     * claim LIVE for synthetic, stale or delayed data — the badge is derived
     * from the same provenance the server-rendered chart already shows, so
     * the two can never disagree.
     */
    public function live()
    {
        $symbol = strtoupper((string)$this->input->get('symbol'));
        $timeframe = (string)($this->input->get('timeframe') ?: '1h');
        $limit = (int)($this->input->get('limit') ?: 200);
        $marketClass = (string)($this->input->get('marketClass') ?: $this->inferClass($symbol));
        if (strlen($symbol) < 2) return $this->jsonError('symbol required');
        if (!in_array($timeframe, self::TIMEFRAMES, true)) return $this->jsonError('invalid timeframe');
        if (!in_array($marketClass, self::MARKET_CLASSES, true)) return $this->jsonError('invalid marketClass');
        $limit = max(30, min(1000, $limit));

        try {
            $series = $this->platform->providers->getCandleSeries($symbol, $marketClass, $timeframe, $limit);
        } catch (Throwable $e) {
            return $this->jsonError($e->getMessage(), 502);
        }

        $quote = null;
        try {
            $q = $this->platform->providers->getQuote($symbol);
            $quote = ['quote' => $q['quote'] ?? null, 'source' => $q['source'] ?? null, 'synthetic' => (bool)($q['synthetic'] ?? true)];
        } catch (Throwable $e) {
            // A quote is a convenience for the chart header; candles are the
            // contract. Never fail the whole feed because a quote was refused
            // (e.g. Frankfurter serves 1d reference rates only).
            $quote = ['quote' => null, 'source' => null, 'synthetic' => null, 'unavailable' => true];
        }

        $provenance = $series['provenance'];
        $this->json([
            'symbol' => $series['symbol'],
            'marketClass' => $series['marketClass'],
            'timeframe' => $series['timeframe'],
            'candles' => $series['candles'],
            'provenance' => $provenance,
            'validation' => $series['validation'] ?? null,
            'quote' => $quote,
            'live' => self::liveVerdict($provenance),
            'refreshSeconds' => self::refreshSeconds($timeframe),
            'serverTime' => (int)(microtime(true) * 1000),
        ]);
    }

    /**
     * Re-read the Admin → API provider store and rebuild the market-data
     * chain, then report what is actually serving. This is the "make it live
     * after connecting" switch: call it once a provider has been connected
     * and enabled and the chart starts streaming real bars on the next poll.
     *
     * GET-only and read-only with respect to trading: it touches the provider
     * registry, never the brokers, risk engine or execution supervisor.
     */
    public function refresh()
    {
        $result = $this->platform->refreshMarketDataProviders();

        $services = [];
        foreach (\AIWorkforce\ApiProviders::MARKET_DATA_SERVICES as $service) {
            $services[$service] = \AIWorkforce\ApiProviders::serviceState($this->AIWorkforce_model->db, $service);
        }

        $health = $this->platform->providers->getAllHealth(true);
        $live = [];
        foreach (self::PROBE_SYMBOLS as $class => $probe) {
            $live[$class] = $this->probe($probe['symbol'], $class, $probe['timeframe']);
        }

        $anyLive = false;
        foreach ($live as $v) {
            if (!empty($v['live'])) $anyLive = true;
        }

        $this->json([
            'ok' => true,
            'refreshed' => (bool)$result['refreshed'],
            'realProvidersAllowed' => (bool)$result['realProvidersAllowed'],
            'registered' => $result['registered'],
            'syntheticOnly' => (bool)$result['syntheticOnly'],
            'services' => $services,
            'health' => $health,
            'live' => $live,
            'marketDataLive' => $anyLive,
            'checkedAt' => (int)(microtime(true) * 1000),
        ]);
    }

    /** Fetch a short series and reduce it to an honest live/not-live verdict. */
    private function probe(string $symbol, string $marketClass, string $timeframe): array
    {
        try {
            $series = $this->platform->providers->getCandleSeries($symbol, $marketClass, $timeframe, 60);
        } catch (Throwable $e) {
            return ['live' => false, 'source' => null, 'reason' => 'NO_PROVIDER', 'detail' => $e->getMessage()];
        }
        $verdict = self::liveVerdict($series['provenance']);
        $verdict['symbol'] = $symbol;
        $verdict['bars'] = count($series['candles']);
        $verdict['lastClose'] = count($series['candles']) ? (float)(end($series['candles'])['close']) : null;
        return $verdict;
    }

    /**
     * Turn provenance into a badge the UI can render without guessing.
     * LIVE requires: a real (non-synthetic) source, fresh data, and no
     * provider delay. Anything else gets an explicit reason so the chart can
     * say exactly why it is not live instead of implying it.
     */
    private static function liveVerdict(array $provenance): array
    {
        $synthetic = (bool)($provenance['synthetic'] ?? true);
        $stale = (bool)($provenance['stale'] ?? true);
        $delayed = (bool)($provenance['delayed'] ?? true);
        $live = !$synthetic && !$stale && !$delayed;

        $reason = 'LIVE';
        if ($synthetic) $reason = 'SYNTHETIC';
        elseif ($stale) $reason = 'STALE';
        elseif ($delayed) $reason = 'DELAYED';

        return [
            'live' => $live,
            'reason' => $reason,
            'source' => $provenance['source'] ?? null,
            'synthetic' => $synthetic,
            'stale' => $stale,
            'delayed' => $delayed,
            'dataAgeMs' => isset($provenance['dataAgeMs']) ? (int)$provenance['dataAgeMs'] : null,
            'dataTimestamp' => isset($provenance['dataTimestamp']) ? (int)$provenance['dataTimestamp'] : null,
            'fetchedAt' => isset($provenance['fetchedAt']) ? (int)$provenance['fetchedAt'] : null,
            'fallbackChain' => $provenance['fallbackChain'] ?? [],
        ];
    }

    /**
     * Poll cadence for the live chart. Mirrors the server-side candle cache
     * TTL (25% of the bar period, clamped) so the browser never polls faster
     * than fresh data can exist, and never hammers a public endpoint.
     */
    private static function refreshSeconds(string $timeframe): int
    {
        $ms = \AIWorkforce\Timeframes::ms($timeframe);
        return (int)max(15, min(120, round(($ms / 1000) * 0.25)));
    }

    public function providers()
    {
        $registry = array_map(fn($p) => [
            'name' => $p->name(), 'synthetic' => $p->synthetic(), 'priority' => $p->priority(),
            'capabilities' => $p->capabilities(),
        ], $this->platform->providers->listProviders());
        $this->json([
            'providers' => $this->platform->providers->getAllHealth(true),
            'registry' => $registry,
        ]);
    }

    private function inferClass(string $symbol): string
    {
        return \AIWorkforce\MarketClasses::infer($symbol);
    }
}
