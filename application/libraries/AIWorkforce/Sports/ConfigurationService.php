<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;

/**
 * Ticket-engine configuration (spec §16/§34).
 *
 * Append-only, versioned: every change inserts a new configuration row with
 * a monotonically increasing version and an audit event carrying the acting
 * administrator, timestamp, the original values, the new values, and the
 * reason. Tickets always store the configuration version that produced them
 * so any historical decision can be reconstructed.
 *
 * AUTOMATED_EXECUTION is refused unless the operator explicitly passes
 * allowAutomatedExecution (spec §20): automated external execution stays
 * disabled by default and there is no external execution connector at all.
 */
class ConfigurationService
{
    public const PLATFORM_MODES = ['SANDBOX', 'PAPER', 'PRODUCTION'];
    public const ENGINE_MODES = ['VIEW_ONLY', 'AI_ANALYSIS', 'AI_TICKET_GENERATION', 'USER_APPROVAL_REQUIRED', 'AUTOMATED_EXECUTION'];
    public const RISK_LEVELS = ['CONSERVATIVE', 'MODERATE', 'AGGRESSIVE'];
    public const CORRELATION_LIMITS = ['LOW', 'MEDIUM'];
    public const VOID_POLICIES = ['RESTITUTE_ODDS', 'ALL_VOID_ONLY'];
    public const STAKING_MODES = ['FLAT', 'FRACTIONAL_KELLY'];

    /**
     * The lowest confidence an operator may configure as the eligibility floor.
     *
     * The confidence hard gate: a candidate must legitimately measure >= 30%.
     * 29.99% fails, 30.00% passes. This is a bound on what is CONFIGURABLE and
     * on what can qualify — never a promise about any prediction. Confidence is
     * never inflated or rounded up to clear it, and the data-quality gate is
     * independent: a fixture below the quality floor is rejected outright
     * whatever its confidence happens to be.
     */
    public const MIN_CONFIDENCE_FLOOR = 30.0;

    /**
     * The hard data-quality gate (§7): 29.99 is rejected, 30 passes. Distinct
     * from the confidence gate above; the two are never conflated.
     *
     * Operator decision (2026-09-15): both gates run from 30 upward. The
     * previous 75 floor rejected fixtures outright on evidence breadth even
     * when the model's measured confidence was strong, which is what produced
     * days of "N predictions → 0 qualified". Lowering the FLOOR does not lower
     * any measurement: quality is still measured honestly, still reported on
     * every candidate, and an administrator may still raise the configured
     * minimum back up at any time. What changes is that the platform no longer
     * forbids operating below 75.
     */
    public const MIN_DATA_QUALITY_FLOOR = 30;

    /**
     * The absolute combined-odds sanity floor (operator decision 2026-09-17,
     * superseding the fixed 5.0 floor of the same date): the odds WINDOW is
     * now fully configurable, and the only bound the platform itself imposes
     * is that decimal odds must exceed 1.0 — a "ticket" at or below 1.01 total
     * odds is not a bet at all. Administrators may configure any window from
     * 1.01 upward (2.0–3.5 for low-variance singles/doubles, 5.0–8.0 for the
     * previous behaviour), append-only and audited like every other value.
     * TicketOptimizer and TicketGovernance enforce the same constant so no
     * surface can ever accept a sub-1.01 window. This is a bound on the ODDS
     * WINDOW only; it never pads a ticket with an extra leg or a fake market to
     * reach a number — a day that cannot reach the configured minimum with
     * real, confident, positive-value legs honestly returns NO QUALIFIED TICKET.
     */
    public const MIN_TARGET_ODDS_FLOOR = 1.01;

    public function __construct(private SportsRepository $repo, private AuditRepository $audit) {}

