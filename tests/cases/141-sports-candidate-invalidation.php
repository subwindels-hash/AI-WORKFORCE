<?php
/**
 * Requirement #1 — active candidate invalidation, pinned against the REAL
 * (throwaway sqlite) repository the application serves from.
 *
 * A new generation must never carry the previous pass's active candidates:
 * un-settled predictions of upcoming fixtures, the PENDING ticket and its
 * legs, the daily slot and unquotable odds rows are cleared BEFORE the new
 * run scores anything. Settled/historical tickets, verified results and
 * predictions of FINISHED matches (the calibration/audit trail) survive, and
 * fixtures outside the run's date window are untouched.
 */
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

const CI141_PROVIDER = 'ci141-sim';
const CI141_FORCE_PROVIDER = 'ci141-force';
const CI141_EMPTY_PROVIDER = 'ci141-empty';

function ci141_repo(): SportsRepository { return platform()->model->sports; }
function ci141_audit(): AuditRepository { return platform()->model->audit; }

/** Remove every row the cases in this file can have written. */
function ci141_reset(): void
{
    $db = ci()->db;
    $repo = ci141_repo();
    foreach ([CI141_PROVIDER, CI141_FORCE_PROVIDER, CI141_EMPTY_PROVIDER] as $code) {
        $provider = $db->get_where('sports_data_sources', ['provider_code' => $code])->row_array();
        if (!$provider) continue;
        $providerId = (int) $provider['id'];
        $matchIds = array_column($db->get_where('sports_matches', ['provider_id' => $providerId])->result_array(), 'id');
        if ($matchIds !== []) {
            $db->where_in('match_id', $matchIds)->delete('sports_odds');
            $db->where_in('match_id', $matchIds)->delete('sports_data_quality_assessments');
            $predIds = array_column($db->where_in('match_id', $matchIds)->get('sports_predictions')->result_array(), 'id');
            if ($predIds !== []) {
                $ticketIds = array_values(array_unique(array_column($db->where_in('prediction_id', $predIds)->get('sports_ticket_selections')->result_array(), 'ticket_id')));
                if ($ticketIds !== []) {
                    $db->where_in('ticket_id', $ticketIds)->delete('sports_ticket_selections');
                    $db->where_in('id', $ticketIds)->delete('sports_tickets');
                }
                $db->where_in('id', $predIds)->delete('sports_predictions');
            }
            $db->where_in('match_id', $matchIds)->delete('sports_results');
            $db->where_in('id', $matchIds)->delete('sports_matches');
        }
    }
    $db->like('execution_key', 'daily-ticket:141', 'after')->delete('sports_job_runs');
    $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
    $dayAfter = gmdate('Y-m-d', strtotime('+2 days'));
    $db->where_in('date', [$tomorrow, $dayAfter])->delete('sports_daily_tickets');
}

function ci141_model_id(): int
{
    return ci141_repo()->ensureModelVersion([
        'modelName' => PredictionEngine::MODEL_NAME,
        'modelVersion' => PredictionEngine::MODEL_VERSION,
        'featureVersion' => FeatureEngineeringEngine::VERSION,
    ]);
}

/** Approve a fitted calibration (like the stub suites do) so confidence clears 75%. */
function ci141_approve_calibration(): int
{
    $repo = ci141_repo();
    $modelId = ci141_model_id();
    // Start the case from a fitted-calibration install: an identity bootstrap
    // a previous case auto-approved must not tie on the second-granularity
    // created_at and shadow the fitted row.
    ci()->db->where('model_version_id', $modelId)->delete('sports_calibrations');
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
    return $modelId;
}

/** Insert a minimal prediction row directly, bypassing the pipeline. */
function ci141_insert_prediction(int $matchId, int $modelId, string $id, string $market = 'MATCH_RESULT', string $selection = 'HOME'): string
{
    ci()->db->insert('sports_predictions', [
        'id' => $id, 'match_id' => $matchId, 'model_version_id' => $modelId,
        'market' => $market, 'selection' => $selection,
        'raw_probability' => 0.5, 'calibrated_probability' => 0.5, 'implied_probability' => 0.48,
        'expected_value' => 0.04, 'confidence' => 80, 'risk' => 'LOW', 'correlation' => 'LOW',
        'data_quality_score' => 85, 'decision' => 'PENDING', 'rejection_reasons' => null,
        'factors' => '{}', 'input_version' => 'test-141', 'odds' => 2.05,
        'odds_timestamp' => gmdate('c'), 'created_at' => gmdate('c'),
    ]);
    return $id;
}

