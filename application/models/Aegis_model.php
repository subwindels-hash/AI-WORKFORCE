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
    public object $analysis;
    public object $state;
    public object $paper;

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
