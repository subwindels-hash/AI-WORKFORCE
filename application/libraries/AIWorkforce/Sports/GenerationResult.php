<?php
namespace AIWorkforce\Sports;

/**
 * The ONE generation result contract (spec §1/§26).
 *
 * POST /sports/generate-ticket and POST /api/sports/ticket-engine/run both run
 * the same DailyTicketService::runDaily() and both render the array produced
 * here. Neither controller recomputes a prediction, a count or a status: this
 * class only PROJECTS what the service already returned onto a stable field
 * set, so the browser and the API can never drift apart.
 *
 * Honesty rules enforced here:
 *   - a field that does not apply is null (unknown) or 0 (counted, none), and
 *     the two are never interchanged: an absent provider is null, a provider
 *     that returned nothing is 0;
 *   - nothing is derived, estimated or back-filled from another field. Every
 *     value is read from the service result, its persisted funnel diagnostics
 *     or the persisted daily row.
 */
final class GenerationResult
{
    /**
     * Outcome states (spec §19). These are the only values `status` may take.
     *
     *   DATA_UNAVAILABLE      providers are configured but every one failed
     *   NO_PROVIDER           no sports-data provider is configured at all
     *   NO_FIXTURES           providers answered correctly with zero fixtures
     *   NO_QUALIFIED_TICKET   fixtures were assessed; nothing cleared the gates
     *   PENDING_USER_APPROVAL a ticket was generated and awaits approval
     *   APPROVED              a ticket was generated and auto-approved
     *   DUPLICATE_SKIPPED     a ticket for this date already existed
     *   GENERATION_IN_PROGRESS another worker holds the generation claim
     *   RETRY_SCHEDULED       a scheduled run is in controlled backoff
     *   RESET_FAILED          a forced candidate reset failed; nothing ran
     *   FAILED                an unexpected error ended the run
     */
    public const STATUSES = [
        'DATA_UNAVAILABLE', 'NO_PROVIDER', 'NO_FIXTURES', 'NO_QUALIFIED_TICKET',
        'PENDING_USER_APPROVAL', 'APPROVED', 'DUPLICATE_SKIPPED',
        'GENERATION_IN_PROGRESS', 'RETRY_SCHEDULED', 'RESET_FAILED', 'FAILED',
        // Administratively switched off: a configuration state, never an
        // outcome of assessing the day's fixtures.
        'DISABLED',
    ];

    /** The states that mean "no ticket exists and the date stays retryable". */
    public const RETRYABLE_STATUSES = [
        'DATA_UNAVAILABLE', 'NO_PROVIDER', 'NO_FIXTURES', 'NO_QUALIFIED_TICKET',
        'GENERATION_IN_PROGRESS', 'RETRY_SCHEDULED', 'RESET_FAILED', 'FAILED',
    ];

    /**
     * The ordered pipeline stages (spec §4). The service reports which of these
     * it actually reached; the UI never advances one on a timer.
     */
    public const STAGES = [
        'provider'      => 'Checking provider',
        'fixtures'      => 'Loading fixtures',
        'eligibility'   => 'Checking fixture eligibility',
        'odds'          => 'Loading current odds',
        'oddsFreshness' => 'Checking odds freshness',
        'predictions'   => 'Calculating predictions',
        'confidence'    => 'Checking confidence',
        'dataQuality'   => 'Checking data quality',
        'expectedValue' => 'Checking expected value',
        'risk'          => 'Checking risk',
        'correlation'   => 'Checking correlation',
        'qualified'     => 'Selecting qualified candidates',
        'optimizer'     => 'Optimizing ticket',
        'persistence'   => 'Saving prediction ticket',
    ];

    public const STAGE_WAITING = 'WAITING';
    public const STAGE_RUNNING = 'RUNNING';
    public const STAGE_COMPLETE = 'COMPLETE';
    public const STAGE_FAILED = 'FAILED';
    public const STAGE_SKIPPED = 'SKIPPED';