function ci141_insert_ticket(string $id, string $approval, string $settlement, int $matchId, string $predictionId, float $odds = 2.0): void
{
    ci()->db->insert('sports_tickets', [
        'id' => $id, 'created_at' => gmdate('c'), 'model_version_id' => null,
        'configuration_version' => '1', 'total_odds' => $odds, 'selection_count' => 1,
        'combined_probability' => 0.5, 'confidence' => 80, 'average_confidence' => 80,
        'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 85, 'average_data_quality' => 85,
        'odds_calculation' => null, 'status' => $settlement === 'PENDING' ? 'PENDING' : 'SETTLED',
        'approval_status' => $approval, 'settlement_status' => $settlement,
        'reason' => null, 'stake' => null, 'pnl' => null,
    ]);
    ci()->db->insert('sports_ticket_selections', [
        'ticket_id' => $id, 'prediction_id' => $predictionId, 'match_id' => $matchId,
        'fixture_id' => 'fx', 'home_team' => 'Home', 'away_team' => 'Away',
        'kickoff_time' => gmdate('c', strtotime('+1 day')), 'market' => 'MATCH_RESULT',
        'selection' => 'HOME', 'odds' => $odds, 'odds_timestamp' => gmdate('c'),
        'confidence' => 80, 'data_quality' => 85, 'model_probability' => 0.5,
        'calibrated_probability' => 0.5, 'expected_value' => 0.04, 'risk' => 'LOW',
        'result' => null, 'status' => 'PENDING',
    ]);
}

