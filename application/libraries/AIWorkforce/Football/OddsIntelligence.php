<?php
namespace AIWorkforce\Football;

/**
 * The Odds Intelligence Engine: fair value, and the gap between it and a price.
 *
 * This class is where WINDELS answers the second and third of its three
 * questions — *what does the market charge*, and *is that charge attractive
 * against our own estimate*. The first question (what the data suggests) is
 * answered upstream by `OutcomePredictor`, and the two are kept apart on
 * purpose: a probability is a reading of football, a price is a reading of a
 * bookmaker, and the only place they can be compared honestly is here, after the
 * price has been normalised.
 *
 * Four rules shape the arithmetic:
 *
 *  1. **A price is never a probability.** `1 / odds` is the *implied*
 *     probability, which contains the bookmaker's margin, so it overstates every
 *     outcome. Both readings are published: `impliedProbability` (with the
 *     margin, which is what the ticket would actually pay) and `fairProbability`
 *     (margin removed, which is what the market believes absent the vig).
 *  2. **Margin removal needs a complete market.** The standard proportional
 *     deduction divides every implied probability by the sum of them
 *     (the overround). That sum only exists when *every* mutually exclusive
 *     outcome of the market is priced: three prices for 1X2, two for Over/Under.
 *     A one-sided quote has no overround to remove, so `fairOdds` is
 *     `DATA_UNAVAILABLE` with the reason — never a figure derived from one leg.
 *  3. **The verdict is in probability points, not in price.** An edge of four
 *     points means the same thing at 1.20 and at 12.00; an edge of four points
 *     of *price* would not. Expected value is published beside it as the return
 *     per unit staked, because those are different quantities and the module
 *     refuses to conflate them (a +6.9-point edge at 1.85 is a +12.8% EV).
 *  4. **Nothing here is a promise.** The classes are comparisons of two
 *     estimates. `STRONG_VALUE` is not "likely to win" and `AVOID` is not
 *     "likely to lose"; `self::DISCLAIMER` travels with every assessment so the
 *     distinction cannot be lost downstream. No stake sizing is computed at all:
 *     the engine stops at "is the price attractive", and says so.
 *
 * Every threshold is configuration (`FootballConfiguration::valueThresholds()`),
 * so the board, the API and the ticket layer classify one price identically.
 */
final class OddsIntelligence
{
    /** The five verdicts a priced selection can carry. */
    public const CLASS_STRONG_VALUE = 'STRONG_VALUE';
    public const CLASS_POSITIVE_VALUE = 'POSITIVE_VALUE';
    public const CLASS_FAIR = 'FAIR';
    public const CLASS_NEGATIVE_VALUE = 'NEGATIVE_VALUE';
    public const CLASS_AVOID = 'AVOID';
    /** Not a verdict: nothing was quoted, so there is nothing to compare with. */
    public const CLASS_UNPRICED = 'UNPRICED';

    /** How much of the market the feed actually priced. */
    public const PRICE_COMPLETE = 'COMPLETE';
    public const PRICE_PARTIAL = 'PARTIAL_MARKET';
    public const PRICE_NONE = 'DATA_UNAVAILABLE';
    /** Priced, but not as a set of mutually exclusive outcomes (correct score). */
    public const PRICE_NOT_EXHAUSTIVE = 'NOT_EXHAUSTIVE_MARKET';

    /** How the margin was taken out, so a reader can audit the fair price. */
    public const MARGIN_PROPORTIONAL = 'PROPORTIONAL_OVERROUND';

    /** The sentence that must stay attached to a value verdict. */
    public const DISCLAIMER = 'A value class compares WINDELS\' probability estimate with a quoted price. '
        . 'It is not a probability of winning, and no selection is guaranteed — a +EV price loses most of the time.';

    /** Human labels for the classification, shared by the console and the API. */
    public const LABELS = [
        self::CLASS_STRONG_VALUE => 'Strong value',
        self::CLASS_POSITIVE_VALUE => 'Positive value',
        self::CLASS_FAIR => 'Fair',
        self::CLASS_NEGATIVE_VALUE => 'Negative value',
        self::CLASS_AVOID => 'Avoid',
        self::CLASS_UNPRICED => 'No price to compare',
    ];

    public function __construct(private FootballConfiguration $config) {}