    /**
     * Human-readable labels for the gate counters shown in the rejection
     * breakdown (spec §12/§17). Keyed by the engine's own rejection reasons so
     * the UI never invents a category the pipeline did not record.
     */
    public const GATE_LABELS = [
        'LOW_CONFIDENCE' => 'Confidence below minimum',
        'CONFIDENCE_UNMEASURED' => 'Confidence unmeasurable',
        'LOW_DATA_QUALITY' => 'Data quality below minimum',
        'STALE_ODDS' => 'Stale odds',
        'INVALID_ODDS' => 'Invalid odds',
        'NO_ODDS' => 'No odds available',
        'MARKET_UNAVAILABLE' => 'Unsupported market',
        'NEGATIVE_EV' => 'Negative expected value',
        'BELOW_MIN_EDGE' => 'Below minimum edge',
        'RISK_REJECTED' => 'Risk rejected',
        'CORRELATION_REJECTED' => 'Correlation rejected',
        'MISSING_MODEL_INPUT' => 'Missing model inputs',
        'MISSING_CALIBRATION' => 'Missing calibration',
        'MARKET_RESTRICTED_AT_DATA_TIER' => 'Market restricted at data tier',
        'FIXTURE_NOT_NS_OR_TOO_SOON' => 'Fixture ineligible (started or too close to kickoff)',
        'FIXTURE_STARTED' => 'Fixture already started',
        'INVALID_KICKOFF' => 'Invalid kickoff timestamp',
        'TOO_CLOSE_TO_KICKOFF' => 'Kickoff within the required lead time',
        'MISSING_TEAM_DATA' => 'Missing team data',
        'MISSING_COMPETITION' => 'Missing competition',
        'INCOMPLETE_PROVIDER_DATA' => 'Incomplete provider data',
    ];

