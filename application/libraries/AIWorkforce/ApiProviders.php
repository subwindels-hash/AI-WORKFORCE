<?php
namespace AIWorkforce;

use AIWorkforce\Providers\GrokProvider;
use AIWorkforce\Providers\OpenAIProvider;

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

    /** Persistent cache version for the request-time api_providers DDL guard. */
    private const SCHEMA_STAMP_VERSION = '2026-09-06-api-providers-schema-v1';

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
            'economic_calendar' => [
                'label' => 'Economic Calendar',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['economic_calendar_feed'],
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
                'drivers' => ['openai_compatible', 'grok', 'libretranslate', 'custom_http'],
            ],
            'stt' => [
                'label' => 'Speech-to-Text',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'browser_webspeech', 'custom_http'],
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
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
            ],
            'llm' => [
                'label' => 'AI / LLM services',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
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
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
            ],
            'image_generation' => [
                'label' => 'Image Generation',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'custom_http'],
            ],
            'summarization' => [
                'label' => 'Text Summarization',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
            ],
            'classification' => [
                'label' => 'Text Classification / Sentiment',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
            ],
            'moderation' => [
                'label' => 'Content Moderation',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'grok', 'custom_http'],
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
                    $f('base_url', 'Base URL', false, false, 'Leave blank for https://api.apollo.io — apollo.io / app.apollo.io are not API origins and are rewritten automatically'),
                    $f('api_key', 'API Key (x-api-key header)', true, true, 'Apollo → Settings → Integrations → API Keys (https://developer.apollo.io/#/keys). Apollo keys are scoped per endpoint: tick mixed_people/api_search (plus people/bulk_match to reveal contacts) or toggle “Set as master key”. People Search needs the plan to include API access — free/personal-email signups cannot use it.'),
                    $f('reveal_contacts', 'Reveal emails/phones (1 = on, 0 = off)', false, false, 'Apollo search never returns emails or phone numbers. Set 1 to enrich each search through people/bulk_match — this spends Apollo credits (1 credit per record). Default 0.'),
                    $f('reveal_personal_emails', 'Reveal personal emails (1 = on, 0 = off)', false, false, 'Personal (gmail/outlook/yahoo …) addresses are what Person Mode filters on. Defaults to the Reveal setting above.'),
                    $f('reveal_phone_number', 'Reveal phone numbers (1 = on, 0 = off)', false, false, 'Mobile/direct dial reveals cost extra Apollo credits. Default 0.'),
                    $f('reveal_limit', 'Max enrichments per search', false, false, 'Credit cap per search, 1–100. Default 25.'),
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
            'economic_calendar_feed' => [
                'label' => 'Economic calendar feed (JSON)',
                'fields' => [
                    $f('base_url', 'Feed URL', false, true, 'HTTPS endpoint returning {"events":[{"at":"2026-09-04T12:30:00Z","name":"Nonfarm Payrolls","impact":"high","currency":"USD"}]} — a bare JSON array also works'),
                    $f('token', 'API token', true, false, 'Sent as an X-Api-Key header when set'),
                    $f('timeout', 'Timeout (seconds)', false, false),
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
            'openai_compatible' => [
                'label' => 'OpenAI (or OpenAI-compatible) API',
                'fields' => [
                    $f('base_url', 'Base URL', false, true, 'e.g. https://api.openai.com/v1 — leave blank to use OpenAI'),
                    $f('api_key', 'API Key', true, true),
                    $f('model', 'Model', false, true, 'e.g. gpt-4o-mini (chat/LLM), text-embedding-3-small (embeddings), dall-e-3 (images)'),
                    $f('organization', 'Organization ID', false, false),
                    $f('project', 'Project ID', false, false, 'Optional OpenAI project'),
                ],
            ],
            'grok' => [
                'label' => 'xAI Grok (x.ai)',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.x.ai/v1 — console.x.ai and team URLs are automatically normalized'),
                    $f('api_key', 'API Key (xai-…)', true, true, 'API key from xAI Console (https://console.x.ai/)'),
                    $f('model', 'Model', false, false, 'Defaults to grok-2-latest (alt: grok-2, grok-2-mini, grok-2-vision-1212, grok-beta, grok-3, grok-3-mini)'),
                    $f('team_id', 'Team ID', false, false, 'Optional xAI Team ID (e.g. 97c85eb6-af25-4109-8554-4c6cbff12ee8 from console URL)'),
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
        $pgsql = str_contains(strtolower($driver), 'pgsql') || str_contains(strtolower($driver), 'postgre')
            || strtolower((string) ($db->subdriver ?? '')) === 'pgsql';

        // Called indirectly from Platform bootstrap on normal page loads. Since
        // each PHP request starts fresh, the static guard alone still caused a
        // CREATE TABLE/INDEX probe every request. Skip the DDL path once a prior
        // healthy request verified the provider store for this database.
        if (self::schemaStampFresh($db, $sqlite, $pgsql)) return;

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
            } elseif ($pgsql) {
                $db->query("CREATE TABLE IF NOT EXISTS api_providers (
                  id SERIAL PRIMARY KEY,
                  service VARCHAR(64) NOT NULL,
                  driver VARCHAR(64) NOT NULL,
                  label VARCHAR(190) NOT NULL,
                  enabled SMALLINT NOT NULL DEFAULT 0,
                  role VARCHAR(16) NOT NULL DEFAULT 'unused',
                  environment VARCHAR(16) NOT NULL DEFAULT 'live',
                  base_url VARCHAR(500) NULL,
                  account_id VARCHAR(190) NULL,
                  extra_json TEXT NULL,
                  secret_blob TEXT NULL,
                  last_test_at VARCHAR(32) NULL,
                  last_test_ok SMALLINT NULL,
                  last_test_ms INTEGER NULL,
                  last_test_message VARCHAR(255) NULL,
                  created_at VARCHAR(32) NOT NULL,
                  updated_at VARCHAR(32) NOT NULL,
                  updated_by INTEGER NULL
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
        try {
            if ($db->table_exists('api_providers')) self::writeSchemaStamp($db, $sqlite, $pgsql);
        } catch (\Throwable $e) { /* leave unstamped so a later request can retry */ }
    }

    private static function schemaStampPath(object $db, bool $sqlite, bool $pgsql): string
    {
        $cacheDir = defined('APPPATH') ? rtrim((string) APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'cache' : sys_get_temp_dir();
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        if (!is_writable($cacheDir)) $cacheDir = sys_get_temp_dir();
        $dialect = $sqlite ? 'sqlite' : ($pgsql ? 'pgsql' : 'mysql');
        $dbKey = hash('sha256', implode('|', [
            $dialect,
            (string) ($db->hostname ?? ''),
            (string) ($db->database ?? ''),
            (string) ($db->dsn ?? ''),
            (string) ($db->subdriver ?? ''),
        ]));
        return rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'ai_workforce_api_providers_' . $dbKey . '.stamp.json';
    }

    private static function schemaStampFresh(object $db, bool $sqlite, bool $pgsql): bool
    {
        $path = self::schemaStampPath($db, $sqlite, $pgsql);
        if (!is_file($path)) return false;
        $stamp = json_decode((string) @file_get_contents($path), true);
        return is_array($stamp) && ($stamp['version'] ?? null) === self::SCHEMA_STAMP_VERSION;
    }

    private static function writeSchemaStamp(object $db, bool $sqlite, bool $pgsql): void
    {
        @file_put_contents(self::schemaStampPath($db, $sqlite, $pgsql), json_encode([
            'version' => self::SCHEMA_STAMP_VERSION,
            'written_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES));
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

    /**
     * Member-facing reason for a provider failure that already carried a safe,
     * actionable message (the Lead Discovery providers never echo credentials).
     *
     * publicError() flattens every cause — missing key, wrong scope, rate limit,
     * vendor outage — into the same opaque "This feature is temporarily
     * unavailable." line, which hid why a search that passed Admin → Test
     * Connection still returned nothing. For ProviderException we already hold a
     * secret-free message built by the adapter, so surface it. As a safety net
     * we still fall back to USER_UNAVAILABLE if the text itself looks like a
     * leaked credential, and we always cap the length at 255 characters.
     */
    public static function providerMessage(string $internal): string
    {
        $msg = trim((string) $internal);
        if ($msg === '') return self::USER_UNAVAILABLE;
        $hay = strtolower($msg);
        foreach (['sk-', 'secret=', 'client_secret=', 'api_key=', 'apikey=', 'x-api-key', 'authorization: bearer', 'bearer ', 'password=', 'getenv '] as $needle) {
            if (str_contains($hay, $needle)) return self::USER_UNAVAILABLE;
        }
        return mb_substr($msg, 0, 255);
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

    /**
     * Active config for **one driver** of a service (primary → fallback → any
     * other enabled row), with secrets — server-side only.
     *
     * A service such as `lead_discovery` runs several providers side by side,
     * and `activeConfig()` only ever returns the single active row. Without a
     * driver-scoped lookup, a provider configured as a fallback looks
     * "not configured" at runtime — or worse, is handed the other provider's
     * key (Google Places used to read whichever row was primary).
     *
     * Pass `$enabledOnly = false` to find a saved-but-disabled row, which lets
     * callers tell "never configured" apart from "switched off".
     */
    public static function resolveDriver(object $db, string $service, string $driver, bool $enabledOnly = true): ?array
    {
        self::ensureSchema($db);
        foreach (['primary', 'fallback', null] as $role) {
            $db->where('service', $service)->where('driver', $driver);
            if ($enabledOnly) $db->where('enabled', 1);
            if ($role !== null) $db->where('role', $role);
            $row = $db->order_by('id', 'ASC')->limit(1)->get('api_providers')->row_array();
            if ($row) return self::hydrate($row, true);
        }
        return null;
    }

    /** resolveDriver() against the current request's database handle. */
    public static function resolveDriverForRequest(string $service, string $driver, bool $enabledOnly = true): ?array
    {
        $ci = function_exists('get_instance') ? get_instance() : null;
        $db = ($ci && isset($ci->AIWorkforce_model)) ? $ci->AIWorkforce_model->db : null;
        if (!$db) return null;
        try { return self::resolveDriver($db, $service, $driver, $enabledOnly); }
        catch (\Throwable $e) { return null; }
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
     * Server-side API-Football credential resolver.
     *
     * `API_FOOTBALL_KEY` is the documented primary environment variable;
     * `WINDELS_API_FOOTBALL_KEY` remains a fully-supported legacy alias so an
     * existing deployment never breaks. Real server environment variables win
     * over the .env file because vp_load_env never overrides them. The base
     * URL resolves the same way (API_FOOTBALL_BASE_URL →
     * WINDELS_API_FOOTBALL_BASE_URL → the canonical v3 host) — a marketing
     * host (api-football.com) is canonicalized onto the real API root by the
     * adapter, so a wrong URL cannot leak the key anywhere else.
     *
     * This is the ONLY place the football key variable names are spelled out;
     * SportsIntelligence::registerProviders() and the admin hints read them
     * from here so a new alias can never be added in one file and missed in
     * another.
     *
     * @return array{key:string, baseUrl:string}
     */
    public static function footballCredential(): array
    {
        $key = '';
        foreach (['API_FOOTBALL_KEY', 'WINDELS_API_FOOTBALL_KEY'] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                $key = trim($value);
                break;
            }
        }
        $base = '';
        foreach (['API_FOOTBALL_BASE_URL', 'WINDELS_API_FOOTBALL_BASE_URL'] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                $base = trim($value);
                break;
            }
        }
        return [
            'key' => $key,
            // The adapter re-canonicalizes this host-side as well; the default
            // here just keeps the historical constant in one place.
            'baseUrl' => $base !== '' ? $base : 'https://v3.football.api-sports.io',
        ];
    }

    /** Every environment variable name footballCredential() may read, for admin hints. */
    public static function footballKeyEnvNames(): array
    {
        return ['API_FOOTBALL_KEY', 'WINDELS_API_FOOTBALL_KEY'];
    }


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
        $extractedTeamId = null;
        if ($driver === 'grok') {
            $baseUrl = self::normalizeGrokBaseUrl($baseUrl, $extractedTeamId);
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
        if ($driver === 'grok' && !empty($extractedTeamId) && empty($extra['team_id'])) {
            $extra['team_id'] = $extractedTeamId;
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
                'apollo_io' => self::testApollo((string) ($secrets['api_key'] ?? ''), $row),
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
                'grok' => self::testGrok($base, (string) ($secrets['api_key'] ?? ''), $extra),
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
        // Apollo authenticates with an `x-api-key` header; mask it too, in case
        // a staged/proxy error text ever carries the header line back.
        $msg = preg_replace('/(x-api-key\s*[:=]\s*)([A-Za-z0-9_\-]{4,})/i', '$1••••', $msg) ?? $msg;
        $msg = preg_replace('#https?://[^\s]+@#', 'https://••••@', $msg) ?? $msg;
        // 255 matches the provider row's last_test_message column, so what the
        // operator sees in the flash is exactly what the page stores and shows.
        return mb_substr($msg, 0, 255);
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

    /**
     * Test an Apollo.io key the way Apollo documents, then — because Apollo keys
     * are **scoped per endpoint** and the People Search endpoint is not granted
     * to every key/plan — verify against the endpoint this app really calls.
     *
     * Probe 1: GET  {root}/api/v1/auth/health   (0 credits, documented key check)
     *          → 200 {"healthy":true,"is_logged_in":true} confirms the key itself
     *            is valid; 401 is final (the key is wrong). A 200 here is NOT by
     *            itself a pass: auth/health cannot tell whether the People Search
     *            endpoint is in this key's scope or plan.
     * Probe 2: POST {root}/api/v1/mixed_people/api_search?per_page=1&q_keywords=apollo
     *          (0 credits) — the real connectivity test. A scoped or limited key
     *          can authenticate on auth/health yet answer HTTP 403 API_INACCESSIBLE
     *          here, so this test only reports Connected when the endpoint Lead
     *          Discovery actually calls is usable.
     *
     * Neither probe spends credits and neither echoes the key back.
     */
    private static function testApollo(string $key, array $row = []): array
    {
        $raw = trim($key);
        if ($raw === '') {
            return ['ok' => false, 'message' => 'An Apollo API key is required. Create one in Apollo → Settings → Integrations → API Keys (developer.apollo.io), then paste the full value here.'];
        }
        // The form masks stored secrets; pasting the mask back saves a value
        // that can never authenticate. Say so instead of "Connection failed".
        if (str_contains($raw, "\u{2022}")) {
            return ['ok' => false, 'message' => 'That is the masked placeholder, not a key. Open the provider, paste the full Apollo API key and save again.'];
        }
        $key = self::normalizeApolloKey($raw);
        if ($key === '') {
            return ['ok' => false, 'message' => 'An Apollo API key is required. Create one in Apollo → Settings → Integrations → API Keys (developer.apollo.io), then paste the full value here.'];
        }
        $root = self::apolloApiRoot($row);
        // When contact reveal is switched on the key also needs the enrichment
        // endpoint scope, so a successful test says so instead of letting the
        // first search silently come back without emails.
        $rowExtra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
        $revealRaw = (string) ($rowExtra['reveal_contacts'] ?? '');
        if ($revealRaw === '') {
            $revealEnv = getenv('APOLLO_IO_REVEAL_CONTACTS');
            $revealRaw = $revealEnv === false ? '' : (string) $revealEnv;
        }
        $revealNote = in_array(strtolower(trim($revealRaw)), ['1', 'true', 'on', 'yes'], true)
            ? ' Contact reveal is on — the key also needs the people/bulk_match endpoint scope.'
            : '';
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Cache-Control: no-cache',
            'x-api-key: ' . $key,
            'User-Agent: WINDELS-AIWorkforce/1.0 (+api-management)',
        ];

        // ---- Probe 1: documented, credit-free key check ----
        // auth/health answers whether the KEY itself is valid before we spend a
        // probe on the search endpoint. 401 means the key is wrong — final.
        $r1 = self::http($root . '/api/v1/auth/health', $headers);
        $s1 = (int) ($r1['status'] ?? 0);
        $b1 = self::decode($r1['body'] ?? '');

        if ($s1 === 401) {
            return ['ok' => false, 'message' => 'Invalid Apollo API key (HTTP 401 on auth/health). Regenerate it in Apollo → Settings → Integrations → API Keys and paste the full value.'];
        }
        if ($s1 === 429) return ['ok' => false, 'message' => 'Apollo rate limit reached (HTTP 429) — run the test again in a minute.'];
        if ($s1 === 0) return ['ok' => false, 'message' => self::apolloNetworkMessage($r1, $root)];
        if ($s1 >= 500) return ['ok' => false, 'message' => 'Apollo.io server error on auth/health (HTTP ' . $s1 . ') — retry the test shortly.'];

        if ($s1 >= 200 && $s1 < 300) {
            // The key authenticates. auth/health alone cannot tell us whether the
            // People Search endpoint is in the key's scope or plan, so rule out
            // key-level problems first and then ALWAYS continue to the search probe.
            if (($b1['is_logged_in'] ?? null) === false) {
                return ['ok' => false, 'message' => 'Apollo says this key is not signed in. Regenerate it in Apollo → Settings → Integrations → API Keys and paste the full value.'];
            }
            if (($b1['healthy'] ?? null) === false) {
                return ['ok' => false, 'message' => 'Apollo reported this key as unhealthy (auth/health: healthy=false). Regenerate the key and check the plan has API access.'];
            }
            $raw1 = trim((string) ($r1['body'] ?? ''));
            if ($b1 === [] && $raw1 !== '') {
                // A 200 with a non-JSON body is an intercepting proxy or the wrong
                // base URL — a search probe would be intercepted the same way.
                return ['ok' => false, 'message' => 'auth/health answered HTTP 200 with a non-JSON body ('
                    . mb_substr($raw1, 0, 60) . '…) — a proxy is intercepting the request, or the Base URL is not api.apollo.io.'];
            }
        }
        // auth/health refused (403/404/422) or passed (200) — either way the key
        // may still be scoped away from the People Search endpoint, so verify the
        // endpoint Lead Discovery actually calls before declaring the outcome.

        // ---- Probe 2: the endpoint Lead Discovery actually calls (0 credits) ----
        $r2 = self::http($root . '/api/v1/mixed_people/api_search?per_page=1&q_keywords=apollo', $headers, '{}');
        $s2 = (int) ($r2['status'] ?? 0);
        $b2 = self::decode($r2['body'] ?? '');
        $code = strtoupper(trim((string) ($b2['error_code'] ?? $b2['code'] ?? '')));
        $detail = trim((string) ($b2['error_message'] ?? $b2['message'] ?? $b2['error'] ?? ''));

        if ($s2 === 401) {
            return ['ok' => false, 'message' => 'Invalid Apollo API key (HTTP 401 on mixed_people/api_search). Regenerate it in Apollo → Settings → Integrations → API Keys.'];
        }
        if ($s2 >= 200 && $s2 < 300) {
            $via = ($s1 >= 200 && $s1 < 300)
                ? 'auth/health and the People Search endpoint both answered'
                : 'auth/health answered HTTP ' . $s1 . ' (scoped) but the People Search endpoint verified it';
            return ['ok' => true, 'message' => 'Connected to Apollo.io — key verified on mixed_people/api_search (' . $via . ').' . $revealNote];
        }
        if ($s2 === 422) {
            // Apollo accepted the key and only rejected the probe filters: the
            // endpoint IS accessible (a validation error is not an auth failure).
            return ['ok' => true, 'message' => 'Connected to Apollo.io — the key authenticated on mixed_people/api_search (HTTP 422 on the probe filters only; auth/health answered HTTP ' . $s1 . ').' . $revealNote];
        }
        if ($s2 === 403) {
            // A 403 only means "Apollo refused the scope" when Apollo actually
            // answered. A proxy/WAF in front of the egress path returns 403 with
            // an HTML body and no Apollo error envelope — telling the operator to
            // change key scopes then sends them to fix the wrong system.
            $raw2 = trim((string) ($r2['body'] ?? ''));
            if ($b2 === [] && $raw2 !== '') {
                return ['ok' => false, 'message' => 'HTTP 403 with a non-JSON body ('
                    . mb_substr($raw2, 0, 60) . '…) — this refusal did not come from Apollo. '
                    . 'A proxy/WAF is blocking egress to ' . $root . ', or the Base URL is not api.apollo.io.'];
            }
            // The key is valid but the People Search endpoint is not accessible:
            // a scoped key without mixed_people_api_search, a plan without API
            // access, or a free/personal-email account. Report the fix, never
            // mark the provider as Connected. (The reveal scope note is left out
            // here — there is no point revealing contacts on an endpoint the key
            // cannot call in the first place.)
            return ['ok' => false, 'message' => self::apolloInaccessibleMessage($code)];
        }
        if ($s2 === 429) return ['ok' => false, 'message' => 'Apollo rate limit reached (HTTP 429) — run the test again in a minute.'];
        if ($s2 === 0) return ['ok' => false, 'message' => self::apolloNetworkMessage($r2, $root)];
        if ($s2 >= 500) return ['ok' => false, 'message' => 'Apollo.io server error (HTTP ' . $s2 . ') — retry the test shortly.'];
        return ['ok' => false, 'message' => 'Connection failed: auth/health HTTP ' . $s1 . ', mixed_people/api_search HTTP ' . $s2
            . ($code !== '' ? ' ' . $code : '') . ($detail !== '' ? ' — ' . $detail : '')];
    }

    /**
     * Apollo's HTTP 403 API_INACCESSIBLE on the People Search endpoint: the key
     * or plan is not permitted to call it. The fix is to grant the scope (or use
     * a master key) or upgrade the plan. The key is never echoed, and the text
     * stays inside the 255 chars the provider row stores.
     */
    private static function apolloInaccessibleMessage(string $code = ''): string
    {
        $code = $code !== '' ? ' ' . strtoupper($code) : '';
        return 'HTTP 403' . $code . ': this Apollo key/plan cannot use the People Search endpoint '
            . '(mixed_people/api_search). Grant the mixed_people_api_search scope or "Set as master key"; '
            . 'if the plan lacks API access, upgrade it (work-email signup required).';
    }

    /**
     * Apollo API origin **without** the /api/v1 suffix (callers append it), so a
     * pasted `https://api.apollo.io/api/v1`, a marketing host (`apollo.io`,
     * `app.apollo.io`) or an `http://` URL all resolve to a working endpoint.
     * Honours the provider row first, then APOLLO_IO_API_BASE / APOLLO_API_BASE.
     */
    public static function apolloApiRoot(array $row = []): string
    {
        $base = trim((string) ($row['base_url'] ?? ''));
        if ($base === '') $base = trim((string) (getenv('APOLLO_IO_API_BASE') ?: getenv('APOLLO_API_BASE') ?: ''));
        if ($base === '') $base = 'https://api.apollo.io';
        // Strip markdown/link wrappers an operator may have pasted.
        $base = (string) (preg_replace('#^\[[^\]]*\]\((https?://[^)\s]+)\)\s*$#i', '$1', $base) ?? $base);
        $base = (string) (preg_replace('#^<\s*(https?://[^>\s]+)\s*>$#i', '$1', $base) ?? $base);
        if (!preg_match('#^https?://#i', $base)) $base = 'https://' . ltrim($base, '/');
        if (stripos($base, 'http://') === 0) $base = 'https://' . substr($base, 7);
        $base = rtrim($base, "/ \t");
        $base = (string) (preg_replace('#/api/v\d+$#i', '', $base) ?? $base);
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?: ''));
        $notApiOrigins = ['apollo.io', 'www.apollo.io', 'app.apollo.io', 'developer.apollo.io', 'docs.apollo.io'];
        if (in_array($host, $notApiOrigins, true)) $base = 'https://api.apollo.io';
        return rtrim($base, '/');
    }

    /**
     * Clean a pasted Apollo key: no surrounding quotes/whitespace, no line
     * breaks inside the token, and a whole copied cURL command still yields the
     * key from its `x-api-key:` header.
     */
    public static function normalizeApolloKey(string $key): string
    {
        $k = trim($key);
        if ($k === '') return '';
        if (preg_match('#x-api-key["\']?\s*[:=]\s*["\']?([A-Za-z0-9_\-]{12,})#i', $k, $m)) return $m[1];
        $k = trim($k, "\"'`");
        // API keys are single opaque tokens — a newline or space is a paste artefact.
        $k = (string) preg_replace('/\s+/', '', $k);
        return trim($k, "\"'`,;");
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $body): array
    {
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turn a transport failure (status 0) into something an operator can act on:
     * a missing/outdated CA bundle, DNS, or a firewall are different problems
     * with different fixes, and "Connection failed" hides all three.
     */
    private static function apolloNetworkMessage(array $resp, string $root): string
    {
        $errno = (int) ($resp['errno'] ?? 0);
        $error = trim((string) ($resp['error'] ?? ''));
        // Fix first, raw transport text last: the provider row stores 255 chars,
        // so whatever gets truncated must be the part the operator can live
        // without. cURL error text is also capped so it cannot crowd out the fix.
        $hint = $error !== '' ? ' cURL said: ' . mb_substr($error, 0, 80) : '';
        $low = strtolower($error);
        if ($errno === 60 || $errno === 51 || $errno === 77 || str_contains($low, 'certificate') || str_contains($low, 'ca bundle') || str_contains($low, 'ssl')) {
            return 'Apollo.io’s TLS certificate could not be verified — point curl.cainfo (and openssl.cafile) in php.ini at a current cacert.pem, then test again.' . $hint;
        }
        if ($errno === 6 || str_contains($low, 'resolve host') || str_contains($low, 'name or service not known')) {
            return 'api.apollo.io does not resolve — DNS is failing on this server.' . $hint;
        }
        if ($errno === 7 || $errno === 28 || str_contains($low, 'timed out') || str_contains($low, 'connection refused')) {
            return 'Outbound HTTPS to ' . $root . ' is blocked by a firewall or timed out — allow egress to api.apollo.io on port 443.' . $hint;
        }
        if ($error === '' && !function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            return 'This PHP install has neither cURL nor allow_url_fopen, so no outbound HTTPS request is possible. Enable the cURL extension, then test again.';
        }
        return 'Could not reach Apollo.io — check outbound HTTPS to api.apollo.io (port 443).' . $hint;
    }

    private static function testOpenAi(string $url, string $key): array
    {
        if ($url === '' || $key === '') return ['ok' => false, 'message' => 'Base URL and API key are required.'];
        $root = rtrim($url, '/');
        // Accept a pasted endpoint URL and probe /models instead.
        $root = (string) preg_replace('#/(chat/completions|responses)$#i', '', $root);
        if (!preg_match('#/v\d+$#i', $root)) $root .= '/v1';
        $models = $root . '/models';
        $resp = self::http($models, ['Authorization: Bearer ' . $key]);
        $status = (int) ($resp['status'] ?? 0);
        if ($status >= 200 && $status < 400) {
            return ['ok' => true, 'message' => 'Connected — the API key lists models at ' . $models];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'message' => 'Connection failed: the API key was rejected (HTTP ' . $status . '). Check the key and that the base URL matches the provider.'];
        }
        return ['ok' => false, 'message' => 'Connection failed: /models answered HTTP ' . $status . '. Check the base URL and network egress.'];
    }

    public static function normalizeGrokBaseUrl(string $url, ?string &$teamId = null): string
    {
        return GrokProvider::normalizeBase($url, $teamId);
    }

    private static function testGrok(string $url, string $key, array $extra = []): array
    {
        if ($key === '') return ['ok' => false, 'message' => 'API key is required. Get an API key at https://console.x.ai/'];
        $teamId = (string) ($extra['team_id'] ?? '');
        $root = self::normalizeGrokBaseUrl($url, $teamId);
        $models = $root . '/models';
        $headers = ['Authorization: Bearer ' . $key];
        if ($teamId !== '') $headers[] = 'X-Team-Id: ' . $teamId;
        $resp = self::http($models, $headers);
        $status = (int) ($resp['status'] ?? 0);
        if ($status >= 200 && $status < 400) {
            return ['ok' => true, 'message' => 'Connected — the xAI API key lists models at ' . $models];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'message' => 'Connection failed: the xAI API key was rejected (HTTP ' . $status . '). Check the key at https://console.x.ai/ and verify billing status.'];
        }
        return ['ok' => false, 'message' => 'Connection failed: /models answered HTTP ' . $status . '. Check network egress to api.x.ai.'];
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
        // Vendor hosts are pinned to https above. A custom host with an
        // EXPLICIT http:// scheme keeps it — an operator who deliberately
        // points the server-side client at a proxy or internal endpoint has
        // already made that choice (the key still travels only server-side).
        // A paste without any scheme defaults to https.
        if (preg_match('#^http://#i', trim($baseUrl))) {
            return rtrim($base, '/');
        }
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
     * so callers can distinguish network failure (0) from HTTP errors, plus the
     * transport error text (`error` / `errno`) so a TLS, DNS or firewall problem
     * can be reported instead of a bare "Connection failed".
     *
     * @return array{status:int,body:string,errno:int,error:string}
     */
    public static function http(string $url, array $headers = [], ?string $body = null): array
    {
        if (is_callable(self::$http)) {
            $stub = (self::$http)($url, $headers, $body);
            if (!is_array($stub)) $stub = [];
            return [
                'status' => (int) ($stub['status'] ?? 0),
                'body' => (string) ($stub['body'] ?? ''),
                'errno' => (int) ($stub['errno'] ?? 0),
                'error' => (string) ($stub['error'] ?? ''),
            ];
        }

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

        $errno = 0;
        $error = '';
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
                $errno = (int) curl_errno($ch);
                $error = (string) curl_error($ch);
                curl_close($ch);
                if ($raw !== false) {
                    return ['status' => $status, 'body' => (string) $raw, 'errno' => $errno, 'error' => $error];
                }
                // Fall through to streams if cURL failed to produce a body and
                // reported a transport error — some hosts mis-configure cURL CA.
                if ($status > 0) {
                    return ['status' => $status, 'body' => '', 'errno' => $errno, 'error' => $error];
                }
                if ($error === '') $error = 'cURL error ' . $errno;
            }
        }

        if (!ini_get('allow_url_fopen')) {
            return [
                'status' => 0,
                'body' => '',
                'errno' => $errno,
                'error' => $error !== '' ? $error : 'no HTTP transport available (cURL missing and allow_url_fopen is off)',
            ];
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
        if (!is_string($raw) && $error === '') $error = 'stream request failed (HTTP ' . $status . ')';
        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'errno' => $errno, 'error' => $error];
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
        $driver = (string) ($cfg['driver'] ?? '');
        if ($driver === 'grok') {
            return self::grokChat($cfg, $messages, $maxTokens);
        }
        $url = trim((string) ($cfg['base_url'] ?? ''));
        $model = (string) ($cfg['extra']['model'] ?? '');
        $key = (string) ($cfg['secrets']['api_key'] ?? '');
        if ($url === '' || $key === '' || $model === '') return null;
        $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $maxTokens], JSON_UNESCAPED_SLASHES);
        $resp = self::http($url, ['Content-Type: application/json', 'Authorization: Bearer ' . $key], $body);
        $payload = json_decode($resp['body'] ?? '', true);
        $answer = $payload['choices'][0]['message']['content'] ?? null;
        return is_string($answer) && trim($answer) !== '' ? mb_substr(trim($answer), 0, 4000) : null;
    }

    /** Responses API call via the configured OpenAI-compatible provider. */
    public static function openaiResponses(array $cfg, string $input, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->responses($input, $options);
    }

    /** Structured JSON output (JSON-schema mode) via the configured provider. */
    public static function openaiStructured(array $cfg, array $messages, array $jsonSchema, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->structuredJson($messages, $jsonSchema, $options);
    }

    /** JSON-object output (no schema) via the configured provider. */
    public static function openaiJsonObject(array $cfg, array $messages, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->jsonObject($messages, $options);
    }

    /** Text embeddings via the configured provider. Accepts a string or an array. */
    public static function openaiEmbedding(array $cfg, $input, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->embedding($input, $options);
    }

    /** Image generation via the configured provider. */
    public static function openaiImage(array $cfg, string $prompt, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->image($prompt, $options);
    }

    /** Content moderation via the configured provider. Accepts a string or an array. */
    public static function openaiModeration(array $cfg, $input, array $options = []): ?array
    {
        return (new OpenAIProvider($cfg))->moderate($input, $options);
    }

    /** List model IDs available to the configured provider. */
    public static function openaiModels(array $cfg): ?array
    {
        return (new OpenAIProvider($cfg))->models();
    }

    /** Chat completion via the configured xAI Grok provider. */
    public static function grokChat(array $cfg, array $messages, int $maxTokens = 260): ?string
    {
        $res = (new GrokProvider($cfg))->chat($messages, ['max_tokens' => $maxTokens]);
        return is_array($res) && isset($res['content']) && is_string($res['content']) ? mb_substr(trim($res['content']), 0, 4000) : null;
    }

    /** Responses API call via the configured Grok provider. */
    public static function grokResponses(array $cfg, string $input, array $options = []): ?array
    {
        return (new GrokProvider($cfg))->responses($input, $options);
    }

    /** Structured JSON output (JSON-schema mode) via the configured Grok provider. */
    public static function grokStructured(array $cfg, array $messages, array $jsonSchema, array $options = []): ?array
    {
        return (new GrokProvider($cfg))->structuredJson($messages, $jsonSchema, $options);
    }

    /** JSON-object output (no schema) via the configured Grok provider. */
    public static function grokJsonObject(array $cfg, array $messages, array $options = []): ?array
    {
        return (new GrokProvider($cfg))->jsonObject($messages, $options);
    }

    /** Text embeddings via the configured Grok provider. Accepts a string or an array. */
    public static function grokEmbedding(array $cfg, $input, array $options = []): ?array
    {
        return (new GrokProvider($cfg))->embedding($input, $options);
    }

    /** List model IDs available to the configured Grok provider. */
    public static function grokModels(array $cfg): ?array
    {
        return (new GrokProvider($cfg))->models();
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
            if ($driver === 'grok') {
                return self::grokChat($cfg, [
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
