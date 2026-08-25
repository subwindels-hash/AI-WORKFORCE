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
                'drivers' => ['google_places', 'custom_http'],
            ],
            'sports' => [
                'label' => 'Sports Intelligence',
                'group' => 'Sports Intelligence',
                'kind' => 'data',
                'drivers' => ['http_sports', 'custom_http'],
            ],
            'lottery' => [
                'label' => 'Lottery / EuroMillions',
                'group' => 'EuroMillions',
                'kind' => 'data',
                'drivers' => ['official_lottery', 'custom_http'],
            ],
            'crypto_market' => [
                'label' => 'Crypto Market Data',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['binance_public', 'custom_http'],
            ],
            'forex_market' => [
                'label' => 'Forex Market Data',
                'group' => 'AI Trading',
                'kind' => 'data',
                'drivers' => ['frankfurter', 'custom_http'],
            ],
            'translation' => [
                'label' => 'Translation',
                'group' => 'Language Learning',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'libretranslate', 'custom_http'],
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
                'drivers' => ['openai_compatible', 'custom_http'],
            ],
            'llm' => [
                'label' => 'AI / LLM services',
                'group' => 'AI Workforce',
                'kind' => 'data',
                'drivers' => ['openai_compatible', 'custom_http'],
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
            'http_sports' => [
                'label' => 'Sports HTTP feed',
                'fields' => [
                    $f('base_url', 'Base URL', false, true, 'HTTPS root exposing /fixtures and /health'),
                    $f('token', 'API token', true, false),
                    $f('timeout', 'Timeout (seconds)', false, false),
                    $f('sports', 'Sports covered', false, false, 'e.g. football,basketball,tennis'),
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
            'frankfurter' => [
                'label' => 'Frankfurter / ECB forex',
                'fields' => [
                    $f('base_url', 'Base URL', false, false, 'Defaults to https://api.frankfurter.dev'),
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
                'binance_public' => self::testGet(($base !== '' ? $base : 'https://api.binance.com') . '/api/v3/ping'),
                'frankfurter' => self::testGet(($base !== '' ? $base : 'https://api.frankfurter.dev') . '/v1/latest?base=EUR&symbols=USD'),
                'http_sports' => self::testGet(($base !== '' ? $base : '') . '/health', $secrets['token'] ?? $secrets['api_key'] ?? ''),
                'official_lottery' => self::testGet((string) ($extra['health_url'] ?? ($base . '/health')), $secrets['token'] ?? $secrets['api_key'] ?? ''),
                'libretranslate' => self::testGet(($base !== '' ? $base : '') . '/languages'),
                'openai_compatible' => self::testOpenAi($base, (string) ($secrets['api_key'] ?? '')),
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

    private static function testOpenAi(string $url, string $key): array
    {
        if ($url === '' || $key === '') return ['ok' => false, 'message' => 'Base URL and API key are required.'];
        $models = preg_replace('#/chat/completions/?$#', '/models', rtrim($url, '/'));
        if ($models === $url) $models = rtrim($url, '/') . '/models';
        $resp = self::http($models, ['Authorization: Bearer ' . $key]);
        $status = (int) ($resp['status'] ?? 0);
        return ['ok' => $status >= 200 && $status < 400, 'message' => ($status >= 200 && $status < 400) ? 'Connected' : 'Connection failed'];
    }

    /** @return array{status:int,body:string} */
    public static function http(string $url, array $headers = [], ?string $body = null): array
    {
        if (is_callable(self::$http)) return (self::$http)($url, $headers, $body);
        $hdr = "Accept: application/json\r\nUser-Agent: WINDELS-API-Management/1.0\r\n";
        foreach ($headers as $h) $hdr .= $h . "\r\n";
        $http = [
            'method' => $body === null ? 'GET' : 'POST',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => $hdr,
        ];
        if ($body !== null) $http['content'] = $body;
        $ctx = stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; break; }
        }
        return ['status' => $status, 'body' => (string) $raw];
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
        $url = trim((string) ($cfg['base_url'] ?? ''));
        $key = (string) ($cfg['secrets']['api_key'] ?? '');
        $model = (string) ($cfg['extra']['model'] ?? '');
        if ($url === '' || $key === '' || $model === '') return null;
        $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $maxTokens], JSON_UNESCAPED_SLASHES);
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