function ci141_insert_daily(string $date): void
{
    ci141_repo()->saveDailyTicket([
        'date' => $date, 'ticket_id' => null, 'status' => 'NO_QUALIFIED_TICKET',
        'configuration_version' => 1, 'candidates_evaluated' => 0, 'predictions_recorded' => 0,
        'rejections' => 0, 'rejection_summary' => '{}', 'message' => 'test seed',
        'provider' => CI141_PROVIDER, 'run_id' => 'seed-' . $date,
        'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. Repository contract on real storage: active state cleared, history kept
// ═══════════════════════════════════════════════════════════════════════════

test('invalidateActiveCandidates clears active state for the window and preserves history', function () {
    ci141_reset();
    $db = ci()->db;
    $repo = ci141_repo();
    $providerId = (int) $repo->ensureProvider(CI141_PROVIDER, CI141_PROVIDER)['id'];
    $modelId = ci141_model_id();

    $tomorrow  = gmdate('Y-m-d', strtotime('+1 day'));
    $dayAfter  = gmdate('Y-m-d', strtotime('+2 days'));
    $inThree   = gmdate('Y-m-d', strtotime('+3 days'));
    $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

    $save = function (string $ext, string $kickoffDay, string $status) use ($repo, $providerId) {
        $m = $repo->saveMatch($providerId, [
            'externalId' => $ext, 'sport' => 'football', 'competition' => 'CI141 League',
            'homeTeam' => 'Home ' . $ext, 'awayTeam' => 'Away ' . $ext,
            'kickoff' => $kickoffDay . 'T15:00:00+00:00', 'status' => $status,
        ]);
        return (int) $m['id'];
    };
    $mTomorrow = $save('ci141-tomorrow', $tomorrow, 'SCHEDULED');
    $mDayAfter = $save('ci141-dayafter', $dayAfter, 'SCHEDULED');
    $mLater    = $save('ci141-later', $inThree, 'SCHEDULED');
    $mFinished = $save('ci141-finished', $yesterday, 'FINISHED');

    ci141_insert_prediction($mTomorrow, $modelId, 'ci141-p-tomorrow');
    ci141_insert_prediction($mDayAfter, $modelId, 'ci141-p-dayafter');
    ci141_insert_prediction($mLater, $modelId, 'ci141-p-later');
    ci141_insert_prediction($mFinished, $modelId, 'ci141-p-finished');

    // PENDING ticket on tomorrow's fixture (must be superseded + legs deleted),
    // an already-APPROVED pending ticket on the same fixture (kept as an
    // operator decision record), and a settled ticket on the finished match
    // (history, untouched).
    ci141_insert_ticket('ci141-t-pending', 'PENDING_USER_APPROVAL', 'PENDING', $mTomorrow, 'ci141-p-tomorrow');
    ci141_insert_ticket('ci141-t-approved', 'APPROVED', 'PENDING', $mTomorrow, 'ci141-p-tomorrow', 2.1);
    ci141_insert_ticket('ci141-t-settled', 'APPROVED', 'WON', $mFinished, 'ci141-p-finished', 1.9);

    ci141_insert_daily($tomorrow);
    ci141_insert_daily($dayAfter);
    ci141_insert_daily($yesterday);

    // Odds: sub-floor and over-ceiling garbage on tomorrow's fixture plus a
    // real quote; the finished fixture keeps even a garbage row (history).
    $repo->saveOdds($mTomorrow, $providerId, ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 0.5, 'observedAt' => gmdate('c')]);
    $repo->saveOdds($mTomorrow, $providerId, ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 999.0, 'observedAt' => gmdate('c')]);
    $repo->saveOdds($mTomorrow, $providerId, ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]);
    $repo->saveOdds($mFinished, $providerId, ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 0.5, 'observedAt' => gmdate('c')]);

    $counts = $repo->invalidateActiveCandidates($tomorrow, $dayAfter, true);

    assert_equals(2, (int) $counts['predictionsDeleted'], 'tomorrow + day-after predictions removed: ' . json_encode($counts));
    assert_equals(1, (int) $counts['ticketsSuperseded'], 'only the PENDING ticket is superseded');
    assert_true((int) $counts['selectionsDeleted'] >= 1, 'the superseded ticket legs are removed');
    assert_equals(2, (int) $counts['dailySlotsCleared'], 'both in-window daily slots cleared');
    assert_equals(2, (int) $counts['invalidOddsDeleted'], 'sub-floor and over-ceiling odds purged (valid quote kept)');

    // Active predictions are gone; out-of-window and finished-match predictions remain.
    assert_null($db->get_where('sports_predictions', ['id' => 'ci141-p-tomorrow'])->row_array(), "tomorrow's active prediction deleted");
    assert_null($db->get_where('sports_predictions', ['id' => 'ci141-p-dayafter'])->row_array(), "day-after active prediction deleted");
    assert_not_null($db->get_where('sports_predictions', ['id' => 'ci141-p-later'])->row_array(), 'fixture outside the window is untouched');
    assert_not_null($db->get_where('sports_predictions', ['id' => 'ci141-p-finished'])->row_array(), 'FINISHED match prediction (history) is preserved');

    // Ticket states.
    $pending = $db->get_where('sports_tickets', ['id' => 'ci141-t-pending'])->row_array();
    assert_equals('SUPERSEDED', (string) $pending['settlement_status']);
    assert_equals('SUPERSEDED', (string) $pending['approval_status']);
    assert_equals('CANCELLED', (string) $pending['status']);
    assert_equals(0, count($db->get_where('sports_ticket_selections', ['ticket_id' => 'ci141-t-pending'])->result_array()), 'superseded ticket has no legs');
    $approved = $db->get_where('sports_tickets', ['id' => 'ci141-t-approved'])->row_array();
    assert_equals('APPROVED', (string) $approved['approval_status'], 'an operator-approved ticket is a kept record');
    assert_equals(1, count($db->get_where('sports_ticket_selections', ['ticket_id' => 'ci141-t-approved'])->result_array()), 'approved ticket legs kept');
    $settled = $db->get_where('sports_tickets', ['id' => 'ci141-t-settled'])->row_array();
    assert_equals('WON', (string) $settled['settlement_status'], 'settled history preserved');
    assert_equals(1, count($db->get_where('sports_ticket_selections', ['ticket_id' => 'ci141-t-settled'])->result_array()), 'settled ticket legs kept');

    // Daily slots.
    assert_null($db->get_where('sports_daily_tickets', ['date' => $tomorrow])->row_array());
    assert_null($db->get_where('sports_daily_tickets', ['date' => $dayAfter])->row_array());
    assert_not_null($db->get_where('sports_daily_tickets', ['date' => $yesterday])->row_array(), 'yesterday slot is history');

    // Odds.
    $survivors = array_map(static fn($r) => (float) $r['decimal_odds'], $db->get_where('sports_odds', ['match_id' => $mTomorrow])->result_array());
    assert_equals([1.9], $survivors, 'only the real quote survives on the upcoming fixture');
    $finishedOdds = array_map(static fn($r) => (float) $r['decimal_odds'], $db->get_where('sports_odds', ['match_id' => $mFinished])->result_array());
    assert_equals([0.5], $finishedOdds, 'a finished fixture is history — its rows are not rewritten');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A forced runDaily() clears a stale pass before scoring, and can re-run
// ═══════════════════════════════════════════════════════════════════════════

function ci141_force_provider(): SportsDataProvider
{
    $fixtures = [];
    for ($i = 0; $i < 3; $i++) {
        $fixtures[] = [
            'externalId' => 'ci141f-' . $i,
            'homeTeam' => 'Force Home ' . $i, 'awayTeam' => 'Force Away ' . $i,
            'competition' => 'Force League ' . $i,
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (12 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.7, 'awayGoalsPerMatch' => 1.3,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
                    'source' => 'ci141-verified', 'timestamp' => gmdate('c'),
                ],
                'marketLiquidity' => 50000,
            ],
        ];
    }
    // Prices spread so 3 legs combine inside the 5.00–8.00 ticket window.
    $prices = ['ci141f-0' => 1.55, 'ci141f-1' => 1.80, 'ci141f-2' => 1.90];
    return new class($fixtures, $prices) implements SportsDataProvider {
        public function __construct(private array $fixtures, private array $prices) {}
        public function id(): string { return CI141_FORCE_PROVIDER; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $this->prices[$e] ?? 1.9, 'observedAt' => gmdate('c')]]; }
        public function results(string $e): array { return []; }
    };
}

