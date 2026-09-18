<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;

/**
 * Promotes persisted provider results to verified — the gate every ticket
 * settlement passes through.
 *
 * Two promotion paths, one standard:
 *  - EXPLICIT (human): verify() — the sports.settle JSON API. A person asks
 *    for a specific (match, provider) result to be verified NOW.
 *  - AUTOMATIC (corroborated): promoteEligible() — the settlement sweep
 *    promotes a stored result on its own once the provider's final word has
 *    aged past the corroboration window, because without this path a result
 *    can never become verified on a deployment where nobody hand-crafts API
 *    calls — every ticket then stays PENDING forever and the measured-results
 *    panel can never show a single number, even though real final results are
 *    stored. The engine's evidence bar is the same either way: a terminal
 *    status and, for FINISHED, a valid non-negative integer score. Nothing is
 *    invented and every promotion is audited as SPORTS_RESULT_VERIFIED.
 */
class PersistedResultVerifier
{
    /** Default corroboration window (seconds) before an unverified stored
     *  result may be auto-promoted by the settlement sweep. */
    public const DEFAULT_CORROBORATION_SECONDS = 600;
    public const MIN_CORROBORATION_SECONDS = 0;
    public const MAX_CORROBORATION_SECONDS = 86400;

    public function __construct(private SportsRepository $repo, private AuditRepository $audit) {}

    /** Human decision path: promote a persisted provider result only after validation. */
    public function verify(int $matchId, int $providerId, string $actor): array
    {
        $r = $this->repo->findResult($matchId, $providerId);
        if (!$r) throw new \InvalidArgumentException('provider result not found');
        $candidate = ['verified' => true, 'status' => $r['status'], 'homeScore' => $r['home_score'] === null ? null : (int) $r['home_score'], 'awayScore' => $r['away_score'] === null ? null : (int) $r['away_score']];
        $v = (new ResultVerificationEngine())->verify($candidate);
        if (empty($v['verified'])) throw new \RuntimeException($v['reason']);
        $this->repo->verifyResult((int) $r['id']);
        $this->audit->emit('SPORTS_RESULT_VERIFIED', 'Provider result verified', ['resultId' => $r['id'], 'matchId' => $matchId], $actor);
        return $v;
    }

    /**
     * Corroboration window in seconds, from
     * WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS (default 600 = 10 minutes),
     * clamped to [0, 86400]. This is how old the provider's own final stamp on
     * a stored result must be before the settlement sweep may promote it
     * automatically: a result that just arrived is left for a later sweep (or
     * an explicit human verification), so a premature "finished" flag never
     * settles a ticket on its own first appearance. 0 disables the wait.
     */
    public static function corroborationSeconds(): int
    {
        $raw = getenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS');
        if ($raw === false || trim((string) $raw) === '') return self::DEFAULT_CORROBORATION_SECONDS;
        $seconds = (int) $raw;
        if ($seconds < self::MIN_CORROBORATION_SECONDS) return self::MIN_CORROBORATION_SECONDS;
        return min(self::MAX_CORROBORATION_SECONDS, $seconds);
    }

    /**
     * Automatically promote a stored (still unverified) result that has earned
     * verification: it must pass the ResultVerificationEngine's evidence bar
     * (terminal status; FINISHED needs a valid non-negative integer score) AND
     * its provider source timestamp must be older than the corroboration
     * window. Idempotent; every promotion audits SPORTS_RESULT_VERIFIED with
     * the acting identity and automation detail, so an operator can see exactly
     * which results the sweep verified on its own.
     *
     * @param array $row a stored sports_results row (findResultByMatch shape)
     * @return array{promoted: bool, reason?: string, retryInSeconds?: int}
     */
    public function promoteEligible(array $row, string $actor, ?int $now = null): array
    {
        if (empty($row['id'])) return ['promoted' => false, 'reason' => 'RESULT_ROW_MISSING'];
        if (!empty($row['verified'])) return ['promoted' => false, 'reason' => 'ALREADY_VERIFIED'];
        $candidate = [
            'verified' => true,
            'status' => (string) ($row['status'] ?? ''),
            'homeScore' => $row['home_score'] === null ? null : (int) $row['home_score'],
            'awayScore' => $row['away_score'] === null ? null : (int) $row['away_score'],
        ];
        $v = (new ResultVerificationEngine())->verify($candidate);
        if (empty($v['verified'])) return ['promoted' => false, 'reason' => (string) ($v['reason'] ?? 'RESULT_UNVERIFIED')];
        $sourceTs = strtotime((string) ($row['source_timestamp'] ?? ''));
        if ($sourceTs === false) return ['promoted' => false, 'reason' => 'RESULT_SOURCE_TIMESTAMP_INVALID'];
        $now = $now ?? time();
        $window = self::corroborationSeconds();
        $age = $now - $sourceTs;
        if ($age < $window) {
            return ['promoted' => false, 'reason' => 'RESULT_NOT_YET_CORROBORATED', 'retryInSeconds' => max(1, $window - $age)];
        }
        $this->repo->verifyResult((int) $row['id']);
        $this->audit->emit('SPORTS_RESULT_VERIFIED', 'Provider result auto-verified (terminal, corroborated)', [
            'resultId' => (int) $row['id'],
            'matchId' => (int) ($row['match_id'] ?? 0),
            'providerId' => (int) ($row['provider_id'] ?? 0),
            'status' => $candidate['status'],
            'sourceTimestamp' => (string) ($row['source_timestamp'] ?? ''),
            'corroborationSeconds' => $window,
            'automation' => true,
        ], $actor);
        return ['promoted' => true] + $v;
    }
}
