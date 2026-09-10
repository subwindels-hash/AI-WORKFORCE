<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Football\CalibrationService;
use AIWorkforce\Football\IntelligenceScore;
use AIWorkforce\Football\PredictionDrivers;
use AIWorkforce\Football\StabilityMonitor;

/**
 * Per-match intelligence pipeline (spec §8–§15), evaluated in one fixed
 * stage order:
 *
 *   data normalization → odds availability/freshness → prediction →
 *   probability → confidence → data quality → value/edge → risk →
 *   correlation → configuration
 *
 * Every stage's actual inputs are captured in `factors` so the final decision
 * can be reconstructed later (spec §28/§29). Stage failures are ALL recorded
 * internally (rejectionReasons) but each rejected candidate exposes exactly
 * one primaryReason — the first stage that failed — plus the concrete
 * missing/stale fields (rejectionDetail), so one upstream data problem can
 * no longer cascade into duplicated rejection counts.
 *
 * The WINDELS prediction and the bookmaker odds stay strictly separated:
 * probability + fairOdds are the model's own numbers; market odds only enter
 * through the value stage (implied probability, edge, expected value).
 *
 * On top of the gates, every ready candidate carries the WINDELS
 * intelligence layer (shared with the Football board, so both screens
 * classify one price identically):
 *   • `value` — margin-removed fair price + value class via FairValueEngine
 *     (OddsIntelligence); a READING, never a gate;
 *   • `stability` — how far the model's own probability moved against the
 *     previous stored prediction of the same selection (BASELINE / STABLE /
 *     MOVED / UNSTABLE);
 *   • `intelligenceScore` — how well evidenced the read is (confidence,
 *     data quality, calibration, stability; unmeasurable components are
 *     excluded with the remaining weights renormalised, never zeroed);
 *   • `drivers` — the stored measurements behind the prediction, restated
 *     as human-readable readings with their sources, including what the
 *     model could NOT see;
 *   • `lastUpdated` — prediction generated / data refreshed / odds refreshed.
 */
class PredictionPipeline
{
    private MatchIntelligenceEngine $intelligence;
    private FeatureEngineeringEngine $features;
    private PredictionEngine $prediction;
    private ValueEngine $value;
    private RiskEngine $risk;
    private CorrelationEngine $correlation;
    private ConfidenceEngine $confidence;
    private FairValueEngine $fairValue;
    private ?IntelligenceScore $scorer = null;
    private ?PredictionDrivers $explainer = null;

    public function __construct(
        ?MatchIntelligenceEngine $intelligence = null,
        ?FeatureEngineeringEngine $features = null,
        ?PredictionEngine $prediction = null,
        ?ValueEngine $value = null,
        ?RiskEngine $risk = null,
        ?CorrelationEngine $correlation = null,
        ?ConfidenceEngine $confidence = null,
        ?FairValueEngine $fairValue = null
    ) {
        $this->intelligence = $intelligence ?? new MatchIntelligenceEngine();
        $this->features = $features ?? new FeatureEngineeringEngine();
        $this->prediction = $prediction ?? new PredictionEngine();
        $this->value = $value ?? new ValueEngine();
        $this->risk = $risk ?? new RiskEngine();
        $this->correlation = $correlation ?? new CorrelationEngine();
        $this->confidence = $confidence ?? new ConfidenceEngine();
        $this->fairValue = $fairValue ?? new FairValueEngine();
    }