function ci141_force_service(): DailyTicketService
{
    $repo = ci141_repo();
    $providers = new SportsProviderManager();
    $providers->register(ci141_force_provider());
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    return new DailyTicketService(
        $repo, ci141_audit(), $providers, new ConfigurationService($repo, ci141_audit()), new DataQualityEngine(),
        $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, ci141_audit(), new CorrelationEngine()),
        new DecisionRecorder($repo, ci141_audit())
    );
}

test('forced daily run invalidates a previous pass before regenerating and is never duplicate-skipped', function () {
    ci141_reset();
    $db = ci()->db;
    $repo = ci141_repo();
    $providerId = (int) $repo->ensureProvider(CI141_FORCE_PROVIDER, CI141_FORCE_PROVIDER)['id'];
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $service = ci141_force_service();

    // Seed the fixtures with a first (non-forced) generation so an "old pass"
    // genuinely exists in storage.
    $first = $service->runDaily($date, 'daily-ticket:141:seed:' . uniqid());
    assert_not_equals('DUPLICATE_SKIPPED', (string) $first['status']);
    $matches = $db->get_where('sports_matches', ['provider_id' => $providerId])->result_array();
    assert_equals(3, count($matches), 'three fixtures synced');

    // Simulate a stale candidate left over from a previous, different pass.
    $staleId = ci141_insert_prediction((int) $matches[0]['id'], ci141_model_id(), 'ci141-stale-pass', 'MATCH_RESULT', 'AWAY');
    $db->insert('sports_odds', [
        'match_id' => (int) $matches[0]['id'], 'provider_id' => $providerId,
        'market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimal_odds' => 0.01,
        'observed_at' => gmdate('c'), 'payload' => '{}',
    ]);

    // Forced regeneration: reset first, unique run slot, then score.
    $forced = $service->runDaily($date, null, ['force' => true]);
    assert_not_equals('DUPLICATE_SKIPPED', (string) $forced['status'], 'a forced run always gets a fresh idempotency slot');
    assert_not_equals('RESET_FAILED', (string) $forced['status']);
    assert_true(is_array($forced['invalidated'] ?? null), 'the run reports what it invalidated');
    assert_true((int) ($forced['invalidated']['predictionsDeleted'] ?? 0) >= 1, 'the stale active predictions were deleted: ' . json_encode($forced['invalidated']));

    assert_null($db->get_where('sports_predictions', ['id' => $staleId])->row_array(), 'the old pass prediction cannot survive into the new generation');
    $garbage = $db->get_where('sports_odds', ['match_id' => (int) $matches[0]['id'], 'decimal_odds' => 0.01])->row_array();
    assert_null($garbage, 'the unquotable stale odds row was purged');

    // A second forced run is also allowed through and starts clean again.
    $forced2 = $service->runDaily($date, null, ['force' => true]);
    assert_not_equals('DUPLICATE_SKIPPED', (string) $forced2['status'], 'consecutive forced runs each get a fresh slot');

    // The audit trail records the invalidation explicitly.
    $types = array_column(ci141_audit()->recent(400), 'type');
    assert_in_array('SPORTS_CANDIDATES_INVALIDATED', $types, 'candidate invalidation is an audited event');
});