    /**
     * What one selection of a market was quoted, and what the model makes of it.
     *
     * @param array{state:string, quotes:array<string,array{odds:float,observedAt:?string,quotes:int,low:float,high:float}>, expected:list<string>, note:?string} $market
     *        the price sheet `PredictionMarkets::priceSheet()` assembled
     * @param string $selection the catalogue selection being judged (HOME, OVER…)
     * @param ?float $modelProbability WINDELS' own probability for that selection
     */
    public function assess(array $market, string $selection, ?float $modelProbability): array
    {
        $thresholds = $this->config->valueThresholds();
        $quote = $market['quotes'][$selection] ?? null;
        $odds = $quote['odds'] ?? null;
        // Validate odds: must be >1.0 and ≤100 — anything else is not a real decimal price
        if ($odds !== null && ((float) $odds <= 1.0 || (float) $odds > 100.0 || !is_finite((float) $odds))) {
            $market['note'] = trim((string) ($market['note'] ?? '') . ' The quoted price for ' . $selection
                . ' was ' . $odds . ', which is not a valid decimal price (>1.0 and ≤100.0); it was ignored.');
            $odds = null;
        }

        // The fair block is computed once for the whole market by
        // `withFairValues()` — every selection of one market shares one
        // overround, so deriving it per selection would re-read the same quotes.
        $fairQuote = $market['fair'][$selection] ?? null;

        $implied = $odds !== null ? 1.0 / (float) $odds : null;
        $fairProbability = $fairQuote['fairProbability'] ?? null;
        $fairOdds = $fairQuote['fairOdds'] ?? null;
        $windelsFairOdds = $modelProbability !== null && $modelProbability > 0 ? 1.0 / $modelProbability : null;

        // `edge` in probability points: what WINDELS believes minus what the
        // price says. Against the fair (de-vigged) probability it is the same
        // comparison without the margin, which is the smaller and truer number.
        $edge = $implied !== null && $modelProbability !== null ? round($modelProbability - $implied, 6) : null;
        $fairEdge = $fairProbability !== null && $modelProbability !== null
            ? round($modelProbability - $fairProbability, 6) : null;
        $expectedValue = $odds !== null && $modelProbability !== null
            ? round($modelProbability * (float) $odds - 1.0, 6) : null;
        // The probability at which this exact price pays for itself. Stated
        // because it is the one figure that makes "positive value" checkable:
        // WINDELS has to be above it, not merely above the implied share.
        $breakEven = $odds !== null ? round(1.0 / (float) $odds, 6) : null;

        if ($modelProbability === null) {
            // The honest fourth state: a price with nothing to compare it to.
            // A quoted price is not an opinion of ours, and it is not turned into
            // one — the block below still carries the price, with no verdict.
            $class = $odds !== null
                ? ['class' => self::CLASS_UNPRICED, 'reason' => 'WINDELS has no probability for this selection, so the quoted '
                    . 'price is shown as a price and is not judged against anything.']
                : ['class' => self::CLASS_UNPRICED, 'reason' => 'Neither a model estimate nor a quoted price exists for this selection.'];
        } else {
            $class = $this->classify($edge, $fairEdge, (string) ($market['state'] ?? self::PRICE_NONE), $expectedValue);
        }

        return [
            'selection' => $selection,
            'marketState' => (string) ($market['state'] ?? self::PRICE_NONE),
            'odds' => $odds !== null ? round((float) $odds, 4) : null,
            'quoteCount' => (int) ($quote['quotes'] ?? 0),
            'priceSpread' => $quote !== null && (float) $quote['high'] - (float) $quote['low'] > 0.0001
                ? round((float) $quote['high'] - (float) $quote['low'], 4) : null,
            'observedAt' => $quote['observedAt'] ?? null,
            'modelProbability' => $modelProbability !== null ? round($modelProbability, 6) : null,
            'windelsFairOdds' => $windelsFairOdds !== null ? round($windelsFairOdds, 4) : null,
            'impliedProbability' => $implied !== null ? round($implied, 6) : null,
            'edge' => $edge,
            'edgePoints' => $edge !== null ? round($edge * 100.0, 2) : null,
            'expectedValue' => $expectedValue,
            'breakEvenProbability' => $breakEven,
            // Margin-removed readings, and why they may be absent.
            'fairOdds' => $fairOdds !== null ? round($fairOdds, 4) : null,
            'fairProbability' => $fairProbability !== null ? round($fairProbability, 6) : null,
            'edgeAgainstFair' => $fairEdge,
            'edgeAgainstFairPoints' => $fairEdge !== null ? round($fairEdge * 100.0, 2) : null,
            'marginMethod' => $fairQuote !== null ? self::MARGIN_PROPORTIONAL : null,
            'valueClass' => $class['class'],
            'valueLabel' => self::LABELS[$class['class']] ?? $class['class'],
            'valueReason' => $class['reason'],
            'thresholds' => ['strongPoints' => round($thresholds['strong'] * 100, 2),
                'positivePoints' => round($thresholds['positive'] * 100, 2), 'avoidPoints' => round($thresholds['avoid'] * 100, 2)],
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * The verdict, and the sentence that justifies it.
     *
     * STRONG_VALUE additionally requires the edge to survive margin removal: a
     * selection that looks valuable against a padded price and ordinary against
     * the de-vigged one is not strong, and calling it strong would be a reading
     * of the vig rather than of the football.
     *
     * @return array{class:string,reason:string}
     */
    public function classify(?float $edge, ?float $fairEdge, string $marketState, ?float $expectedValue): array
    {
        $thresholds = $this->config->valueThresholds();
        if ($edge === null) {
            return ['class' => self::CLASS_UNPRICED, 'reason' => match ($marketState) {
                self::PRICE_NONE => 'No price is stored for this selection, so WINDELS\' estimate has nothing to be compared with. '
                    . 'The probability stands on its own; no value verdict is given.',
                self::PRICE_PARTIAL => 'Only part of this market is priced, so the comparison is not complete and no value verdict is given.',
                self::PRICE_NOT_EXHAUSTIVE => 'This market does not price out a set of mutually exclusive outcomes, so its margin cannot be '
                    . 'removed and no value verdict is given.',
                default => 'No comparable price is stored for this selection.',
            }];
        }
        $points = round($edge * 100.0, 2);
        $fairPoints = $fairEdge === null ? null : round($fairEdge * 100.0, 2);
        $ev = $expectedValue === null ? null : round($expectedValue * 100.0, 2);
        $measurement = 'WINDELS is ' . ($points >= 0 ? '+' : '') . $points . ' points above the quoted price'
            . ($ev === null ? '' : ' (expected return ' . ($ev >= 0 ? '+' : '') . $ev . '% per unit staked)');
        if ($edge >= $thresholds['strong']) {
            if ($fairPoints === null) {
                return ['class' => self::CLASS_POSITIVE_VALUE, 'reason' => $measurement
                    . '. The margin could not be removed from this market, so it is reported as positive value rather than strong value: '
                    . 'a large edge against a padded price has not been shown to survive de-vigging.'];
            }
            if ($fairPoints < $thresholds['positive'] * 100.0) {
                return ['class' => self::CLASS_POSITIVE_VALUE, 'reason' => $measurement
                    . ', but against the margin-removed price the edge is only ' . $fairPoints
                    . ' points — the quoted gap is largely the bookmaker\'s margin, so this is not strong value.'];
            }
            return ['class' => self::CLASS_STRONG_VALUE, 'reason' => $measurement
                . ', and the edge still holds at ' . $fairPoints . ' points once the margin is removed.'];
        }
        if ($edge >= $thresholds['positive']) {
            return ['class' => self::CLASS_POSITIVE_VALUE, 'reason' => $measurement
                . ', past the ' . round($thresholds['positive'] * 100, 1) . '-point floor but short of the '
                . round($thresholds['strong'] * 100, 1) . '-point strong line.'];
        }
        if ($edge > -$thresholds['positive']) {
            return ['class' => self::CLASS_FAIR, 'reason' => $measurement . ' — inside the '
                . round($thresholds['positive'] * 100, 1) . '-point band the module treats as noise, so the price is '
                . 'judged fair rather than wrong.'];
        }
        if ($edge > -$thresholds['avoid']) {
            return ['class' => self::CLASS_NEGATIVE_VALUE, 'reason' => $measurement
                . ': the price asks for more than WINDELS\' estimate supports, by less than the avoid line.'];
        }
        return ['class' => self::CLASS_AVOID, 'reason' => $measurement . ', at or beyond the -'
            . round($thresholds['avoid'] * 100, 1) . '-point line: the market is priced well above what the '
            . 'stored data supports.'];
    }

    /**
     * Market-level reading: the overround, the margin, and each selection's
     * margin-removed probability and fair price.
     *
     * The whole-margin deduction is the standard proportional method: with
     * implied probabilities summing to 1.081, each is divided by 1.081. It is an
     * estimate of the fair market, not a measurement of the bookmaker's true
     * over-round on each leg, which no feed publishes — hence `marginMethod`, so
     * the derivation is always visible next to the number.
     *
     * @param array{state:string,quotes:array<string,array{odds:float,observedAt:?string,quotes:int}>} $market
     * @return array<string,mixed> the same market with the fair block attached
     */
    public function withFairValues(array $market): array
    {
        $fair = $this->fairFromQuotes($market);
        $market['overround'] = $fair['overround'];
        $market['marginPoints'] = $fair['marginPoints'];
        $market['marginMethod'] = $fair['method'];
        $market['fair'] = $fair['quotes'];
        // The freshness of a price is independent of whether the market was
        // complete: a single stale leg is still a stale leg, and reporting no
        // timestamp for it would read as "we have a price and it is current".
        $pricedAt = $fair['pricedAt'];
        if ($pricedAt === null) {
            foreach ((array) ($market['quotes'] ?? []) as $quote) {
                $observed = $quote['observedAt'] ?? null;
                if (is_string($observed) && $observed !== '' && ($pricedAt === null || $observed > $pricedAt)) $pricedAt = $observed;
            }
        }
        $market['pricedAt'] = $pricedAt;
        $market['staleAfterSeconds'] = $this->config->maxDataAgeSeconds('odds');
        $market['pricedAgoSeconds'] = $pricedAt !== null ? max(0, time() - (int) strtotime($pricedAt)) : null;
        $market['priceStale'] = $market['pricedAgoSeconds'] !== null
            && $market['pricedAgoSeconds'] > $market['staleAfterSeconds'];
        return $market;
    }

    /**
     * The margin-removed probabilities of a priced market.
     *
     * @param array<string,array{odds:float,observedAt:?string,quotes:int}> $quotes
     * @return array{overround:?float,marginPoints:?float,method:?string,quotes:array<string,array<string,mixed>>,pricedAt:?string}
     */
    private function fairFromQuotes(array $market): array
    {
        $empty = ['overround' => null, 'marginPoints' => null, 'method' => null, 'quotes' => [], 'pricedAt' => null];
        if ((string) ($market['state'] ?? '') !== self::PRICE_COMPLETE) return $empty;
        $quotes = (array) ($market['quotes'] ?? []);
        $sum = 0.0;
        foreach ($quotes as $quote) {
            $odds = (float) ($quote['odds'] ?? 0);
            // Validate: >1.0, ≤100, finite — unrealistic odds void the overround
            if ($odds <= 1.0 || $odds > 100.0 || !is_finite($odds)) return $empty;
            $sum += 1.0 / $odds;
        }
        if ($sum <= 0.0) return $empty;
        $out = [];
        $pricedAt = null;
        foreach ($quotes as $key => $quote) {
            $implied = 1.0 / (float) $quote['odds'];
            $fair = $implied / $sum;
            $observed = $quote['observedAt'] ?? null;
            if (is_string($observed) && $observed !== '' && ($pricedAt === null || $observed > $pricedAt)) $pricedAt = $observed;
            $out[(string) $key] = [
                'impliedProbability' => round($implied, 6),
                'fairProbability' => round($fair, 6),
                // A fair probability of zero would divide by itself below; a
                // market that sums sensibly cannot produce one, so the guard is
                // arithmetic hygiene rather than a story about the match.
                'fairOdds' => $fair > 0.0 ? round(1.0 / $fair, 4) : null,
                'marginRemoved' => round(($implied - $fair) * 100.0, 2),
                'observedAt' => $observed,
            ];
        }
        return [
            'overround' => round($sum, 6),
            'marginPoints' => round(($sum - 1.0) * 100.0, 2),
            'method' => self::MARGIN_PROPORTIONAL,
            'quotes' => $out,
            'pricedAt' => $pricedAt,
        ];
    }
}
