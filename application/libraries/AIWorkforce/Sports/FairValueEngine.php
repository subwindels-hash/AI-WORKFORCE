<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\OddsIntelligence;

/**
 * Fair-value engine for the odds-prediction ticket layer — the "Odds
 * Intelligence" step of the WINDELS architecture:
 *
 *   authorized market odds → normalize → remove bookmaker margin →
 *   implied probability → compare with the WINDELS probability → edge
 *
 * The arithmetic itself is NOT re-implemented here. It delegates to the
 * Football module's `OddsIntelligence` — the same engine the football board
 * and API use — so one price can never be "strong value" on one screen and
 * "fair" on another, and the classification thresholds
 * (WINDELS_FOOTBALL_VALUE_*_PP) are shared by every layer.
 *
 * Honesty rules inherited from the shared engine:
 *   • a price is never a probability: `impliedProbability` keeps the margin,
 *     `marketFairProbability` has it removed (proportional overround);
 *   • margin removal needs a COMPLETE market (every mutually exclusive
 *     outcome priced). A one-sided quote gets no fair price — the gap is
 *     stated, never filled;
 *   • the verdict is in probability points, alongside expected value per
 *     unit staked — different quantities, never conflated;
 *   • STRONG_VALUE additionally requires the edge to survive de-vigging;
 *   • nothing is a promise: the disclaimer travels with every assessment.
 *
 * The classification is a READING, not a gate: ticket qualification still
 * runs on real expected value against the real price (ValueEngine), so
 * adding this layer can never loosen or tighten the safety rules.
 */
final class FairValueEngine
{
    /**
     * The mutually exclusive outcomes that make a market complete. A market
     * is only de-vigged when EVERY one of these selections is priced.
     *
     * @var array<string,list<string>>
     */
    public const MARKET_OUTCOMES = [
        'MATCH_RESULT' => ['HOME', 'DRAW', 'AWAY'],
        'TOTAL_GOALS' => ['OVER_1_5', 'UNDER_1_5'],
        'BTTS' => ['YES', 'NO'],
        'DOUBLE_CHANCE' => ['HOME_OR_DRAW', 'AWAY_OR_DRAW', 'HOME_OR_AWAY'],
        'DRAW_NO_BET' => ['HOME', 'AWAY'],
    ];

    /**
     * The complete set of outcomes for the market a given selection belongs
     * to. Goal lines are the reason this is not a flat lookup: Over 2.5 is
     * de-vigged against Under 2.5, never against Under 1.5. Asking for the
     * wrong pair would compute a margin from two different markets, so the
     * line is read from the selection itself.
     *
     * @return list<string>
     */
    public static function outcomesFor(string $market, string $selection): array
    {
        $market = strtoupper(trim($market));
        $selection = strtoupper(trim($selection));
        if ($market === 'TOTAL_GOALS') {
            $line = PredictionEngine::totalsLine($selection);
            if ($line === null) return self::MARKET_OUTCOMES['TOTAL_GOALS'];
            $suffix = str_replace('.', '_', (string) $line[0]);
            return ['OVER_' . $suffix, 'UNDER_' . $suffix];
        }
        if ($market === 'ASIAN_HANDICAP') {
            // A handicap is de-vigged against its MIRROR: Home -1.5 pairs with
            // Away +1.5, never with another line. Pairing across lines would
            // compute an overround from two different markets.
            $parsed = ScoreGridPricer::handicapSelection($selection);
            if ($parsed === null) return [];
            $opposite = $parsed['side'] === 'HOME' ? 'AWAY' : 'HOME';
            $line = -$parsed['line'];
            $sign = $line < 0 ? 'MINUS' : 'PLUS';
            $magnitude = rtrim(rtrim(number_format(abs($line), 1, '.', ''), '0'), '.');
            $suffix = str_replace('.', '_', $magnitude === '' ? '0' : $magnitude);
            return [$selection, $opposite . '_' . $sign . '_' . $suffix];
        }
        // CORRECT_SCORE is deliberately absent: its complete outcome set is
        // the entire scoreline space, which no book prices exhaustively. The
        // market therefore stays PARTIAL and gets no de-vigged fair price —
        // the gap is stated by the shared engine, never estimated.
        return self::MARKET_OUTCOMES[$market] ?? [];
    }

    private FootballConfiguration $config;
    private OddsIntelligence $odds;

    public function __construct(?FootballConfiguration $config = null)
    {
        $this->config = $config ?? new FootballConfiguration();
        $this->odds = new OddsIntelligence($this->config);
    }

    /** The shared configuration (thresholds are common to every layer). */
    public function configuration(): FootballConfiguration
    {
        return $this->config;
    }

    /**
     * Judge one selection of a market against the WINDELS model probability.
     *
     * @param string $market catalogue market (MATCH_RESULT, TOTAL_GOALS…)
     * @param array<string,array{odds:float,observedAt:?string}> $marketPrices
     *        the fresh prices of the market's selections — including
     *        companion selections that are NOT ticket candidates
     *        (UNDER_1_5, BTTS NO): the overround needs the whole market,
     *        and a companion price is never itself predicted.
     * @param string $selection the candidate selection being judged
     * @param float|null $modelProbability WINDELS' own calibrated probability
     * @return array the shared OddsIntelligence assessment (valueClass,
     *         valueReason, implied/fair probabilities, edges, EV, disclaimer)
     */
    public function assessMarket(string $market, array $marketPrices, string $selection, ?float $modelProbability): array
    {
        $market = strtoupper(trim($market));
        $selection = strtoupper(trim($selection));
        // The overround is only meaningful across the SAME line/market, so
        // the expected outcome set is resolved from the selection too.
        $expected = self::outcomesFor($market, $selection);

        $quotes = [];
        foreach ($marketPrices as $pricedSelection => $price) {
            $odds = is_numeric($price['odds'] ?? null) ? (float) $price['odds'] : 0.0;
            if ($odds <= 1.0) continue; // not a decimal price; the shared engine states the drop
            $quotes[strtoupper(trim((string) $pricedSelection))] = [
                'odds' => $odds,
                'observedAt' => isset($price['observedAt']) && is_string($price['observedAt']) ? $price['observedAt'] : null,
                'quotes' => 1,
                'low' => $odds,
                'high' => $odds,
            ];
        }

        $complete = $expected !== [];
        foreach ($expected as $needed) {
            if (empty($quotes[$needed])) { $complete = false; break; }
        }

        $sheet = [
            'state' => $quotes === []
                ? OddsIntelligence::PRICE_NONE
                : ($complete ? OddsIntelligence::PRICE_COMPLETE : OddsIntelligence::PRICE_PARTIAL),
            'quotes' => $quotes,
            'expected' => $expected,
            'note' => null,
        ];
        $sheet = $this->odds->withFairValues($sheet);
        $assessment = $this->odds->assess($sheet, $selection, $modelProbability);
        // The margin/freshness block lives on the sheet (`withFairValues`),
        // not in `assess()`'s verdict: carry it into the one returned
        // structure so a reader sees the removed margin next to the class it
        // produced. Null when the market was incomplete — never estimated.
        foreach (['marginPoints', 'overround', 'pricedAt', 'pricedAgoSeconds', 'priceStale', 'staleAfterSeconds'] as $sheetKey) {
            $assessment[$sheetKey] = $sheet[$sheetKey] ?? null;
        }
        return $assessment;
    }
}