test('a new generation supersedes the previous pending ticket for the same fixture window', function () {
    ci141_reset();
    $db = ci()->db;
    $repo = ci141_repo();
    $repo->ensureProvider(CI141_FORCE_PROVIDER, CI141_FORCE_PROVIDER);
    ci141_approve_calibration();
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $service = ci141_force_service();

    $r1 = $service->runDaily($date, 'daily-ticket:141:auto-a:' . uniqid());
    assert_equals('PENDING_USER_APPROVAL', (string) $r1['status'], 'first pass records a pending ticket: ' . (string) ($r1['message'] ?? ''));
    $ticket1 = (string) $r1['ticketId'];
    assert_true($ticket1 !== '', 'a ticket id was returned');

    // A second, genuinely proceeding generation (new key — e.g. a later sweep
    // after a config-version bump) records the new live pass and must retire
    // the old undecided one so it can never be approved as a stale ticket.
    $r2 = $service->runDaily($date, 'daily-ticket:141:auto-b:' . uniqid());
    assert_equals('PENDING_USER_APPROVAL', (string) $r2['status'], 'second pass also qualifies: ' . (string) ($r2['message'] ?? ''));
    $ticket2 = (string) $r2['ticketId'];
    assert_not_equals($ticket1, $ticket2, 'a new ticket was recorded');

    $old = $db->get_where('sports_tickets', ['id' => $ticket1])->row_array();
    assert_equals('SUPERSEDED', (string) $old['settlement_status'], 'the old pending ticket is no longer live');
    assert_equals('SUPERSEDED', (string) $old['approval_status']);
    assert_equals('CANCELLED', (string) $old['status']);
    assert_true((int) ($r2['diagnostics']['ticketsSuperseded'] ?? 0) >= 1, 'the funnel reports the supersede');

    // The superseded pass keeps its legs as an audit trail (append-only).
    assert_true(count($db->get_where('sports_ticket_selections', ['ticket_id' => $ticket1])->result_array()) >= 2, 'superseded ticket legs are retained as history');
    $new = $db->get_where('sports_tickets', ['id' => $ticket2])->row_array();
    assert_equals('PENDING_USER_APPROVAL', (string) $new['approval_status'], 'only the new ticket stays pending');

    // Every leg of the real-DB ticket carries odds provenance and a separate
    // WINDELS fair-odds column (requirement #2/#14).
    $legs = $db->get_where('sports_ticket_selections', ['ticket_id' => $ticket2])->result_array();
    assert_true(count($legs) >= 2, 'the new ticket has legs');
    foreach ($legs as $leg) {
        assert_true(trim((string) ($leg['odds_source'] ?? '')) !== '', 'leg names its odds source: ' . json_encode(array_keys($leg)));
        assert_true(trim((string) ($leg['odds_timestamp'] ?? '')) !== '', 'leg carries the odds last-update timestamp');
        assert_true((float) $leg['odds'] > 1.0, 'bookmaker odds quotable');
        assert_true($leg['fair_odds'] === null || (float) $leg['fair_odds'] > 1.0, 'WINDELS fair odds quotable when stored');
    }

    // The daily slot now points at the new ticket, never the old pass.
    $slot = $db->get_where('sports_daily_tickets', ['date' => $date])->row_array();
    assert_equals($ticket2, (string) ($slot['ticket_id'] ?? ''), 'the daily slot references the new ticket');

    // A superseded ticket can never be approved afterwards.
    $types = array_column(ci141_audit()->recent(400), 'type');
    assert_in_array('SPORTS_TICKET_SUPERSEDED', $types, 'the supersede is audited');
});

