<?php
namespace AIWorkforce\Sports;

/**
 * ADAPTIVE CONFIDENCE POLICY (odds-prediction engine requirements #1 and #8).
 *
 * WHY THIS EXISTS
 * ---------------
 * The engine used to demand ONE fixed confidence figure (75%) from every
 * candidate, no matter how much verified evidence the day actually offered.
 * A legitimate 71% read on good data was thrown away exactly like a 40% read
 * on nothing, so whole days ended in "0 predictions" while real, well-priced
 * markets sat unused. The opposite mistake — inflating a 68% read to 75% so
 * it passes — is forbidden: the number a prediction shows is always the
 * number the model produced.
 *
 * WHAT IT DOES
 * ------------
 * The confidence a candidate must reach is a function of the DATA QUALITY
 * behind it, resolved through configurable tiers:
 *
 *   Data Quality >= 85  → EXCELLENT: the normal/high confidence requirement
 *   Data Quality 75–84  → GOOD:      a moderate requirement
 *   Data Quality 65–74  → LIMITED:   a lower requirement, and only the safer
 *                                    supported markets may be used
 *   Data Quality < 65   → REJECT:    no prediction is qualified at all
 *
 * Every number above is a CONFIGURATION VALUE, resolved in this one place:
 *
 *   1. an explicit policy passed by the caller (the stored
 *      `confidence_policy` column of the active ticket configuration);
 *   2. WINDELS_SPORTS_CONFIDENCE_POLICY — a JSON array of tiers, for
 *      deployments that configure by environment;
 *   3. the built-in defaults below.
 *
 * The tier's requirement is what the pipeline gates on; the candidate's own
 * measured confidence is what it reports. The two are never conflated, and
 * this class NEVER changes a confidence value — it only answers "is this
 * legitimate figure good enough for the evidence behind it?".
 */
class ConfidencePolicy
{
    public const ENV_POLICY = 'WINDELS_SPORTS_CONFIDENCE_POLICY';

    public const TIER_EXCELLENT = 'EXCELLENT';
    public const TIER_GOOD = 'GOOD';
    public const TIER_LIMITED = 'LIMITED';
    public const TIER_REJECT = 'REJECT';

    /**
     * The default adaptive tiers, highest data quality first.
     *
     *   minDataQuality  inclusive lower bound of the tier
     *   minConfidence   the confidence a candidate must legitimately reach
     *   markets         'ALL', or the restricted list of supported markets
     *                   this tier may use (requirement #8: limited data is
     *                   only allowed into safer markets)
     */
    public const DEFAULT_TIERS = [
        ['tier' => self::TIER_EXCELLENT, 'minDataQuality' => 85, 'minConfidence' => 75.0, 'markets' => 'ALL'],
        ['tier' => self::TIER_GOOD, 'minDataQuality' => 75, 'minConfidence' => 70.0, 'markets' => 'ALL'],
        ['tier' => self::TIER_LIMITED, 'minDataQuality' => 65, 'minConfidence' => 65.0, 'markets' => 'SAFE'],
    ];

    /**
     * The "safer supported markets" the LIMITED tier may use: outcomes with a
     * wide margin for error (two of three results, or a goal line the game
     * almost always clears). Thin lines (exact-ish totals, both-teams-score,
     * a single 1X2 outcome) need better evidence than a limited-data fixture
     * can offer, so they are not admitted at that tier.
     *
     * @var array<string,list<string>> market → selections ('*' = every selection)
     */
    public const DEFAULT_SAFE_MARKETS = [
        'DOUBLE_CHANCE' => ['*'],
        'DRAW_NO_BET' => ['*'],
        'TOTAL_GOALS' => ['OVER_0_5', 'OVER_1_5', 'UNDER_3_5', 'UNDER_4_5'],
    ];

    /** Absolute data-quality floor when no tier matches (nothing below is predictable). */
    public const DEFAULT_MIN_DATA_QUALITY = 65;

    /** @var list<array{tier:string,minDataQuality:int,minConfidence:float,markets:mixed}> */
    private array $tiers;
    /** @var array<string,list<string>> */
    private array $safeMarkets;

