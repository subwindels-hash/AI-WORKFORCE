<?php
namespace AIWorkforce\Sports;

/**
 * Stake sizing — flat units, flat percent-of-bankroll, or fractional Kelly
 * (operator decision 2026-09-17).
 *
 * WHY THIS EXISTS
 * ---------------
 * Survivability first: even a genuinely +EV model walks through 5–10 bet
 * losing runs under normal variance, and a stake that is too large relative
 * to the bankroll turns an ordinary drawdown into depletion. Three modes:
 *
 *   FLAT          — the fixed stake_amount per ticket (legacy behaviour).
 *   FLAT_PERCENT  — stake_percent of the configured bankroll per ticket
 *                   (default 1.5%, validated within the disciplined (0,5]
 *                   band). The shipped default: simple, predictable, and it
 *                   survives losing runs by construction.
 *   FRACTIONAL_KELLY — sizes the bet to the edge the model actually measured:
 *
 *     f* = (b·p − q) / b        b = decimal odds − 1, p = calibrated
 *                               probability, q = 1 − p
 *
 * Full Kelly is famously volatile, so the platform only ever stakes a
 * configured FRACTION of it (0.25 by default — quarter Kelly), against a
 * configured bankroll figure.
 *
 * No mode ever raises a stake in response to losses: the inputs are the
 * configuration and this ticket's own numbers, never yesterday's results —
 * loss-chasing is structurally impossible here.
 *
 * HONESTY RULES, in the spirit of every other engine here:
 *   • the probability used is the ticket's CALIBRATED combined probability —
 *     never the raw model read, never an implied bookmaker number;
 *   • a non-positive Kelly edge stakes NOTHING (0.0). The ticket is still
 *     recorded so the day's decision trail stays complete, but no stake is
 *     invented for a bet the maths says not to make;
 *   • the result is capped by max_exposure AND by 4× the configured flat
 *     stake_amount, so one over-confident calibration can never bet a
 *     meaningful share of the bankroll in a single ticket;
 *   • every figure that went into the number is returned beside it, so the
 *     stored stake is reconstructable from its inputs.
 *
 * This class computes a RECOMMENDED stake for the ticket record. It moves no
 * money: there is no external execution connector in this deployment.
 */
class StakeSizer
{
    /** A single ticket may never take more than this multiple of the flat unit. */
    public const MAX_FLAT_MULTIPLE = 4.0;

    /**
     * @param float $combinedProbability calibrated probability of the whole
     *        ticket winning (product of calibrated leg probabilities)
     * @param float $totalOdds decimal combined odds actually quoted
     * @param array $config active sports configuration (staking_mode,
     *        stake_amount, bankroll, kelly_fraction, max_exposure)
     * @return array{stake:float, mode:string, detail:array<string,mixed>}
     */
    public function size(float $combinedProbability, float $totalOdds, array $config): array
    {
        $mode = strtoupper((string) ($config['staking_mode'] ?? 'FLAT'));
        $flat = (float) ($config['stake_amount'] ?? 10.0);
        $maxExposure = (float) ($config['max_exposure'] ?? 100.0);
        $bankroll = (float) ($config['bankroll'] ?? 1000.0);

        if ($mode === 'FLAT_PERCENT') {
            // A disciplined percent of bankroll per ticket (default 1.5%,
            // inside the 1–2% guidance). Still bounded by max_exposure so a
            // large configured bankroll cannot push a single ticket past the
            // operator's absolute exposure ceiling.
            $percent = (float) ($config['stake_percent'] ?? 1.5);
            if ($percent <= 0 || $percent > 5.0) $percent = 1.5;
            $stake = $bankroll > 0 ? min($bankroll * $percent / 100.0, $maxExposure) : 0.0;
            return ['stake' => round($stake, 2), 'mode' => 'FLAT_PERCENT', 'detail' => [
                'stakePercent' => $percent, 'bankroll' => $bankroll, 'maxExposure' => $maxExposure,
            ]];
        }

        if ($mode !== 'FRACTIONAL_KELLY') {
            $stake = min($flat, $maxExposure);
            return ['stake' => round($stake, 2), 'mode' => 'FLAT', 'detail' => [
                'flatStake' => $flat, 'maxExposure' => $maxExposure,
            ]];
        }
        $fraction = (float) ($config['kelly_fraction'] ?? 0.25);
        $p = max(0.0, min(1.0, $combinedProbability));
        $b = $totalOdds - 1.0;

        // Un-stakeable inputs: no odds edge is computable at b <= 0, and a
        // probability of exactly 0 or 1 is a calibration artefact, not a bet.
        if ($b <= 0.0 || $p <= 0.0 || $p >= 1.0 || $bankroll <= 0.0) {
            return ['stake' => 0.0, 'mode' => 'FRACTIONAL_KELLY', 'detail' => [
                'kellyFraction' => $fraction, 'bankroll' => $bankroll,
                'probability' => $p, 'odds' => $totalOdds,
                'fullKelly' => 0.0, 'reason' => 'UNSTAKEABLE_INPUTS',
            ]];
        }

        $fullKelly = ($b * $p - (1.0 - $p)) / $b;
        if ($fullKelly <= 0.0) {
            // The maths says this ticket is not worth a stake at these odds.
            // Record it honestly with stake 0 — never a token minimum bet.
            return ['stake' => 0.0, 'mode' => 'FRACTIONAL_KELLY', 'detail' => [
                'kellyFraction' => $fraction, 'bankroll' => $bankroll,
                'probability' => $p, 'odds' => $totalOdds,
                'fullKelly' => round($fullKelly, 6), 'reason' => 'NON_POSITIVE_KELLY_EDGE',
            ]];
        }

        $raw = $bankroll * $fraction * $fullKelly;
        $capFlat = $flat * self::MAX_FLAT_MULTIPLE;
        $stake = min($raw, $maxExposure, $capFlat);
        return ['stake' => round($stake, 2), 'mode' => 'FRACTIONAL_KELLY', 'detail' => [
            'kellyFraction' => $fraction, 'bankroll' => $bankroll,
            'probability' => $p, 'odds' => $totalOdds,
            'fullKelly' => round($fullKelly, 6),
            'uncappedStake' => round($raw, 2),
            'caps' => ['maxExposure' => $maxExposure, 'flatMultiple' => $capFlat],
        ]];
    }
}
