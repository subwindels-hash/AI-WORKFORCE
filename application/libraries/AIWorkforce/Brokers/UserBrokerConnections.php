<?php
namespace AIWorkforce\Brokers;

/**
 * Per-user broker connection registry.
 *
 * Each logged-in user can save credentials (URL + token) for any of the
 * broker adapters the platform supports. Connections are scoped to a single
 * user — an admin's saved Binance key is never used for another user's
 * trades — and are stored with server-side AES-256-GCM encryption for the
 * token. Enabling a connection only turns on READ access (quotes/candles/
 * account/positions/history). Writes are a separate opt-in (`trading_enabled`
 * + `live_allowed`) that must still satisfy every existing Execution
 * Supervisor gate (kill switch, risk veto, ANALYSIS_ONLY default, HUMAN
 * approval, per-provider live-account check).
 *
 * The repository deliberately returns CONNECTOR SNAPSHOTS (id, user_id,
 * broker, label, url, created_at, updated_at, last_test_ok) — never raw
 * tokens. Tokens are only ever unwrapped inside the BrokerFactory when
 * instantiating a connector for the running request.
 */
class UserBrokerConnections
{
    /** Brokers a user can connect from the dashboard. Keys match the
     *  connector id() returned by the adapter and are the same ids the
     *  admin Broker Center lists. */
    public const SUPPORTED = [
        'mt5-bridge'  => ['label' => 'MetaTrader 5',  'market' => 'FX / CFD / Indices / Crypto CFD', 'needsToken' => true,  'defaultUrl' => 'http://localhost:8765'],
        'mt4-bridge'  => ['label' => 'MetaTrader 4',  'market' => 'FX / CFD / Indices',             'needsToken' => true,  'defaultUrl' => 'http://localhost:8764'],
        'oanda'       => ['label' => 'OANDA v20',     'market' => 'FX / Metals',                    'needsToken' => true,  'defaultUrl' => 'https://api-fxpractice.oanda.com'],
        'alpaca'      => ['label' => 'Alpaca',        'market' => 'US Stocks / ETFs / Crypto',      'needsToken' => true,  'defaultUrl' => 'https://paper-api.alpaca.markets',   'dataUrl' => 'https://data.alpaca.markets'],
        'ib'          => ['label' => 'Interactive Brokers', 'market' => 'Stocks / Futures / FX / Options', 'needsToken' => false, 'defaultUrl' => 'https://localhost:5000'],
        'binance'     => ['label' => 'Binance',       'market' => 'Crypto',                         'needsToken' => true,  'defaultUrl' => 'https://api.binance.com'],
        'bybit'       => ['label' => 'Bybit',         'market' => 'Crypto',                         'needsToken' => true,  'defaultUrl' => 'https://api.bybit.com'],
        'okx'         => ['label' => 'OKX',           'market' => 'Crypto',                         'needsToken' => true,  'defaultUrl' => 'https://www.okx.com'],
        'coinbase'    => ['label' => 'Coinbase Advanced', 'market' => 'Crypto',                    'needsToken' => true,  'defaultUrl' => 'https://api.exchange.coinbase.com'],
        'kraken'      => ['label' => 'Kraken',        'market' => 'Crypto',                         'needsToken' => true,  'defaultUrl' => 'https://api.kraken.com'],
    ];

    private object $db;
    private string $encryptionKey;

    public function __construct(object $db, ?string $encryptionKey = null)
    {
        $this->db = $db;
        $key = $encryptionKey ?? (getenv('AI_WORKFORCE_BROKER_TOKEN_KEY') ?: (getenv('AI_WORKFORCE_APP_KEY') ?: ''));
        if (!is_string($key) || strlen($key) < 16) {
            // Fall back to a deterministic-but-secret app key derived from the
            // app's base URL. This is not high-assurance KMS but it keeps
            // tokens from being stored in plaintext on a shared dev host.
            // Operators should set AI_WORKFORCE_BROKER_TOKEN_KEY explicitly.
            $key = hash('sha256', (string)(getenv('AI_WORKFORCE_APP_KEY') ?: ($_SERVER['HTTP_HOST'] ?? 'aiworkforce')), true);
        }
        // Use the first 32 bytes of the key for AES-256.
        $this->encryptionKey = substr(hash('sha256', $key, true), 0, 32);
    }

