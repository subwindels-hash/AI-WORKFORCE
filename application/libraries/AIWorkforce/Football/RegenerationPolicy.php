<?php
namespace AIWorkforce\Football;

/**
 * When an existing prediction may be regenerated.
 *
 * The module's central promise is that a match which already has a prediction
 * is returned, not recomputed: paging, reloading and re-sweeping a date must
 * cost nothing. So the default answer is REUSE, and refreshing is the
 * exception that has to justify itself.
 *
 * The reasons that justify it are exactly the ones an operator would accept:
 *
 *  - the model version changed, so the stored numbers came from another model;
 *  - the prediction has expired (it is older than the configured window, or
 *    kickoff has moved past it);
 *  - the market moved materially — a price change big enough to change the
 *    selection, not a tick;
 *  - a lineup was confirmed and differs from the one the prediction was made
 *    against;
 *  - a major injury or team news item landed;
 *  - the fixture's status changed (postponed, cancelled, in play);
 *  - new provider data arrived for the fixture after the prediction was made.
 *
 * A signal nobody has observed is `null`, and a `null` is never a reason: an
 * unknown cannot justify a regeneration, because the cost of regenerating
 * wrongly is a wasted model run and a changed number, while the cost of
 * reusing wrongly is one stale row that the next sweep revisits.
 *
 * Kickoff is a hard stop, not a reason: once a match has kicked off its
 * pre-match prediction is frozen, and no signal — not odds movement, not team
 * news — reopens it.
 */
final class RegenerationPolicy
{
    /** Keep the stored prediction. */
    public const REUSE = 'REUSE';
    /** Replace the stored prediction, because a stated reason requires it. */
    public const REFRESH = 'REFRESH';

    /** The reason codes a decision can rest on. */
    public const R_MODEL_VERSION = 'MODEL_VERSION_CHANGED';
    public const R_EXPIRED = 'PREDICTION_EXPIRED';
    public const R_ODDS_MOVEMENT = 'SIGNIFICANT_ODDS_MOVEMENT';
    public const R_LINEUP = 'CONFIRMED_LINEUP_CHANGE';
    public const R_INJURY = 'MAJOR_INJURY_OR_NEWS';
    public const R_STATUS = 'MATCH_STATUS_CHANGE';
    public const R_STATISTICS = 'NEW_STATISTICS_AVAILABLE';
    /** Kickoff passed: the prediction is frozen, so nothing may regenerate it. */
    public const R_FROZEN = 'FROZEN_AT_KICKOFF';

    public function __construct(private FootballConfiguration $config) {}

    /**
     * Should the stored prediction for this fixture be kept or replaced?
     *
     * @param array<string,mixed> $prediction the stored prediction row
     * @param array<string,mixed> $fixture the current fixture row
     * @param array<string,mixed> $signals keys: oddsMovement (float, change in
     *        implied probability), lineupChanged (bool), injuryNews (bool),
     *        statisticsUpdatedAt (?string)
     * @return array{action:string, codes:list<string>, reasons:list<string>, reusedUntil:?string}
     */
    public function decide(array $prediction, array $fixture, array $signals = [], int $modelVersionId = 0, ?int $now = null): array
    {
        $now ??= time();
        $codes = [];
        $reasons = [];

        // Kickoff is a hard stop. It is checked first so that no later signal
        // can be read as permission to reopen a frozen prediction.
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        if ($kickoff !== '' && strtotime($kickoff) !== false && strtotime($kickoff) <= $now) {
            return ['action' => self::REUSE, 'codes' => [self::R_FROZEN], 'reasons' => [
                'Kickoff has passed, so the pre-match prediction is frozen: it is reused and settled, never regenerated.',
            ], 'reusedUntil' => null];
        }

        if ($modelVersionId > 0 && (int) ($prediction['model_version_id'] ?? 0) !== $modelVersionId) {
            $codes[] = self::R_MODEL_VERSION;
            $reasons[] = 'The prediction was produced by model version ' . (int) ($prediction['model_version_id'] ?? 0)
                . ' and the active model is version ' . $modelVersionId . '.';
        }

        $generatedAt = (string) ($prediction['generated_at'] ?? '');
        $generated = $generatedAt !== '' ? strtotime($generatedAt) : null;
        $ttl = $this->config->predictionTtlSeconds();
        if ($generated !== false && $generated !== null && $ttl > 0 && ($now - $generated) > $ttl) {
            $codes[] = self::R_EXPIRED;
            $reasons[] = 'The prediction is ' . $this->duration($now - $generated)
                . ' old, past the ' . $this->duration($ttl) . ' window it is valid for.';
        }

        $movement = $signals['oddsMovement'] ?? null;
        if (is_numeric($movement)) {
            $threshold = $this->config->oddsMovementThreshold();
            if (abs((float) $movement) >= $threshold) {
                $codes[] = self::R_ODDS_MOVEMENT;
                $reasons[] = 'The market moved ' . round(abs((float) $movement) * 100, 1)
                    . ' points in implied probability, past the ' . round($threshold * 100, 1) . '-point threshold.';
            }
        }
        if (!empty($signals['lineupChanged'])) {
            $codes[] = self::R_LINEUP;
            $reasons[] = 'A confirmed lineup change was recorded against this match.';
        }
        if (!empty($signals['injuryNews'])) {
            $codes[] = self::R_INJURY;
            $reasons[] = 'A major injury or team-news update was recorded against this match.';
        }

        $status = strtoupper((string) ($fixture['status'] ?? ''));
        if (in_array($status, array_merge(['POSTPONED', 'CANCELLED', 'SUSPENDED'], FixtureSyncService::LIVE_STATUSES), true)) {
            $codes[] = self::R_STATUS;
            $reasons[] = 'The fixture status is now ' . $status . '; the prediction was made against a scheduled match.';
        }

        // New provider data for this fixture: the timestamp the provider stamped
        // on the row, not the row's own update time — a re-save is not new data.
        $stamped = (string) ($fixture['source_timestamp'] ?? '');
        if ($generated !== null && $generated !== false && $stamped !== '' && strtotime($stamped) > $generated) {
            $codes[] = self::R_STATISTICS;
            $reasons[] = 'The provider sent new data for this fixture (stamped ' . $stamped
                . ') after the prediction was produced.';
        }

        return [
            'action' => $codes === [] ? self::REUSE : self::REFRESH,
            'codes' => $codes,
            'reasons' => $reasons === []
                ? ['No signal justifies regenerating this prediction, so the stored one is reused.']
                : $reasons,
            'reusedUntil' => $kickoff !== '' ? $kickoff : null,
        ];
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 3600) return max(1, (int) round($seconds / 60)) . ' minutes';
        if ($seconds < 172800) return round($seconds / 3600, 1) . ' hours';
        return round($seconds / 86400, 1) . ' days';
    }
}
