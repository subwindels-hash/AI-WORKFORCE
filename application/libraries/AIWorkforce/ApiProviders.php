<?php
namespace AIWorkforce;

/**
 * Central Provider / API Management.
 *
 * Service → Provider → encrypted credentials → status.
 * Modules resolve active config here. Secrets never leave the server
 * in full, never appear in views, JS, audit logs or user errors.
 */
final class ApiProviders
{
    public const USER_UNAVAILABLE = 'This feature is temporarily unavailable. Please try again later.';

    public const SECRET_FIELDS = ['api_key', 'api_secret', 'token', 'password', 'client_secret'];

    /** @var callable|null */
    public static $http = null;

    private static bool $schemaReady = false;

    public static function services(): array
    {
        return [
            'lead_discovery' => [
                'label' => 'Lead Discovery',
                'group' => 'Lead Discovery',
                'kind' => 'data',
                'drivers' => ['google_places', 'apollo_io', 'custom_http'],
            ],
            'sports' => [
                'label' => 'Sports Intelligence',
                'group' => 'Sports Intelligence',
                'kind' => 'data',
                'drivers' => ['api_football', 'thesportsdb', 'sportmonks', 'http_sports', 'custom_http'],
            ],
            'lottery' => [
                'label' => 'Lottery / EuroMillions',
                'group' => 'EuroMillions',
                'kind' => 'data',
                'drivers' => ['loteriasapi', 'official_lottery', 'custom_http'],
            ],
            'crypto_market' => [
                'label' => 'Crypto Market Data',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['binance_public', 'bybit_public', 'okx_public', 'coinbase_public', 'kraken_public', 'alpaca_public', 'custom_http'],
            ],
            'forex_market' => [
                'label' => 'Forex Market Data',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['oanda_v20', 'frankfurter', 'custom_http'],
            ],
            'stock_market' => [
                'label' => 'Stock / ETF / Futures Market Data',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['alpaca_public', 'ibkr_gateway', 'custom_http'],
            ],
            'translation' => [
                'label' => 'Translation',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'cloudflare_workers_ai', 'libretranslate', 'custom_http'],
            ],
            'stt' => [
                'label' => 'Speech-to-Text',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['cloudflare_workers_ai', 'openai_compatible', 'browser_webspeech', 'custom_http'],
            ],
            'tts' => [
                'label' => 'Text-to-Speech',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'browser_webspeech', 'custom_http'],
            ],
            'language_ai' => [
                'label' => 'Language AI tutor',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'cloudflare_workers_ai', 'custom_http'],
            ],
            'llm' => [
                'label' => 'AI / LLM services',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'cloudflare_workers_ai', 'custom_http'],
            ],
            'pronunciation' => [
                'label' => 'Pronunciation scoring',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'browser_webspeech', 'custom_http'],
            ],
            'trading_execution' => [
                'label' => 'Trading / Execution (separate authorization)',
                'group' => 'AI Trading',
                'kind' => 'action',
                'drivers' => ['custom_http'],
            ],
            'text_embeddings' => [
                'label' => 'Text Embeddings / Vector Search',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['cloudflare_workers_ai', 'openai_compatible', 'custom_http'],
            ],
            'image_generation' => [
                'label' => 'Image Generation',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['cloudflare_workers_ai', 'custom_http'],
            ],
            'summarization' => [
                'label' => 'Text Summarization',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['cloudflare_workers_ai', 'openai_compatible', 'custom_http'],
            ],
            'classification' => [
                'label' => 'Text Classification / Sentiment',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['cloudflare_workers_ai', 'openai_compatible', 'custom_http'],
            ],
        ];
    }

    public static function drivers(): array
    {
        $f = fn(string $name, string $label, bool $secret = true, bool $required = false, string $hint = ''): array => [
            'name' => $name, 'label' => $label, 'secret' => $secret, 'required' => $required, 'hint' => $hint,
        ];
        return [
            'google_places' => [
                'label' => 'Google Places',
                'fields' => [
                    $f('api_key', 'API Key', true, true, 'Places API (New) key'),
                ],
            ],
            'apollo_io' => [
                'label' => 'Apollo.io',
                'fields' => [
                    $f('api_key', 'API Key', true, true, 'Apollo.io API key (Settings → API → API Keys). B2B people + company enrichment.'),
                ],
            ],
            'api_football' => [
                'label' => 'API-Football (api-football.com)',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Leave blank or use https://v3.football.api-sports.io — do not paste api-football.com / football.com (marketing sites are auto-rewritten)'),
                    $f('api_key', 'API Key (x-apisports-key)', true, true, 'Dashboard → Account → API key at https://dashboard.api-football.com/ (header: x-apisports-key)'),
                    $f('timeout', 'Timeout (seconds)', false, false),
                ],
            ],
            'thesportsdb' => [
                'label' => 'TheSportsDB (thesportsdb.com)',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://www.thesportsdb.com/api/v1/json'),
                    $f('api_key', 'API Key (tier key)', true, true, 'Free tier = "123" (the legacy "3" key is retired and now answers HTTP 400); get a paid key at https://www.thesportsdb.com'),
                    $f('timeout', 'Timeout (seconds)', false, false),
                ],
            ],
            'sportmonks' => [
                'label' => 'SportMonks (sportmonks.com)',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.sportmonks.com/v3/football'),
                    $f('api_key', 'API Token', true, true, 'Get yours at https://my.sportmonks.com/'),
                    $f('timeout', 'Timeout (seconds)', false, false),
                ],
            ],
            'http_sports' => [
                'label' => 'Sports HTTP feed',
                'fields' => [
                    $f('base_url', 'Base URL', false, true, 'HTTPS root exposing /fixtures and /health'),
                    $f('token', 'API token', true, false),
                    $f('timeout', 'Timeout (seconds)', false, false),
                    $f('sports', 'Sports covered', false, false, 'e.g. football,basketball,tennis'),
                ],
            ],
            'loteriasapi' => [
                'label' => 'LoteriasAPI (loteriasapi.com) — EuroMillions',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.loteriasapi.com/api/v1 — the /api prefix is required (a pasted /v1 or marketing URL is rewritten automatically)'),
                    $f('api_key', 'API Key (x-api-key)', true, true, 'Key from https://loteriasapi.com/auth/register (free tier available; plan limits at https://loteriasapi.com/planes)'),
                    $f('game', 'Game code', false, false, 'Defaults to euromillones ("euromillions" is accepted and normalized)'),
                    $f('timeout', 'Timeout (seconds)', false, false),
                ],
            ],
            'official_lottery' => [
                'label' => 'Authorized EuroMillions feed',
                'fields' => [
                    $f('base_url', 'Base URL', false, true),
                    $f('token', 'API token', true, false),
                    $f('license', 'License / contract ID', false, true),
                    $f('source', 'Source identifier', false, true),
                    $f('health_url', 'Health URL', false, false),
                    $f('jackpot_url', 'Jackpot URL', false, false),
                ],
            ],
            'binance_public' => [
                'label' => 'Binance public market data',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.binance.com — market data only, no trading'),
                ],
            ],
            'bybit_public' => [
                'label' => 'Bybit public market data',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.bybit.com (alt: https://api.bytick.com). Spot klines + tickers, no key.'),
                ],
            ],
            'okx_public' => [
                'label' => 'OKX public market data',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://www.okx.com. Public /api/v5/market endpoints.'),
                ],
            ],
            'coinbase_public' => [
                'label' => 'Coinbase Exchange public market data',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.exchange.coinbase.com. Public /products/{id}/candles.'),
                ],
            ],
            'kraken_public' => [
                'label' => 'Kraken public market data',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.kraken.com. Public /0/public/OHLC + /Ticker.'),
                ],
            ],
            'alpaca_public' => [
                'label' => 'Alpaca Markets (crypto public; equities keyed)',
                'fields' => [
                    $f('base_url', 'Data API base URL', false, false, 'Defaults to https://data.alpaca.markets. Crypto works without keys; equities require APCA key/secret.'),
                    $f('api_key', 'APCA-API-KEY-ID', true, false, 'Optional — unlocks US equities/ETFs'),
                ],
            ],
            'oanda_v20' => [
                'label' => 'OANDA v20 forex (token required)',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api-fxpractice.oanda.com. Use api-fxtrade.oanda.com for live.'),
                    $f('api_key', 'Bearer token', true, true, 'Personal access token for /v3/instruments/*'),
                ],
            ],
            'ibkr_gateway' => [
                'label' => 'Interactive Brokers Client Portal Gateway',
                'fields' => [
                    $f('base_url', 'Gateway URL', false, false, 'Defaults to https://localhost:5000 — must be running AND authenticated.'),
                ],
            ],
            'frankfurter' => [
                'label' => 'Frankfurter / ECB forex',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.frankfurter.dev'),
                ],
            ],
            'cloudflare_workers_ai' => [
                'label' => 'Cloudflare Workers AI',
                'fields' => [
                    $f('account_id', 'Cloudflare Account ID', false, true, 'Cloudflare dashboard → Account ID'),
                    $f('base_url', 'AI Gateway / API base URL', false, false, 'Defaults to https://api.cloudflare.com/client/v4/accounts/{account}/ai/run'),
                    $f('token', 'Cloudflare API token', true, true, 'Token needs Workers AI: Read permission; use a restricted token.'),
                    $f('model', 'Workers AI model', false, true, 'e.g. @cf/meta/llama-3.1-8b-instruct'),
                    $f('gateway', 'AI Gateway name', false, false, 'Optional gateway for observability, caching and rate limits'),
                ],
            ],
            'openai_compatible' => [
                'label' => 'OpenAI-compatible API',
                'fields' => [
                    $f('base_url', 'Base URL', false, true, 'e.g. https://api.openai.com/v1/chat/completions'),
                    $f('api_key', 'API Key', true, true),
                    $f('model', 'Model', false, true),
                    $f('organization', 'Organization ID', false, false),
                ],
            ],
            'libretranslate' => [
                'label' => 'LibreTranslate',
                'fields' => [
                    $f('base_url', 'Base URL', false, true),
                    $f('api_key', 'API Key', true, false),
                ],
            ],
            'browser_webspeech' => [
                'label' => 'Browser Web Speech (no server key)',
                'fields' => [],
            ],
            'custom_http' => [
                'label' => 'Custom HTTPS provider',
                'fields' => [
                    $f('base_url', 'Base URL', false, true),
                    $f('api_key', 'API Key', true, false),
                    $f('api_secret', 'API Secret', true, false),
                    $f('token', 'Bearer token', true, false),
                    $f('account_id', 'Account / Project ID', false, false),
                    $f('health_path', 'Health path', false, false, 'e.g. /health'),
                ],
            ],
        ];
    }

    public static function ensureSchema(object $db): void
    {
        if (self::$schemaReady) return;
        self::$schemaReady = true;
        $driver = (string) ($db->dbdriver ?? '');
        $sqlite = str_contains($driver, 'sqlite') || (string) ($db->subdriver ?? '') === 'sqlite';
        try {
            if ($sqlite) {
                $db->query("CREATE TABLE IF NOT EXISTS api_providers (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  service TEXT NOT NULL,
                  driver TEXT NOT NULL,
                  label TEXT NOT NULL,
                  enabled INTEGER NOT NULL DEFAULT 0,
                  role TEXT NOT NULL DEFAULT 'unused',
                  environment TEXT NOT NULL DEFAULT 'live',
                  base_url TEXT,
                  account_id TEXT,
                  extra_json TEXT,
                  secret_blob TEXT,
                  last_test_at TEXT,
                  last_test_ok INTEGER,
                  last_test_ms INTEGER,
                  last_test_message TEXT,
                  created_at TEXT NOT NULL,
                  updated_at TEXT NOT NULL,
                  updated_by INTEGER
                )");
                $db->query('CREATE INDEX IF NOT EXISTS idx_api_providers_service ON api_providers(service, enabled, role)');
            } else {
                $db->query("CREATE TABLE IF NOT EXISTS api_providers (
                  id INT AUTO_INCREMENT PRIMARY KEY,
                  service VARCHAR(64) NOT NULL,
                  driver VARCHAR(64) NOT NULL,
                  label VARCHAR(190) NOT NULL,
                  enabled TINYINT NOT NULL DEFAULT 0,
                  role VARCHAR(16) NOT NULL DEFAULT 'unused',
                  environment VARCHAR(16) NOT NULL DEFAULT 'live',
                  base_url VARCHAR(500) NULL,
                  account_id VARCHAR(190) NULL,
                  extra_json LONGTEXT NULL,
                  secret_blob LONGTEXT NULL,
                  last_test_at VARCHAR(32) NULL,
                  last_test_ok TINYINT NULL,
                  last_test_ms INT NULL,
                  last_test_message VARCHAR(255) NULL,
                  created_at VARCHAR(32) NOT NULL,
                  updated_at VARCHAR(32) NOT NULL,
                  updated_by INT NULL,
                  INDEX idx_api_providers_service (service, enabled, role)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
        } catch (\Throwable $e) { /* already exists */ }
    }

    public static function bind(object $db): void
    {
        self::ensureSchema($db);
    }

    /** Test helper: forget the schema cache and HTTP stub. */
    public static function reset(): void
    {
        self::$schemaReady = false;
        self::$http = null;
    }

    /** Public, secret-free status for member-facing modules. */
    public static function publicStatus(string $service): array
    {
        $cfg = self::resolve($service);
        return [
            'service' => $service,
            'configured' => is_array($cfg),
            'driver' => is_array($cfg) ? (string) ($cfg['driver'] ?? '') : null,
            'label' => is_array($cfg) ? (string) ($cfg['label'] ?? '') : null,
            'browserFallback' => in_array($service, ['stt', 'tts', 'pronunciation'], true),
        ];
    }

    public static function publicError(string $internal): string
    {
        $hay = strtolower($internal);
        foreach (['api key', 'api_key', 'secret', 'token', 'getenv', 'environment variable', 'not configured', 'unconfigured', 'unauthorized', '401', '403', 'missing'] as $needle) {
            if (str_contains($hay, $needle)) return self::USER_UNAVAILABLE;
        }
        return self::USER_UNAVAILABLE;
    }

    public static function mask(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') return '';
        $tail = substr($value, -4);
        return '••••••••••••' . $tail;
    }

    public static function seal(string $plain): string
    {
        $key = self::cryptoKey();
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new \RuntimeException('unable to encrypt provider secret');
        return base64_encode($iv . $tag . $ct);
    }

    public static function open(?string $blob): string
    {
        $blob = (string) $blob;
        if ($blob === '') return '';
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }

    private static function cryptoKey(): string
    {
        $raw = (string) (getenv('VP_ENCRYPTION_KEY') ?: getenv('AI_WORKFORCE_ENCRYPTION_KEY') ?: '');
        if ($raw === '') $raw = (defined('FCPATH') ? FCPATH : __DIR__) . '|windels-api-providers';
        return hash('sha256', $raw, true);
    }

    public static function list(object $db): array
    {
        self::ensureSchema($db);
        try { $rows = $db->order_by('service', 'ASC')->order_by('id', 'ASC')->get('api_providers')->result_array(); }
        catch (\Throwable $e) { return []; }
        return array_map(fn($r) => self::hydrate($r, false), is_array($rows) ? $rows : []);
    }

    public static function dashboard(object $db): array
    {
        $rows = self::list($db);
        $byService = [];
        foreach ($rows as $row) $byService[$row['service']][] = $row;
        $out = [];
        foreach (self::services() as $code => $meta) {
            $items = $byService[$code] ?? [];
            $primary = null;
            foreach ($items as $item) {
                if (!empty($item['enabled']) && ($item['role'] ?? '') === 'primary') { $primary = $item; break; }
            }
            if (!$primary) {
                foreach ($items as $item) {
                    if (!empty($item['enabled'])) { $primary = $item; break; }
                }
            }
            $status = 'Not configured';
            if ($primary) {
                if ((int) ($primary['last_test_ok'] ?? -1) === 1) $status = 'Connected';
                elseif ((int) ($primary['last_test_ok'] ?? -1) === 0) $status = 'Connection failed';
                elseif (empty($primary['enabled'])) $status = 'Disabled';
                else $status = 'Configured';
            }
            $out[] = [
                'service' => $code,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'kind' => $meta['kind'],
                'provider' => $primary,
                'providers' => $items,
                'status' => $status,
                'primary' => $primary !== null,
            ];
        }
        return $out;
    }

    public static function find(object $db, int $id): ?array
    {
        self::ensureSchema($db);
        $row = $db->get_where('api_providers', ['id' => $id], 1)->row_array();
        return $row ? self::hydrate($row, false) : null;
    }

    public static function findSecrets(object $db, int $id): array
    {
        $row = $db->get_where('api_providers', ['id' => $id], 1)->row_array();
        if (!$row) return [];
        $decoded = json_decode(self::open($row['secret_blob'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Active primary (then fallback) config with secrets — server-side only. */
    /** Resolve using the current request's database handle. */
    public static function resolve(string $service): ?array
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $db = ($ci && isset($ci->AIWorkforce_model)) ? $ci->AIWorkforce_model->db : null;
        if (!$db) return null;
        try { return self::activeConfig($db, $service); }
        catch (\Throwable $e) { return null; }
    }

    public static function enabled(string $service, bool $default = true): bool
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $db = ($ci && isset($ci->AIWorkforce_model)) ? $ci->AIWorkforce_model->db : null;
        if (!$db) return $default;
        try { return self::serviceEnabled($db, $service, $default); }
        catch (\Throwable $e) { return $default; }
    }

    public static function activeConfig(object $db, string $service): ?array
    {
        self::ensureSchema($db);
        foreach (['primary', 'fallback'] as $role) {
            $row = $db->where('service', $service)->where('enabled', 1)->where('role', $role)
                ->order_by('id', 'ASC')->limit(1)->get('api_providers')->row_array();
            if ($row) return self::hydrate($row, true);
        }
        $row = $db->where('service', $service)->where('enabled', 1)
            ->order_by('id', 'ASC')->limit(1)->get('api_providers')->row_array();
        return $row ? self::hydrate($row, true) : null;
    }

    public static function chain(object $db, string $service): array
    {
        $out = [];
        foreach (['primary', 'fallback'] as $role) {
            $row = $db->where('service', $service)->where('enabled', 1)->where('role', $role)
                ->order_by('id', 'ASC')->limit(1)->get('api_providers')->row_array();
            if ($row) $out[] = self::hydrate($row, true);
        }
        return $out;
    }

    public static function serviceEnabled(object $db, string $service, bool $default = true): bool
    {
        self::ensureSchema($db);
        $count = (int) $db->where('service', $service)->count_all_results('api_providers');
        if ($count === 0) return $default;
        return self::activeConfig($db, $service) !== null;
    }

    /**
     * Market-data services whose live provider registration is gated on this
     * store (see Platform::registerMarketDataProviders).
     */
    public const MARKET_DATA_SERVICES = ['crypto_market', 'forex_market', 'stock_market'];

    /**
     * Public, no-API-key market-data drivers. These are safe to switch on
     * programmatically because they need no credential and no license.
     */
    public const KEYLESS_MARKET_DRIVERS = [
        'crypto_market' => 'binance_public',
        'forex_market' => 'frankfurter',
        'stock_market' => null, // stocks require a key or the Yahoo delayed fallback
    ];

    /**
     * Full connection state for one service — the single source of truth used
     * by the admin dashboard, the market-data report and the live chart badge.
     *
     * 'configured' → at least one row exists for the service
     * 'live'       → an enabled row is actually serving (activeConfig !== null)
     *
     * The gap between those two is exactly the "connected but dark" state that
     * keeps market data off; activateKeylessFeed() closes it for public feeds.
     *
     * @return array{service:string,label:string,configured:bool,live:bool,driver:?string,base_url:?string,rows:int,enabled_rows:int,last_test_ok:?int}
     */
    public static function serviceState(object $db, string $service): array
    {
        self::ensureSchema($db);
        $meta = self::services()[$service] ?? ['label' => $service];
        $rows = 0;
        $enabledRows = 0;
        try {
            $rows = (int) $db->where('service', $service)->count_all_results('api_providers');
            $enabledRows = (int) $db->where('service', $service)->where('enabled', 1)
                ->count_all_results('api_providers');
        } catch (\Throwable $e) { /* schema unavailable — report as unconfigured */ }
        $active = null;
        try { $active = self::activeConfig($db, $service); } catch (\Throwable $e) { $active = null; }
        return [
            'service' => $service,
            'label' => (string) $meta['label'],
            'configured' => $rows > 0,
            'live' => $active !== null,
            'driver' => $active['driver'] ?? null,
            'base_url' => $active['base_url'] ?? null,
            'rows' => $rows,
            'enabled_rows' => $enabledRows,
            'last_test_ok' => $active['last_test_ok'] ?? null,
        ];
    }

    /**
     * Switch a connected-but-not-yet-serving public market-data feed to LIVE.
     *
     * Why this exists: adding a provider row for crypto_market / forex_market
     * makes serviceEnabled() stop defaulting to true, so a row saved with the
     * Enable box unticked silently drops the live feed back to the labelled
     * synthetic provider. This promotes the keyless public feed instead.
     *
     * Deliberately conservative — it never:
     *   • touches a service that is already live (operator intent wins),
     *   • enables custom_http or any licensed/credentialed driver,
     *   • creates rows; it only promotes one the operator already saved.
     *
     * @return array{ok:bool,service:string,action:string,detail:string,id?:int,label?:string,driver?:string}
     */
    public static function activateKeylessFeed(object $db, string $service): array
    {
        $keyless = self::KEYLESS_MARKET_DRIVERS[$service] ?? null;
        if ($keyless === null) {
            return ['ok' => false, 'service' => $service, 'action' => 'skipped',
                'detail' => 'Not a keyless public market-data service; enable it from Admin → API.'];
        }
        self::ensureSchema($db);
        $active = self::activeConfig($db, $service);
        if ($active !== null) {
            return ['ok' => true, 'service' => $service, 'action' => 'already_live',
                'detail' => 'Already serving live data.', 'id' => (int) ($active['id'] ?? 0),
                'label' => (string) ($active['label'] ?? ''), 'driver' => (string) ($active['driver'] ?? '')];
        }
        try {
            $row = $db->where('service', $service)->where('driver', $keyless)
                ->order_by('id', 'ASC')->limit(1)->get('api_providers')->row_array();
        } catch (\Throwable $e) { $row = null; }
        if (!$row) {
            return ['ok' => false, 'service' => $service, 'action' => 'not_connected',
                'detail' => 'No ' . $keyless . ' provider saved yet — add it in Admin → API first.'];
        }
        $id = (int) $row['id'];
        self::setEnabled($db, $id, true);
        self::setRole($db, $id, 'primary');
        return ['ok' => true, 'service' => $service, 'action' => 'activated',
            'detail' => 'Enabled and promoted to primary — market data is now live.',
            'id' => $id, 'label' => (string) ($row['label'] ?? ''), 'driver' => (string) ($row['driver'] ?? '')];
    }

    public static function save(object $db, array $input, ?int $id, ?int $actorId, bool $canSecrets): array
    {
        self::ensureSchema($db);
        $service = (string) ($input['service'] ?? '');
        $driver = (string) ($input['driver'] ?? '');
        if (!isset(self::services()[$service])) throw new \InvalidArgumentException('Unknown service category.');
        if (!isset(self::drivers()[$driver])) throw new \InvalidArgumentException('Unknown provider.');
        if (!in_array($driver, self::services()[$service]['drivers'], true)) {
            throw new \InvalidArgumentException('That provider cannot be used for this service.');
        }
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') $label = self::drivers()[$driver]['label'];
        $role = in_array($input['role'] ?? '', ['primary', 'fallback', 'unused'], true) ? $input['role'] : 'unused';
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $environment = in_array($input['environment'] ?? '', ['live', 'sandbox'], true) ? $input['environment'] : 'live';
        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        // API-Football's public/marketing hosts (including football.com) are
        // not API origins. Canonicalize them before validation so a copied
        // http://football.com URL cannot be saved and later tested as a dead
        // website endpoint.
        if ($driver === 'api_football' && $baseUrl !== '') {
            $baseUrl = self::normalizeApiFootballBaseUrl($baseUrl);
        }
        if ($baseUrl !== '' && !preg_match('#^https://#i', $baseUrl)) {
            throw new \InvalidArgumentException('Base URL must use HTTPS.');
        }
        $accountId = trim((string) ($input['account_id'] ?? ''));
        $extra = [];
        $secrets = [];
        foreach (self::drivers()[$driver]['fields'] as $field) {
            $name = $field['name'];
            $value = isset($input[$name]) ? trim((string) $input[$name]) : '';
            if (!empty($field['secret'])) {
                if ($value !== '') $secrets[$name] = $value;
            } elseif (!in_array($name, ['base_url', 'account_id'], true)) {
                if ($value !== '') $extra[$name] = $value;
            }
        }
        if (!empty($input['extra']) && is_array($input['extra'])) {
            foreach ($input['extra'] as $k => $v) {
                if (!is_string($k) || $k === '' || in_array($k, self::SECRET_FIELDS, true)) continue;
                $extra[$k] = is_scalar($v) ? (string) $v : '';
            }
        }

        $existing = $id ? $db->get_where('api_providers', ['id' => $id], 1)->row_array() : null;
        $mergedSecrets = $existing ? (json_decode(self::open($existing['secret_blob'] ?? ''), true) ?: []) : [];
        if (!$canSecrets && $existing) {
            $secrets = $mergedSecrets;
        } else {
            foreach ($secrets as $k => $v) $mergedSecrets[$k] = $v;
            $secrets = $mergedSecrets;
        }

        $now = gmdate('c');
        $row = [
            'service' => $service,
            'driver' => $driver,
            'label' => mb_substr($label, 0, 190),
            'enabled' => $enabled,
            'role' => $role,
            'environment' => $environment,
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'account_id' => $accountId !== '' ? $accountId : null,
            'extra_json' => $extra ? json_encode($extra) : null,
            'secret_blob' => $secrets ? self::seal(json_encode($secrets)) : ($existing['secret_blob'] ?? null),
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];
        if ($existing) {
            $db->where('id', $id)->update('api_providers', $row);
        } else {
            $row['created_at'] = $now;
            $db->insert('api_providers', $row);
            $id = (int) $db->insert_id();
        }
        if ($enabled && $role === 'primary') self::demoteOthers($db, $service, (int) $id);
        return self::find($db, (int) $id) ?? [];
    }

    public static function setEnabled(object $db, int $id, bool $enabled): void
    {
        $db->where('id', $id)->update('api_providers', ['enabled' => $enabled ? 1 : 0, 'updated_at' => gmdate('c')]);
    }

    public static function setRole(object $db, int $id, string $role): void
    {
        if (!in_array($role, ['primary', 'fallback', 'unused'], true)) return;
        $row = $db->get_where('api_providers', ['id' => $id], 1)->row_array();
        if (!$row) return;
        $db->where('id', $id)->update('api_providers', ['role' => $role, 'updated_at' => gmdate('c')]);
        if ($role === 'primary') self::demoteOthers($db, (string) $row['service'], $id);
    }

    public static function delete(object $db, int $id): void
    {
        $db->where('id', $id)->delete('api_providers');
    }

    private static function demoteOthers(object $db, string $service, int $keepId): void
    {
        $db->where('service', $service)->where('id !=', $keepId)->where('role', 'primary')
            ->update('api_providers', ['role' => 'fallback', 'updated_at' => gmdate('c')]);
    }

    public static function recordTest(object $db, int $id, array $result): void
    {
        $db->where('id', $id)->update('api_providers', [
            'last_test_at' => gmdate('c'),
            'last_test_ok' => !empty($result['ok']) ? 1 : 0,
            'last_test_ms' => isset($result['ms']) ? (int) $result['ms'] : null,
            'last_test_message' => mb_substr(self::sanitizeTestMessage((string) ($result['message'] ?? '')), 0, 255),
            'updated_at' => gmdate('c'),
        ]);
    }

    public static function test(array $row, array $secrets = []): array
    {
        $t0 = microtime(true);
        $driver = (string) ($row['driver'] ?? '');
        $base = rtrim((string) ($row['base_url'] ?? ''), '/');
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        try {
            $ok = match ($driver) {
                'google_places' => self::testGooglePlaces((string) ($secrets['api_key'] ?? '')),
                'apollo_io' => self::testApollo((string) ($secrets['api_key'] ?? '')),
                'binance_public' => self::testGet(($base !== '' ? $base : 'https://api.binance.com') . '/api/v3/ping'),
                'bybit_public'   => self::testGet(($base !== '' ? $base : 'https://api.bybit.com') . '/v5/market/time'),
                'okx_public'     => self::testGet(($base !== '' ? $base : 'https://www.okx.com') . '/api/v5/public/time'),
                'coinbase_public'=> self::testGet(($base !== '' ? $base : 'https://api.exchange.coinbase.com') . '/products/BTC-USD/ticker'),
                'kraken_public'  => self::testGet(($base !== '' ? $base : 'https://api.kraken.com') . '/0/public/Time'),
                'alpaca_public'  => self::testGet(($base !== '' ? $base : 'https://data.alpaca.markets') . '/v1beta3/crypto/us/bars?symbols=BTC%2FUSD&timeframe=1Min&limit=1', $secrets['api_key'] ?? ''),
                'oanda_v20'      => self::testGet(($base !== '' ? $base : 'https://api-fxpractice.oanda.com') . '/v3/accounts', $secrets['api_key'] ?? $secrets['token'] ?? ''),
                'ibkr_gateway'   => self::testGet(($base !== '' ? $base : 'https://localhost:5000') . '/v1/api/tickle'),
                'frankfurter' => self::testGet(($base !== '' ? $base : 'https://api.frankfurter.dev') . '/v1/latest?base=EUR&symbols=USD'),
                'http_sports' => self::testGet(($base !== '' ? $base : '') . '/health', $secrets['token'] ?? $secrets['api_key'] ?? ''),
                'api_football' => self::testApiFootball(($base !== '' ? $base : 'https://v3.football.api-sports.io'), $secrets['api_key'] ?? ''),
                'thesportsdb' => self::testTheSportsDb(($base !== '' ? $base : 'https://www.thesportsdb.com/api/v1/json'), $secrets['api_key'] ?? '123'),
                'sportmonks' => self::testSportMonks(($base !== '' ? $base : 'https://api.sportmonks.com/v3/football'), $secrets['api_key'] ?? ''),
                'loteriasapi' => self::testLoteriasApi((string) ($row['base_url'] ?? ''), (string) ($secrets['api_key'] ?? $secrets['token'] ?? ''), (string) ($extra['game'] ?? '')),
                'official_lottery' => self::testGet((string) ($extra['health_url'] ?? ($base . '/health')), $secrets['token'] ?? $secrets['api_key'] ?? ''),
                'libretranslate' => self::testGet(($base !== '' ? $base : '') . '/languages'),
                'openai_compatible' => self::testOpenAi($base, (string) ($secrets['api_key'] ?? '')),
                'cloudflare_workers_ai' => self::testCloudflare($row, $secrets),
                'browser_webspeech' => ['ok' => true, 'message' => 'Browser Web Speech needs no server credential.'],
                'custom_http' => self::testGet($base . ((string) ($extra['health_path'] ?? '/health')), $secrets['token'] ?? $secrets['api_key'] ?? ''),
                default => ['ok' => false, 'message' => 'No test is defined for this provider.'],
            };
            if (is_bool($ok)) $ok = ['ok' => $ok, 'message' => $ok ? 'Connected' : 'Connection failed'];
        } catch (\Throwable $e) {
            $ok = ['ok' => false, 'message' => self::sanitizeTestMessage($e->getMessage())];
        }
        $ok['ms'] = (int) round((microtime(true) - $t0) * 1000);
        $ok['message'] = self::sanitizeTestMessage((string) ($ok['message'] ?? ($ok['ok'] ? 'Connected' : 'Connection failed')));
        return $ok;
    }

    private static function sanitizeTestMessage(string $msg): string
    {
        $msg = preg_replace('/(sk-|Bearer\s+|key=)[A-Za-z0-9_\-]{6,}/i', '$1••••', $msg) ?? $msg;
        $msg = preg_replace('#https?://[^\s]+@#', 'https://••••@', $msg) ?? $msg;
        return mb_substr($msg, 0, 180);
    }

    private static function testGet(string $url, string $token = ''): array
    {
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return ['ok' => false, 'message' => 'A valid HTTPS URL is required to test this provider.'];
        }
        $resp = self::http($url, $token !== '' ? ['Authorization: Bearer ' . $token] : []);
        $status = (int) ($resp['status'] ?? 0);
        if ($status >= 200 && $status < 400) return ['ok' => true, 'message' => 'Connected'];
        if ($status === 401 || $status === 403) return ['ok' => false, 'message' => 'Connection failed'];
        if ($status === 0) return ['ok' => false, 'message' => 'Connection failed'];
        return ['ok' => $status < 500, 'message' => $status < 500 ? 'Connected' : 'Connection failed'];
    }

    private static function testGooglePlaces(string $key): array
    {
        if ($key === '') return ['ok' => false, 'message' => 'An API key is required.'];
        $resp = self::http('https://places.googleapis.com/v1/places:searchText', [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $key,
            'X-Goog-FieldMask: places.id',
        ], json_encode(['textQuery' => 'cafe', 'maxResultCount' => 1]));
        $status = (int) ($resp['status'] ?? 0);
        return ['ok' => $status >= 200 && $status < 400, 'message' => ($status >= 200 && $status < 400) ? 'Connected' : 'Connection failed'];
    }

    private static function testApollo(string $key): array
    {
        if ($key === '') return ['ok' => false, 'message' => 'An API key is required.'];
        // Apollo uses POST with the api_key in the JSON body for /v1/auth/health,
        // but a cheap /mixed_people/search with per_page=0 is the canonical ping
        // that returns 200 for valid keys and 401 for bad/missing keys.
        $resp = self::http('https://api.apollo.io/api/v1/mixed_people/search', [
            'Content-Type: application/json',
            'Accept: application/json',
        ], json_encode(['api_key' => $key, 'page' => 1, 'per_page' => 1, 'q_keywords' => 'test']));
        $status = (int) ($resp['status'] ?? 0);
        if ($status >= 200 && $status < 400) return ['ok' => true, 'message' => 'Connected'];
        if ($status === 401 || $status === 403) return ['ok' => false, 'message' => 'Invalid API key'];
        return ['ok' => false, 'message' => 'Connection failed'];
    }

    private static function testCloudflare(array $row, array $secrets): array
    {
        $account = (string)($row['account_id'] ?? ''); $token = (string)($secrets['token'] ?? '');
        $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        $model = (string)($extra['model'] ?? '@cf/meta/llama-3.1-8b-instruct');
        if ($account === '' || $token === '') return ['ok'=>false,'message'=>'Cloudflare account ID and token are required.'];
        $url = rtrim((string)($row['base_url'] ?? ''), '/');
        if ($url === '') $url = 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($account).'/ai/run/'.rawurlencode($model);
        $r = self::http($url, ['Authorization: Bearer '.$token, 'Content-Type: application/json'], json_encode(['prompt'=>'Reply with OK.']));
        $status=(int)($r['status']??0); return ['ok'=>$status>=200&&$status<400,'message'=>$status>=200&&$status<400?'Connected':'Connection failed'];
    }

    private static function testOpenAi(string $url, string $key): array
    {
        if ($url === '' || $key === '') return ['ok' => false, 'message' => 'Base URL and API key are required.'];
        $models = preg_replace('#/chat/completions/?$#', '/models', rtrim($url, '/'));
        if ($models === $url) $models = rtrim($url, '/') . '/models';
        $resp = self::http($models, ['Authorization: Bearer ' . $key]);
        $status = (int) ($resp['status'] ?? 0);
        return ['ok' => $status >= 200 && $status < 400, 'message' => ($status >= 200 && $status < 400) ? 'Connected' : 'Connection failed'];
    }

    /**
     * Map marketing / RapidAPI hostnames onto a real API-Football v3 root.
     *
     * Operators frequently paste the product site (api-football.com / football.com)
     * or a markdown-wrapped URL. Those are not API origins — without this remap
     * Test Connection hits a website and reports "Connection failed".
     */
    public static function normalizeApiFootballBaseUrl(string $baseUrl): string
    {
        $base = trim($baseUrl);
        // Strip accidental markdown / link wrappers: [http://football.com](http://football.com)
        $base = preg_replace('#^\[[^\]]*\]\((https?://[^)\s]+)\)\s*$#i', '$1', $base) ?? $base;
        $base = preg_replace('#^<\s*(https?://[^>\s]+)\s*>$#i', '$1', $base) ?? $base;
        $base = rtrim(trim($base), "/ \t");
        if ($base === '' || strcasecmp($base, 'default') === 0 || strcasecmp($base, 'auto') === 0) {
            return 'https://v3.football.api-sports.io';
        }
        // parse_url treats a bare hostname as a path. Accept it because
        // provider settings are often pasted without a scheme.
        $urlForParsing = preg_match('#^https?://#i', $base) ? $base : 'https://' . ltrim($base, '/');
        $parts = parse_url($urlForParsing);
        $host = strtolower((string) ($parts['host'] ?? ''));
        // If parse_url still only saw a path (e.g. "api-football.com/docs"), use first segment.
        if ($host === '' && isset($parts['path'])) {
            $host = strtolower((string) explode('/', ltrim((string) $parts['path'], '/'))[0]);
        }
        $host = preg_replace('#:\d+$#', '', $host) ?? $host;
        $marketing = [
            'api-football.com', 'www.api-football.com', 'dashboard.api-football.com',
            'football.com', 'www.football.com', 'api.football.com',
            'v3.api-football.com', 'api-sports.io', 'www.api-sports.io',
        ];
        if (in_array($host, $marketing, true) || str_ends_with($host, '.api-football.com')) {
            return 'https://v3.football.api-sports.io';
        }
        if ($host === 'v3.football.api-sports.io' || $host === 'football.api-sports.io') {
            return 'https://v3.football.api-sports.io';
        }
        if (str_contains($host, 'rapidapi.com')) {
            // Canonical RapidAPI API-Football v3 root.
            if ($host === 'api-football-v1.p.rapidapi.com' || str_contains($host, 'api-football')) {
                return 'https://api-football-v1.p.rapidapi.com/v3';
            }
            if (!str_ends_with(rtrim($base, '/'), '/v3')) {
                $base = rtrim($urlForParsing, '/') . '/v3';
            } else {
                $base = rtrim($urlForParsing, '/');
            }
            // Force https even if the operator pasted http://
            if (str_starts_with(strtolower($base), 'http://')) {
                $base = 'https://' . substr($base, 7);
            }
            return $base;
        }
        // Any leftover non-https paste becomes https so save() + test() agree.
        if (!preg_match('#^https://#i', $base)) {
            $base = 'https://' . preg_replace('#^https?://#i', '', $urlForParsing);
        }
        return rtrim($base, '/');
    }

    private static function testApiFootball(string $baseUrl, string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'message' => 'An API key is required. Register at https://dashboard.api-football.com/'];
        }
        $base = self::normalizeApiFootballBaseUrl($baseUrl);
        $url = rtrim($base, '/') . '/status';
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));
        $headers = [
            'Accept: application/json',
            'x-apisports-key: ' . $key,
        ];
        if (str_contains($host, 'rapidapi.com')) {
            $headers[] = 'x-rapidapi-key: ' . $key;
            $headers[] = 'x-rapidapi-host: ' . $host;
        }
        $resp = self::http($url, $headers);
        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) $decoded = [];

        // Vendor may return HTTP 200 with errors: { token: "..." } or errors: ["..."].
        $errors = $decoded['errors'] ?? null;
        $hasErrors = false;
        $errorText = '';
        if (is_array($errors) && $errors !== []) {
            $hasErrors = true;
            $flat = [];
            foreach ($errors as $k => $v) {
                if (is_string($v) && $v !== '') $flat[] = $v;
                elseif (is_string($k) && is_scalar($v)) $flat[] = $k . ': ' . (string) $v;
                elseif (is_string($k)) $flat[] = $k;
            }
            $errorText = strtolower(implode(' ', $flat));
        } elseif (is_string($errors) && trim($errors) !== '') {
            $hasErrors = true;
            $errorText = strtolower($errors);
        }

        $response = is_array($decoded['response'] ?? null) ? $decoded['response'] : [];
        // A real /status payload is JSON with response/account/subscription/requests
        // (or at least get=status). Plain HTML from a marketing host must not pass.
        $looksLikeStatus = $response !== []
            || (isset($decoded['get']) && (string) $decoded['get'] === 'status')
            || isset($decoded['results'])
            || isset($decoded['paging']);

        if ($status >= 200 && $status < 400 && !$hasErrors && $looksLikeStatus) {
            // Never do arithmetic on the whole requests object (PHP 8 TypeError → false "Connection failed").
            $requests = is_array($response['requests'] ?? null) ? $response['requests'] : [];
            $limit = null;
            $used = null;
            if (isset($requests['limit_day']) && is_numeric($requests['limit_day'])) {
                $limit = (int) $requests['limit_day'];
            }
            if (isset($requests['current']) && is_numeric($requests['current'])) {
                $used = (int) $requests['current'];
            } elseif (isset($requests['used']) && is_numeric($requests['used'])) {
                $used = (int) $requests['used'];
            }
            $msg = 'Connected to API-Football';
            if ($limit !== null && $used !== null) {
                $msg .= ' (' . max(0, $limit - $used) . ' of ' . $limit . ' requests remaining today)';
            } elseif ($limit !== null) {
                $msg .= ' (daily limit ' . $limit . ')';
            }
            return ['ok' => true, 'message' => $msg];
        }

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'message' => 'Invalid API key'];
        }
        if ($hasErrors) {
            if (str_contains($errorText, 'token') || str_contains($errorText, 'key') || str_contains($errorText, 'auth')) {
                return ['ok' => false, 'message' => 'Invalid API key'];
            }
            return ['ok' => false, 'message' => 'API-Football rejected the request'];
        }
        if ($status === 429) return ['ok' => false, 'message' => 'Rate limited — try again later'];
        if ($status === 0) {
            return ['ok' => false, 'message' => 'Could not reach API-Football (network/SSL/firewall). Check outbound HTTPS to v3.football.api-sports.io'];
        }
        return ['ok' => false, 'message' => 'Connection failed (HTTP ' . $status . ')'];
    }

    private static function testLoteriasApi(string $baseUrl, string $key, string $game): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'message' => 'An API key is required. Get a key (free tier available) at https://loteriasapi.com/auth/register — plan limits: https://loteriasapi.com/planes'];
        }
        // Same canonicalisation the runtime adapter applies: the vendor serves
        // the API under /api/v1 — the /v1 root its marketing pages advertise
        // answers 404 on every route.
        $base = \AIWorkforce\Lottery\LoteriasApiProvider::normalizeBaseUrl($baseUrl);
        $game = \AIWorkforce\Lottery\LoteriasApiProvider::normalizeGame($game);
        $url = rtrim($base, '/') . '/results/' . rawurlencode($game) . '/latest';
        $resp = self::http($url, ['Accept: application/json', 'x-api-key: ' . $key]);
        $status = (int) ($resp['status'] ?? 0);
        $decoded = json_decode((string) ($resp['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        if ($status >= 200 && $status < 300) {
            // The vendor reports errors inside a 200 body as { success: false, error: { code } }.
            if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
                $code = strtoupper((string) ($decoded['error']['code'] ?? ''));
                if (in_array($code, ['UNAUTHORIZED', 'FORBIDDEN'], true)) {
                    return ['ok' => false, 'message' => 'Invalid API key'];
                }
                return ['ok' => false, 'message' => 'LoteriasAPI rejected the request' . ($code !== '' ? ' (' . $code . ')' : '')];
            }
            $numbers = $data['combination'] ?? ($data['numbers'] ?? null);
            if (is_array($numbers) && $numbers !== []) {
                $rawDate = $data['drawDate'] ?? ($data['draw_date'] ?? null);
                $date = is_scalar($rawDate) ? (string) $rawDate : '';
                return ['ok' => true, 'message' => 'Connected to LoteriasAPI (' . $game . ')' . ($date !== '' ? ' — latest draw ' . $date : '')];
            }
            return ['ok' => false, 'message' => 'LoteriasAPI responded without a draw payload — check the game code (default: euromillones)'];
        }
        if ($status === 401 || $status === 403) return ['ok' => false, 'message' => 'Invalid API key'];
        if ($status === 404) return ['ok' => false, 'message' => 'Endpoint not found (HTTP 404) — base URL must be https://api.loteriasapi.com/api/v1 (the /api prefix is required: the /v1 root answers 404 on every route)'];
        if ($status === 429) return ['ok' => false, 'message' => 'Rate limited — the plan request quota is exhausted (limits: https://loteriasapi.com/planes)'];
        if ($status === 0) return ['ok' => false, 'message' => 'Could not reach LoteriasAPI (network/SSL/firewall). Check outbound HTTPS to api.loteriasapi.com'];
        return ['ok' => false, 'message' => 'Connection failed (HTTP ' . $status . ')'];
    }

    private static function testTheSportsDb(string $baseUrl, string $key): array
    {
        // Same canonicalisation the runtime adapter applies: the legacy free key
        // "3" became "123" and a base URL that already carries the key (or the
        // premium-only v2 root) would otherwise 400 on every request.
        $key = \AIWorkforce\Sports\Providers\TheSportsDbProvider::normalizeKey($key);
        $baseUrl = \AIWorkforce\Sports\Providers\TheSportsDbProvider::normalizeBaseUrl($baseUrl);
        $url = rtrim($baseUrl, '/') . '/' . rawurlencode($key) . '/all_sports.php';
        $resp = self::http($url, ['Accept: application/json']);
        $status = (int) ($resp['status'] ?? 0);
        $decoded = json_decode($resp['body'] ?? '', true);
        if ($status >= 200 && $status < 400) {
            if (!empty($decoded['sports'])) {
                $tier = $key === \AIWorkforce\Sports\Providers\TheSportsDbProvider::FREE_KEY ? 'Free tier' : 'Premium tier';
                return ['ok' => true, 'message' => 'Connected to TheSportsDB (' . $tier . ')'];
            }
            if (isset($decoded['error'])) return ['ok' => false, 'message' => 'Invalid API key or tier'];
            return ['ok' => true, 'message' => 'Connected to TheSportsDB'];
        }
        if ($status === 401 || $status === 403) return ['ok' => false, 'message' => 'Invalid API key'];
        if ($status === 400) return ['ok' => false, 'message' => 'TheSportsDB rejected the request (HTTP 400) — the key is not a valid tier key (free tier is "123") or the base URL is wrong'];
        if ($status === 404) return ['ok' => false, 'message' => 'TheSportsDB endpoint not found (HTTP 404) — base URL must be https://www.thesportsdb.com/api/v1/json'];
        if ($status === 429) return ['ok' => false, 'message' => 'Rate limited (free tier allows 30 requests/minute) — try again later'];
        return ['ok' => false, 'message' => 'Connection failed' . ($status > 0 ? ' (HTTP ' . $status . ')' : ' (no HTTP response)')];
    }

    private static function testSportMonks(string $baseUrl, string $key): array
    {
        if ($key === '') return ['ok' => false, 'message' => 'An API token is required. Register at https://my.sportmonks.com/'];
        $baseUrl = \AIWorkforce\Sports\Providers\SportMonksProvider::normalizeBaseUrl($baseUrl);
        $url = rtrim($baseUrl, '/') . '/leagues?api_token=' . rawurlencode($key);
        $resp = self::http($url, ['Accept: application/json']);
        $status = (int) ($resp['status'] ?? 0);
        $decoded = json_decode($resp['body'] ?? '', true);
        if ($status >= 200 && $status < 400 && !empty($decoded['data'])) {
            $count = count($decoded['data']);
            return ['ok' => true, 'message' => 'Connected to SportMonks (' . $count . ' leagues available)'];
        }
        if ($status === 401 || $status === 403) return ['ok' => false, 'message' => 'Invalid API token'];
        if ($status === 404) return ['ok' => false, 'message' => 'SportMonks endpoint not found (HTTP 404) — base URL must be https://api.sportmonks.com/v3/football (v2 hosts and the marketing site 404 on every call)'];
        if ($status === 429) return ['ok' => false, 'message' => 'Rate limited — try again later'];
        return ['ok' => false, 'message' => 'Connection failed'];
    }

    /**
     * Outbound HTTP for provider connection tests.
     *
     * Prefer cURL when available (typical on cPanel; works when allow_url_fopen
     * is off). Fall back to file_get_contents streams. Always returns a status
     * so callers can distinguish network failure (0) from HTTP errors.
     *
     * @return array{status:int,body:string}
     */
    public static function http(string $url, array $headers = [], ?string $body = null): array
    {
        if (is_callable(self::$http)) return (self::$http)($url, $headers, $body);

        $method = $body === null ? 'GET' : 'POST';
        $headerList = ['Accept: application/json', 'User-Agent: WINDELS-API-Management/1.0'];
        foreach ($headers as $h) {
            $h = trim((string) $h);
            if ($h === '') continue;
            // Avoid duplicating Accept / User-Agent when callers pass them.
            if (preg_match('#^(Accept|User-Agent)\s*:#i', $h)) {
                $headerList = array_values(array_filter(
                    $headerList,
                    static fn(string $existing): bool => !preg_match('#^' . preg_quote(strtok($h, ':'), '#') . '\s*:#i', $existing)
                ));
            }
            $headerList[] = $h;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT => 12,
                    CURLOPT_HTTPHEADER => $headerList,
                    CURLOPT_USERAGENT => 'WINDELS-API-Management/1.0',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING => '',
                ]);
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
                }
                $raw = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errno = curl_errno($ch);
                curl_close($ch);
                if ($raw !== false) {
                    return ['status' => $status > 0 ? $status : ($errno ? 0 : 0), 'body' => (string) $raw];
                }
                // Fall through to streams if cURL failed to produce a body and
                // reported a transport error — some hosts mis-configure cURL CA.
                if ($status > 0) {
                    return ['status' => $status, 'body' => ''];
                }
            }
        }

        if (!ini_get('allow_url_fopen')) {
            return ['status' => 0, 'body' => ''];
        }

        $hdr = '';
        foreach ($headerList as $h) $hdr .= $h . "\r\n";
        $http = [
            'method' => $method,
            'timeout' => 12,
            'ignore_errors' => true,
            'header' => $hdr,
        ];
        if ($body !== null) $http['content'] = $body;
        $ctx = stream_context_create([
            'http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; break; }
        }
        return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
    }

    private static function hydrate(array $row, bool $withSecrets): array
    {
        $extra = json_decode((string) ($row['extra_json'] ?? ''), true);
        $secrets = json_decode(self::open($row['secret_blob'] ?? ''), true);
        if (!is_array($extra)) $extra = [];
        if (!is_array($secrets)) $secrets = [];
        $masked = [];
        foreach ($secrets as $k => $v) $masked[$k] = self::mask((string) $v);
        $out = [
            'id' => (int) $row['id'],
            'service' => (string) $row['service'],
            'driver' => (string) $row['driver'],
            'label' => (string) $row['label'],
            'enabled' => !empty($row['enabled']),
            'role' => (string) $row['role'],
            'environment' => (string) ($row['environment'] ?? 'live'),
            'base_url' => (string) ($row['base_url'] ?? ''),
            'account_id' => (string) ($row['account_id'] ?? ''),
            'extra' => $extra,
            'masked' => $masked,
            'has_secrets' => $secrets !== [],
            'last_test_at' => $row['last_test_at'] ?? null,
            'last_test_ok' => isset($row['last_test_ok']) ? (int) $row['last_test_ok'] : null,
            'last_test_ms' => isset($row['last_test_ms']) ? (int) $row['last_test_ms'] : null,
            'last_test_message' => $row['last_test_message'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        if ($withSecrets) $out['secrets'] = $secrets;
        return $out;
    }

    public static function openaiChat(array $cfg, array $messages, int $maxTokens = 260): ?string
    {
        $driver = (string)($cfg['driver'] ?? '');
        $url = trim((string) ($cfg['base_url'] ?? ''));
        $model = (string) ($cfg['extra']['model'] ?? '');
        $key = (string) ($cfg['secrets']['api_key'] ?? $cfg['secrets']['token'] ?? '');
        if ($driver === 'cloudflare_workers_ai') {
            $account = (string)($cfg['account_id'] ?? '');
            if ($url === '') $url = 'https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($account).'/ai/run/'.rawurlencode($model);
            if ($account !== '' && ($cfg['extra']['gateway'] ?? '') !== '') $url = 'https://gateway.ai.cloudflare.com/v1/'.rawurlencode($account).'/'.rawurlencode((string)$cfg['extra']['gateway']).'/workers-ai/'.rawurlencode($model);
        }
        if ($url === '' || $key === '' || $model === '') return null;
        $body = $driver === 'cloudflare_workers_ai'
            ? json_encode(['messages' => $messages, 'max_tokens' => $maxTokens], JSON_UNESCAPED_SLASHES)
            : json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $maxTokens], JSON_UNESCAPED_SLASHES);
        $resp = self::http($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], $body);
        $payload = json_decode($resp['body'] ?? '', true);
        $answer = $payload['choices'][0]['message']['content'] ?? null;
        return is_string($answer) && trim($answer) !== '' ? mb_substr(trim($answer), 0, 4000) : null;
    }

    /** Server-side translation via the configured provider. Returns null when unused or unavailable. */
    public static function translateText(array $cfg, string $text, string $source, string $target): ?string
    {
        $driver = (string) ($cfg['driver'] ?? '');
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $key = (string) ($cfg['secrets']['api_key'] ?? $cfg['secrets']['token'] ?? '');
        try {
            if ($driver === 'libretranslate') {
                if ($base === '') return null;
                $payload = ['q' => $text, 'source' => $source !== '' ? $source : 'auto', 'target' => $target, 'format' => 'text'];
                if ($key !== '') $payload['api_key'] = $key;
                $resp = self::http($base . '/translate', ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE));
                $decoded = json_decode((string) ($resp['body'] ?? ''), true);
                $out = is_array($decoded) ? ($decoded['translatedText'] ?? null) : null;
                return is_string($out) && trim($out) !== '' ? mb_substr(trim($out), 0, 2000) : null;
            }
            if ($driver === 'openai_compatible') {
                return self::openaiChat($cfg, [
                    ['role' => 'system', 'content' => 'Translate the user text from ' . ($source !== '' ? $source : 'auto-detected language') . ' to ' . $target . '. Return only the translation, with no quotes or commentary.'],
                    ['role' => 'user', 'content' => $text],
                ], 400);
            }
            if ($driver === 'custom_http') {
                if ($base === '') return null;
                $headers = ['Content-Type: application/json'];
                if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
                $resp = self::http($base . '/translate', $headers, json_encode(['q' => $text, 'source' => $source, 'target' => $target], JSON_UNESCAPED_UNICODE));
                $decoded = json_decode((string) ($resp['body'] ?? ''), true);
                if (!is_array($decoded)) return null;
                $out = $decoded['translatedText'] ?? ($decoded['translation'] ?? ($decoded['text'] ?? null));
                return is_string($out) && trim($out) !== '' ? mb_substr(trim($out), 0, 2000) : null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}