test('a fresh no-qualified-ticket verdict retires the previous pending ticket; an outage does not', function () {
    ci141_reset();
    $db = ci()->db;
    $repo = ci141_repo();
    $repo->ensureProvider(CI141_FORCE_PROVIDER, CI141_FORCE_PROVIDER);
    ci141_approve_calibration();
    $date = gmdate('Y-m-d', strtotime('+1 day'));

    // First pass: a qualified pending ticket.
    $r1 = ci141_force_service()->runDaily($date, 'daily-ticket:141:noq-a:' . uniqid());
    assert_equals('PENDING_USER_APPROVAL', (string) $r1['status'], (string) ($r1['message'] ?? ''));
    $ticket1 = (string) $r1['ticketId'];

    // Second pass: a different feed answers fixtures but offers NO odds — a
    // complete evaluation that honestly qualifies nothing (dataState OK).
    $emptyFixtures = [];
    for ($i = 0; $i < 2; $i++) {
        $emptyFixtures[] = [
            'externalId' => 'ci141e-' . $i,
            'homeTeam' => 'Empty Home ' . $i, 'awayTeam' => 'Empty Away ' . $i,
            'competition' => 'Empty League ' . $i,
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (15 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => ['recentForm' => ['homeGoalsPerMatch' => 1.2, 'awayGoalsPerMatch' => 1.1, 'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.0, 'source' => 'ci141-verified', 'timestamp' => gmdate('c')]],
        ];
    }
    $emptyProvider = new class($emptyFixtures) implements SportsDataProvider {
        public function __construct(private array $fixtures) {}
        public function id(): string { return CI141_EMPTY_PROVIDER; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { return []; }
        public function results(string $e): array { return []; }
    };
    $providers2 = new SportsProviderManager();
    $providers2->register($emptyProvider);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service2 = new DailyTicketService(
        $repo, ci141_audit(), $providers2, new ConfigurationService($repo, ci141_audit()), new DataQualityEngine(),
        $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, ci141_audit(), new CorrelationEngine()),
        new DecisionRecorder($repo, ci141_audit())
    );
    $r2 = $service2->runDaily($date, 'daily-ticket:141:noq-b:' . uniqid());
    assert_equals('NO_QUALIFIED_TICKET', (string) $r2['status']);
    assert_equals('OK', (string) $r2['dataState'], 'a no-odds answer is a verdict, not a provider outage');
    $old = $db->get_where('sports_tickets', ['id' => $ticket1])->row_array();
    assert_equals('SUPERSEDED', (string) $old['settlement_status'], 'the earlier pass cannot remain actionable after a fresh no-ticket verdict');
    $slot = $db->get_where('sports_daily_tickets', ['date' => $date])->row_array();
    assert_true(empty($slot['ticket_id']), 'the daily slot no longer points at the old pass');
});

test('non-forced daily runs keep their idempotent skip behaviour', function () {
    ci141_reset();
    $repo = ci141_repo();
    $repo->ensureProvider(CI141_FORCE_PROVIDER, CI141_FORCE_PROVIDER);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $service = ci141_force_service();
    $key = 'daily-ticket:141:idem:' . uniqid();
    $first = $service->runDaily($date, $key);
    assert_not_equals('DUPLICATE_SKIPPED', (string) $first['status']);
    $second = $service->runDaily($date, $key);
    assert_equals('DUPLICATE_SKIPPED', (string) $second['status'], 'without force the same execution key is skipped as before');
    assert_null($second['invalidated'] ?? null, 'no invalidation happens on a normal run');
});