    /**
     * @param array|string|null $policy stored/served policy: a list of tiers,
     *        a JSON string of one, or a map {tiers:[…], safeMarkets:{…}}.
     */
    public function __construct($policy = null)
    {
        $resolved = self::normalizePolicy($policy);
        if ($resolved === null) $resolved = self::normalizePolicy(getenv(self::ENV_POLICY) ?: null);
        $this->tiers = $resolved['tiers'] ?? self::DEFAULT_TIERS;
        $this->safeMarkets = $resolved['safeMarkets'] ?? self::DEFAULT_SAFE_MARKETS;
    }

    /**
     * Build the policy the given active ticket configuration describes.
     *
     * Two configuration styles are supported, and neither is hard-coded here:
     *
     *   • `confidence_policy` — explicit tiers (full control, audited like
     *     every other configuration value);
     *   • otherwise the tiers are DERIVED from the two floors operators
     *     already set: `min_confidence` is the requirement at the best
     *     evidence level and `min_data_quality` is the absolute reject floor.
     *     An operator who only raises min_confidence therefore raises the
     *     whole ladder, and one who lowers min_data_quality opens the lower
     *     tier — without either of them silently becoming a fixed 75%.
     */
    public static function fromConfiguration(array $config): self
    {
        $explicit = self::normalizePolicy($config['confidence_policy'] ?? null);
        if ($explicit !== null) return new self($explicit);
        $topConfidence = isset($config['min_confidence']) && is_numeric($config['min_confidence'])
            ? (float) $config['min_confidence']
            : (isset($config['minConfidence']) && is_numeric($config['minConfidence']) ? (float) $config['minConfidence'] : 75.0);
        $floor = isset($config['min_data_quality']) && is_numeric($config['min_data_quality'])
            ? (int) $config['min_data_quality']
            : (isset($config['minDataQuality']) && is_numeric($config['minDataQuality']) ? (int) $config['minDataQuality'] : self::DEFAULT_MIN_DATA_QUALITY);
        return new self(self::derive($topConfidence, $floor));
    }

    /**
     * How the ladder is derived from the two floors an operator already sets.
     *
     * The configured pair (`min_confidence`, `min_data_quality`) is the GOOD
     * tier — the ordinary requirement. One documented step above it is the
     * EXCELLENT tier (better evidence, the operator's full confidence bar)
     * and one step below is the LIMITED tier (thinner evidence, a lower bar,
     * and safer markets only). Moving either configured floor moves the whole
     * ladder with it, which is what keeps the policy configurable instead of
     * hard-coded — with the stock 75 / 80 configuration the bands come out at
     * exactly the requested >=85 / 75-84 / 65-74 / <65.
     */
    public const TIER_QUALITY_STEP = 5;      // quality points between EXCELLENT and GOOD
    public const LIMITED_QUALITY_STEP = 10;  // further quality points down to LIMITED
    public const GOOD_CONFIDENCE_RELIEF = 5.0;   // confidence points relaxed at GOOD
    public const LIMITED_CONFIDENCE_RELIEF = 10.0; // …and at LIMITED
    /** Mirrors the configuration validation range: nothing below 50 is assessable. */
    public const ABSOLUTE_MIN_DATA_QUALITY = 50;

    /**
     * The derived ladder. Never below the absolute assessable floor, never
     * two tiers on the same threshold, and never a confidence requirement
     * outside [0, 100].
     *
     * @return array{tiers:list<array>,safeMarkets:array<string,list<string>>}
     */
    public static function derive(float $topConfidence, int $minDataQuality): array
    {
        $configured = max(self::ABSOLUTE_MIN_DATA_QUALITY, min(100, $minDataQuality));
        $top = max(0.0, min(100.0, $topConfidence));
        $ladder = [
            // tier, quality threshold, confidence relief, markets
            [self::TIER_EXCELLENT, $configured + self::TIER_QUALITY_STEP, 0.0, 'ALL'],
            [self::TIER_GOOD, $configured - self::TIER_QUALITY_STEP, self::GOOD_CONFIDENCE_RELIEF, 'ALL'],
            [self::TIER_LIMITED, $configured - self::TIER_QUALITY_STEP - self::LIMITED_QUALITY_STEP, self::LIMITED_CONFIDENCE_RELIEF, 'SAFE'],
        ];
        $tiers = [];
        $seen = [];
        foreach ($ladder as [$name, $quality, $relief, $markets]) {
            $threshold = max(self::ABSOLUTE_MIN_DATA_QUALITY, min(100, (int) $quality));
            if (isset($seen[$threshold])) continue;   // the stricter tier already owns this threshold
            $seen[$threshold] = true;
            $tiers[] = [
                'tier' => $name,
                'minDataQuality' => $threshold,
                'minConfidence' => round(max(0.0, $top - $relief), 2),
                'markets' => $markets,
            ];
        }
        usort($tiers, fn(array $a, array $b): int => $b['minDataQuality'] <=> $a['minDataQuality']);
        return ['tiers' => $tiers, 'safeMarkets' => self::DEFAULT_SAFE_MARKETS];
    }

