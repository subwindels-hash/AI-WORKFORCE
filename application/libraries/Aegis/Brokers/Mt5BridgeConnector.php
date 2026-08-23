<?php
namespace Aegis\Brokers;

/**
 * MT5 bridge discovery connector.
 *
 * MetaTrader 5 has no native PHP API, so production deployments run a local,
 * separately authenticated bridge (normally Python/MetaTrader5). This class
 * only discovers its health endpoint. It cannot place orders: Phase 5's
 * execution supervisor must own all order routing and approval checks.
 *
 * Environment:
 *   AEGIS_MT5_BRIDGE_URL=https://bridge.internal (optional)
 *   AEGIS_MT5_BRIDGE_ENABLED=1                  (explicit opt-in)
 */
class Mt5BridgeConnector implements BrokerConnector
{
    private string $url;
    private bool $enabled;
    private string $token;
    /** @var callable(string, string, ?string): array|null */
    private $request;

    public function __construct(?string $url = null, ?bool $enabled = null, ?callable $request = null, ?string $token = null)
    {
        $this->url = trim($url ?? (getenv('AEGIS_MT5_BRIDGE_URL') ?: ''));
        $this->enabled = $enabled ?? (getenv('AEGIS_MT5_BRIDGE_ENABLED') === '1');
        $this->token = trim($token ?? (getenv('AEGIS_MT5_BRIDGE_TOKEN') ?: ''));
        $this->request = $request ?? [$this, 'defaultRequest'];
    }

    public function id(): string { return 'mt5-bridge'; }

    public function capabilities(): array
    {
        return [
            'accountRead' => true,
            'marketData' => true,
            'orderSubmission' => false,
            'reason' => 'Read-only bridge endpoints are available when explicitly configured. Live execution is unavailable until the Phase 5 execution supervisor is implemented.',
        ];
    }

    public function status(): array
    {
        if (!$this->enabled) return $this->statusPayload('DISABLED', 'Set AEGIS_MT5_BRIDGE_ENABLED=1 after deploying an authenticated bridge.');
        if (!$this->validUrl()) return $this->statusPayload('NOT_CONFIGURED', 'AEGIS_MT5_BRIDGE_URL must be an absolute http(s) URL.');
        try {
            $result = ($this->request)($this->url, '/health', null);
            if (!is_array($result) || ($result['ok'] ?? false) !== true) {
                return $this->statusPayload('DOWN', 'Bridge health check failed.');
            }
            return $this->statusPayload('READY', 'Bridge health endpoint reachable; execution remains disabled.', [
                'bridgeVersion' => isset($result['version']) ? (string) $result['version'] : null,
            ]);
        } catch (\Throwable $e) {
            return $this->statusPayload('DOWN', 'Bridge health check failed.');
        }
    }

    /** Read-only account snapshot from an authenticated bridge. */
    public function account(): array
    {
        return BrokerDataNormalizer::account($this->read('/v1/account', 'account'), $this->id());
    }

    /** Read-only latest quote. The symbol is encoded as a path segment. */
    public function quote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9._-]{1,32}$/', $symbol)) {
            throw new \InvalidArgumentException('invalid MT5 symbol');
        }
        return BrokerDataNormalizer::quote($this->read('/v1/quotes/' . rawurlencode($symbol), 'quote'), $this->id());
    }

    private function read(string $path, string $kind): array
    {
        if (!$this->enabled || !$this->validUrl()) {
            throw new \RuntimeException('MT5 bridge is not enabled and configured');
        }
        if ($this->token === '') {
            throw new \RuntimeException('MT5 bridge token is not configured');
        }
        try {
            $payload = ($this->request)($this->url, $path, $this->token);
        } catch (\Throwable $e) {
            throw new \RuntimeException("MT5 {$kind} read failed");
        }
        if (!is_array($payload) || ($payload['ok'] ?? true) === false) {
            throw new \RuntimeException("MT5 {$kind} read failed");
        }
        // The bridge contract returns its data below data; do not pass through
        // arbitrary headers, credentials, or execution-related fields.
        $data = $payload['data'] ?? null;
        if (!is_array($data)) throw new \RuntimeException("MT5 {$kind} response is invalid");
        return $data;
    }

    private function statusPayload(string $state, string $message, array $extra = []): array
    {
        // Never return URL query strings or any environment secrets.
        return array_merge(['state' => $state, 'message' => $message, 'configured' => $this->validUrl()], $extra);
    }

    private function validUrl(): bool
    {
        $parts = parse_url($this->url);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
    }

    private function defaultRequest(string $url, string $path, ?string $token): ?array
    {
        $endpoint = rtrim($url, '/') . $path;
        $headers = "Accept: application/json\r\n";
        if ($token !== null) $headers .= "Authorization: Bearer {$token}\r\n";
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'header' => $headers, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($endpoint, false, $ctx);
        $decoded = $body === false ? null : json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