    /**
     * @param array  $match    repository match row (payload holds provider context)
     * @param array  $odds     latest odds row or null
     * @param array  $quality  DataQualityEngine assessment
     * @param array  $calibration approved calibration row (with intercept/slope) or null
     * @param array  $config   active configuration
     * @param int|null $now
     * @param array  $extras   intelligence-layer inputs:
     *   marketPrices      map market → selection → {odds, observedAt} — the
     *                     fresh prices of the WHOLE market (companion
     *                     selections included) for margin removal;
     *   previousPrediction stored prediction row for the same selection, for
     *                     the stability reading (null = baseline);
     *   matchUpdatedAt    when the fixture data was last refreshed.
     */
    public function evaluate(array $match, ?array $odds, array $quality, ?array $calibration, array $config = [], ?int $now = null, array $extras = []): array
    {
        // ── Stage 1: data normalization (match intelligence) ──────────────
        $intel = $this->intelligence->analyze($match, $odds, [], $now);
        $freshness = $intel['oddsFreshness'] ?? [];
        $factors = [
            'drivers' => [],
            'odds' => $odds ? $this->oddsFactors($odds, $freshness) : null,
            'calibration' => null,
            'quality' => ['score' => $quality['score'] ?? 0, 'band' => $quality['band'] ?? 'UNKNOWN', 'missing' => $quality['missing'] ?? [], 'missingMandatory' => $quality['missingMandatory'] ?? []],
            'inputsUnavailable' => $intel['unavailableInputs'] ?? [],
            'simulated' => !empty($intel['match']['simulated']),
            'gate' => ['passed' => [], 'failed' => []],
            'stages' => [],
        ];

        $candidate = [
            'matchId' => $match['id'] ?? null,
            'match' => $intel['match'],
            'market' => $odds ? strtoupper((string) ($odds['market'] ?? '')) : 'TOTAL_GOALS',
            'selection' => $odds ? strtoupper((string) ($odds['selection'] ?? '')) : 'OVER_1_5',
            'odds' => $odds ? (float) ($odds['decimalOdds'] ?? $odds['decimal_odds'] ?? 0) : null,
            'oddsTimestamp' => $odds ? ($odds['observedAt'] ?? $odds['observed_at'] ?? null) : null,
            // Odds provenance, stored with every candidate/decision record.
            'oddsUpdatedAt' => $freshness['oddsUpdatedAt'] ?? null,
            'oddsSource' => $freshness['oddsSource'] ?? ($odds['oddsSource'] ?? null),
            'oddsAgeSeconds' => $freshness['oddsAgeSeconds'] ?? null,
            'oddsStatus' => $freshness['oddsStatus'] ?? null,
            'intelligence' => $intel,
            'quality' => $quality,
        ];

        $failed = [];                 // every failed stage's reason, in stage order
        $missingFields = [];          // concrete missing model inputs
        $staleFields = [];            // concrete stale inputs

        // Record a stage outcome. Only a FAILED stage contributes a rejection
        // reason; a stage that could not run (SKIPPED) never adds one, so a
        // single upstream failure is never counted twice.
        $stage = function (string $name, string $outcome, ?string $reason) use (&$factors, &$failed): void {
            $factors['stages'][$name] = $outcome;
            if ($outcome === 'FAILED' && $reason !== null && !in_array($reason, $failed, true)) $failed[] = $reason;
        };

        $intelReady = ($intel['decision'] ?? '') === 'INTELLIGENCE_READY';
        $stage('dataNormalization', $intelReady ? 'PASSED' : 'FAILED', ($intel['rejectionReasons'] ?? [])[0] ?? 'MATCH_DATA_INVALID');
        if (!$intelReady) {
            foreach ($intel['rejectionReasons'] ?? ['MATCH_DATA_INVALID'] as $r) if (!in_array($r, $failed, true)) $failed[] = $r;
            return $this->finalize($candidate, $factors, null, null, null, null, 'REJECTED', $failed, 'NO_PREDICTION', $quality, $missingFields, $staleFields);
        }

        // ── Stage 2: odds availability / freshness (before any prediction) ─
        if ($odds === null || empty($freshness['available'])) {
            $stage('oddsAvailability', 'FAILED', 'ODDS_UNAVAILABLE');
        } elseif (empty($freshness['fresh'])) {
            $stage('oddsAvailability', 'FAILED', $freshness['reason'] ?? 'STALE_ODDS');
            $staleFields[] = 'odds';
        } else {
            $stage('oddsAvailability', 'PASSED', null);
        }
        if ($failed) {
            return $this->finalize($candidate, $factors, null, null, null, null, 'REJECTED', $failed, 'NO_PREDICTION', $quality, $missingFields, $staleFields);
        }

        // ── Stage 3: prediction (features + model + calibration) ──────────
        $fs = $this->features->build($intel);
        if (!empty($fs['features'])) $factors['drivers'] = $fs['features'];
        $factors['featureVersion'] = $fs['version'] ?? null;

        $calibrationInput = null;
        if ($calibration !== null) {
            $calibrationInput = ['approved' => true, 'intercept' => (float) $calibration['intercept'], 'slope' => (float) $calibration['slope'], 'version' => $calibration['calibrationVersion'] ?? $calibration['version'] ?? null, 'ece' => $calibration['ece'] ?? null, 'samples' => $calibration['samples'] ?? 0, 'approvedAt' => $calibration['approved_at'] ?? null];
            $factors['calibration'] = ['version' => $calibrationInput['version'], 'intercept' => $calibrationInput['intercept'], 'slope' => $calibrationInput['slope'], 'ece' => $calibrationInput['ece'], 'samples' => $calibrationInput['samples'], 'approvedAt' => $calibrationInput['approvedAt']];
        }

        if (empty($fs['ok'])) {
            $stage('prediction', 'FAILED', $fs['reason'] ?? 'INSUFFICIENT_DATA');
            foreach (($fs['missingFields'] ?? []) as $mf) $missingFields[] = $mf;
        } elseif (($config['require_calibration'] ?? 1) && $calibrationInput === null) {
            $stage('prediction', 'FAILED', 'MODEL_NOT_CALIBRATED');
        } else {
            $prediction = $this->prediction->predict((string) $candidate['market'], (string) $candidate['selection'], $fs, $calibrationInput ?? []);
            if (!empty($prediction['market'])) { $candidate['market'] = $prediction['market']; $candidate['selection'] = $prediction['selection']; }
            if (($prediction['decision'] ?? '') !== 'PREDICTION_READY') {
                $stage('prediction', 'FAILED', $prediction['reason'] ?? 'NO_PREDICTION');
                foreach (($prediction['missingFields'] ?? []) as $mf) $missingFields[] = $mf;
            } else {
                $stage('prediction', 'PASSED', null);
            }
        }
        $predictionReady = ($factors['stages']['prediction'] ?? '') === 'PASSED';

        // ── Stage 4: probability — model output, separated from odds ──────
        // (rawModelProbability / calibratedProbability / fairOdds on the prediction)

        // ── Stage 5: confidence (WINDELS blend; gated ONCE, here) ─────────
        $conf = $this->confidence->assess($prediction ?? ['decision' => 'NO_PREDICTION'], $quality, $calibrationInput);
        $factors['confidence'] = $conf['breakdown'] ?? null;
        $minConfidence = (float) ($config['min_confidence'] ?? 0);
        if (!$predictionReady) {
            $stage('confidence', 'SKIPPED', null);
        } elseif (is_numeric($conf['confidence'] ?? null)) {
            $stage('confidence', (float) $conf['confidence'] >= $minConfidence ? 'PASSED' : 'FAILED', 'LOW_CONFIDENCE');
        } else {
            $stage('confidence', 'FAILED', 'CONFIDENCE_UNMEASURED');
        }

        // ── Stage 6: data quality (configurable floor) ────────────────────
        $minQuality = (int) ($config['min_data_quality'] ?? $config['minDataQuality'] ?? DataQualityEngine::DEFAULT_MIN_DATA_QUALITY);
        $stage('dataQuality', ($quality['score'] ?? 0) >= $minQuality ? 'PASSED' : 'FAILED', 'LOW_DATA_QUALITY');

        // ── Stage 7: value / edge (model probability vs real market odds) ─
        $value = $this->value->assess($prediction ?? ['decision' => 'NO_PREDICTION'], $odds !== null ? ['decimalOdds' => (float) ($odds['decimalOdds'] ?? $odds['decimal_odds'] ?? 0)] : ['decimalOdds' => 0]);
        if (!$predictionReady) {
            $stage('valueEdge', 'SKIPPED', null);
        } else {
            $requireValue = !array_key_exists('require_positive_value', $config) || (bool) $config['require_positive_value'];
            $valueOk = !$requireValue || !empty($value['qualified']);
            $stage('valueEdge', $valueOk ? 'PASSED' : 'FAILED', $value['reason'] ?? 'LOW_MODEL_EDGE');
            // Odds Intelligence: the margin-removed market reading (value
            // class, fair price, edge against the de-vigged probability).
            // A READING on top of the gate — it never changes qualification.
            $marketPrices = $extras['marketPrices'] ?? null;
            if (is_array($marketPrices) && $marketPrices !== []) {
                $fair = $this->fairValue->assessMarket(
                    (string) $candidate['market'],
                    $marketPrices,
                    (string) $candidate['selection'],
                    is_numeric($prediction['calibratedProbability'] ?? null) ? (float) $prediction['calibratedProbability'] : null
                );
                // Keep the model's own fairOdds distinct from the market's
                // margin-removed fair price: renaming, not overwriting.
                foreach ($fair as $fairKey => $fairValueEntry) {
                    if (in_array($fairKey, ['selection', 'quoteCount'], true)) continue;
                    $value[$fairKey === 'fairOdds' ? 'marketFairOdds' : ($fairKey === 'fairProbability' ? 'marketFairProbability' : $fairKey)] = $fairValueEntry;
                }
            }
        }

        // ── Stage 7b: stability, intelligence score, drivers, timestamps ──
        $stability = $this->stabilityOf($extras['previousPrediction'] ?? null, $prediction ?? null, $predictionReady);
        $intelligenceScore = null;
        $drivers = null;
        if ($predictionReady) {
            $intelligenceScore = $this->scoreOf($conf, $quality, $calibrationInput, $stability);
            $drivers = $this->driversOf($intel, $fs, $prediction, $value, $candidate, $quality);
        }
        $candidate['stability'] = $stability;
        $candidate['intelligenceScore'] = $intelligenceScore;
        $candidate['drivers'] = $drivers;
        $candidate['lastUpdated'] = [
            'predictionGenerated' => gmdate('c', (int) ($now ?? time())),
            'dataRefreshed' => is_string($extras['matchUpdatedAt'] ?? null) ? $extras['matchUpdatedAt'] : null,
            'oddsRefreshed' => $candidate['oddsUpdatedAt'],
        ];
        // factors['drivers'] keeps its legacy meaning — the feature set the
        // model consumed. The WINDELS human-readable readings (drivers with
        // their stored figures, or honest DATA_UNAVAILABLE rows) are stored
        // alongside, never over it.
        $factors['stability'] = $stability;
        $factors['intelligenceScore'] = $intelligenceScore;
        $factors['readings'] = $drivers;
        $factors['lastUpdated'] = $candidate['lastUpdated'];


        // ── Stage 8: risk ─────────────────────────────────────────────────
        $riskContext = [
            'liquidity' => $intel['inputs']['marketLiquidity'] ?? null,
            'marketSuspended' => !empty($odds['suspended']) || ($match['status'] ?? '') === 'SUSPENDED',
            'oddsMovement' => $this->oddsMovement($odds),
        ];
        $risk = $this->risk->assess($value, $quality, $config, $riskContext);
        if ($risk['classification'] === 'REJECTED') {
            $stage('risk', 'FAILED', $risk['reasons'][0] ?? 'HIGH_RISK');
            foreach ($risk['reasons'] ?? [] as $r) if (!in_array($r, $failed, true)) $failed[] = $r;
        } elseif ($risk['classification'] === 'HIGH') {
            $stage('risk', 'FAILED', 'HIGH_RISK');
        } else {
            $stage('risk', 'PASSED', null);
        }

        // ── Stage 9: configuration (allowed markets / leagues) ────────────
        $allowedMarkets = $config['allowed_markets'] ?? [];
        if (!PredictionEngine::isSupportedMarketSelection((string) $candidate['market'], (string) $candidate['selection'])) {
            $stage('configuration', 'FAILED', 'UNSUPPORTED_MARKET');
        } elseif (is_array($allowedMarkets) && count($allowedMarkets) > 0 && !in_array($candidate['market'], $allowedMarkets, true)) {
            $stage('configuration', 'FAILED', 'OUTSIDE_CONFIGURATION');
        } else {
            $allowedLeagues = $config['allowed_leagues'] ?? [];
            if (is_array($allowedLeagues) && count($allowedLeagues) > 0 && !in_array($candidate['match']['competition'] ?? null, $allowedLeagues, true)) {
                $stage('configuration', 'FAILED', 'OUTSIDE_CONFIGURATION');
            } else {
                $stage('configuration', 'PASSED', null);
            }
        }

        $candidate = array_merge($candidate, ['features' => $fs, 'prediction' => $prediction ?? null, 'value' => $value, 'confidence' => $conf, 'risk' => $risk, 'correlation' => ['classification' => 'LOW', 'reasons' => []]]);

        $failed = array_values(array_unique($failed));
        $factors['gate']['failed'] = $failed;
        $factors['gate']['passed'] = $failed ? [] : ['INTELLIGENCE_READY', 'PREDICTION_READY', 'CONFIDENCE_OK', 'DATA_QUALITY_OK', 'POSITIVE_VALUE', 'RISK_APPROVED', 'WITHIN_CONFIGURATION'];

        $decision = $failed ? 'REJECTED' : 'QUALIFIED';
        $predictionDecision = $failed ? 'NO_PREDICTION' : 'PREDICTION_READY';
        return $this->finalize($candidate, $factors, $value, $conf, $risk, $prediction ?? null, $decision, $failed, $predictionDecision, $quality, $missingFields, $staleFields);
    }