    /**
     * Validate + canonicalise a policy document. Returns null when the input
     * carries no usable policy (so the next source in the chain is used); an
     * unusable tier is dropped rather than silently reinterpreted.
     *
     * @return array{tiers:list<array>,safeMarkets:array<string,list<string>>}|null
     */
    public static function normalizePolicy($policy): ?array
    {
        if (is_string($policy)) {
            $policy = trim($policy);
            if ($policy === '') return null;
            $decoded = json_decode($policy, true);
            if (!is_array($decoded)) return null;
            $policy = $decoded;
        }
        if (!is_array($policy) || $policy === []) return null;

        $rawTiers = array_is_list($policy) ? $policy : ($policy['tiers'] ?? null);
        $rawSafe = array_is_list($policy) ? null : ($policy['safeMarkets'] ?? null);

        $tiers = [];
        foreach ((array) $rawTiers as $row) {
            if (!is_array($row)) continue;
            if (!isset($row['minDataQuality'], $row['minConfidence'])) continue;
            if (!is_numeric($row['minDataQuality']) || !is_numeric($row['minConfidence'])) continue;
            $quality = (int) $row['minDataQuality'];
            $confidence = (float) $row['minConfidence'];
            if ($quality < 0 || $quality > 100 || $confidence < 0 || $confidence > 100) continue;
            $markets = $row['markets'] ?? 'ALL';
            if (is_string($markets)) $markets = strtoupper(trim($markets));
            $tiers[] = [
                'tier' => strtoupper(trim((string) ($row['tier'] ?? ('TIER_' . $quality)))),
                'minDataQuality' => $quality,
                'minConfidence' => round($confidence, 2),
                'markets' => $markets === '' ? 'ALL' : $markets,
            ];
        }
        if ($tiers === []) return null;
        usort($tiers, fn(array $a, array $b): int => $b['minDataQuality'] <=> $a['minDataQuality']);

        $safe = [];
        foreach ((array) $rawSafe as $market => $selections) {
            $market = strtoupper(trim((string) $market));
            if ($market === '') continue;
            $list = [];
            foreach ((array) $selections as $selection) {
                $selection = strtoupper(trim((string) $selection));
                if ($selection !== '') $list[] = $selection;
            }
            $safe[$market] = $list === [] ? ['*'] : $list;
        }

        return ['tiers' => $tiers, 'safeMarkets' => $safe === [] ? self::DEFAULT_SAFE_MARKETS : $safe];
    }

    /** @return list<array{tier:string,minDataQuality:int,minConfidence:float,markets:mixed}> */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /** @return array<string,list<string>> */
    public function safeMarkets(): array
    {
        return $this->safeMarkets;
    }

    /**
     * The lowest data-quality score any tier accepts. Below it nothing is
     * predictable, whatever the confidence — that is the honest REJECT band
     * requirement #8 asks for.
     */
    public function minimumDataQuality(): int
    {
        $min = null;
        foreach ($this->tiers as $tier) $min = $min === null ? $tier['minDataQuality'] : min($min, $tier['minDataQuality']);
        return $min ?? self::DEFAULT_MIN_DATA_QUALITY;
    }

    /** The strictest confidence requirement in force (the top tier's). */
    public function highestConfidenceRequirement(): float
    {
        $max = 0.0;
        foreach ($this->tiers as $tier) $max = max($max, (float) $tier['minConfidence']);
        return $max;
    }

    /** The tier a data-quality score falls into (null below the floor). */
    public function tierFor(int $dataQuality): ?array
    {
        foreach ($this->tiers as $tier) {
            if ($dataQuality >= (int) $tier['minDataQuality']) return $tier;
        }
        return null;
    }

    /** The confidence a candidate with this data quality must legitimately reach. */
    public function requiredConfidence(int $dataQuality): ?float
    {
        $tier = $this->tierFor($dataQuality);
        return $tier === null ? null : (float) $tier['minConfidence'];
    }