    /** Current active configuration (latest version), or a safe default when none exists yet. */
    public function active(): array
    {
        $row = $this->repo->activeConfiguration();
        if ($row === null) return self::defaults();
        // Layer the stored row OVER the defaults. Configuration is
        // append-only, so an active row may have been written by an older
        // app version before a column existed (the deployed row predating
        // require_calibration is the cautionary tale: the daily engine read
        // the missing key as "bootstrap not needed" at one site and as
        // "calibration enforced" at another — a hard lock-out no operator
        // chose). Stored values always win; defaults only fill absent keys.
        $row = array_merge(self::defaults(), $row);
        $row['module_enabled'] = (int) (bool) $row['module_enabled'];
        $row['ticket_engine_enabled'] = (int) (bool) $row['ticket_engine_enabled'];
        $row['system_timezone'] = DailyTicketDate::configuredTimezone((string) ($row['system_timezone'] ?? ''));
        $row['require_calibration'] = (int) (bool) $row['require_calibration'];
        $row['min_confidence'] = (float) $row['min_confidence'];
        $row['min_data_quality'] = (int) $row['min_data_quality'];
        // Kept as stored (JSON string or array); ConfidencePolicy normalises
        // it and falls back to the derived ladder when it is absent/invalid.
        $row['confidence_policy'] = $row['confidence_policy'] ?? null;
        $row['version'] = (int) $row['version'];
        $row['allowed_markets'] = $row['allowed_markets'] ?? [];
        $row['allowed_leagues'] = $row['allowed_leagues'] ?? [];
        // The 1.01 combined-odds sanity floor is absolute and retroactive: a
        // row written by any path that stored a nonsensical minimum (<= 1.0)
        // is clamped up here so no generation can ever read a sub-1.01
        // minimum. The maximum is lifted with it when a legacy window would
        // otherwise collapse (min > max), so the engine keeps a usable range
        // instead of silently generating nothing.
        $row['target_odds_min'] = max(self::MIN_TARGET_ODDS_FLOOR, (float) $row['target_odds_min']);
        $row['target_odds_max'] = max((float) $row['target_odds_max'], $row['target_odds_min']);
        // Staking discipline keys may be absent on rows written before they
        // existed; array_merge above already filled the defaults, so only
        // normalize the types here (an unknown stored mode falls back FLAT —
        // the safe behaviour — rather than throwing on a legacy row).
        $row['staking_mode'] = in_array(strtoupper((string) ($row['staking_mode'] ?? 'FLAT')), self::STAKING_MODES, true)
            ? strtoupper((string) $row['staking_mode']) : 'FLAT';
        $row['bankroll'] = (float) ($row['bankroll'] ?? 1000.0) > 0 ? (float) $row['bankroll'] : 1000.0;
        $kf = (float) ($row['kelly_fraction'] ?? 0.25);
        $row['kelly_fraction'] = ($kf > 0 && $kf <= 1.0) ? $kf : 0.25;
        return $row;
    }

