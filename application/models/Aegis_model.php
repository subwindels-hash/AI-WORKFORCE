<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Aegis/autoload.php';

/**
 * AEGIS persistence layer — the ONLY place SQL lives. Exposes typed
 * repositories (implementing the domain interfaces) over CodeIgniter 3's
 * database abstraction: MySQL/MariaDB (mysqli) in production, pdo_sqlite
 * available for the offline dev runtime. JSON documents (agent reports,
 * equity curves…) are stored as TEXT for maximum engine compatibility.
 *
 * insert()-style saves RETURN the record with its generated id.
 */
class Aegis_model extends CI_Model
{
    public object $strategies;
    public object $backtests;
    public object $journal;
    public object $audit;
    public object $identity;
    public object $sports;
    public object $analysis;
    public object $state;
    public object $paper;
    public object $proposals;
    public object $notifications;
    public object $langlearn;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $db = $this->db;

        $this->strategies = new class($db) implements Aegis\Persistence\StrategyRepository {
            public function __construct(private object $db) {}
            public function find(string $id, string $version): ?array {
                $row = $this->db->get_where('strategies', ['strategy_id' => $id, 'version' => $version], 1)->row_array();
                return $row ? self::decode($row) : null;
            }
            public function all(): array {
                $rows = $this->db->order_by('strategy_id', 'ASC')->order_by('updated_at', 'ASC')->get('strategies')->result_array();
                return array_map(fn($r) => self::decode($r), $rows);
            }
            public function save(array $record): void {
                $exists = $this->db->from('strategies')
                    ->where(['strategy_id' => $record['strategy_id'], 'version' => $record['version']])
                    ->count_all_results() > 0;
                if ($exists) {
                    $this->db->where(['strategy_id' => $record['strategy_id'], 'version' => $record['version']])
                        ->update('strategies', self::encode($record));
                } else {
                    $this->db->insert('strategies', self::encode($record));
                }
            }
            public function countBacktests(string $strategyId, string $version): int {
                return $this->db->from('backtests')
                    ->where('strategy_id', $strategyId)->where('strategy_version', $version)
                    ->count_all_results();
            }
            public function latestBacktest(string $strategyId, string $version): ?array {
                $row = $this->db->order_by('created_at', 'DESC')->limit(1)
                    ->get_where('backtests', ['strategy_id' => $strategyId, 'strategy_version' => $version])->row_array();
                return $row ? BacktestRepo::decode($row) : null;
            }
            private static function decode(array $row): array {
                return [
                    'strategy_id' => $row['strategy_id'], 'version' => $row['version'],
                    'name' => $row['name'], 'description' => $row['description'],
                    'market_classes' => json_decode($row['market_classes'] ?: '[]', true),
                    'timeframes' => json_decode($row['timeframes'] ?: '[]', true),
                    'params' => json_decode($row['params'] ?: '{}', true),
                    'source' => $row['source'], 'lifecycle' => $row['lifecycle'],
                    'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                    'lifecycle_history' => json_decode($row['lifecycle_history'] ?: '[]', true),
                ];
            }
            private static function encode(array $r): array {
                return [
                    'strategy_id' => $r['strategy_id'], 'version' => $r['version'],
                    'name' => $r['name'], 'description' => $r['description'],
                    'market_classes' => json_encode($r['market_classes']),
                    'timeframes' => json_encode($r['timeframes']),
                    'params' => json_encode($r['params']),
                    'source' => $r['source'], 'lifecycle' => $r['lifecycle'],
                    'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
                    'lifecycle_history' => json_encode($r['lifecycle_history']),
                ];
            }
        };

        $this->backtests = new class($db) implements Aegis\Persistence\BacktestRepository {
            public function __construct(private object $db) {}
            public function save(array $record): void {
                $exists = $this->db->from('backtests')->where('id', $record['id'])->count_all_results() > 0;
                if ($exists) $this->db->where('id', $record['id'])->update('backtests', BacktestRepo::encode($record));
                else $this->db->insert('backtests', BacktestRepo::encode($record));
            }
            public function find(string $id): ?array {
                $row = $this->db->get_where('backtests', ['id' => $id], 1)->row_array();
                return $row ? BacktestRepo::decode($row) : null;
            }
            public function list(?string $strategyId = null, int $limit = 50): array {
                if ($strategyId !== null) $this->db->where('strategy_id', $strategyId);
                $rows = $this->db->order_by('created_at', 'DESC')->limit($limit)->get('backtests')->result_array();
                return array_map(fn($r) => BacktestRepo::decode($r), $rows);
            }
        };

        $this->journal = new class($db) implements Aegis\Persistence\JournalRepository {
            public function __construct(private object $db) {}
            public function save(array $e): void {
                $exists = isset($e['id']) && $this->db->from('journal_entries')->where('id', $e['id'])->count_all_results() > 0;
                if ($exists) $this->db->where('id', $e['id'])->update('journal_entries', $e);
                else $this->db->insert('journal_entries', $e);
            }
            public function list(array $filter = [], int $limit = 200): array {
                if (!empty($filter['source'])) $this->db->where('source', $filter['source']);
                if (!empty($filter['strategy'])) $this->db->where('strategy', $filter['strategy']);
                if (!empty($filter['symbol'])) $this->db->where('symbol', $filter['symbol']);
                $rows = $this->db->order_by('execution_time', 'DESC')->limit($limit)->get('journal_entries')->result_array();
                $out = [];
                foreach ($rows as $row) {
                    foreach (['pnl', 'r_multiple', 'ai_confidence', 'entry_price', 'exit_price', 'fees', 'slippage'] as $k) {
                        $row[$k] = $row[$k] !== null ? (float)$row[$k] : null;
                    }
                    $out[] = $row;
                }
                return $out;
            }
        };