    /**
     * Project a DailyTicketService::runDaily() result onto the canonical
     * contract. `$result` is always the service's own array — this method must
     * never be handed a controller-built payload.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function fromRunDaily(array $result): array
    {
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
        $status = self::normalizeStatus($result, $diagnostics);

        $ticketId = isset($result['ticketId']) && trim((string) $result['ticketId']) !== ''
            ? (string) $result['ticketId'] : null;

        // Counts. A count is 0 when the stage ran and found none; it is null
        // only when the stage never ran, which the funnel records explicitly.
        $reached = self::stageReached($result, $diagnostics);
        $intOrNull = static fn(string $key, bool $ran): ?int => $ran
            ? (int) ($diagnostics[$key] ?? 0)
            : null;

        $rejectionSummary = self::rejectionSummary($result);

        $startedAt = self::timestamp($result['generationStartedAt'] ?? ($diagnostics['generationStartedAt'] ?? null));
        $completedAt = self::timestamp($result['generationCompletedAt'] ?? ($diagnostics['generationCompletedAt'] ?? ($result['generatedAt'] ?? null)));
        $duration = $result['duration'] ?? $diagnostics['durationSeconds'] ?? null;
        if ($duration === null && $startedAt !== null && $completedAt !== null) {
            $a = strtotime($startedAt); $b = strtotime($completedAt);
            if ($a !== false && $b !== false && $b >= $a) $duration = round($b - $a, 3);
        }

        return [
            // ── identity ──────────────────────────────────────────────
            'status' => $status,
            'dataState' => (string) ($result['dataState'] ?? 'OK'),
            'date' => isset($result['date']) ? (string) $result['date'] : null,
            'runId' => isset($result['runId']) && $result['runId'] !== '' ? (string) $result['runId'] : null,
            'ticketId' => $ticketId,

            // ── provider ──────────────────────────────────────────────
            'provider' => isset($result['provider']) && $result['provider'] !== '' ? (string) $result['provider'] : null,
            'providerStatus' => self::providerStatus($result),
            'providerStatuses' => (array) ($result['providerStatuses'] ?? []),

            // ── funnel counts (spec §13/§14) ──────────────────────────
            'fixturesEvaluated' => (int) ($result['fixturesEvaluated'] ?? $result['evaluated'] ?? 0),
            'eligibleFixtures' => $intOrNull('eligibleFixtures', $reached['eligibility']),
            'predictionsGenerated' => (int) ($result['predictionsGenerated'] ?? $diagnostics['predictionsGenerated'] ?? 0),
            'freshOdds' => $intOrNull('fixturesWithFreshOdds', $reached['odds']),
            'staleOdds' => $intOrNull('fixturesRejectedStaleOdds', $reached['odds']),
            // Qualified candidates = passed EVERY required gate, never a count
            // of fixtures, predictions or odds rows.
            'qualifiedCandidates' => self::qualifiedCandidates($result, $diagnostics, $reached),
            // Selected picks = what the optimizer actually put on the ticket.
            'selectedPicks' => (int) ($result['selectedPicks'] ?? 0),

            // ── rejections (spec §12/§17) ─────────────────────────────
            'rejections' => (int) ($result['rejections'] ?? 0),
            'rejectionSummary' => $rejectionSummary,
            'gateFailures' => self::gateFailures($rejectionSummary),

            // ── provenance (spec §5/§24) ──────────────────────────────
            'message' => (string) ($result['message'] ?? ''),
            'modelVersion' => self::scalarOrNull($result['modelVersion'] ?? $diagnostics['modelVersionId'] ?? null),
            'configurationVersion' => isset($result['configurationVersion']) ? (int) $result['configurationVersion'] : null,
            'generationStartedAt' => $startedAt,
            'generationCompletedAt' => $completedAt,
            'duration' => $duration === null ? null : (float) $duration,
            'auditId' => self::scalarOrNull($result['auditId'] ?? null),
            'actor' => self::scalarOrNull($result['actor'] ?? null),

            // ── stage ledger (spec §4) ────────────────────────────────
            'stages' => self::stages($result, $diagnostics, $status),

            // ── passthrough detail (unchanged, for existing consumers) ─
            'generationStatus' => (string) ($result['generationStatus'] ?? ''),
            'outcomeStatus' => (string) ($result['outcomeStatus'] ?? $status),
            'existing' => (bool) ($result['existing'] ?? false),
            'nextRetryAt' => self::scalarOrNull($result['nextRetryAt'] ?? null),
            'errorCode' => self::scalarOrNull($result['errorCode'] ?? null),
            'attempt' => isset($result['attempt']) ? (int) $result['attempt'] : null,
            'retryable' => in_array($status, self::RETRYABLE_STATUSES, true),
            'diagnostics' => $diagnostics,
            'errors' => array_values((array) ($result['errors'] ?? [])),
        ];
    }

    /**
     * Resolve the outcome state (spec §19), including the two the service does
     * not name itself: DUPLICATE_SKIPPED (an existing ticket was returned) and
     * NO_FIXTURES (healthy providers, zero fixtures).
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $diagnostics
     */
    private static function normalizeStatus(array $result, array $diagnostics): string
    {
        $raw = strtoupper(trim((string) ($result['status'] ?? '')));
        $dataState = strtoupper(trim((string) ($result['dataState'] ?? '')));

        // An already-persisted ticket is a duplicate request, not a new run.
        if (!empty($result['existing'])) return 'DUPLICATE_SKIPPED';

        if ($raw === 'GENERATED') {
            $outcome = strtoupper(trim((string) ($result['outcomeStatus'] ?? '')));
            if ($outcome === 'APPROVED' || $outcome === 'APPROVED_NOT_EXECUTED') return 'APPROVED';
            return $outcome !== '' && $outcome !== 'GENERATED' ? $outcome : 'PENDING_USER_APPROVAL';
        }

        if ($dataState === 'DISABLED') return 'DISABLED';
        if ($dataState === 'NO_PROVIDER') return 'NO_PROVIDER';
        if ($dataState === 'DATA_UNAVAILABLE' || $raw === 'DATA_UNAVAILABLE') return 'DATA_UNAVAILABLE';

        // Providers answered, but the day genuinely had no fixtures to assess.
        // Distinct from DATA_UNAVAILABLE (nobody could look) and from
        // NO_QUALIFIED_TICKET (fixtures existed and were all rejected).
        if ($raw === 'NO_QUALIFIED_TICKET'
            && (int) ($result['fixturesEvaluated'] ?? $result['evaluated'] ?? 0) === 0
            && (int) ($diagnostics['eligibleFixtures'] ?? 0) === 0
            && (int) ($diagnostics['fixturesDeduped'] ?? 0) === 0
            && (int) ($result['rejections'] ?? 0) === 0
            && $dataState === 'OK') {
            return 'NO_FIXTURES';
        }

        if ($raw !== '' && in_array($raw, self::STATUSES, true)) return $raw;
        return $raw !== '' ? $raw : 'FAILED';
    }

    /** Which pipeline stages the run actually reached, from persisted counts. */
    private static function stageReached(array $result, array $diagnostics): array
    {
        $dataState = strtoupper((string) ($result['dataState'] ?? 'OK'));
        $providerOk = !in_array($dataState, ['NO_PROVIDER', 'DATA_UNAVAILABLE', 'DISABLED'], true);
        $evaluated = (int) ($result['fixturesEvaluated'] ?? $result['evaluated'] ?? 0);
        $hasFunnel = $diagnostics !== [];
        return [
            'provider' => true,
            'fixtures' => $providerOk,
            'eligibility' => $providerOk && $hasFunnel,
            'odds' => $providerOk && $hasFunnel && ($evaluated > 0 || (int) ($diagnostics['eligibleFixtures'] ?? 0) > 0),
            'predictions' => $providerOk && $hasFunnel,
        ];
    }