    public static function defaults(): array
    {
        return [
            'version' => 0,
            'module_enabled' => 1,
            'ticket_engine_enabled' => 1,
            // The daily ticket date is this local calendar date; fixture and
            // odds timestamps remain UTC. Can also be supplied at deployment
            // time through WINDELS_SYSTEM_TIMEZONE / APP_TIMEZONE.
            'system_timezone' => DailyTicketDate::configuredTimezone(),
            'platform_mode' => 'SANDBOX',
            'engine_mode' => 'USER_APPROVAL_REQUIRED',
            // Operator decision (2026-09-17): the shipped window targets
            // low-variance tickets — 2.00–3.50 combined odds over at most two
            // legs. Multi-leg accumulators compound the bookmaker margin
            // (1-(1-margin)^N) and variance with every extra leg; singles and
            // doubles keep the realized edge closest to the modelled edge.
            // Administrators may configure any window from 1.01 upward.
            'target_odds_min' => 2.0,
            'target_odds_max' => 3.5,
            'max_selections' => 2,
            'risk_level' => 'CONSERVATIVE',
            // Qualified-ticket policy: predictions must have measured confidence
            // >= the configured minimum (30% by default), data quality 55+,
            // positive value and LOW correlation between legs. Anything weaker
            // is rejected and the day honestly reports NO QUALIFIED TICKET
            // instead of a forced combination. Changes remain append-only and
            // audited.
            'min_confidence' => 30.0,
            // Value floor (operator decision 2026-09-17): +3% edge after the
            // FairValueEngine strips the bookmaker margin. A high-probability
            // leg that is still -EV after de-vigging must never qualify.
            'min_expected_value' => 0.03,
            'max_correlation' => 'LOW',
            // Data-quality default (operator decision 2026-09-17): the shipped
            // gate is 55 — a middle ground between the old 75 floor (which
            // produced "N predictions → 0 qualified" days) and the 30 hard
            // gate. The FLOOR stays 30: an administrator may still lower the
            // configured value back to 30, or raise it toward QUALIFIED (70+),
            // append-only and audited.
            'min_data_quality' => 55,
            // Adaptive confidence policy (requirements #1/#8). NULL means the
            // tiers are DERIVED from the two floors above, but every tier is
            // still bounded by the hard gates: 30%+ confidence and 30+ data
            // quality. Set it explicitly to author the tiers directly; nothing
            // in the engine may admit a sub-30 confidence reading.
            'confidence_policy' => null,
            'min_liquidity' => null,
            // Every market the model can price AND settle (requirement #5).
            // Draw No Bet is included: it is fully supported end to end, and
            // leaving it out would have the engine generate predictions the
            // configuration then discards as OUTSIDE_CONFIGURATION.
            'allowed_markets' => ['MATCH_RESULT', 'TOTAL_GOALS', 'BTTS', 'DOUBLE_CHANCE', 'DRAW_NO_BET'],
            'allowed_leagues' => [],
            'max_exposure' => 100.0,
            'stake_amount' => 10.0,
            // Staking discipline (operator decision 2026-09-17). FLAT keeps
            // the fixed stake_amount per ticket. FRACTIONAL_KELLY sizes the
            // stake as kelly_fraction × full-Kelly on the ticket's calibrated
            // probability and quoted odds, against the configured bankroll —
            // never above max_exposure, never above stake_amount × 4, and a
            // non-positive Kelly edge stakes NOTHING (the ticket is still
            // recorded, with stake 0, so the day's record stays honest).
            'staking_mode' => 'FLAT',
            'bankroll' => 1000.0,
            'kelly_fraction' => 0.25,
            'void_policy' => 'RESTITUTE_ODDS',
            'require_calibration' => 1,
            'updated_by' => 'system',
            'reason' => 'built-in defaults',
        ];
    }