    /** Is this market:selection usable at the tier the data quality earned? */
    public function marketAllowed(int $dataQuality, ?string $market, ?string $selection): bool
    {
        $tier = $this->tierFor($dataQuality);
        if ($tier === null) return false;
        return $this->marketAllowedAtTier($tier, $market, $selection);
    }

    private function marketAllowedAtTier(array $tier, ?string $market, ?string $selection): bool
    {
        $markets = $tier['markets'] ?? 'ALL';
        if ($markets === 'ALL' || $markets === '*') return true;
        $market = strtoupper(trim((string) $market));
        $selection = strtoupper(trim((string) $selection));
        $allowed = $markets === 'SAFE' ? $this->safeMarkets : self::normalizeMarketMap($markets);
        if (!isset($allowed[$market])) return false;
        $selections = $allowed[$market];
        return in_array('*', $selections, true) || in_array($selection, $selections, true);
    }

    /** @return array<string,list<string>> */
    private static function normalizeMarketMap($markets): array
    {
        $out = [];
        foreach ((array) $markets as $key => $value) {
            if (is_int($key)) { $out[strtoupper(trim((string) $value))] = ['*']; continue; }
            $list = [];
            foreach ((array) $value as $selection) $list[] = strtoupper(trim((string) $selection));
            $out[strtoupper(trim((string) $key))] = $list === [] ? ['*'] : $list;
        }
        return $out;
    }

    /**
     * The full adaptive verdict for one candidate — the record the decision
     * trace stores and the UI shows. Nothing here modifies the confidence
     * figure; it only states what was required and whether it was met.
     *
     * @param int        $dataQuality the transparent DataQualityEngine score
     * @param float|null $confidence  the candidate's MEASURED confidence (null = unmeasurable)
     *
     * @return array{
     *   tier:string, requiredConfidence:?float, confidence:?float, dataQuality:int,
     *   minDataQuality:int, qualified:bool, marketAllowed:bool, reasons:list<string>, explanation:string
     * }
     */
    public function evaluate(int $dataQuality, ?float $confidence, ?string $market = null, ?string $selection = null): array
    {
        $floor = $this->minimumDataQuality();
        $tier = $this->tierFor($dataQuality);
        if ($tier === null) {
            return [
                'tier' => self::TIER_REJECT,
                'requiredConfidence' => null,
                'confidence' => $confidence,
                'dataQuality' => $dataQuality,
                'minDataQuality' => $floor,
                'qualified' => false,
                'marketAllowed' => false,
                'reasons' => ['DATA_QUALITY_BELOW_MINIMUM'],
                'explanation' => sprintf('data quality %d%% is below the configured minimum of %d%% — no market is predictable at this evidence level', $dataQuality, $floor),
            ];
        }

        $required = (float) $tier['minConfidence'];
        $marketAllowed = $this->marketAllowedAtTier($tier, $market, $selection);
        $reasons = [];
        if (!$marketAllowed) $reasons[] = 'MARKET_RESTRICTED_AT_DATA_TIER';
        if ($confidence === null) $reasons[] = 'CONFIDENCE_UNMEASURED';
        elseif ($confidence + 1e-9 < $required) $reasons[] = 'LOW_CONFIDENCE';

        return [
            'tier' => (string) $tier['tier'],
            'requiredConfidence' => $required,
            'confidence' => $confidence,
            'dataQuality' => $dataQuality,
            'minDataQuality' => $floor,
            'qualified' => $reasons === [],
            'marketAllowed' => $marketAllowed,
            'reasons' => $reasons,
            'explanation' => sprintf(
                'data quality %d%% → %s tier → %.0f%% confidence required; measured %s%s',
                $dataQuality,
                (string) $tier['tier'],
                $required,
                $confidence === null ? 'unmeasurable' : number_format($confidence, 2) . '%',
                $marketAllowed ? '' : ' (this market is restricted at the ' . $tier['tier'] . ' data tier)'
            ),
        ];
    }

    /** The policy as stored/reported (audit, diagnostics, API). */
    public function toArray(): array
    {
        return ['tiers' => $this->tiers, 'safeMarkets' => $this->safeMarkets, 'minDataQuality' => $this->minimumDataQuality()];
    }
}