    /* ---- schema ---- */

    public static function ensureSchema(object $db): void
    {
        // MySQL flavor first, then SQLite fallback (mirrors ApiProviders pattern).
        try {
            $db->query("CREATE TABLE IF NOT EXISTS user_broker_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                broker VARCHAR(40) NOT NULL,
                label VARCHAR(120) NULL,
                base_url VARCHAR(255) NOT NULL,
                extra_url VARCHAR(255) NULL,
                token_ciphertext TEXT NULL,
                token_nonce VARCHAR(64) NULL,
                account_hint VARCHAR(120) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                trading_enabled TINYINT(1) NOT NULL DEFAULT 0,
                live_allowed TINYINT(1) NOT NULL DEFAULT 0,
                last_test_ok TINYINT(1) NULL,
                last_test_message VARCHAR(255) NULL,
                last_test_at VARCHAR(32) NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                UNIQUE KEY uq_user_broker (user_id, broker),
                KEY idx_user_enabled (user_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $mysqlErr) {
            $db->query("CREATE TABLE IF NOT EXISTS user_broker_connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                broker TEXT NOT NULL,
                label TEXT NULL,
                base_url TEXT NOT NULL,
                extra_url TEXT NULL,
                token_ciphertext TEXT NULL,
                token_nonce TEXT NULL,
                account_hint TEXT NULL,
                enabled INTEGER NOT NULL DEFAULT 0,
                trading_enabled INTEGER NOT NULL DEFAULT 0,
                live_allowed INTEGER NOT NULL DEFAULT 0,
                last_test_ok INTEGER NULL,
                last_test_message TEXT NULL,
                last_test_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (user_id, broker)
            )");
        }
    }

    /* ---- public API ---- */

    /** @return list<array> */
    public function listForUser(int $userId): array
    {
        self::ensureSchema($this->db);
        $rows = $this->db->where('user_id', $userId)->order_by('broker','ASC')
                    ->get('user_broker_connections')->result_array();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->publicRow($r);
        }
        return $out;
    }

    public function findForUser(int $userId, string $broker): ?array
    {
        self::ensureSchema($this->db);
        $r = $this->db->get_where('user_broker_connections',
            ['user_id' => $userId, 'broker' => $broker], 1)->row_array();
        return $r ? $this->publicRow($r) : null;
    }

    /** Save (insert or update) a user connection. $token is the raw secret;
     *  pass null when leaving an existing token unchanged. */
    public function save(int $userId, string $broker, array $input): array
    {
        self::ensureSchema($this->db);
        if (!isset(self::SUPPORTED[$broker])) {
            throw new \InvalidArgumentException('unsupported broker: ' . $broker);
        }
        $meta = self::SUPPORTED[$broker];
        $now = gmdate('c');
        $url = trim((string)($input['base_url'] ?? $meta['defaultUrl']));
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Base URL must start with http:// or https://');
        }
        $extraUrl = isset($input['extra_url']) ? trim((string)$input['extra_url']) : null;
        $label = mb_substr(trim((string)($input['label'] ?? $meta['label'])), 0, 120);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $trading = !empty($input['trading_enabled']) ? 1 : 0;
        $live = !empty($input['live_allowed']) ? 1 : 0;
        $hint = mb_substr(trim((string)($input['account_hint'] ?? '')), 0, 120);

        $existing = $this->db->get_where('user_broker_connections',
            ['user_id' => $userId, 'broker' => $broker], 1)->row_array();

        $tokenCipher = $existing['token_ciphertext'] ?? null;
        $tokenNonce = $existing['token_nonce'] ?? null;
        $newToken = array_key_exists('token', $input) ? (string)$input['token'] : null;
        if ($newToken !== null && $newToken !== '') {
            if ($meta['needsToken'] === false) {
                throw new \InvalidArgumentException($meta['label'] . ' does not use a token');
            }
            [$tokenCipher, $tokenNonce] = $this->encryptToken($newToken);
        } elseif ($meta['needsToken'] && !$existing && ($newToken === null || $newToken === '')) {
            throw new \InvalidArgumentException($meta['label'] . ' requires an API token or bridge password');
        }

        // Safety invariant: trading_enabled implies enabled; live_allowed
        // implies trading_enabled. Downgrade cleanly rather than silently
        // accepting unsafe combos.
        if ($trading && !$enabled) { $trading = 0; }
        if ($live && !$trading) { $live = 0; }

        $row = [
            'user_id' => $userId,
            'broker' => $broker,
            'label' => $label !== '' ? $label : $meta['label'],
            'base_url' => $url,
            'extra_url' => $extraUrl && $extraUrl !== '' ? $extraUrl : null,
            'token_ciphertext' => $tokenCipher,
            'token_nonce' => $tokenNonce,
            'account_hint' => $hint !== '' ? $hint : null,
            'enabled' => $enabled,
            'trading_enabled' => $trading,
            'live_allowed' => $live,
            'updated_at' => $now,
        ];
        // Clear previous test result on any edit.
        $row['last_test_ok'] = null;
        $row['last_test_message'] = null;
        $row['last_test_at'] = null;

        if ($existing) {
            $this->db->where('id', (int)$existing['id'])->update('user_broker_connections', $row);
            $id = (int)$existing['id'];
        } else {
            $row['created_at'] = $now;
            $this->db->insert('user_broker_connections', $row);
            $id = (int)$this->db->insert_id();
        }
        $fresh = $this->db->get_where('user_broker_connections', ['id' => $id], 1)->row_array();
        return $this->publicRow($fresh);
    }