        $this->audit = new class($db) implements Aegis\Persistence\AuditRepository {
            public function __construct(private object $db) {}
            public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void {
                try {
                    $this->db->insert('audit_logs', [
                        'type' => $type, 'at' => gmdate('c'), 'actor' => $actor,
                        'summary' => mb_substr($summary, 0, 500), 'detail' => json_encode($detail),
                    ]);
                } catch (Throwable $e) { /* audit must never break the pipeline */ }
            }
            public function recent(int $limit = 100): array {
                $rows = $this->db->order_by('id', 'DESC')->limit($limit)->get('audit_logs')->result_array();
                foreach ($rows as &$row) {
                    $row['detail'] = json_decode($row['detail'] ?: 'null', true);
                }
                return $rows;
            }
        };

        $this->sports = new class($db) implements Aegis\Persistence\SportsRepository {
            public function __construct(private object $db) {}
            public function ensureProvider(string $code, string $name): array {
                $row = $this->db->get_where('sports_data_sources', ['provider_code' => $code], 1)->row_array();
                if ($row) return $row;
                $now = gmdate('c'); $this->db->insert('sports_data_sources', ['provider_code' => $code, 'display_name' => $name, 'enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
                return $this->db->get_where('sports_data_sources', ['id' => $this->db->insert_id()], 1)->row_array();
            }
            public function saveHealth(int $providerId, array $h): void {
                $this->db->insert('sports_provider_health', ['provider_id' => $providerId, 'status' => $h['status'], 'response_ms' => $h['responseMs'] ?? null, 'error_rate' => $h['errorRate'] ?? null, 'rate_limit_remaining' => $h['rateLimitRemaining'] ?? null, 'last_success_at' => $h['lastSuccessAt'] ?? null, 'last_failure_at' => $h['lastFailureAt'] ?? null, 'last_fixture_sync_at' => $h['lastFixtureSyncAt'] ?? null, 'last_odds_sync_at' => $h['lastOddsSyncAt'] ?? null, 'last_result_sync_at' => $h['lastResultSyncAt'] ?? null, 'data_freshness_seconds' => $h['dataFreshnessSeconds'] ?? null, 'records_received' => $h['recordsReceived'] ?? 0, 'invalid_records' => $h['invalidRecords'] ?? 0, 'missing_fields' => json_encode($h['missingFields'] ?? []), 'observed_at' => gmdate('c')]);
            }
            public function saveMatch(int $providerId, array $m): array {
                $row = $this->db->get_where('sports_matches', ['provider_id' => $providerId, 'external_id' => $m['externalId']], 1)->row_array();
                $data = ['sport' => $m['sport'], 'competition' => $m['competition'], 'home_team' => $m['homeTeam'], 'away_team' => $m['awayTeam'], 'kickoff_at' => $m['kickoff'], 'status' => $m['status'], 'source_timestamp' => $m['sourceTimestamp'], 'payload' => json_encode($m), 'updated_at' => gmdate('c')];
                if ($row) { $this->db->where('id', $row['id'])->update('sports_matches', $data); return array_merge($row, $data); }
                $this->db->insert('sports_matches', array_merge(['provider_id' => $providerId, 'external_id' => $m['externalId'], 'created_at' => gmdate('c')], $data)); return array_merge($data, ['id' => (int)$this->db->insert_id(), 'provider_id' => $providerId, 'external_id' => $m['externalId']]);
            }
            public function findMatch(int $providerId, string $externalId): ?array { return $this->db->get_where('sports_matches', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array() ?: null; }
            public function saveOdds(int $matchId, int $providerId, array $odds): void { $this->db->insert('sports_odds', ['match_id' => $matchId, 'provider_id' => $providerId, 'market' => $odds['market'], 'selection' => $odds['selection'], 'decimal_odds' => $odds['decimalOdds'], 'observed_at' => $odds['observedAt'], 'payload' => json_encode($odds)]); }
            public function saveResult(int $matchId, int $providerId, array $r): void { $row=$this->db->get_where('sports_results',['match_id'=>$matchId,'provider_id'=>$providerId],1)->row_array(); $data=['home_score'=>$r['homeScore'],'away_score'=>$r['awayScore'],'status'=>$r['status'],'verified'=>0,'source_timestamp'=>$r['sourceTimestamp'],'verified_at'=>null,'payload'=>json_encode($r['payload'])]; if($row)$this->db->where('id',$row['id'])->update('sports_results',$data); else $this->db->insert('sports_results',array_merge(['match_id'=>$matchId,'provider_id'=>$providerId],$data)); }
            public function findResult(int $matchId,int $providerId): ?array { return $this->db->get_where('sports_results',['match_id'=>$matchId,'provider_id'=>$providerId],1)->row_array() ?: null; }
            public function verifyResult(int $id): void { $this->db->where('id',$id)->update('sports_results',['verified'=>1,'verified_at'=>gmdate('c')]); }
            public function saveQuality(int $matchId, array $a): void { $this->db->insert('sports_data_quality_assessments', ['match_id' => $matchId, 'score' => $a['score'], 'band' => $a['band'], 'freshness_score' => $a['freshnessScore'], 'provider_reliability_score' => $a['providerReliabilityScore'], 'eligible_prediction' => $a['eligibleForPrediction'] ? 1 : 0, 'eligible_ticket' => $a['eligibleForTicket'] ? 1 : 0, 'missing_fields' => json_encode($a['missing']), 'checks_payload' => json_encode($a['checks']), 'assessed_at' => gmdate('c')]); }
            public function startSync(array $run): ?array {
                if ($this->db->get_where('sports_sync_runs', ['execution_key' => $run['executionKey']], 1)->row_array()) return null;
                $this->db->insert('sports_sync_runs', ['id' => $run['id'], 'provider_id' => $run['providerId'] ?? null, 'job_type' => $run['jobType'], 'status' => 'RUNNING', 'started_at' => gmdate('c'), 'execution_key' => $run['executionKey']]); return $run;
            }
            public function finishSync(string $id, array $result): void { $this->db->where('id', $id)->update('sports_sync_runs', ['status' => $result['status'], 'ended_at' => gmdate('c'), 'records_processed' => $result['processed'] ?? 0, 'records_created' => $result['created'] ?? 0, 'records_updated' => $result['updated'] ?? 0, 'errors' => json_encode($result['errors'] ?? [])]); }
            public function ensureModelVersion(array $m): int { $row = $this->db->get_where('sports_model_versions', ['model_name' => $m['modelName'], 'model_version' => $m['modelVersion']], 1)->row_array(); if ($row) return (int)$row['id']; $this->db->insert('sports_model_versions', ['model_name' => $m['modelName'], 'model_version' => $m['modelVersion'], 'feature_version' => $m['featureVersion'], 'calibration_version' => $m['calibrationVersion'] ?? null, 'status' => $m['status'] ?? 'APPROVED', 'created_at' => gmdate('c')]); return (int)$this->db->insert_id(); }
            public function savePrediction(array $p): void { $this->db->insert('sports_predictions', $p); }
            public function saveTicket(array $t): void { $this->db->insert('sports_tickets', $t); }
            public function saveTicketSelection(array $s): void { $this->db->insert('sports_ticket_selections', $s); }
            public function ticketSelections(string $ticketId): array { return $this->db->get_where('sports_ticket_selections', ['ticket_id' => $ticketId])->result_array(); }
            public function updateTicketSelection(int $id, array $patch): void { $this->db->where('id', $id)->update('sports_ticket_selections', $patch); }
            public function findTicket(string $id): ?array { return $this->db->get_where('sports_tickets', ['id' => $id], 1)->row_array() ?: null; }
            public function listTickets(array $filter = [], int $limit = 500): array { if(!empty($filter['from']))$this->db->where('created_at >=',$filter['from']); if(!empty($filter['to']))$this->db->where('created_at <=',$filter['to']); if(!empty($filter['status']))$this->db->where('settlement_status',$filter['status']); if(!empty($filter['modelVersionId']))$this->db->where('model_version_id',(int)$filter['modelVersionId']); return $this->db->order_by('created_at','DESC')->limit(min(500,max(1,$limit)))->get('sports_tickets')->result_array(); }
            public function updateTicket(string $id, array $patch): void { $this->db->where('id', $id)->update('sports_tickets', $patch); }
        };

        $this->identity = new class($db) implements Aegis\Persistence\IdentityRepository {
            public function __construct(private object $db) {}
            public function findUserByEmail(string $email): ?array { return $this->db->get_where('users', ['email' => $email], 1)->row_array() ?: null; }
            public function findUserById(int $id): ?array { return $this->db->get_where('users', ['id' => $id], 1)->row_array() ?: null; }
            public function createUser(array $user): array {
                $this->db->insert('users', $user); $user['id'] = (int) $this->db->insert_id(); return $user;
            }
            public function ensureRole(string $code, string $name): int { return $this->ensure('roles', $code, $name); }
            public function ensurePermission(string $code, string $name): int { return $this->ensure('permissions', $code, $name); }
            private function ensure(string $table, string $code, string $name): int {
                $row = $this->db->get_where($table, ['code' => $code], 1)->row_array();
                if ($row) return (int) $row['id'];
                $this->db->insert($table, ['code' => $code, 'name' => $name]); return (int) $this->db->insert_id();
            }
            public function grantRolePermission(int $roleId, int $permissionId): void {
                if (!$this->db->get_where('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId], 1)->row_array()) $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
            public function assignRole(int $userId, int $roleId): void {
                if (!$this->db->get_where('user_roles', ['user_id' => $userId, 'role_id' => $roleId], 1)->row_array()) $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
            }
            public function permissionsForUser(int $userId): array {
                $rows = $this->db->select('p.code')->from('permissions p')->join('role_permissions rp', 'rp.permission_id = p.id')->join('user_roles ur', 'ur.role_id = rp.role_id')->where('ur.user_id', $userId)->get()->result_array();
                return array_values(array_unique(array_map(fn($r) => $r['code'], $rows)));
            }
            public function recordAuthEvent(int $userId, string $type, array $detail = []): void {
                $this->db->insert('auth_events', ['user_id' => $userId, 'type' => $type, 'detail' => json_encode($detail), 'at' => gmdate('c')]);
            }
        };

        $this->analysis = new class($db) implements Aegis\Persistence\AnalysisRepository {
            public function __construct(private object $db) {}
            public function save(array $run): void {
                $exists = $this->db->from('analysis_runs')->where('id', $run['id'])->count_all_results() > 0;
                $fields = [
                    'symbol' => $run['symbol'], 'timeframe' => $run['timeframe'], 'bias' => $run['bias'],
                    'confidence' => $run['confidence'], 'regime' => $run['marketRegime'],
                    'recommendation' => $run['recommendation'],
                    'synthetic' => !empty($run['provenance']['synthetic']) ? 1 : 0,
                    'source' => $run['provenance']['source'],
                    'completed_at' => $run['completedAt'], 'payload' => json_encode($run),
                ];
                if ($exists) {
                    $this->db->where('id', $run['id'])->update('analysis_runs', $fields);
                } else {
                    $this->db->insert('analysis_runs', array_merge(['id' => $run['id']], $fields));
                }
            }
            public function history(int $limit = 20): array {
                $rows = $this->db->select('id, symbol, timeframe, bias, confidence, regime, recommendation, synthetic, source, completed_at')
                    ->order_by('completed_at', 'DESC')->limit($limit)->get('analysis_runs')->result_array();
                foreach ($rows as &$r) $r['synthetic'] = (bool)$r['synthetic'];
                return $rows;
            }
            public function find(string $id): ?array {
                $row = $this->db->get_where('analysis_runs', ['id' => $id], 1)->row_array();
                return $row ? json_decode($row['payload'], true) : null;
            }
        };

        $this->state = new class($db) implements Aegis\Persistence\PlatformStateRepository {
            public function __construct(private object $db) {}
            private static function defaults(): array
            {
                static $d = null;
                if ($d === null) {
                    $d = [
                        'tradingMode' => 'ANALYSIS_ONLY',
                        'killSwitch' => ['active' => true, 'activatedAt' => null, 'reason' => 'Default state at boot — orders blocked until explicitly released'],
                        // Dev/offline switch: allow paper fills on clearly-labeled
                        // synthetic prices (production keeps this false).
                        'allowSyntheticPaperData' => (getenv('AEGIS_ALLOW_SYNTHETIC_PAPER') === '1'),
                    ];
                }
                return $d;
            }
            public function load(): array {
                $row = $this->db->get_where('platform_state', ['k' => 'state'], 1)->row_array();
                if (!$row) {
                    $this->db->insert('platform_state', ['k' => 'state', 'v' => json_encode(self::defaults())]);
                    return self::defaults();
                }
                $v = json_decode($row['v'], true);
                return array_merge(self::defaults(), is_array($v) ? $v : []);
            }
            public function save(array $state): void {
                $exists = $this->db->from('platform_state')->where('k', 'state')->count_all_results() > 0;
                if ($exists) $this->db->where('k', 'state')->update('platform_state', ['v' => json_encode($state)]);
                else $this->db->insert('platform_state', ['k' => 'state', 'v' => json_encode($state)]);
            }
        };

        $this->paper = new class($db) implements Aegis\Persistence\PaperRepository {
            public function __construct(private object $db) {}

            public function saveAccount(array $a): array {
                if (!empty($a['id'])) {
                    $this->db->where('id', $a['id'])->update('paper_accounts', $a);
                } else {
                    $this->db->insert('paper_accounts', $a);
                    $a['id'] = (int)$this->db->insert_id();
                }
                return $a;
            }
            public function findAccount(int $id): ?array {
                $r = $this->db->get_where('paper_accounts', ['id' => $id], 1)->row_array();
                if ($r) $r['starting_balance'] = (float)$r['starting_balance'];
                if ($r) $r['balance'] = (float)$r['balance'];
                if ($r) $r['peak_equity'] = (float)$r['peak_equity'];
                return $r ?: null;
            }
            public function listAccounts(): array {
                return $this->db->order_by('id', 'ASC')->get('paper_accounts')->result_array();
            }

            public function saveOrder(array $o): array {
                if (!empty($o['id'])) {
                    $this->db->where('id', $o['id'])->update('paper_orders', $o);
                } else {
                    $this->db->insert('paper_orders', $o);
                    $o['id'] = (int)$this->db->insert_id();
                }
                return $o;
            }
            public function listOrders(int $accountId, ?string $status = null): array {
                if ($status !== null) $this->db->where('status', $status);
                $rows = $this->db->where('account_id', $accountId)->order_by('id', 'DESC')->limit(200)->get('paper_orders')->result_array();
                foreach ($rows as &$r) {
                    $r['units'] = (float)$r['units'];
                    $r['price'] = $r['price'] !== null ? (float)$r['price'] : null;
                    $r['stop_loss'] = (float)$r['stop_loss'];
                    $r['take_profit'] = (float)$r['take_profit'];
                }
                return $rows;
            }
            public function findOpenOrder(int $accountId, string $symbol): ?array {
                $row = $this->db->where('account_id', $accountId)->where('symbol', $symbol)
                    ->where('status', 'PENDING')->order_by('id', 'DESC')->limit(1)->get('paper_orders')->row_array();
                return $row ?: null;
            }

            public function savePosition(array $p): array {
                if (!empty($p['id'])) {
                    $this->db->where('id', $p['id'])->update('paper_positions', $p);
                } else {
                    $this->db->insert('paper_positions', $p);
                    $p['id'] = (int)$this->db->insert_id();
                }
                return $p;
            }
            public function findPosition(int $id): ?array {
                $r = $this->db->get_where('paper_positions', ['id' => $id], 1)->row_array();
                return $r ? self::castPosition($r) : null;
            }
            public function findOpenPosition(int $accountId, string $symbol): ?array {
                $r = $this->db->where('account_id', $accountId)->where('symbol', $symbol)
                    ->where('status', 'OPEN')->order_by('id', 'DESC')->limit(1)->get('paper_positions')->row_array();
                return $r ? self::castPosition($r) : null;
            }
            public function listOpenPositions(int $accountId): array {
                $rows = $this->db->where('account_id', $accountId)->where('status', 'OPEN')->order_by('id', 'ASC')->get('paper_positions')->result_array();
                return array_map(fn($r) => self::castPosition($r), $rows);
            }
            private static function castPosition(array $r): array {
                foreach (['units', 'entry_price', 'stop_loss', 'take_profit', 'entry_fee', 'risk_amount', 'ai_confidence', 'realized_pnl', 'exit_price'] as $k) {
                    $r[$k] = $r[$k] !== null ? (float)$r[$k] : null;
                }
                return $r;
            }

            public function saveTrade(array $t): void {
                $this->db->insert('paper_trades', $t);
            }
            public function listTrades(int $accountId, int $limit = 100): array {
                $rows = $this->db->where('account_id', $accountId)->order_by('id', 'DESC')->limit($limit)->get('paper_trades')->result_array();
                $pnlByPos = [];
                $q = $this->db->select('id, realized_pnl')->where('account_id', $accountId)->get('paper_positions');
                foreach ($q->result_array() as $p) $pnlByPos[$p['id']] = (float)$p['realized_pnl'];
                foreach ($rows as &$r) {
                    $r['price'] = (float)$r['price'];
                    $r['units'] = (float)$r['units'];
                    $r['fee'] = (float)$r['fee'];
                    if ($r['leg'] === 'EXIT') $r['net_pnl'] = $pnlByPos[$r['position_id']] ?? 0.0;
                }
                return $rows;
            }

            public function saveDeployment(array $d): array {
                if (!empty($d['id'])) {
                    $this->db->where('id', $d['id'])->update('paper_deployments', $d);
                } else {
                    $this->db->insert('paper_deployments', $d);
                    $d['id'] = (int)$this->db->insert_id();
                }
                return $d;
            }
            public function findDeployment(int $id): ?array {
                return $this->db->get_where('paper_deployments', ['id' => $id], 1)->row_array() ?: null;
            }
            public function listDeployments(?int $accountId = null, ?bool $active = null): array {
                if ($accountId !== null) $this->db->where('account_id', $accountId);
                if ($active !== null) $this->db->where('active', $active ? 1 : 0);
                return $this->db->order_by('id', 'ASC')->get('paper_deployments')->result_array();
            }
        };

        $this->notifications = new class($db) implements Aegis\Persistence\NotificationRepository {
            public function __construct(private object $db) {}

            public function save(array $n): array {
                if (empty($n['id'])) $n['id'] = bin2hex(random_bytes(16));
                $row = [
                    'id' => $n['id'], 'user_id' => $n['userId'] ?? null, 'type' => (string) $n['type'],
                    'severity' => in_array($n['severity'] ?? 'info', ['info', 'warning', 'critical'], true) ? $n['severity'] : 'info',
                    'title' => mb_substr((string) $n['title'], 0, 200),
                    'detail' => json_encode($n['detail'] ?? []),
                    'dedupe_key' => $n['dedupeKey'] ?? null,
                    'read_at' => null, 'created_at' => $n['createdAt'] ?? gmdate('c'),
                ];
                $this->db->insert('notifications', $row);
                $n['id'] = $row['id'];
                return $n;
            }

            public function list(?int $userId = null, bool $unreadOnly = false, int $limit = 50): array {
                if ($userId === null) {
                    // broadcast only (no authenticated operator)
                    $this->db->where('user_id', null);
                } else {
                    $this->db->group_start()->where('user_id', null)->or_where('user_id', $userId)->group_end();
                }
                if ($unreadOnly) $this->db->where('read_at', null);
                $rows = $this->db->order_by('created_at', 'DESC')->limit(max(1, min(200, $limit)))->get('notifications')->result_array();
                $out = [];
                foreach ($rows as $r) {
                    $r['detail'] = json_decode($r['detail'], true) ?: [];
                    $out[] = $r;
                }
                return $out;
            }

            public function markRead(string $id, ?int $userId = null): bool {
                $this->db->where('id', $id)->where('read_at', null);
                if ($userId === null) $this->db->where('user_id', null);
                else $this->db->group_start()->where('user_id', null)->or_where('user_id', $userId)->group_end();
                $this->db->set('read_at', gmdate('c'))->update('notifications');
                return $this->db->affected_rows() > 0;
            }

            public function markAllRead(?int $userId = null): int {
                $this->db->where('read_at', null);
                if ($userId === null) $this->db->where('user_id', null);
                else $this->db->group_start()->where('user_id', null)->or_where('user_id', $userId)->group_end();
                $this->db->set('read_at', gmdate('c'))->update('notifications');
                return $this->db->affected_rows();
            }

            public function unreadCount(?int $userId = null): int {
                $this->db->where('read_at', null);
                if ($userId === null) $this->db->where('user_id', null);
                else $this->db->group_start()->where('user_id', null)->or_where('user_id', $userId)->group_end();
                return (int) $this->db->count_all_results('notifications');
            }

            public function hasUnreadDedupe(string $dedupeKey): bool {
                return $this->db->from('notifications')
                    ->where('dedupe_key', $dedupeKey)->where('read_at', null)->count_all_results() > 0;
            }
        };

        $this->langlearn = new class($db) implements Aegis\LangLearn\Persistence\LangLearnRepository {
            public function __construct(private object $db) {}

            public function upsertLanguage(array $row): void {
                $exists = $this->db->from('languages')->where('code', $row['code'])->count_all_results() > 0;
                if ($exists) $this->db->where('code', $row['code'])->update('languages', $row);
                else $this->db->insert('languages', $row);
            }
            public function listLanguages(bool $activeOnly = true): array {
                if ($activeOnly) $this->db->where('active', 1);
                return $this->db->order_by('name', 'ASC')->get('languages')->result_array();
            }
            public function findLanguage(string $code): ?array {
                return $this->db->get_where('languages', ['code' => $code], 1)->row_array() ?: null;
            }

            public function saveProfile(array $p): array {
                if (!empty($p['id'])) $this->db->where('id', $p['id'])->update('user_language_profiles', $p);
                else { $this->db->insert('user_language_profiles', $p); $p['id'] = (int) $this->db->insert_id(); }
                return $this->repoFindProfile((int) $p['id']) ?? $p;
            }
            public function findProfile(int $id): ?array { return $this->repoFindProfile($id); }
            private function repoFindProfile(int $id): ?array {
                $r = $this->db->get_where('user_language_profiles', ['id' => $id], 1)->row_array();
                if ($r) $r['id'] = (int) $r['id'];
                return $r ?: null;
            }
            public function findProfileByUserLanguage(int $userId, string $code): ?array {
                $r = $this->db->get_where('user_language_profiles', ['user_id' => $userId, 'language_code' => $code], 1)->row_array();
                if ($r) $r['id'] = (int) $r['id'];
                return $r ?: null;
            }
            public function listProfilesByUser(int $userId): array {
                return $this->db->where('user_id', $userId)->order_by('created_at', 'ASC')->get('user_language_profiles')->result_array();
            }

            public function saveAssessment(array $a): array {
                $row = $a;
                $row['state'] = is_array($a['state'] ?? null) ? json_encode($a['state']) : $a['state'];
                $row['result'] = is_array($a['result'] ?? null) ? json_encode($a['result']) : $a['result'];
                $exists = $this->db->from('language_assessments')->where('id', $row['id'])->count_all_results() > 0;
                if ($exists) { unset($row['started_at']); $this->db->where('id', $row['id'])->update('language_assessments', $row); }
                else $this->db->insert('language_assessments', $row);
                return $this->castAssessment($this->db->get_where('language_assessments', ['id' => $a['id']], 1)->row_array());
            }
            public function findAssessment(string $id): ?array {
                $r = $this->db->get_where('language_assessments', ['id' => $id], 1)->row_array();
                return $r ? $this->castAssessment($r) : null;
            }
            public function latestCompletedAssessment(int $profileId): ?array {
                $r = $this->db->where('profile_id', $profileId)->where('status', 'COMPLETED')
                    ->order_by('completed_at', 'DESC')->limit(1)->get('language_assessments')->row_array();
                return $r ? $this->castAssessment($r) : null;
            }
            private function castAssessment(array $r): array {
                $r['state'] = json_decode((string) $r['state'], true) ?: [];
                $r['result'] = $r['result'] !== null ? (json_decode((string) $r['result'], true) ?: null) : null;
                $r['profile_id'] = (int) $r['profile_id'];
                $r['user_id'] = (int) $r['user_id'];
                return $r;
            }

            public function savePath(array $p): array {
                $exists = $this->db->from('learning_paths')->where('id', $p['id'])->count_all_results() > 0;
                if ($exists) $this->db->where('id', $p['id'])->update('learning_paths', $p);
                else $this->db->insert('learning_paths', $p);
                return $this->db->get_where('learning_paths', ['id' => $p['id']], 1)->row_array() ?: $p;
            }
            public function activePath(int $profileId): ?array {
                return $this->db->where('profile_id', $profileId)->where('status', 'ACTIVE')
                    ->order_by('created_at', 'DESC')->limit(1)->get('learning_paths')->row_array() ?: null;
            }

            public function saveModule(array $m): array {
                $row = $m;
                $exists = $this->db->from('learning_modules')->where('id', $row['id'])->count_all_results() > 0;
                if ($exists) { unset($row['path_id'], $row['profile_id'], $row['sequence']); $this->db->where('id', $row['id'])->update('learning_modules', $row); }
                else $this->db->insert('learning_modules', $row);
                return $this->findModule($m['id']) ?? $m;
            }
            public function findModule(string $id): ?array {
                $r = $this->db->get_where('learning_modules', ['id' => $id], 1)->row_array();
                if ($r) { $r['sequence'] = (int) $r['sequence']; $r['attempts_count'] = (int) $r['attempts_count']; $r['profile_id'] = (int) $r['profile_id']; }
                return $r ?: null;
            }
            public function listModules(string $pathId): array {
                return $this->db->where('path_id', $pathId)->order_by('sequence', 'ASC')->get('learning_modules')->result_array();
            }

            public function saveAttempt(array $a): array {
                $row = $a;
                $row['detail'] = json_encode($a['detail'] ?? []);
                $this->db->insert('lesson_attempts', $row);
                return $row;
            }
            public function saveSession(array $s): void {
                $this->db->insert('study_sessions', $s);
            }
            public function listAttemptsForProfile(int $profileId, int $limit = 100): array {
                $rows = $this->db->where('profile_id', $profileId)->order_by('created_at', 'DESC')
                    ->limit(max(1, min(300, $limit)))->get('lesson_attempts')->result_array();
                foreach ($rows as &$r) {
                    $r['detail'] = json_decode((string) $r['detail'], true) ?: [];
                    if ($r['score_pct'] !== null) $r['score_pct'] = (float) $r['score_pct'];
                }
                return $rows;
            }
            public function sessionDays(int $profileId): array {
                $rows = $this->db->select('day')->distinct()->where('profile_id', $profileId)
                    ->order_by('day', 'DESC')->limit(400)->get('study_sessions')->result_array();
                return array_map(fn($r) => (string) $r['day'], $rows);
            }

            public function saveConversation(array $c): array {
                $row = $c;
                $row['state'] = is_array($c['state'] ?? null) ? json_encode($c['state']) : $c['state'];
                $exists = $this->db->from('conversation_sessions')->where('id', $row['id'])->count_all_results() > 0;
                if ($exists) { unset($row['started_at']); $this->db->where('id', $row['id'])->update('conversation_sessions', $row); }
                else $this->db->insert('conversation_sessions', $row);
                return $this->castConversation($this->db->get_where('conversation_sessions', ['id' => $c['id']], 1)->row_array());
            }
            public function findConversation(string $id): ?array {
                $r = $this->db->get_where('conversation_sessions', ['id' => $id], 1)->row_array();
                return $r ? $this->castConversation($r) : null;
            }
            public function listConversations(int $profileId, int $limit = 20): array {
                return $this->db->where('profile_id', $profileId)->order_by('started_at', 'DESC')
                    ->limit(max(1, min(100, $limit)))->get('conversation_sessions')->result_array();
            }
            private function castConversation(array $r): array {
                $r['state'] = json_decode((string) $r['state'], true) ?: [];
                $r['profile_id'] = (int) $r['profile_id'];
                $r['user_id'] = (int) $r['user_id'];
                $r['turn_count'] = (int) $r['turn_count'];
                return $r;
            }
            public function saveWriting(array $w): array {
                $row = $w;
                $row['feedback'] = json_encode($w['feedback'] ?? []);
                $this->db->insert('writing_attempts', $row);
                $row['feedback'] = $w['feedback'] ?? [];
                return $row;
            }
            public function listWriting(int $profileId, int $limit = 20): array {
                $rows = $this->db->where('profile_id', $profileId)->order_by('created_at', 'DESC')
                    ->limit(max(1, min(100, $limit)))->get('writing_attempts')->result_array();
                foreach ($rows as &$r) $r['feedback'] = json_decode((string) $r['feedback'], true) ?: [];
                return $rows;
            }

            public function upsertProgress(array $row): void {
                $keys = ['profile_id' => $row['profile_id'], 'skill' => $row['skill'], 'source' => $row['source']];
                $exists = $this->db->from('language_progress')->where($keys)->count_all_results() > 0;
                if ($exists) $this->db->where($keys)->update('language_progress', ['level' => $row['level'], 'value_pct' => $row['value_pct'], 'updated_at' => $row['updated_at']]);
                else $this->db->insert('language_progress', $row);
            }
            public function listProgress(int $profileId): array {
                return $this->db->where('profile_id', $profileId)->get('language_progress')->result_array();
            }
        };

        $this->proposals = new class($db) implements Aegis\Persistence\ProposalRepository {
            public function __construct(private object $db) {}

            public function saveProposal(array $p): array {
                // Accepts both the supervisor's camelCase contract and rows
                // returned by findProposal()/listProposals() (snake_case).
                $pick = fn(string $camel, string $snake, $default = null) => $p[$camel] ?? $p[$snake] ?? $default;
                $intent = $pick('intent', 'intent', []);
                $row = [
                    'id' => $p['id'], 'created_at' => $pick('createdAt', 'created_at', gmdate('c')),
                    'actor' => $pick('actor', 'actor', 'user'),
                    'broker' => $pick('broker', 'broker', 'none'),
                    'symbol' => $pick('symbol', 'symbol', ''),
                    'market_class' => $pick('marketClass', 'market_class', ''),
                    'side' => $pick('side', 'side', ''), 'order_type' => $pick('orderType', 'order_type', 'MARKET'),
                    'volume' => (float)$pick('volume', 'volume', 0),
                    'price' => ($px = $pick('price', 'price')) !== null ? (float)$px : null,
                    'stop_loss' => (float)$pick('stopLoss', 'stop_loss', 0),
                    'take_profit' => ($tp = $pick('takeProfit', 'take_profit')) !== null ? (float)$tp : null,
                    'strategy_id' => $pick('strategyId', 'strategy_id'), 'reason' => mb_substr((string)$pick('reason', 'reason', ''), 0, 500),
                    'status' => $p['status'], 'intent' => json_encode($intent),
                    'checks' => json_encode($pick('checks', 'checks', [])),
                    'risk_decision' => ($rd = $pick('riskDecision', 'riskDecision')) !== null ? json_encode($rd) : null,
                    'decision_by' => $pick('decisionBy', 'decision_by'), 'decided_at' => $pick('decidedAt', 'decided_at'),
                    'updated_at' => gmdate('c'),
                ];
                $exists = $this->db->from('trade_proposals')->where('id', $row['id'])->count_all_results() > 0;
                if ($exists) { unset($row['created_at'], $row['actor']); $this->db->where('id', $row['id'])->update('trade_proposals', $row); }
                else $this->db->insert('trade_proposals', $row);
                return $this->findProposal($p['id']) ?? $p;
            }

            public function findProposal(string $id): ?array {
                $r = $this->db->get_where('trade_proposals', ['id' => $id], 1)->row_array();
                return $r ? self::cast($r) : null;
            }

            public function listProposals(?string $status = null, int $limit = 100): array {
                if ($status !== null) $this->db->where('status', $status);
                $rows = $this->db->order_by('created_at', 'DESC')->limit(max(1, min(500, $limit)))->get('trade_proposals')->result_array();
                return array_map(self::cast(...), $rows);
            }

            public function countAutomatedExecutionsToday(): int {
                return (int) $this->db->from('trade_executions')
                    ->where('automated', 1)->where('submitted_at >=', gmdate('Y-m-d'))
                    ->count_all_results();
            }

            public function saveExecution(array $e): array {
                $pick = fn(string $camel, string $snake, $default = null) => $e[$camel] ?? $e[$snake] ?? $default;
                $row = [
                    'id' => $e['id'], 'proposal_id' => $pick('proposalId', 'proposal_id'), 'broker' => $e['broker'],
                    'broker_order_id' => $pick('brokerOrderId', 'broker_order_id'), 'automated' => !empty($e['automated']) ? 1 : 0,
                    'submitted_at' => $pick('submittedAt', 'submitted_at', gmdate('c')), 'status' => $e['status'],
                    'result' => json_encode($e['result'] ?? []),
                ];
                $exists = $this->db->from('trade_executions')->where('id', $row['id'])->count_all_results() > 0;
                if ($exists) $this->db->where('id', $row['id'])->update('trade_executions', $row);
                else $this->db->insert('trade_executions', $row);
                return self::castExecution(array_merge($row, ['result' => $e['result'] ?? []]));
            }

            public function listExecutions(string $proposalId, int $limit = 10): array {
                $rows = $this->db->where('proposal_id', $proposalId)->order_by('submitted_at', 'DESC')
                    ->limit(max(1, min(50, $limit)))->get('trade_executions')->result_array();
                return array_map(self::castExecution(...), $rows);
            }

            public function listRecentExecutions(int $limit = 50): array {
                $rows = $this->db->order_by('submitted_at', 'DESC')->limit(max(1, min(200, $limit)))
                    ->get('trade_executions')->result_array();
                return array_map(self::castExecution(...), $rows);
            }

            private static function cast(array $r): array {
                foreach (['volume', 'price', 'stop_loss', 'take_profit'] as $k) if ($r[$k] !== null) $r[$k] = (float)$r[$k];
                $r['intent'] = json_decode($r['intent'], true) ?: [];
                $r['checks'] = json_decode($r['checks'], true) ?: [];
                $r['riskDecision'] = $r['risk_decision'] !== null ? (json_decode($r['risk_decision'], true) ?: null) : null;
                unset($r['risk_decision']);
                return $r;
            }

            private static function castExecution(array $r): array {
                $r['automated'] = (bool)$r['automated'];
                if (is_string($r['result'])) $r['result'] = json_decode($r['result'], true) ?: [];
                return $r;
            }
        };
    }
}

/** Row encode/decode helpers for backtests (shared by anonymous classes). */
final class BacktestRepo
{
    public static function decode(array $row): array {
        $record = json_decode($row['payload'], true) ?: [];
        $record['id'] = $row['id'];
        $record['created_at'] = $row['created_at'];
        return $record;
    }
    public static function encode(array $r): array {
        return [
            'id' => $r['id'], 'created_at' => $r['created_at'],
            'strategy_id' => $r['request']['strategyId'], 'strategy_version' => $r['request']['strategyVersion'],
            'symbol' => $r['request']['symbol'], 'timeframe' => $r['request']['timeframe'],
            'synthetic' => !empty($r['dataProvenance']['synthetic']) ? 1 : 0,
            'payload' => json_encode($r),
        ];
    }
}