    private function finalize(array $candidate, array $factors, ?array $value, ?array $conf, ?array $risk, ?array $prediction, string $decision, array $rejectionReasons, string $predictionDecision, array $quality, array $missingFields = [], array $staleFields = []): array
    {
        $candidate['value'] = $value ?? ['qualified' => false, 'reason' => $rejectionReasons[0] ?? 'NO_PREDICTION'];
        $candidate['confidence'] = $conf ?? ['confidence' => null, 'breakdown' => null];
        $candidate['risk'] = $risk ?? ['classification' => 'REJECTED', 'approved' => false, 'reasons' => $rejectionReasons];
        $candidate['prediction'] = $prediction ?? ['decision' => $predictionDecision, 'reason' => $rejectionReasons[0] ?? 'NO_PREDICTION', 'modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => $factors['featureVersion'] ?? FeatureEngineeringEngine::VERSION];
        $candidate['decision'] = $decision;
        // Intelligence layer defaults — a candidate that never reached the
        // prediction stage carries explicit nulls, not invented readings.
        $candidate['stability'] = $candidate['stability'] ?? null;
        $candidate['intelligenceScore'] = $candidate['intelligenceScore'] ?? null;
        $candidate['drivers'] = $candidate['drivers'] ?? null;
        $candidate['lastUpdated'] = $candidate['lastUpdated'] ?? null;
        // All reasons stay on the record; the FIRST failed stage is the
        // single primary blocking reason (no double counting).
        $candidate['rejectionReasons'] = array_values(array_unique($rejectionReasons));
        $candidate['primaryReason'] = $candidate['rejectionReasons'][0] ?? null;
        $candidate['rejectionDetail'] = [
            'primary' => $candidate['primaryReason'],
            'allReasons' => $candidate['rejectionReasons'],
            'missingFields' => array_values(array_unique($missingFields)),
            'staleFields' => array_values(array_unique($staleFields)),
            'oddsStatus' => $candidate['oddsStatus'] ?? null,
            'oddsUpdatedAt' => $candidate['oddsUpdatedAt'] ?? null,
            'oddsAgeSeconds' => $candidate['oddsAgeSeconds'] ?? null,
            'oddsSource' => $candidate['oddsSource'] ?? null,
        ];
        $candidate['factors'] = $factors;
        return $candidate;
    }

    /**
     * How far the model's own probability moved against the previous stored
     * prediction of the same selection. The first reading of a selection is
     * BASELINE — movement cannot be measured yet, and the intelligence score
     * excludes the component rather than scoring an absence.
     *
     * @param array|null $previous stored prediction row (calibrated_probability, created_at)
     */
    private function stabilityOf(?array $previous, ?array $prediction, bool $predictionReady): ?array
    {
        if (!$predictionReady) return null;
        $thresholds = $this->fairValue->configuration()->stabilityThresholds();
        $out = [
            'thresholds' => ['movedPoints' => round($thresholds['moved'] * 100.0, 2), 'unstablePoints' => round($thresholds['unstable'] * 100.0, 2)],
            'disclaimer' => StabilityMonitor::DISCLAIMER,
        ];
        if ($previous === null || !is_numeric($previous['calibrated_probability'] ?? null)) {
            return $out + ['state' => StabilityMonitor::BASELINE, 'movementPoints' => null, 'previousProbability' => null, 'previousAt' => null,
                'note' => 'first stored reading of this selection — movement cannot be measured yet'];
        }
        $old = (float) $previous['calibrated_probability'];
        $new = (float) ($prediction['calibratedProbability'] ?? 0);
        $movement = ($new - $old) * 100.0;
        $abs = abs($movement);
        $state = $abs <= $thresholds['moved'] * 100.0
            ? StabilityMonitor::STABLE
            : ($abs <= $thresholds['unstable'] * 100.0 ? StabilityMonitor::MOVED : StabilityMonitor::UNSTABLE);
        return $out + [
            'state' => $state,
            'movementPoints' => round($movement, 2),
            'previousProbability' => $old,
            'previousAt' => $previous['created_at'] ?? null,
            'note' => sprintf("WINDELS' estimate moved %+.2f points since the last stored reading (%s)", $movement, $previous['created_at'] ?? 'unknown time'),
        ];
    }

    /**
     * The WINDELS Intelligence Score: how well evidenced this read is. Shared
     * engine and shared weights with the Football board; the market price is
     * deliberately excluded (a market that agrees with us is the thing being
     * measured, not evidence). Coverage is not passed because the ticket
     * engine stores no scoreline grid — it is excluded, never zeroed.
     */
    private function scoreOf(array $conf, array $quality, ?array $calibrationInput, ?array $stability): array
    {
        if ($this->scorer === null) $this->scorer = new IntelligenceScore($this->fairValue->configuration());
        return $this->scorer->compute([
            'confidence' => is_numeric($conf['confidence'] ?? null) ? (float) $conf['confidence'] : null,
            'confidenceBasis' => $calibrationInput !== null ? 'CALIBRATED' : null,
            'dataQuality' => is_numeric($quality['score'] ?? null) ? (int) $quality['score'] : null,
            'band' => (string) ($quality['band'] ?? ''),
            'coverage' => null,
            'calibrationState' => $calibrationInput !== null ? CalibrationService::CALIBRATED : CalibrationService::PENDING,
            'stability' => $stability,
        ]);
    }

    /**
     * Why WINDELS selected what it selected — the stored measurements behind
     * the prediction restated as readings, via the shared PredictionDrivers
     * engine. Nothing here recomputes a probability; a missing input is
     * reported as missing (DATA_UNAVAILABLE), never as "no injuries".
     */
    private function driversOf(array $intel, array $fs, array $prediction, array $value, array $candidate, array $quality): array
    {
        $form = is_array($intel['inputs']['recentForm'] ?? null) ? $intel['inputs']['recentForm'] : [];
        $features = $fs['features'] ?? [];
        $source = (string) ($form['source'] ?? 'unknown');
        $teams = [
            'HOME' => [
                'name' => $candidate['match']['homeTeam'],
                'attackStrength' => $features['homeAttack'] ?? null,
                'defenseWeakness' => $features['homeDefenseConceded'] ?? null,
                'attackSource' => $source, 'defenseSource' => $source,
            ],
            'AWAY' => [
                'name' => $candidate['match']['awayTeam'],
                'attackStrength' => $features['awayAttack'] ?? null,
                'defenseWeakness' => $features['awayDefenseConceded'] ?? null,
                'attackSource' => $source, 'defenseSource' => $source,
            ],
        ];
        // The expected-goals pair the ticket model reads together, named with
        // its derivation so a reader knows these are form rates, not an xG feed.
        $homeStrength = isset($features['homeAttack'], $features['awayDefenseConceded']) ? ((float) $features['homeAttack'] + (float) $features['awayDefenseConceded']) / 2 : null;
        $awayStrength = isset($features['awayAttack'], $features['homeDefenseConceded']) ? ((float) $features['awayAttack'] + (float) $features['homeDefenseConceded']) / 2 : null;

        // quality_components in the shared driver's shape: each stored check
        // restated as 100 (met) / 0 (absent), so the "what the model could
        // not see" row lists every family the assessment scored as missing.
        $components = [];
        foreach ((array) ($quality['checks'] ?? []) as $check) {
            if (!is_array($check) || !isset($check['field'])) continue;
            $components[(string) $check['field']] = ['value' => !empty($check['ok']) ? 100.0 : 0.0];
        }

        $selection = strtoupper((string) ($candidate['selection'] ?? ''));
        $input = [
            // The supports/against wording only makes sense for a match-winner
            // selection; other markets pass no predicted side.
            'predicted_result' => in_array($selection, ['HOME', 'DRAW', 'AWAY'], true) ? $selection : '',
            'feature_snapshot' => [
                'teams' => $teams,
                'expectedGoals' => ['home' => $homeStrength, 'away' => $awayStrength, 'method' => 'ticket-engine recent-form venue rates'],
                'coverage' => [],
            ],
            // The ticket engine stores no last-five evidence rows and no H2H
            // feed: those driver rows report themselves as absent, honestly.
            'evidence' => [],
            'quality_components' => $components,
        ];
        $market = ['value' => $value, 'pricing' => ['marginPoints' => $value['marginPoints'] ?? null]];
        $fixture = ['payload' => [
            'injuries' => is_array($intel['inputs']['injuries'] ?? null) ? $intel['inputs']['injuries'] : [],
            'lineups' => is_array($intel['inputs']['lineups'] ?? null) ? $intel['inputs']['lineups'] : [],
            'lineupConfirmed' => false,
        ]];
        if ($this->explainer === null) $this->explainer = new PredictionDrivers();
        return $this->explainer->describe($input, $market, $fixture);
    }

    /** Odds provenance block for the decision factors. */
    private function oddsFactors(array $odds, array $freshness): array
    {
        $out = [
            'market' => strtoupper((string) ($odds['market'] ?? '')),
            'selection' => strtoupper((string) ($odds['selection'] ?? '')),
            'decimal' => (float) ($odds['decimalOdds'] ?? $odds['decimal_odds'] ?? 0),
            'observedAt' => $odds['observedAt'] ?? $odds['observed_at'] ?? null,
        ];
        foreach (['ageSeconds', 'oddsStatus', 'oddsUpdatedAt', 'oddsSource', 'oddsAgeSeconds', 'maxAgeSeconds'] as $key) {
            if (isset($freshness[$key])) $out[$key] = $freshness[$key];
        }
        return $out;
    }

    private function oddsMovement(?array $odds): ?float
    {
        if ($odds === null) return null;
        $payload = is_array($odds['payload'] ?? null) ? $odds['payload'] : [];
        $opening = $payload['openingDecimalOdds'] ?? $odds['openingDecimalOdds'] ?? null;
        $current = (float) ($odds['decimalOdds'] ?? $odds['decimal_odds'] ?? 0);
        if (!is_numeric($opening) || (float) $opening <= 0) return null;
        return $current - (float) $opening;
    }
}
