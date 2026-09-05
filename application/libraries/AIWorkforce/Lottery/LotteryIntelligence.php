<?php
namespace AIWorkforce\Lottery;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\LotteryRepository;

/**
 * WINDELS Lottery Intelligence — module facade (Phases 5–8, 13–15, 19, 26, 31).
 *
 * Wires provider → validation → historical database → statistics →
 * combination analysis / generation / diversification, with the same
 * governance rules as the rest of WINDELS: source attribution on every
 * record, idempotent ingestion, audited decisions, and honest state when no
 * provider is configured (DISABLED_NO_PROVIDER — nothing fabricated).
 *
 * EuroMillions is a random lottery: this module provides STATISTICAL
 * intelligence, never a claim of prediction (spec §41/§42).
 */
class LotteryIntelligence
{
    /** WINDELS Lottery Model — bumped on any change to generation/statistics behavior. */
    public const MODEL_VERSION = '1.0';
    public const LOTTERY = 'EUROMILLIONS';
    public const ENGINE_DISABLED = 'DISABLED_NO_PROVIDER';
    public const ENGINE_ACTIVE = 'ACTIVE';
    /** Honest dashboard state: the feed failed AND nothing verified is stored. */
    public const STATUS_DATA_UNAVAILABLE = 'DATA UNAVAILABLE';
    /** The live feed is not serving, but verified historical draws ARE stored. */
    public const STATUS_STORED_DATA = 'STORED DATA';
    public const VERIFIED = 'VERIFIED';

    public readonly LotteryStatisticsEngine $statistics;
    public readonly CombinationAnalyzer $analyzer;
    public readonly CombinationGenerator $generator;
    public readonly DiversificationEngine $diversification;
    public readonly SystemBuilder $systemBuilder;
    public readonly LotteryBacktester $backtester;
    public readonly LotteryRules $rules;
    public readonly LotteryProvider $provider;

    public function __construct(
        private LotteryRepository $repo,
        private AuditRepository $audit,
        ?LotteryProvider $provider = null,
        ?LotteryRules $rules = null,
    ) {
        $this->statistics = new LotteryStatisticsEngine();
        $this->rules = $rules ?? $this->storedRules() ?? new EuroMillionsRules();
        $this->provider = $provider ?? new UnavailableLotteryProvider();
        $this->analyzer = new CombinationAnalyzer($this->rules, $this->statistics);
        $this->generator = new CombinationGenerator($this->rules, $this->analyzer, $this->statistics, self::MODEL_VERSION);
        $this->diversification = new DiversificationEngine($this->rules);
        $this->systemBuilder = new SystemBuilder($this->rules);
        $this->backtester = new LotteryBacktester($this->rules, $this->analyzer, $this->generator, self::MODEL_VERSION);
        $this->repo->ensureLottery($this->rules->code(), $this->rules->name(), $this->rules->version());
    }

    /** Stored (admin-updatable) rules when present; the code default is authoritative otherwise. */
    private function storedRules(): ?LotteryRules
    {
        $row = $this->repo->activeRules(self::LOTTERY);
        return $row ? EuroMillionsRules::fromArray(self::LOTTERY, 'EuroMillions', $row) : null;
    }

    // ------------------------------------------------------------------ status

    public function status(): array
    {
        $health = $this->provider->health();
        $enabled = $health['state'] === 'ONLINE';
        $last = $this->repo->listDraws(['lotteryCode' => self::LOTTERY], 1);
        $lastDraw = $last !== [] ? $this->presentDraw($last[0]) : null;
        $jackpot = $lastDraw['jackpot'] ?? null;
        // Where the displayed amount came from — the dashboard must be able to
        // prove the jackpot is feed data, never a hardcoded figure.
        $jackpotSource = $jackpot !== null && $jackpot !== ''
            ? ['origin' => 'STORED_DRAW', 'provider' => $this->provider->id(),
               'observedAt' => $lastDraw['source_timestamp'] ?? null, 'currency' => 'EUR']
            : null;
        $jpInfo = $this->provider->jackpotInfo();
        if (is_array($jpInfo) && ($jpInfo['value'] ?? null) !== null && ($jpInfo['value'] ?? '') !== '') {
            $jackpot = $jpInfo['value'];
            $jackpotSource = [
                'origin' => 'PROVIDER_FEED',
                'provider' => $this->provider->id(),
                'feed' => (string) ($jpInfo['source'] ?? $this->provider->id()),
                'observedAt' => $jpInfo['observedAt'] ?? null,
                'currency' => (string) ($jpInfo['currency'] ?? 'EUR'),
                'hardcoded' => false,
            ];
        }
        $imported = $this->drawCount();
        $verified = $this->verifiedDrawCount();
        $sync = $this->syncState();
        $uiStatus = $enabled ? 'ONLINE' : (($health['state'] ?? '') === 'UNCONFIGURED' ? 'NO_DATA' : (string) ($health['state'] ?? 'NO_DATA'));
        // Honest failure state (spec §41): when the feed cannot be reached and
        // nothing verified is stored, the module says DATA UNAVAILABLE — it
        // never presents an empty dataset as "no lottery data exists".
        // A feed that is configured but unreachable is DATA UNAVAILABLE; an
        // unconfigured feed stays NO_DATA (nothing was ever connected). When
        // verified draws ARE stored, the module never claims NO_DATA — the
        // live feed is down, the historical dataset is not.
        $dataAvailable = $verified > 0;
        $providerState = (string) ($health['state'] ?? '');
        $feedFailed = in_array($providerState, ['OFFLINE', 'DEGRADED', 'ERROR'], true);
        if (!$enabled) {
            if ($dataAvailable) {
                $uiStatus = self::STATUS_STORED_DATA;
            } elseif ($feedFailed) {
                $uiStatus = self::STATUS_DATA_UNAVAILABLE;
            }
        }
        return [
            'module' => 'lottery-intelligence',
            'activeLottery' => self::LOTTERY,
            'lotteries' => array_map(fn($l) => ['code' => $l['code'], 'name' => $l['name'], 'enabled' => (bool) $l['enabled']], $this->repo->listLotteries()),
            'rules' => [
                'code' => $this->rules->code(),
                'version' => $this->rules->version(),
                'main' => ['count' => $this->rules->mainCount(), 'min' => $this->rules->mainMin(), 'max' => $this->rules->mainMax()],
                'stars' => ['count' => $this->rules->starCount(), 'min' => $this->rules->starMin(), 'max' => $this->rules->starMax()],
                'schedule' => $this->rules->drawSchedule(),
                'mains' => $this->rules->mainCount(),
                'mainMax' => $this->rules->mainMax(),
                'starMax' => $this->rules->starMax(),
            ],
            'provider' => $health + ['id' => $this->provider->id(), 'name' => $this->provider->name()],
            'providerLabel' => (string) ($health['message'] ?? $this->provider->id()),
            'engine' => $enabled ? self::ENGINE_ACTIVE : self::ENGINE_DISABLED,
            'modelVersion' => self::MODEL_VERSION,
            'drawsTracked' => $imported,
            'lastDraw' => $lastDraw,
            'disclaimer' => LotteryStatisticsEngine::DISCLAIMER,
            // Dashboard / widget aliases (views/lottery/index.php)
            'status' => $uiStatus,
            'jackpot' => $jackpot,
            'jackpotSource' => $jackpotSource,
            'imported' => $imported,
            'verifiedDraws' => $verified,
            'dataAvailable' => $dataAvailable,
            'historicalDataset' => $this->datasetInfo(),
            'lastSuccessfulSync' => $sync['lastSuccessAt'],
            'lastSyncAttempt' => $sync['lastAttemptAt'],
            'syncStatus' => $sync['status'],
            'syncMessage' => $sync['message'],
            'nextEstimated' => $this->nextDrawHint(),
        ];
    }

