<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Live crash-game history provider.
 *
 * Pulls completed (and in-progress, when advertised) rounds from a real
 * public crash API. Never fabricates multipliers. If every endpoint fails,
 * history is empty and the dashboard reports NO_DATA instead of demo data.
 *
 * Default feed: Bustabit public GraphQL/REST (no API key). Override with
 * WINDELS_CRASH_HISTORY_URL / WINDELS_CRASH_API_KEY / WINDELS_CRASH_PROVIDER.
 */
class LiveCrashProvider extends AbstractCrashGameProvider
{
    public const CODE = 'bustabit';

    /** @var callable(string, array): ?string  ($url, $opts) → body */
    private $transport;

    private array $rounds = [];
    private ?array $live = null;
    private ?string $lastError = null;
    private ?string $sourceUrl = null;
    private float $fetchedAt = 0;
    private int $ttlSeconds = 8;

    public function __construct(array $config = [], ?callable $transport = null)
    {
        parent::__construct($config);
        $this->transport = $transport ?? [$this, 'defaultTransport'];
    }

    public function code(): string
    {
        return (string) ($this->config['code'] ?? self::CODE);
    }

    public function name(): string
    {
        return (string) ($this->config['name'] ?? 'Bustabit (Live)');
    }

    public function isConfigured(): bool
    {
        $this->refresh();
        return $this->rounds !== [] || $this->live !== null;
    }

    public function metadata(): array
    {
        $this->refresh();
        return [
            'game' => 'crash',
            'mode' => $this->rounds === [] ? 'offline' : 'live',
            'disclaimer' => $this->rounds === []
                ? 'LIVE FEED UNAVAILABLE — no demo data is substituted'
                : 'LIVE crash history from ' . ($this->sourceUrl ?? 'remote provider'),
            'houseEdge' => 0.01,
            'totalRounds' => count($this->rounds),
            'source' => $this->sourceUrl,
            'error' => $this->lastError,
            'fetchedAt' => $this->fetchedAt ? gmdate('c', (int) $this->fetchedAt) : null,
        ];
    }

    public function latestRound(): ?array
    {
        $this->refresh();
        if ($this->rounds === []) {
            return null;
        }
        return $this->rounds[count($this->rounds) - 1];
    }

    public function history(int $limit = 100): array
    {
        $this->refresh();
        if ($limit <= 0) {
            return [];
        }
        return array_slice($this->rounds, -$limit);
    }

    public function currentMultiplier(): ?float
    {
        $this->refresh();
        if ($this->live && isset($this->live['currentMultiplier'])) {
            return (float) $this->live['currentMultiplier'];
        }
        return null;
    }

    public function isInRound(): bool
    {
        $this->refresh();
        return !empty($this->live['inRound']);
    }

    public function health(): array
    {
        $this->refresh();
        $ok = $this->rounds !== [];
        return [
            'status' => $ok ? 'ONLINE' : 'DOWN',
            'mode' => $ok ? 'live' : 'offline',
            'rounds' => count($this->rounds),
            'inRound' => $this->isInRound(),
            'source' => $this->sourceUrl,
            'error' => $this->lastError,
            'checkedAt' => gmdate('c'),
        ];
    }

    /** Refresh cache (used by /multiplier/live). */
    public function updateMultiplier(): array
    {
        $this->fetchedAt = 0;
        $this->refresh();
        return [
            'currentMultiplier' => $this->currentMultiplier() ?? 1.0,
            'roundId' => $this->live['roundId'] ?? ($this->latestRound()['roundId'] ?? null),
            'inRound' => $this->isInRound(),
            'elapsedMs' => (int) ($this->live['elapsedMs'] ?? 0),
        ];
    }

    public function allRounds(): array
    {
        $this->refresh();
        return $this->rounds;
    }