    /**
     * Validate a patch and persist it as the next configuration version.
     * Returns ['ok' => bool, 'reason' => string, 'configuration' => array].
     */
    public function update(array $patch, string $actor, string $reason = '', bool $allowAutomatedExecution = false): array
    {
        $base = $this->active();
        $next = array_merge($base, array_intersect_key($patch, array_flip([
            'module_enabled', 'ticket_engine_enabled', 'system_timezone', 'platform_mode', 'engine_mode',
            'target_odds_min', 'target_odds_max', 'max_selections', 'risk_level',
            'min_confidence', 'min_expected_value', 'max_correlation', 'min_data_quality',
            'confidence_policy',
            'min_liquidity', 'allowed_markets', 'allowed_leagues', 'max_exposure',
            'stake_amount', 'void_policy', 'require_calibration',
            'staking_mode', 'bankroll', 'kelly_fraction',
        ])));

        $error = $this->validate($next, $allowAutomatedExecution);
        if ($error !== null) return ['ok' => false, 'reason' => $error, 'configuration' => null];

        $present = fn($v) => $this->presentable($v);
        $previous = array_map($present, $base);
        $row = [
            'version' => (int) $base['version'] + 1,
            'module_enabled' => (int) (bool) $next['module_enabled'],
            'ticket_engine_enabled' => (int) (bool) $next['ticket_engine_enabled'],
            'system_timezone' => DailyTicketDate::configuredTimezone((string) $next['system_timezone']),
            'platform_mode' => (string) $next['platform_mode'],
            'engine_mode' => (string) $next['engine_mode'],
            'target_odds_min' => (float) $next['target_odds_min'],
            'target_odds_max' => (float) $next['target_odds_max'],
            'max_selections' => (int) $next['max_selections'],
            'risk_level' => (string) $next['risk_level'],
            'min_confidence' => (float) $next['min_confidence'],
            'min_expected_value' => (float) $next['min_expected_value'],
            'max_correlation' => (string) $next['max_correlation'],
            'min_data_quality' => (int) $next['min_data_quality'],
            // Adaptive confidence tiers. NULL keeps the derived ladder, so a
            // configuration that never mentions the policy behaves exactly as
            // its two floors describe (requirements #1/#8).
            'confidence_policy' => self::encodePolicy($next['confidence_policy'] ?? null),
            'min_liquidity' => $next['min_liquidity'] === null ? null : (float) $next['min_liquidity'],
            'allowed_markets' => json_encode(array_values((array) $next['allowed_markets'])),
            'allowed_leagues' => json_encode(array_values((array) $next['allowed_leagues'])),
            'max_exposure' => (float) $next['max_exposure'],
            'stake_amount' => (float) $next['stake_amount'],
            'staking_mode' => strtoupper((string) $next['staking_mode']),
            'bankroll' => (float) $next['bankroll'],
            'kelly_fraction' => (float) $next['kelly_fraction'],
            'void_policy' => (string) $next['void_policy'],
            'require_calibration' => (int) (bool) $next['require_calibration'],
            'updated_by' => $actor,
            'reason' => mb_substr($reason, 0, 500),
            'created_at' => gmdate('c'),
        ];
        $id = $this->repo->saveConfiguration($row);
        $stored = $this->repo->findConfiguration($id);
        $this->audit->emit('SPORTS_CONFIGURATION_UPDATED', 'Sports ticket-engine configuration updated to v' . $row['version'], [
            'version' => $row['version'], 'changed' => array_diff_assoc(array_map($present, $stored), $previous),
            'previous' => $previous, 'new' => array_map($present, $stored), 'reason' => $row['reason'],
        ], $actor);
        return ['ok' => true, 'reason' => 'configuration saved', 'configuration' => $stored];
    }

    /** Store an explicit policy as canonical JSON; null when none is set. */
    private static function encodePolicy($policy): ?string
    {
        if ($policy === null || $policy === '' || $policy === []) return null;
        $normalized = ConfidencePolicy::normalizePolicy($policy, true);
        return $normalized === null ? null : json_encode($normalized);
    }

    private function presentable($v)
    {
        if (is_string($v) && $v !== '' && (str_starts_with($v, '[') || str_starts_with($v, '{'))) return json_decode($v, true);
        return $v;
    }

