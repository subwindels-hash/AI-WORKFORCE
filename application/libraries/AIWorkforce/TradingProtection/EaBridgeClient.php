<?php
namespace AIWorkforce\TradingProtection;

/**
 * Transport + sync loop between the platform and the MT4/MT5 bridge that
 * Expert Advisors talk to (python-services/mt5-bridge → /v1/ea/*).
 *
 * The terminal host sits behind the operator's firewall, so the platform never
 * waits for an EA to call in. It polls the bridge for the heartbeats the EAs
 * posted locally, evaluates them against policy, and pushes the resulting
 * decisions back to the bridge, where `GET /v1/ea/decision` serves them to the
 * EAs on their next tick.
 *
 *   EA ──POST heartbeats──► bridge ◄──GET /v1/ea/heartbeats── platform
 *   EA ──GET /v1/ea/decision──► bridge ◄──POST /v1/ea/decisions── platform
 *
 * Transport is injectable for tests, matching Mt5BridgeConnector:
 *   callable(string $method, string $path, ?string $token, ?array $body): ?array
 */
final class EaBridgeClient
{
    private string $url;
    private bool $enabled;
    private string $token;
    /** @var callable(string, string, ?string, ?array): ?array */
    private $request;
    private ?string $lastError = null;

    public function __construct(
        ?string $url = null,
        ?callable $request = null,
        ?string $token = null,
        ?bool $enabled = null
    ) {
        $this->url = rtrim(trim($url ?? (getenv('AI_WORKFORCE_MT5_BRIDGE_URL') ?: '')), '/');
        $this->token = trim($token ?? (getenv('AI_WORKFORCE_MT5_BRIDGE_TOKEN') ?: ''));
        $this->enabled = $enabled ?? ($this->url !== '' && $this->token !== '');
        $this->request = $request ?? [$this, 'defaultRequest'];
    }

    public function configured(): bool
    {
        return $this->enabled && $this->url !== '' && $this->token !== '' && preg_match('#^https?://#i', $this->url) === 1;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array{ok:bool, endpoint:string, status:?int} basic reachability probe. */
    public function probe(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'endpoint' => $this->url, 'status' => null];
        }
        $payload = $this->call('GET', '/v1/ea/health', null);
        return [
            'ok' => is_array($payload) && ($payload['ok'] ?? false) === true,
            'endpoint' => $this->url,
            'status' => is_array($payload) ? ($payload['status'] ?? null) : null,
            'error' => $this->lastError,
        ];
    }

    /**
     * Pull the heartbeats the EAs posted since the last call, evaluate them and
     * push the decisions back.
     *
     * A transport failure is not an outage of protection: the EAs keep
     * enforcing locally, and the next successful pull re-evaluates everything.
     * It is reported (and audited) so a bridge that has gone silent is visible.
     *
     * @return array<string,mixed> sync report
     */
    public function sync(EaProtection $protection): array
    {
        if (!$this->configured()) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'No EA bridge configured — set AI_WORKFORCE_MT5_BRIDGE_URL and AI_WORKFORCE_MT5_BRIDGE_TOKEN to manage Expert Advisors.',
                'accepted' => 0,
                'registered' => [],
                'decisions' => [],
                'pushed' => false,
            ];
        }

        $payload = $this->call('GET', '/v1/ea/heartbeats', null);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'skipped' => false,
                'reason' => 'Could not reach the EA bridge: ' . ($this->lastError ?? 'unknown error'),
                'accepted' => 0,
                'registered' => [],
                'decisions' => [],
                'pushed' => false,
            ];
        }

        $heartbeats = (array) ($payload['heartbeats'] ?? []);
        $ingest = $protection->ingest($heartbeats);
        $decisions = array_values($protection->evaluateAll());

        $pushed = $this->call('POST', '/v1/ea/decisions', ['decisions' => $decisions]) !== null;

        return [
            'ok' => $pushed,
            'skipped' => false,
            'reason' => null,
            'accepted' => $ingest['accepted'],
            'registered' => $ingest['registered'],
            'decisions' => $decisions,
            'pushed' => $pushed,
            'blocked' => count(array_filter($decisions, fn(array $d): bool => empty($d['allowNewTrades']))),
        ];
    }

    /** @return array<string,mixed>|null */
    private function call(string $method, string $path, ?array $body): ?array
    {
        $this->lastError = null;
        try {
            $result = call_user_func($this->request, $method, $this->url . $path, $this->token, $body);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
        if (!is_array($result)) {
            $this->lastError = 'the EA bridge returned no usable JSON response';
            return null;
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function defaultRequest(string $method, string $url, ?string $token, ?array $body): ?array
    {
        if (preg_match('#^https?://#i', $url) !== 1) return null;

        $headers = ['Accept: application/json'];
        $payload = null;
        if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($payload === false) return null;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $this->lastError = 'HTTP request failed';
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->lastError = 'bridge returned a non-JSON response';
            return null;
        }
        return $decoded;
    }
}