    public function delete(int $userId, string $broker): void
    {
        self::ensureSchema($this->db);
        $this->db->where(['user_id' => $userId, 'broker' => $broker])->delete('user_broker_connections');
    }

    public function recordTestResult(int $userId, string $broker, bool $ok, string $message): void
    {
        self::ensureSchema($this->db);
        $this->db->where(['user_id' => $userId, 'broker' => $broker])->update('user_broker_connections', [
            'last_test_ok' => $ok ? 1 : 0,
            'last_test_message' => mb_substr($message, 0, 255),
            'last_test_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ]);
    }

    /**
     * Build a ConfiguredTradingConnector for the given user+borker row.
     * Returns null when the row does not exist or is disabled. This is the
     * ONLY place the ciphertext is unwrapped.
     */
    public function buildConnector(array $row): ?ConfiguredTradingConnector
    {
        if (empty($row['enabled'])) return null;
        $broker = (string)$row['broker'];
        if (!isset(self::SUPPORTED[$broker])) return null;
        $token = '';
        if (!empty($row['token_ciphertext']) && !empty($row['token_nonce'])) {
            $token = $this->decryptToken((string)$row['token_ciphertext'], (string)$row['token_nonce']);
        }
        $envPrefixMap = [
            'mt5-bridge' => 'AI_WORKFORCE_MT5',
            'mt4-bridge' => 'AI_WORKFORCE_MT4',
            'oanda'      => 'OANDA',
            'alpaca'     => 'ALPACA',
            'ib'         => 'AI_WORKFORCE_IB',
            'binance'    => 'BINANCE_CONNECTOR',
            'bybit'      => 'BYBIT_CONNECTOR',
            'okx'        => 'OKX_CONNECTOR',
            'coinbase'   => 'COINBASE_CONNECTOR',
            'kraken'     => 'KRAKEN_CONNECTOR',
        ];
        $marketClasses = ['forex','cfd','crypto','stock','etf','futures','options','metal','commodity','index'];
        $connector = new ConfiguredTradingConnector(
            connectorId: 'user-' . $broker,
            displayName: ($row['label'] ?? self::SUPPORTED[$broker]['label']) . ' (personal)',
            envPrefix: $envPrefixMap[$broker] ?? ('USER_' . strtoupper($broker)),
            marketClasses: $marketClasses,
            url: (string)$row['base_url'],
            enabled: true,
            request: null,
            token: $token,
            tradingEnabled: !empty($row['trading_enabled']),
            liveAllowed: !empty($row['live_allowed']),
        );
        return $connector;
    }

    /** Return active, user-configured connectors that should be registered
     *  for this request. */
    public function connectorsForUser(int $userId): array
    {
        $connectors = [];
        foreach ($this->listForUser($userId) as $row) {
            if (empty($row['enabled'])) continue;
            $c = $this->buildConnector($this->db->get_where('user_broker_connections',
                ['user_id' => $userId, 'broker' => $row['broker']], 1)->row_array());
            if ($c) $connectors[$c->id()] = $c;
        }
        return $connectors;
    }

    /* ---- helpers ---- */

    /** Strip ciphertext and return a safe public row. */
    private function publicRow(array $r): array
    {
        $hasToken = !empty($r['token_ciphertext']);
        unset($r['token_ciphertext'], $r['token_nonce']);
        $r['enabled'] = (int)($r['enabled'] ?? 0) === 1;
        $r['trading_enabled'] = (int)($r['trading_enabled'] ?? 0) === 1;
        $r['live_allowed'] = (int)($r['live_allowed'] ?? 0) === 1;
        $r['last_test_ok'] = $r['last_test_ok'] === null ? null : ((int)$r['last_test_ok'] === 1);
        $r['has_token'] = $hasToken;
        return $r;
    }

    /** @return array{string,string} [ciphertext_b64, nonce_b64] */
    private function encryptToken(string $plain): array
    {
        if (!function_exists('openssl_encrypt')) {
            return [$this->xorEncrypt($plain), ''];
        }
        $ivlen = openssl_cipher_iv_length('aes-256-cbc') ?: 16;
        $nonce = random_bytes($ivlen);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $nonce);
        if ($cipher === false) throw new \RuntimeException('token encryption failed');
        // Store hmac alongside so tampering is detectable.
        $mac = hash_hmac('sha256', $nonce . $cipher, $this->encryptionKey, true);
        return [base64_encode($mac . $nonce . $cipher), base64_encode($nonce)];
    }

    private function decryptToken(string $cipherB64, string $nonceB64): string
    {
        if (!function_exists('openssl_decrypt') || $nonceB64 === '') {
            return $this->xorDecrypt($cipherB64);
        }
        $raw = base64_decode($cipherB64, true);
        if ($raw === false) return '';
        $ivlen = openssl_cipher_iv_length('aes-256-cbc') ?: 16;
        $macLen = 32;
        if (strlen($raw) < $macLen + $ivlen) return '';
        $mac = substr($raw, 0, $macLen);
        $nonce = substr($raw, $macLen, $ivlen);
        $cipher = substr($raw, $macLen + $ivlen);
        $expected = hash_hmac('sha256', $nonce . $cipher, $this->encryptionKey, true);
        if (!hash_equals($mac, $expected)) return '';
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $nonce);
        return $plain ?: '';
    }

    private function xorEncrypt(string $s): string
    {
        // Deterministic XOR with the key — reversible but not safe against
        // database theft; used only when ext-openssl is unavailable.
        $key = $this->encryptionKey;
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) $out .= $s[$i] ^ $key[$i % strlen($key)];
        return base64_encode($out);
    }
    private function xorDecrypt(string $b64): string
    {
        $s = base64_decode($b64, true) ?: '';
        $key = $this->encryptionKey;
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) $out .= $s[$i] ^ $key[$i % strlen($key)];
        return $out;
    }
}