    /** @return string|null error message, or null when valid */
    private function validate(array $c, bool $allowAutomatedExecution): ?string
    {
        try {
            $timezone = trim((string) ($c['system_timezone'] ?? ''));
            if ($timezone === '') return 'system_timezone is required';
            new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            return 'system_timezone must be a valid IANA timezone (for example UTC, Europe/London or Africa/Johannesburg)';
        }
        if (!in_array($c['platform_mode'], self::PLATFORM_MODES, true)) return 'platform_mode must be one of ' . implode(', ', self::PLATFORM_MODES);
        if (!in_array($c['engine_mode'], self::ENGINE_MODES, true)) return 'engine_mode must be one of ' . implode(', ', self::ENGINE_MODES);
        if ($c['engine_mode'] === 'AUTOMATED_EXECUTION' && !$allowAutomatedExecution) {
            return 'AUTOMATED_EXECUTION requires explicit authorization (allowAutomatedExecution) and remains disabled in this deployment';
        }
        if (!in_array($c['risk_level'], self::RISK_LEVELS, true)) return 'risk_level must be one of ' . implode(', ', self::RISK_LEVELS);
        if (!in_array($c['max_correlation'], self::CORRELATION_LIMITS, true)) return 'max_correlation must be LOW or MEDIUM';
        if (!in_array($c['void_policy'], self::VOID_POLICIES, true)) return 'void_policy must be one of ' . implode(', ', self::VOID_POLICIES);
        $min = (float) $c['target_odds_min']; $max = (float) $c['target_odds_max'];
        // The combined-odds sanity floor is absolute: decimal odds at or
        // below 1.01 are not a bet. Any window from 1.01 upward is valid.
        if ($min + 1e-9 < self::MIN_TARGET_ODDS_FLOOR) {
            return 'target_odds_min must be at least ' . number_format(self::MIN_TARGET_ODDS_FLOOR, 2)
                . ' — decimal odds at or below ' . number_format(self::MIN_TARGET_ODDS_FLOOR, 2) . ' are not a stakeable price';
        }
        if ($max <= $min) return 'target odds range must satisfy min <= max (min at least ' . number_format(self::MIN_TARGET_ODDS_FLOOR, 2) . ')';
        $maxSel = (int) $c['max_selections'];
        if ($maxSel < 1 || $maxSel > 12) return 'max_selections must be within [1, 12]';
        // The configurable floor is 30: a legitimately measured 29.99%
        // read is still below the eligibility gate, while 30.00% and above can
        // qualify when every other gate passes. Administrators may raise the
        // floor, but no configuration path may lower it. Confidence is never
        // inflated to clear a floor; see ConfidencePolicy.
        $conf = (float) $c['min_confidence'];
        if ($conf < self::MIN_CONFIDENCE_FLOOR || $conf > 100) {
            return 'min_confidence must be within [' . (int) self::MIN_CONFIDENCE_FLOOR . ', 100]';
        }
        if ((float) $c['min_expected_value'] < 0) return 'min_expected_value must be >= 0';
        $dq = (int) $c['min_data_quality'];
        if ($dq < self::MIN_DATA_QUALITY_FLOOR || $dq > 100) {
            return 'min_data_quality must be within [' . self::MIN_DATA_QUALITY_FLOOR . ', 100]';
        }
        // An explicit adaptive policy must be readable, or the engine would
        // silently fall back to the derived ladder and the operator would
        // believe tiers are in force that are not.
        $policy = $c['confidence_policy'] ?? null;
        if ($policy !== null && $policy !== '' && $policy !== []) {
            if (ConfidencePolicy::normalizePolicy($policy, true) === null) {
                return 'confidence_policy must be a list of tiers, each with numeric minDataQuality within [0, 100] and minConfidence within [' . (int) self::MIN_CONFIDENCE_FLOOR . ', 100]';
            }
        }
        if ((float) $c['max_exposure'] <= 0) return 'max_exposure must be > 0';
        if ((float) $c['stake_amount'] <= 0) return 'stake_amount must be > 0';
        if ((float) $c['stake_amount'] > (float) $c['max_exposure']) return 'stake_amount cannot exceed max_exposure';
        if (!in_array(strtoupper((string) ($c['staking_mode'] ?? 'FLAT')), self::STAKING_MODES, true)) {
            return 'staking_mode must be one of ' . implode(', ', self::STAKING_MODES);
        }
        if ((float) ($c['bankroll'] ?? 0) <= 0) return 'bankroll must be > 0';
        $kf = (float) ($c['kelly_fraction'] ?? 0);
        // Full Kelly (1.0) is the mathematical ceiling; disciplined deployments
        // run 0.1–0.5. Zero or negative would silently stake nothing forever.
        if ($kf <= 0 || $kf > 1.0) return 'kelly_fraction must be within (0, 1]';
        if ($c['min_liquidity'] !== null && (float) $c['min_liquidity'] < 0) return 'min_liquidity must be >= 0';
        foreach (['allowed_markets', 'allowed_leagues'] as $listKey) {
            $list = $c[$listKey];
            if (!is_array($list)) return "{$listKey} must be an array";
            foreach ($list as $item) if (!is_string($item) || trim($item) === '') return "{$listKey} entries must be non-empty strings";
        }
        return null;
    }
}