    public function stats(): array
    {
        $this->refresh();
        $multipliers = array_column($this->rounds, 'multiplier');
        $count = count($multipliers);
        if ($count === 0) {
            return ['error' => 'No live rounds available', 'total_rounds' => 0];
        }
        sort($multipliers);
        $mean = array_sum($multipliers) / $count;
        $variance = 0.0;
        foreach ($multipliers as $m) {
            $variance += ($m - $mean) ** 2;
        }
        return [
            'total_rounds' => $count,
            'mean' => round($mean, 2),
            'median' => round($multipliers[(int) ($count / 2)], 2),
            'stddev' => round(sqrt($variance / $count), 2),
            'min' => round(min($multipliers), 2),
            'max' => round(max($multipliers), 2),
            'p25' => round($multipliers[(int) ($count * 0.25)], 2),
            'p75' => round($multipliers[(int) ($count * 0.75)], 2),
            'house_edge' => 0.01,
            'distribution' => 'empirical-live',
        ];
    }

    /**
     * Parse a provider JSON payload into normalised rounds.
     * Public so tests can pin parser behaviour without the network.
     *
     * @return array{rounds: array, live: ?array}
     */
    public static function parsePayload($json): array
    {
        if (!is_array($json)) {
            return ['rounds' => [], 'live' => null];
        }

        $rows = self::extractRows($json);
        $rounds = [];
        $live = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = self::mapRow($row);
            if ($mapped === null) {
                continue;
            }
            if (!empty($mapped['_live'])) {
                unset($mapped['_live']);
                $live = $mapped;
                continue;
            }
            $rounds[] = $mapped;
        }

        usort($rounds, static function ($a, $b) {
            return strcmp((string) ($a['timestamp'] ?? ''), (string) ($b['timestamp'] ?? ''));
        });