    /**
     * Candidates that cleared EVERY required gate (spec §13). The pipeline's
     * own final counter is authoritative; the correlation-stage counter is the
     * documented fallback for runs recorded before it existed.
     */
    private static function qualifiedCandidates(array $result, array $diagnostics, array $reached): ?int
    {
        if (isset($result['qualifiedCandidates']) && $result['qualifiedCandidates'] !== null) {
            return (int) $result['qualifiedCandidates'];
        }
        if (!$reached['predictions']) return null;
        if (isset($diagnostics['finalQualifiedCandidates'])) return (int) $diagnostics['finalQualifiedCandidates'];
        if (isset($diagnostics['correlationQualifiedCandidates'])) return (int) $diagnostics['correlationQualifiedCandidates'];
        return 0;
    }

    /** @return array<string,int> */
    private static function rejectionSummary(array $result): array
    {
        $summary = $result['rejectionSummary'] ?? [];
        if (!is_array($summary)) return [];
        $out = [];
        foreach ($summary as $reason => $count) {
            if (!is_string($reason) || $reason === '' || $reason[0] === '_') continue;
            if (is_array($count)) { $count = $count['count'] ?? 0; }
            if (!is_numeric($count)) continue;
            $out[$reason] = (int) $count;
        }
        arsort($out);
        return $out;
    }

    /**
     * The rejection summary rendered as labelled gate failures (spec §12).
     * Only reasons the pipeline actually recorded appear.
     *
     * @param array<string,int> $rejectionSummary
     * @return array<string,array{reason:string,label:string,count:int}>
     */
    private static function gateFailures(array $rejectionSummary): array
    {
        $out = [];
        foreach ($rejectionSummary as $reason => $count) {
            if ((int) $count <= 0) continue;
            $out[$reason] = [
                'reason' => $reason,
                'label' => self::GATE_LABELS[$reason] ?? self::humanize($reason),
                'count' => (int) $count,
            ];
        }
        return $out;
    }

    private static function humanize(string $reason): string
    {
        $text = strtolower(str_replace('_', ' ', $reason));
        return ucfirst($text);
    }

    /**
     * Per-stage state (spec §4). A stage is COMPLETE only when the service's
     * own persisted output proves it ran; stages after a failure are SKIPPED,
     * never silently shown as complete.
     *
     * @return list<array{key:string,label:string,state:string,detail:?string}>
     */
    private static function stages(array $result, array $diagnostics, string $status): array
    {
        $ledger = $result['stageLedger'] ?? null;
        $out = [];
        foreach (self::STAGES as $key => $label) {
            $state = self::STAGE_WAITING;
            $detail = null;
            if (is_array($ledger) && isset($ledger[$key])) {
                $entry = $ledger[$key];
                if (is_array($entry)) {
                    $state = (string) ($entry['state'] ?? self::STAGE_WAITING);
                    $detail = isset($entry['detail']) ? (string) $entry['detail'] : null;
                } else {
                    $state = (string) $entry;
                }
            }
            $out[] = ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail];
        }
        return $out;
    }

    private static function providerStatus(array $result): ?string
    {
        $dataState = strtoupper((string) ($result['dataState'] ?? ''));
        if ($dataState === 'NO_PROVIDER') return 'NOT_CONFIGURED';
        $statuses = (array) ($result['providerStatuses'] ?? []);
        if ($statuses !== []) {
            $unique = array_values(array_unique(array_map(static fn($s): string => strtoupper((string) $s), $statuses)));
            if (count($unique) === 1) return $unique[0];
            return implode(',', $unique);
        }
        if ($dataState === 'DATA_UNAVAILABLE') return 'FAILED';
        if (isset($result['provider']) && (string) $result['provider'] !== '') return 'READY';
        return null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $stamp = strtotime($value);
        return $stamp === false ? null : gmdate('c', $stamp);
    }

    private static function scalarOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_scalar($value)) {
            $text = trim((string) $value);
            return $text === '' ? null : $text;
        }
        return null;
    }
}