    /** Next EuroMillions draw day (Tue/Fri) as a hint — not a guaranteed official date. */
    private function nextDrawHint(): ?string
    {
        $days = $this->rules->drawSchedule()['days'] ?? [2, 5];
        $ts = time();
        for ($i = 0; $i < 8; $i++) {
            $t = $ts + $i * 86400;
            if (in_array((int) gmdate('w', $t), $days, true)) return gmdate('Y-m-d', $t);
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    public function presentDraw(array $row): array
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $mains = array_map('intval', (array) ($payload['main'] ?? []));
        $stars = array_map('intval', (array) ($payload['stars'] ?? []));
        $row['numbers'] = ['main' => $mains, 'stars' => $stars];
        $row['main_numbers'] = $mains;
        $row['lucky_stars'] = $stars;
        $row['draw_no'] = $row['external_id'] ?? ($row['id'] ?? '');
        return $row;
    }

    public function drawCount(): int
    {
        return $this->repo->countDraws(self::LOTTERY);
    }

    /**
     * Draws that passed validation and are stored as official results. Only
     * these feed the statistics and the AI generator.
     */
    public function verifiedDrawCount(): int
    {
        return count($this->repo->listDraws([
            'lotteryCode' => self::LOTTERY,
            'verificationStatus' => self::VERIFIED,
        ], 100000));
    }

    /**
     * The verified historical dataset every statistical/generation path reads.
     * A single accessor keeps Strategy Lab, statistics and the generator on
     * exactly the same rows (and makes "which dataset was used" auditable).
     * @return list<array{drawDate:string,main:list<int>,stars:list<int>}>
     */
    public function historicalDataset(int $limit = 100000): array
    {
        return $this->repo->drawsForStats(self::LOTTERY, $limit);
    }

    /** Dataset provenance stamp shown on the dashboard and every report. */
    public function datasetInfo(): array
    {
        $draws = $this->historicalDataset();
        $n = count($draws);
        $first = $n > 0 ? (string) $draws[0]['drawDate'] : null;
        $last = $n > 0 ? (string) $draws[$n - 1]['drawDate'] : null;
        return [
            'source' => 'VERIFIED_HISTORICAL_DATABASE',
            'draws' => $n,
            'from' => $first,
            'to' => $last,
            'available' => $n > 0,
            'datasetVersion' => 'n=' . $n . ';last=' . ($last ?? 'none'),
        ];
    }

    /**
     * Last sync attempt / last SUCCESSFUL sync and a coarse status, read from
     * the recorded provider-health history (never invented).
     * @return array{status:string,lastSuccessAt:?string,lastAttemptAt:?string,message:string}
     */
    public function syncState(): array
    {
        $pid = $this->providerRowId();
        $latest = $pid !== null ? $this->repo->latestHealth($pid) : null;
        $history = $pid !== null ? $this->repo->listHealth($pid, 50) : [];
        $lastSuccess = null;
        foreach ($history as $h) {
            if (!empty($h['last_success_at'])) { $lastSuccess = (string) $h['last_success_at']; break; }
        }
        $lastAttempt = $latest !== null ? (string) ($latest['observed_at'] ?? '') : null;
        if ($lastAttempt === '') $lastAttempt = null;
        if ($latest === null) {
            // No health row for the ACTIVE provider (e.g. the feed was
            // reconfigured, or draws were ingested by another provider). The
            // stored draws still prove when data last landed — report that
            // rather than claiming nothing ever synchronized.
            $stored = $this->repo->listDraws(['lotteryCode' => self::LOTTERY, 'verificationStatus' => self::VERIFIED], 1);
            $retrieved = $stored !== [] ? (string) ($stored[0]['retrieved_at'] ?? '') : '';
            if ($retrieved !== '') {
                return ['status' => 'STALE', 'lastSuccessAt' => $retrieved, 'lastAttemptAt' => $retrieved,
                    'message' => 'Serving stored verified draws. The active provider has not synchronized yet — '
                        . 'the newest verified data was retrieved at ' . $retrieved . '.'];
            }
            return ['status' => 'NEVER_SYNCED', 'lastSuccessAt' => null, 'lastAttemptAt' => null,
                'message' => 'No synchronization has run yet for this provider.'];
        }
        $state = strtoupper((string) ($latest['status'] ?? ''));
        $status = match ($state) {
            'ONLINE' => 'OK',
            'DEGRADED' => 'DEGRADED',
            default => 'FAILED',
        };
        if ($lastSuccess === null && $status === 'OK') $lastSuccess = $lastAttempt;
        $message = match ($status) {
            'OK' => 'Last synchronization succeeded' . ($lastSuccess !== null ? ' at ' . $lastSuccess : '') . '.',
            'DEGRADED' => 'The last synchronization returned no usable draws — historical data may be incomplete.',
            default => 'DATA UNAVAILABLE — the last synchronization failed'
                . ($lastSuccess !== null ? '; the newest verified data is from ' . $lastSuccess : ' and no verified data has been stored') . '.',
        };
        return ['status' => $status, 'lastSuccessAt' => $lastSuccess, 'lastAttemptAt' => $lastAttempt, 'message' => $message];
    }

    // ------------------------------------------------------------------- ingestion

    /**
     * Sync from the configured provider: fetch → validate → store (idempotent)
     * → provider health. (spec §28/§31)
     */
    public function sync(int $limit = 100): array
    {
        $health = $this->provider->health();
        if ($health['state'] !== 'ONLINE') {
            // A configured-but-unreachable feed is a FAILURE, not "no provider":
            // record it so the dashboard can say DATA UNAVAILABLE honestly.
            $offline = in_array((string) ($health['state'] ?? ''), ['OFFLINE', 'DEGRADED', 'ERROR'], true);
            if ($offline) {
                $pid = $this->providerRowId();
                if ($pid !== null) {
                    $this->repo->saveHealth($pid, [
                        'status' => 'OFFLINE',
                        'response_ms' => 0,
                        'records_received' => 0,
                        'invalid_records' => 0,
                        'last_success_at' => null,
                        'last_failure_at' => gmdate('c'),
                        'last_draw_retrieved' => null,
                        'synthetic' => !empty($health['synthetic']) ? 1 : 0,
                    ]);
                }
                $this->audit->emit('LOTTERY_SYNC_FAILED', 'Lottery sync could not run: ' . (string) ($health['message'] ?? 'provider unavailable'), [
                    'provider' => $this->provider->id(), 'state' => $health['state'] ?? null,
                ], 'system');
                return ['status' => self::STATUS_DATA_UNAVAILABLE, 'provider' => $this->provider->id(),
                    'imported' => 0, 'failed' => 0, 'unchanged' => 0, 'conflicts' => 0, 'corrected' => 0,
                    'verifiedDraws' => $this->verifiedDrawCount(),
                    'errors' => [['provider' => $this->provider->id(), 'errors' => [(string) ($health['message'] ?? 'provider unavailable')]]],
                    'message' => 'DATA UNAVAILABLE — ' . (string) ($health['message'] ?? 'the lottery feed is unreachable')];
            }
            return ['status' => 'NO_PROVIDER', 'provider' => $this->provider->id(), 'imported' => 0, 'failed' => 0, 'unchanged' => 0, 'conflicts' => 0, 'errors' => []];
        }
        $t0 = microtime(true);
        $error = null;
        try {
            $raw = $this->provider->draws(null, null, $limit);
        } catch (\Throwable $e) {
            $raw = [];
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $summary = $this->importDraws($raw);
        $respMs = (int) round((microtime(true) - $t0) * 1000);
        $pid = $this->providerRowId();
        $ok = count($raw) > 0;
        // The newest date in the batch, whichever order the feed used.
        $lastDraw = null;
        foreach ($raw as $d) {
            $date = (string) ($d['drawDate'] ?? '');
            if ($date !== '' && ($lastDraw === null || $date > $lastDraw)) $lastDraw = $date;
        }
        if ($pid !== null) {
            $this->repo->saveHealth($pid, [
                'status' => $ok ? 'ONLINE' : ($error !== null ? 'OFFLINE' : 'DEGRADED'),
                'response_ms' => $respMs,
                'records_received' => count($raw),
                'invalid_records' => (int) $summary['failed'],
                'last_success_at' => $ok ? gmdate('c') : null,
                'last_failure_at' => $ok ? null : gmdate('c'),
                'last_draw_retrieved' => $lastDraw,
                'synthetic' => !empty($health['synthetic']) ? 1 : 0,
            ]);
        }
        $verified = $this->verifiedDrawCount();
        if ($ok) {
            $this->audit->emit('LOTTERY_SYNC_COMPLETED',
                'Lottery sync: ' . (int) $summary['imported'] . ' imported, ' . (int) $summary['unchanged'] . ' unchanged, '
                . (int) $summary['failed'] . ' rejected; ' . $verified . ' verified draws stored', [
                    'provider' => $this->provider->id(), 'received' => count($raw),
                    'imported' => (int) $summary['imported'], 'verifiedDraws' => $verified,
                    'latestDraw' => $lastDraw,
                ], 'system');
        }
        $status = $ok ? 'OK' : ($error !== null ? self::STATUS_DATA_UNAVAILABLE : 'NO_DATA');
        return $summary + [
            'status' => $status,
            'provider' => $this->provider->id(),
            'response_ms' => $respMs,
            'received' => count($raw),
            'verifiedDraws' => $verified,
            'latestDraw' => $lastDraw,
            'dataset' => $this->datasetInfo(),
        ] + ($error !== null ? ['message' => 'DATA UNAVAILABLE — ' . $error] : []);
    }

    /**
     * Import raw provider draws with full validation (spec §5/§6/§28):
     *  - invalid draws are NEVER stored; they are marked DATA_VALIDATION_FAILED and audited
     *  - re-import of identical data is a no-op (idempotent)
     *  - a VERIFIED draw is never silently overwritten; conflicting provider data is audited
     *  - every stored draw carries source + source timestamp + retrieved_at
     * @param list<array<string,mixed>> $rawDraws
     */
    public function importDraws(array $rawDraws): array
    {
        $validator = new LotteryResultValidator($this->rules);
        $summary = ['imported' => 0, 'unchanged' => 0, 'corrected' => 0, 'failed' => 0, 'conflicts' => 0, 'errors' => []];
        $providerId = $this->providerRowId();
        foreach ($rawDraws as $raw) {
            $externalId = (string) ($raw['externalId'] ?? '?');
            $check = $validator->validate($raw);
            if (!$check['valid']) {
                $summary['failed']++;
                $summary['errors'][] = ['externalId' => $externalId, 'errors' => $check['errors']];
                $this->audit->emit('LOTTERY_DRAW_VALIDATION_FAILED', 'Lottery draw ' . $externalId . ' rejected: ' . implode('; ', $check['errors']), [
                    'externalId' => $externalId, 'errors' => $check['errors'], 'lottery' => self::LOTTERY,
                ], 'system');
                continue;
            }
            $numbers = ['main' => array_values($raw['main']), 'stars' => array_values($raw['stars'])];
            $existing = $this->repo->findDrawByExternal(self::LOTTERY, $externalId);
            if ($existing) {
                $existingPayload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
                $same = ($existingPayload['main'] ?? null) === $numbers['main'] && ($existingPayload['stars'] ?? null) === $numbers['stars'];
                if ($same) {
                    $summary['unchanged']++; // idempotent — verified data never silently touched
                    continue;
                }
                if (($existing['verification_status'] ?? '') === 'VERIFIED') {
                    $summary['conflicts']++;
                    $this->audit->emit('LOTTERY_RESULT_CONFLICT', 'Verified draw ' . $externalId . ' differs from provider data — NOT overwritten; manual correction required', [
                        'externalId' => $externalId, 'existing' => $numbers, 'incoming' => $numbers,
                        'existingMain' => $existingPayload['main'] ?? null, 'incomingMain' => $numbers['main'],
                    ], 'system');
                    continue;
                }
                $this->repo->saveDraw($this->drawRow($raw, $numbers, $providerId));
                $this->repo->saveDrawNumbers((int) $existing['id'], $numbers);
                $summary['corrected']++;
                $this->audit->emit('LOTTERY_DRAW_CORRECTED', 'Unverified draw ' . $externalId . ' corrected from provider ' . (string) $raw['source'], ['externalId' => $externalId], 'system');
                continue;
            }
            $out = $this->repo->saveDraw($this->drawRow($raw, $numbers, $providerId));
            $this->repo->saveDrawNumbers((int) $out['row']['id'], $numbers);
            $summary['imported']++;
            $this->audit->emit('LOTTERY_DRAW_IMPORTED', 'Lottery draw ' . $externalId . ' (' . $raw['drawDate'] . ') imported from ' . (string) $raw['source'], [
                'drawId' => (int) $out['row']['id'], 'externalId' => $externalId, 'drawDate' => (string) $raw['drawDate'], 'source' => (string) $raw['source'],
            ], 'system');
        }
        return $summary;
    }

    private function drawRow(array $raw, array $numbers, ?int $providerId): array
    {
        return [
            'lottery_code' => self::LOTTERY,
            'provider_id' => $providerId,
            'external_id' => (string) $raw['externalId'],
            'draw_date' => (string) $raw['drawDate'],
            'jackpot' => isset($raw['jackpot']) && $raw['jackpot'] !== '' ? (string) $raw['jackpot'] : null,
            'rollover' => !empty($raw['rollover']) ? 1 : 0,
            'source' => (string) $raw['source'],
            'source_timestamp' => (string) $raw['sourceTimestamp'],
            'retrieved_at' => gmdate('c'),
            'verification_status' => 'VERIFIED',
            'payload' => json_encode([
                'main' => $numbers['main'], 'stars' => $numbers['stars'],
                'jackpot' => $raw['jackpot'] ?? null, 'rollover' => !empty($raw['rollover']),
                'winners' => $raw['winners'] ?? null, 'source' => (string) $raw['source'],
                // Prize breakdown / winner counts where the feed supplies them
                // (spec §29 — recorded only when reliably provided).
                'prizes' => is_array($raw['prizes'] ?? null) && $raw['prizes'] !== [] ? $raw['prizes'] : null,
                'totalWinners' => isset($raw['totalWinners']) && is_numeric($raw['totalWinners']) ? (int) $raw['totalWinners'] : null,
                'drawDate' => (string) $raw['drawDate'],
                // Provider-specific extras (e.g. LoteriasAPI El Millón code,
                // next-draw jackpot) travel with the draw without changing the
                // provider-neutral contract every engine reads.
                'extra' => is_array($raw['extra'] ?? null) ? $raw['extra'] : null,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }

    private function providerRowId(): ?int
    {
        if ($this->provider instanceof UnavailableLotteryProvider) return null;
        $row = $this->repo->ensureProvider($this->provider->id(), $this->provider->name());
        return (int) $row['id'];
    }

    // ----------------------------------------------------------------- reads

    /** @return array<int,array<string,mixed>> */
    public function listDraws(int $limit = 50, ?string $from = null, ?string $to = null): array
    {
        return $this->repo->listDraws(['lotteryCode' => self::LOTTERY, 'from' => $from, 'to' => $to], $limit);
    }

    /** @return array<string,mixed>|null */
    public function drawDetail(int $id): ?array
    {
        $row = $this->repo->findDraw($id);
        if (!$row || $row['lottery_code'] !== self::LOTTERY) return null;
        $row['numbers'] = $this->repo->listDrawNumbers($id);
        return $row;
    }

    // --------------------------------------------------------------- statistics

    /**
     * Statistical endpoints (spec §8–§14). All outputs carry the DISCLAIMER.
     * EuroMillions draws twice a week — used to interpret calendar windows
     * such as `1y` / `2y` from the live dashboard links.
     */
    public const DRAWS_PER_YEAR = 104;

    /**
     * @param string|int $raw 0|all|50|1y|2y|6m
     */
    public static function parseWindow(string|int $raw): int
    {
        $s = strtolower(trim((string) $raw));
        if ($s === '' || $s === '0' || $s === 'all') return 0;
        if (preg_match('/^(\d+)\s*y/', $s, $m)) return max(1, (int) $m[1]) * self::DRAWS_PER_YEAR;
        if (preg_match('/^(\d+)\s*m/', $s, $m)) return max(1, (int) round((int) $m[1] * self::DRAWS_PER_YEAR / 12));
        if (ctype_digit($s)) return (int) $s;
        return 0;
    }

    public static function normalizeStatsKind(string $kind): string
    {
        $k = strtolower(trim($kind));
        return match ($k) {
            '', 'frequency', 'freq', 'numbers', 'hot-cold-frequency' => 'frequency',
            'gap', 'gaps' => 'gap',
            'hot-cold', 'hotcold', 'hot_cold' => 'hot-cold',
            'distribution', 'dist' => 'distribution',
            'stars' => 'stars',
            'pairs' => 'pairs',
            'triplets' => 'triplets',
            'star-pairs', 'starpairs' => 'star-pairs',
            default => $k,
        };
    }

    /**
     * @param string $kind numbers|stars|hot-cold|distribution|pairs|star-pairs|frequency|gap
     */
    public function statistics(string $kind = 'numbers', int $window = 0): array
    {
        $draws = $this->historicalDataset();
        $r = $this->rules;
        $kind = self::normalizeStatsKind($kind);
        $stats = match ($kind) {
            'numbers', 'frequency' => array_merge(
                $this->statistics->numberStats($draws, $r->mainMin(), $r->mainMax(), $window),
                ['hotCold' => $this->statistics->hotCold($draws, 'main', $r->mainMin(), $r->mainMax(), $window > 0 ? $window : 50)]
            ),
            'gap' => $this->statistics->numberStats($draws, $r->mainMin(), $r->mainMax(), $window),
            'stars' => $this->statistics->starStats($draws, $r->starMin(), $r->starMax(), $window),
            'hot-cold' => $this->statistics->hotCold($draws, 'main', $r->mainMin(), $r->mainMax(), $window > 0 ? $window : 50),
            'distribution' => $this->statistics->distribution($draws, $r->mainMin(), $r->mainMax(), $r->mainCount()),
            'pairs' => $this->statistics->groupStats($draws, 'main', $r->mainMin(), $r->mainMax(), 2),
            'triplets' => $this->statistics->groupStats($draws, 'main', $r->mainMin(), $r->mainMax(), 3),
            'star-pairs' => $this->statistics->groupStats($draws, 'stars', $r->starMin(), $r->starMax(), 2),
            default => throw new \InvalidArgumentException('unknown statistics kind: ' . $kind),
        };
        // Statistical analysis always states which stored dataset produced it
        // (Strategy Lab and the statistics pages read this).
        $stats['dataset'] = $this->datasetInfo();
        return $stats;
    }

    // ------------------------------------------------- combination intelligence

    /**
     * Phase 13 (spec §13): full statistical profile of one line against all
     * stored draws. Historical observation only — never a changed probability.
     * @param list<int> $mains
     * @param list<int> $stars
     */
    public function analyzeLine(array $mains, array $stars): array
    {
        $draws = $this->historicalDataset();
        $profile = $this->analyzer->analyze(array_map('intval', $mains), array_map('intval', $stars), $draws);
        $last = $draws !== [] ? (string) end($draws)['drawDate'] : null;
        return $profile + [
            'lottery' => self::LOTTERY,
            'modelVersion' => self::MODEL_VERSION,
            'datasetVersion' => 'n=' . count($draws) . ';last=' . ($last ?? 'none'),
        ];
    }

    /**
     * Phase 14 (spec §15/§16): generate combination lines. Returns the full
     * AI combination report + decision report (model version, actual inputs,
     * factors, method, scores). Scores are statistical balance scores only.
     */
    public function generate(array $opts = []): array
    {
        $draws = $this->historicalDataset();
        $dataset = $this->datasetInfo();
        $mode = strtoupper((string) ($opts['mode'] ?? 'RANDOM'));
        // Modes that are defined by the historical record must never silently
        // degrade into a random fallback on an empty dataset (spec §41).
        if ($draws === [] && in_array($mode, self::HISTORY_BACKED_MODES, true)) {
            throw new \InvalidArgumentException(
                'DATA UNAVAILABLE — ' . $mode . ' generation requires the verified historical dataset, which is empty. '
                . 'Synchronize the lottery provider first (Admin → API Management → lottery), or use mode RANDOM for an explicit random baseline.'
            );
        }
        $report = $this->generator->generate($draws, $opts);
        // Provenance: which dataset actually produced these lines.
        $report['dataset'] = $dataset + [
            'usedForGeneration' => in_array($mode, self::HISTORY_BACKED_MODES, true),
            'randomBaseline' => $mode === 'RANDOM',
        ];
        return $report;
    }

    /** Modes whose output is derived from the stored historical draws. */
    public const HISTORY_BACKED_MODES = ['BALANCED', 'HISTORICAL', 'ANTI-POPULAR', 'DIVERSIFIED'];

    /**
     * Persist a generated report: one lottery_combinations row (the lines +
     * constraints) and one lottery_ai_decisions row (the full report), plus
     * an audit event. Historical results stay connected to their model
     * version (spec §33).
     * @return array{combinationId:int,decisionId:int}
     */
    public function saveGeneration(array $report, string $actor = 'system'): array
    {
        $now = gmdate('c');
        $model = (string) ($report['model'] ?? 'WINDELS Lottery Model v' . self::MODEL_VERSION);
        $mode = (string) ($report['mode'] ?? 'RANDOM');
        $lines = array_map(
            fn($l) => ['mains' => array_map('intval', (array) ($l['mains'] ?? [])), 'stars' => array_map('intval', (array) ($l['stars'] ?? [])), 'score' => $l['score'] ?? null],
            (array) ($report['lines'] ?? [])
        );
        $comb = $this->repo->saveCombination([
            'lottery_code' => self::LOTTERY,
            'mode' => $mode,
            'model_version' => $model,
            'seed' => isset($report['inputs']['seed']) ? (string) $report['inputs']['seed'] : null,
            'line_count' => count($lines),
            'lines' => json_encode($lines, JSON_UNESCAPED_SLASHES),
            'constraints' => json_encode([
                'locks' => $report['inputs']['locks'] ?? ['mains' => [], 'stars' => []],
                'excludes' => $report['inputs']['excludes'] ?? ['mains' => [], 'stars' => []],
            ], JSON_UNESCAPED_SLASHES),
            'score_summary' => json_encode(['averageBalanceScore' => $report['averageBalanceScore'] ?? null], JSON_UNESCAPED_SLASHES),
            'created_by' => is_numeric($actor) ? (int) $actor : null,
            'created_at' => $now,
        ]);
        $cid = (int) $comb['row']['id'];
        $decision = $this->repo->saveAiDecision([
            'lottery_code' => self::LOTTERY,
            'combination_id' => $cid,
            'model_version' => $model,
            'mode' => $mode,
            'decision' => json_encode($report, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
        $this->audit->emit('LOTTERY_COMBINATION_GENERATED', 'Generated ' . count($lines) . ' combination line(s), mode ' . $mode . ', model ' . $model, [
            'combinationId' => $cid,
            'decisionId' => (int) $decision['row']['id'],
            'mode' => $mode,
            'lineCount' => count($lines),
            'seed' => $report['inputs']['seed'] ?? null,
            'modelVersion' => $model,
        ], $actor);
        return ['combinationId' => $cid, 'decisionId' => (int) $decision['row']['id']];
    }

    /**
     * Phase 15 (spec §22): diversification score for a set of lines.
     * @param list<array{mains:list<int>,stars:list<int>}> $lines
     */
    public function diversity(array $lines): array
    {
        return $this->diversification->score($lines);
    }

    // ------------------------------------------------------- system builder

    /**
     * Phase 16 (spec §18/§19): build a system from a main/star pool.
     * Small systems (<= SYNC_LINE_LIMIT lines) are built inline and saved;
     * larger ones are queued idempotently (execution key) for the background
     * `systems` cron job.
     * @param array{mains:int[],stars:int[]} $pools
     */
    public function buildSystem(array $pools, string $actor = 'system'): array
    {
        $plan = $this->systemBuilder->plan($pools['mains'] ?? [], $pools['stars'] ?? []);
        if ($plan['totalLines'] <= SystemBuilder::SYNC_LINE_LIMIT) {
            $lines = $this->systemBuilder->allLines($plan['mainPool'], $plan['starPool']);
            return ['plan' => $plan, 'queued' => false, 'saved' => $this->saveSystem($plan, $lines, $actor)];
        }
        $key = 'system:' . self::LOTTERY . ':' . md5(implode(',', $plan['mainPool']) . '|' . implode(',', $plan['starPool']));
        $existing = $this->repo->findJobRunByKey($key);
        if ($existing) {
            return ['plan' => $plan, 'queued' => true, 'run' => $existing, 'note' => 'a system build for this pool is already ' . (string) $existing['status'] . ' (idempotent)'];
        }
        $run = $this->repo->startJobRun([
            'id' => \AIWorkforce\Backtest\Backtester::uuid(),
            'jobType' => 'system',
            'executionKey' => $key,
            'payload' => json_encode(['mains' => $plan['mainPool'], 'stars' => $plan['starPool'], 'totalLines' => $plan['totalLines']], JSON_UNESCAPED_SLASHES),
        ]);
        $this->audit->emit('LOTTERY_SYSTEM_QUEUED', 'System build queued: ' . $plan['totalLines'] . ' lines (' . $plan['formula'] . ')', [
            'executionKey' => $key, 'totalLines' => $plan['totalLines'],
        ], $actor);
        return ['plan' => $plan, 'queued' => true, 'run' => $run, 'note' => 'background build queued — processed by: php index.php tools lottery-cron systems'];
    }

    /**
     * Persist a built system (inline or from the background job) as a
     * SYSTEM combination row, with model stamping (spec §33) + audit.
     * @return array{combinationId:int}
     */
    public function saveSystem(array $plan, array $lines, string $actor = 'system'): array
    {
        $row = $this->repo->saveCombination([
            'lottery_code' => self::LOTTERY,
            'mode' => 'SYSTEM',
            'model_version' => 'WINDELS Lottery Model v' . self::MODEL_VERSION,
            'seed' => null,
            'line_count' => count($lines),
            'lines' => json_encode($lines, JSON_UNESCAPED_SLASHES),
            'constraints' => json_encode(['type' => 'system', 'mainPool' => $plan['mainPool'], 'starPool' => $plan['starPool']], JSON_UNESCAPED_SLASHES),
            'score_summary' => json_encode(['totalLines' => count($lines), 'formula' => $plan['formula']], JSON_UNESCAPED_SLASHES),
            'created_by' => is_numeric($actor) ? (int) $actor : null,
            'created_at' => gmdate('c'),
        ]);
        $cid = (int) $row['row']['id'];
        $this->audit->emit('LOTTERY_SYSTEM_BUILT', 'System built and saved: ' . count($lines) . ' lines (' . $plan['formula'] . ')', [
            'combinationId' => $cid, 'totalLines' => count($lines),
        ], $actor);
        return ['combinationId' => $cid];
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function listCombinations(int $limit = 50, int $offset = 0): array
    {
        return $this->repo->listCombinations($limit, $offset);
    }

    /** @return array<string,mixed>|null */
    public function combinationDetail(int $id): ?array
    {
        $row = $this->repo->findCombination($id);
        if (!$row || $row['lottery_code'] !== self::LOTTERY) return null;
        $row['decisions'] = $this->repo->listAiDecisions($id, 5);
        return $row;
    }

    /** @return array<int, array<string, mixed>> newest first */
    public function listDecisions(?int $combinationId = null, int $limit = 50): array
    {
        return $this->repo->listAiDecisions($combinationId, $limit);
    }

    // ------------------------------------------------------------ saved tickets

    public const MAX_TICKET_LINES = 50;
    public const TICKET_METHODS = ['MANUAL', 'RANDOM', 'BALANCED', 'HISTORICAL', 'DIVERSIFIED', 'ANTI-POPULAR'];
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CHECKED = 'CHECKED';
    public const STATUS_ARCHIVED = 'ARCHIVED';

    /**
     * Official EuroMillions prize tier structure (stable rule set). Tier
     * labels only — prize amounts vary per draw and are NEVER stored
     * (spec §29: "where applicable and reliably supported").
     */
    public static function prizeTier(int $mainMatches, int $starMatches): ?string
    {
        return match (true) {
            $mainMatches === 5 && $starMatches === 2 => 'TIER_1 (5 mains + 2 stars)',
            $mainMatches === 5 && $starMatches === 1 => 'TIER_2 (5 mains + 1 star)',
            $mainMatches === 5 => 'TIER_3 (5 mains)',
            $mainMatches === 4 && $starMatches === 2 => 'TIER_4 (4 mains + 2 stars)',
            $mainMatches === 4 && $starMatches === 1 => 'TIER_5 (4 mains + 1 star)',
            $mainMatches === 3 && $starMatches === 2 => 'TIER_6 (3 mains + 2 stars)',
            $mainMatches === 3 && $starMatches === 1 => 'TIER_7 (3 mains + 1 star)',
            $mainMatches === 2 && $starMatches === 2 => 'TIER_8 (2 mains + 2 stars)',
            $mainMatches === 1 && $starMatches === 2 => 'TIER_9 (1 main + 2 stars)',
            $mainMatches === 0 && $starMatches === 2 => 'TIER_10 (2 stars)',
            default => null,
        };
    }

    /**
     * Phase 17 (spec §20): create a named, user-scoped ticket. EVERY line is
     * validated against the rules before anything is stored — one bad line
     * rejects the whole ticket. Lines are normalized (sorted) on save.
     * @param list<array{mains:list<int>,stars:list<int>}> $lines
     * @return array<string,mixed> the stored ticket row
     */
    public function createTicket(int $userId, string $name, array $lines, string $method = 'MANUAL', ?string $drawDate = null, ?string $modelVersion = null, array $configuration = []): array
    {
        $method = strtoupper($method);
        if (!in_array($method, self::TICKET_METHODS, true)) throw new \InvalidArgumentException('unknown generation method: ' . $method);
        if (trim($name) === '') throw new \InvalidArgumentException('ticket name is required');
        if ($lines === []) throw new \InvalidArgumentException('at least one line is required');
        if (count($lines) > self::MAX_TICKET_LINES) throw new \InvalidArgumentException('a ticket holds at most ' . self::MAX_TICKET_LINES . ' lines');
        if ($drawDate !== null) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $drawDate);
            if ($d === false || $d->format('Y-m-d') !== (string) $drawDate) throw new \InvalidArgumentException('invalid draw date: ' . $drawDate);
        }
        $validated = [];
        foreach ($lines as $i => $line) {
            $mains = array_values((array) ($line['mains'] ?? []));
            $stars = array_values((array) ($line['stars'] ?? []));
            $check = $this->rules->validateLine($mains, $stars);
            if (!$check['valid']) throw new \InvalidArgumentException('line ' . ($i + 1) . ' invalid: ' . implode('; ', $check['errors']));
            sort($mains);
            sort($stars);
            $validated[] = ['mains' => $mains, 'stars' => $stars];
        }
        $now = gmdate('c');
        $row = $this->repo->saveTicket([
            'user_id' => $userId,
            'lottery_code' => self::LOTTERY,
            'name' => (string) $name,
            'draw_date' => $drawDate,
            'generation_method' => $method,
            'model_version' => $modelVersion ?? 'MANUAL',
            'configuration' => json_encode($configuration, JSON_UNESCAPED_SLASHES),
            'status' => self::STATUS_OPEN,
            'result' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tid = (int) $row['row']['id'];
        $this->repo->saveTicketLines($tid, $validated);
        $this->audit->emit('LOTTERY_TICKET_CREATED', 'Ticket "' . (string) $name . '" created with ' . count($validated) . ' line(s)', [
            'ticketId' => $tid, 'userId' => $userId, 'method' => $method, 'lineCount' => count($validated),
        ], (string) $userId);
        return $row['row'];
    }

    /** The caller's own tickets (spec §38 — never other users'). */
    public function listMyTickets(int $userId, int $limit = 50): array
    {
        return $this->repo->listTickets($userId, $limit);
    }

    /** @return array<string,mixed>|null with decoded lines; user-scoped when $userId given */
    public function ticketDetail(int $id, ?int $userId = null): ?array
    {
        $row = $this->repo->findTicket($id, $userId);
        if (!$row || $row['lottery_code'] !== self::LOTTERY) return null;
        $row['lines'] = $this->repo->ticketLines($id);
        return $row;
    }

    /**
     * Phase 18 (spec §29): compare a ticket's lines against the stored draw
     * for its draw date — the latest VERIFIED draw on or before that date
     * (latest overall when the ticket has no date). Marks the ticket
     * CHECKED with per-line matches + official prize tier (no amounts).
     * User-scoped: a ticket is only checkable by its owner (or system).
     * @return array<string,mixed>|null null = not found / not owner
     */
    public function checkTicket(int $ticketId, ?int $userId = null, string $actor = 'system'): ?array
    {
        $ticket = $this->repo->findTicket($ticketId, $userId);
        if (!$ticket || $ticket['lottery_code'] !== self::LOTTERY) return null;
        $drawFilter = ['lotteryCode' => self::LOTTERY, 'verificationStatus' => 'VERIFIED'];
        if (!empty($ticket['draw_date'])) $drawFilter['to'] = (string) $ticket['draw_date'];
        $draws = $this->repo->listDraws($drawFilter, 1);
        if ($draws === []) {
            return ['ticketId' => $ticketId, 'status' => 'NO_DRAW', 'note' => 'no stored VERIFIED draw on or before ' . (!empty($ticket['draw_date']) ? (string) $ticket['draw_date'] : 'the latest draw')];
        }
        $draw = $draws[0];
        $drawPayload = is_array($draw['payload'] ?? null) ? $draw['payload'] : [];
        $drawMains = array_flip(array_map('intval', (array) ($drawPayload['main'] ?? [])));
        $drawStars = array_flip(array_map('intval', (array) ($drawPayload['stars'] ?? [])));
        $lineResults = [];
        foreach ($this->repo->ticketLines($ticketId) as $line) {
            $mainMatches = count(array_intersect_key(array_flip(array_map('intval', (array) $line['mains'])), $drawMains));
            $starMatches = count(array_intersect_key(array_flip(array_map('intval', (array) $line['stars'])), $drawStars));
            $lineResults[] = [
                'mains' => (array) $line['mains'],
                'stars' => (array) $line['stars'],
                'mainMatches' => $mainMatches,
                'starMatches' => $starMatches,
                'prizeTier' => self::prizeTier($mainMatches, $starMatches),
            ];
        }
        $result = [
            'drawDate' => (string) $draw['draw_date'],
            'drawExternalId' => (string) $draw['external_id'],
            'drawSource' => (string) $draw['source'],
            'lines' => $lineResults,
            'checkedAt' => gmdate('c'),
            'note' => 'Prize tiers follow the official EuroMillions structure; amounts vary per draw and are not stored. These are ACTUAL ticket outcomes — kept separate from backtest results and demo data (spec §30).',
        ];
        $this->repo->updateTicket($ticketId, ['status' => self::STATUS_CHECKED, 'result' => json_encode($result, JSON_UNESCAPED_SLASHES), 'updated_at' => gmdate('c')]);
        $this->audit->emit('LOTTERY_TICKET_CHECKED', 'Ticket ' . $ticketId . ' checked against draw ' . (string) $draw['draw_date'], [
            'ticketId' => $ticketId, 'drawDate' => (string) $draw['draw_date'],
        ], $actor);
        return $result + ['ticketId' => $ticketId, 'status' => self::STATUS_CHECKED];
    }

    /** Phase 17: archive (soft delete) — user-scoped. */
    public function archiveTicket(int $ticketId, ?int $userId = null, string $actor = 'system'): bool
    {
        $ticket = $this->repo->findTicket($ticketId, $userId);
        if (!$ticket || $ticket['lottery_code'] !== self::LOTTERY) return false;
        $this->repo->updateTicket($ticketId, ['status' => self::STATUS_ARCHIVED, 'updated_at' => gmdate('c')]);
        $this->audit->emit('LOTTERY_TICKET_ARCHIVED', 'Ticket ' . $ticketId . ' archived', ['ticketId' => $ticketId], $actor);
        return true;
    }

    // ------------------------------------------------------------------ health

    /** Provider health (spec §32): status + last sync + validation failures. */
    public function providerHealth(): array
    {
        $health = $this->provider->health();
        $pid = $this->providerRowId();
        $latest = $pid !== null ? $this->repo->latestHealth($pid) : null;
        $history = $pid !== null ? $this->repo->listHealth($pid, 20) : [];
        return ['provider' => $this->provider->id(), 'live' => $health, 'latest' => $latest, 'history' => $history];
    }

    // ------------------------------------------------- model versioning (23/24)

    /**
     * Model versioning (spec §33): the model row records name, version and the
     * full statistical configuration. It is ensured once and never deleted or
     * replaced — historical results stay connected to the model that produced
     * them. Idempotent: repeated calls return the same row.
     */
    public function ensureModelVersion(): array
    {
        $config = [
            'model' => 'WINDELS Lottery Model v' . self::MODEL_VERSION,
            'scoreWeights' => CombinationAnalyzer::WEIGHTS,
            'generatorModes' => CombinationGenerator::MODES,
            'backtesterStrategies' => LotteryBacktester::STRATEGIES,
            'backtester' => [
                'minHistory' => LotteryBacktester::MIN_HISTORY,
                'maxWindow' => LotteryBacktester::MAX_WINDOW,
                'maxLines' => LotteryBacktester::MAX_LINES,
            ],
        ];
        return $this->repo->ensureModelVersion([
            'model_name' => 'WINDELS Lottery Model',
            'model_version' => self::MODEL_VERSION,
            'config' => json_encode($config),
            'dataset_version' => null,
            'status' => 'ACTIVE',
            'created_at' => gmdate('c'),
        ]);
    }

    /** @return array<int, array<string, mixed>> every known model version, oldest first */
    public function modelVersions(): array
    {
        $this->ensureModelVersion();
        return $this->repo->listModelVersions();
    }

    // ------------------------------------------------------ backtesting (20-22)

    /**
     * Phases 20–22 (spec §23/§24/§25): replay a strategy over the stored draws
     * WITHOUT look-ahead, persist the HISTORICAL SIMULATION report (audited,
     * model-version stamped) and return it with the saved row id.
     * @throws \InvalidArgumentException unknown strategy or insufficient history
     */
    public function backtest(string $strategy, int $lines = 1, int $window = 0): array
    {
        $this->ensureModelVersion();
        $draws = $this->historicalDataset();
        $report = $this->backtester->run($draws, $strategy, $lines, $window);
        $saved = $this->persistBacktest($strategy, $report, $draws);
        return $report + ['dataset' => $this->datasetInfo(), 'saved' => ['backtestId' => (int) $saved['row']['id']]];
    }

    /**
     * Strategy comparison (spec §24): every strategy replayed on the SAME
     * period, including the mandatory random baseline (spec §25). The
     * comparison report is persisted like any other backtest row.
     */
    public function backtestCompare(array $strategies, int $lines = 1, int $window = 0): array
    {
        $this->ensureModelVersion();
        $draws = $this->historicalDataset();
        $report = $this->backtester->compare($draws, $strategies, $lines, $window);
        $saved = $this->persistBacktest('COMPARISON', $report, $draws);
        return $report + ['dataset' => $this->datasetInfo(), 'saved' => ['backtestId' => (int) $saved['row']['id']]];
    }

    private function persistBacktest(string $strategy, array $report, array $draws): array
    {
        $last = $draws !== [] ? (string) $draws[count($draws) - 1]['drawDate'] : 'none';
        $row = $this->repo->saveBacktest([
            'lottery_code' => self::LOTTERY,
            'strategy' => $strategy,
            'model_version' => self::MODEL_VERSION,
            'lines_per_draw' => (int) $report['linesPerDraw'],
            'draws_tested' => (int) $report['period']['drawsTested'],
            'period_from' => (string) $report['period']['from'],
            'period_to' => (string) $report['period']['to'],
            'dataset_version' => 'n=' . count($draws) . ';last=' . $last,
            'report' => json_encode($report),
            'created_at' => gmdate('c'),
        ]);
        $id = (int) $row['row']['id'];
        $this->audit->emit('LOTTERY_BACKTEST_RUN', 'Backtest ' . $strategy . ' over ' . $report['period']['drawsTested'] . ' draws saved (id ' . $id . ')', [
            'backtestId' => $id,
            'strategy' => $strategy,
            'modelVersion' => self::MODEL_VERSION,
        ], 'system');
        return $row;
    }

    /** @return array<int, array<string, mixed>> newest first, report decoded */
    public function listBacktests(int $limit = 50): array
    {
        return $this->repo->listBacktests($limit);
    }

    /** @return array<string, mixed>|null */
    public function backtestDetail(int $id): ?array
    {
        $row = $this->repo->findBacktest($id);
        if ($row === null || (string) $row['lottery_code'] !== self::LOTTERY) return null;
        return $row;
    }

    // ------------------------------------------------- performance (25, spec §30)

    /**
     * Performance overview (spec §30): ACTUAL ticket results, HISTORICAL
     * backtest results and DEMO/SANDBOX data — three separate sections that
     * are never mixed. Prize tiers only; official amounts are never stored.
     */
    public function performance(): array
    {
        $tickets = $this->repo->listAllTickets(200);
        $checked = array_values(array_filter($tickets, fn($t) => ($t['status'] ?? '') === self::STATUS_CHECKED));
        $byTier = [];
        $linesChecked = 0;
        foreach ($checked as $t) {
            $res = is_array($t['result'] ?? null) ? $t['result'] : [];
            foreach (is_array($res['lines'] ?? null) ? $res['lines'] : [] as $ln) {
                $linesChecked++;
                if (!empty($ln['prizeTier'])) $byTier[$ln['prizeTier']] = ($byTier[$ln['prizeTier']] ?? 0) + 1;
            }
        }
        ksort($byTier);
        $backtests = $this->repo->listBacktests(500);
        $recentBacktests = array_map(fn($b) => [
            'id' => (int) $b['id'],
            'strategy' => (string) $b['strategy'],
            'modelVersion' => (string) $b['model_version'],
            'drawsTested' => (int) $b['draws_tested'],
            'periodFrom' => $b['period_from'],
            'periodTo' => $b['period_to'],
            'createdAt' => (string) $b['created_at'],
        ], array_slice($backtests, 0, 5));
        $health = $this->provider->health();
        return [
            'lottery' => self::LOTTERY,
            'label' => 'PERFORMANCE OVERVIEW — sections are separate and never mixed (spec §30)',
            'actualTicketResults' => [
                'section' => 'ACTUAL TICKET RESULTS',
                'ticketsTotal' => count($tickets),
                'ticketsChecked' => count($checked),
                'linesChecked' => $linesChecked,
                'byTier' => $byTier,
                'recent' => array_map(fn($t) => [
                    'id' => (int) $t['id'],
                    'name' => (string) $t['name'],
                    'status' => (string) $t['status'],
                    'drawDate' => $t['draw_date'] ?? null,
                    'checkedAt' => is_array($t['result'] ?? null) ? ($t['result']['checkedAt'] ?? null) : null,
                ], array_slice($tickets, 0, 5)),
                'note' => 'Outcomes of saved tickets checked against stored VERIFIED draws. Prize tiers only — official amounts are never stored.',
            ],
            'historicalBacktestResults' => [
                'section' => 'HISTORICAL BACKTEST RESULTS',
                'count' => count($backtests),
                'recent' => $recentBacktests,
                'note' => 'HISTORICAL SIMULATION of strategies over past draws without look-ahead. Descriptive only — the random baseline is always available for comparison.',
            ],
            'demoSandboxData' => [
                'section' => 'DEMO / SANDBOX DATA',
                'provider' => $this->provider->id(),
                'synthetic' => (bool) ($health['synthetic'] ?? false),
                'state' => $health['state'] ?? 'UNKNOWN',
                'drawsTracked' => $this->repo->countDraws(self::LOTTERY),
                'note' => 'Sandbox simulation draws are clearly labeled synthetic and are never presented as official results.',
            ],
            'separationNote' => 'ACTUAL ticket results, HISTORICAL backtest results and DEMO/SANDBOX data are reported separately and are never mixed (spec §30).',
            'disclaimer' => LotteryStatisticsEngine::DISCLAIMER,
        ];
    }
}
