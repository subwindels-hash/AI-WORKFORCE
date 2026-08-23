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
    /** @var callable(string): array|null */
    private $probe;

    public function __construct(?string $url = null, ?bool $enabled = null, ?callable $probe = null)
    {
        $this->url = trim($url ?? (getenv('AEGIS_MT5_BRIDGE_URL') ?: ''));
        $this->enabled = $enabled ?? (getenv('AEGIS_MT5_BRIDGE_ENABLED') === '1');
        $this->probe = $probe ?? [$this, 'defaultProbe'];
    }

    public function id(): string { return 'mt5-bridge'; }

    public function capabilities(): array
    {
        return [
            'accountRead' => false,
            'marketData' => false,
            'orderSubmission' => false,
            'reason' => 'Discovery only. Live execution is unavailable until the Phase 5 execution supervisor is implemented.',
        ];
    }

    public function status(): array
    {
        if (!$this->enabled) return $this->statusPayload('DISABLED', 'Set AEGIS_MT5_BRIDGE_ENABLED=1 after deploying an authenticated bridge.');
        if (!$this->validUrl()) return $this->statusPayload('NOT_CONFIGURED', 'AEGIS_MT5_BRIDGE_URL must be an absolute http(s) URL.');
        try {
            $result = ($this->probe)($this->url);
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

    private function defaultProbe(string $url): ?array
    {
        $healthUrl = rtrim($url, '/') . '/health';
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2, 'header' => "Accept: application/json\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($healthUrl, false, $ctx);
        $decoded = $body === false ? null : json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