        return ['rounds' => $rounds, 'live' => $live];
    }

    private function refresh(): void
    {
        if ($this->fetchedAt > 0 && (microtime(true) - $this->fetchedAt) < $this->ttlSeconds) {
            return;
        }
        $this->fetchedAt = microtime(true);
        $this->lastError = null;

        foreach ($this->endpoints() as $endpoint) {
            try {
                $body = ($this->transport)($endpoint['url'], $endpoint);
                if ($body === null || $body === '') {
                    continue;
                }
                $json = json_decode($body, true);
                $parsed = self::parsePayload($json);
                if ($parsed['rounds'] === [] && $parsed['live'] === null) {
                    continue;
                }
                $this->rounds = $parsed['rounds'];
                $this->live = $parsed['live'];
                $this->sourceUrl = $endpoint['url'];
                return;
            } catch (\Throwable $e) {
                $this->lastError = $e->getMessage();
            }
        }

        if ($this->rounds === []) {
            $this->lastError = $this->lastError ?: 'all live crash endpoints failed';
            $this->sourceUrl = null;
            $this->live = null;
        }
    }

    /** @return list<array{url:string,method:string,headers?:array,body?:string}> */
    private function endpoints(): array
    {
        $custom = trim((string) ($this->config['url'] ?? getenv('WINDELS_CRASH_HISTORY_URL') ?: ''));
        $key = trim((string) ($this->config['apiKey'] ?? getenv('WINDELS_CRASH_API_KEY') ?: ''));
        $out = [];
        if ($custom !== '') {
            $headers = ['Accept: application/json', 'User-Agent: AI_WORKFORCE/0.3'];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
                $headers[] = 'X-API-Key: ' . $key;
            }
            $out[] = ['url' => $custom, 'method' => 'GET', 'headers' => $headers];
        }

        $gql = '{ games(first: 100) { id bust hash createdAt created } }';
        $out[] = [
            'url' => 'https://api.bustabit.com/graphql',
            'method' => 'POST',
            'headers' => ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: AI_WORKFORCE/0.3'],
            'body' => json_encode(['query' => $gql]),
        ];
        $out[] = [
            'url' => 'https://api.bustabit.com/graphql?query=' . rawurlencode($gql),
            'method' => 'GET',
            'headers' => ['Accept: application/json', 'User-Agent: AI_WORKFORCE/0.3'],
        ];
        $out[] = [
            'url' => 'https://api.bustabit.com/games?limit=100',
            'method' => 'GET',
            'headers' => ['Accept: application/json', 'User-Agent: AI_WORKFORCE/0.3'],
        ];

        return $out;
    }

    private function defaultTransport(string $url, array $opts): ?string
    {
        $method = strtoupper((string) ($opts['method'] ?? 'GET'));
        $headers = $opts['headers'] ?? ['Accept: application/json', 'User-Agent: AI_WORKFORCE/0.3'];
        $http = [
            'method' => $method,
            'timeout' => 8.0,
            'header' => implode("\r\n", $headers) . "\r\n",
            'ignore_errors' => true,
        ];
        if ($method === 'POST' && !empty($opts['body'])) {
            $http['content'] = $opts['body'];
        }
        $ctx = stream_context_create([
            'http' => $http,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    private static function extractRows(array $json): array
    {
        foreach (['data.games', 'data.games.edges', 'games', 'history', 'rounds', 'results', 'items', 'data'] as $path) {
            $cur = $json;
            $ok = true;
            foreach (explode('.', $path) as $seg) {
                if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                    $ok = false;
                    break;
                }
                $cur = $cur[$seg];
            }
            if ($ok && is_array($cur)) {
                if (isset($cur[0]) || $cur === []) {
                    $rows = [];
                    foreach ($cur as $item) {
                        if (is_array($item) && isset($item['node']) && is_array($item['node'])) {
                            $rows[] = $item['node'];
                        } else {
                            $rows[] = $item;
                        }
                    }
                    return $rows;
                }
            }
        }
        if (isset($json[0]) && is_array($json[0])) {
            return $json;
        }
        return [];
    }

    private static function mapRow(array $row): ?array
    {
        $id = $row['id'] ?? $row['roundId'] ?? $row['gameId'] ?? $row['game_id'] ?? $row['gid'] ?? null;
        $bust = $row['bust'] ?? $row['crashedAt'] ?? $row['crashPoint'] ?? $row['crash_point']
            ?? $row['multiplier'] ?? $row['crash'] ?? $row['result'] ?? null;
        $inProgress = ($bust === null || $bust === '') && (
            !empty($row['inProgress']) || !empty($row['inRound']) || (($row['status'] ?? '') === 'IN_PROGRESS')
        );
        if ($inProgress) {
            $liveMult = $row['currentMultiplier'] ?? $row['multiplier'] ?? $row['elapsed'] ?? 1.0;
            return [
                '_live' => true,
                'roundId' => $id !== null ? (string) $id : null,
                'currentMultiplier' => (float) $liveMult,
                'inRound' => true,
                'elapsedMs' => (int) ($row['elapsedMs'] ?? 0),
            ];
        }
        if ($bust === null || $bust === '' || !is_numeric($bust)) {
            return null;
        }
        $mult = (float) $bust;
        if ($mult < 1.0) {
            return null;
        }
        $ts = $row['createdAt'] ?? $row['created'] ?? $row['timestamp'] ?? $row['startedAt']
            ?? $row['crashedAt'] ?? $row['time'] ?? gmdate('c');
        if (is_numeric($ts) && (int) $ts > 1_000_000_000) {
            $ts = gmdate('c', (int) ((int) $ts > 20_000_000_000 ? ((int) $ts / 1000) : $ts));
        }
        return [
            'roundId' => $id !== null ? (string) $id : ('live_' . substr(sha1((string) $mult . $ts), 0, 12)),
            'multiplier' => round($mult, 2),
            'timestamp' => (string) $ts,
            'startedAt' => (string) $ts,
            'crashedAt' => (string) $ts,
            'hash' => isset($row['hash']) ? (string) $row['hash'] : null,
            'gameCode' => 'crash',
            'verified' => !empty($row['hash']),
        ];
    }
}
