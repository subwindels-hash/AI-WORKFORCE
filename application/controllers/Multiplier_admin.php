<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Multiplier Intelligence Admin Controller
 * 
 * Provides administrative controls for the AI Multiplier Intelligence module:
 * - Enable/disable module
 * - Configure providers
 * - View prediction accuracy
 * - Manage models
 * - Audit logs
 */
class Multiplier_admin extends App_Controller
{
    /** Shared layout scaffolding (title, nav state, platform status banner). */
    private function base(string $title, string $active): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => $active,
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->session->flashdata('notice'), 'error' => $this->session->flashdata('error'),
        ];
    }

    public function __construct()
    {
        parent::__construct();
        // Require admin access
        if (!$this->platform->identity->can($this->identity ?? [], 'admin.settings')) {
            show_error('Access denied', 403);
        }
    }
    
    /**
     * Admin dashboard for multiplier module
     */
    public function index()
    {
        $data = $this->base('Multiplier Intelligence Admin', 'multiplier_admin');
        
        // Load configuration from state
        $config = $this->getConfig();
        
        // Load stats
        $stats = $this->getStats();
        
        $data['config'] = $config;
        $data['stats'] = $stats;
        $data['providers'] = $this->getProviders();
        $data['models'] = $this->getModels();
        $data['recentPredictions'] = $this->getRecentPredictions(20);
        $data['accuracyHistory'] = $this->getAccuracyHistory();
        
        $this->load->view('layout/header', $data);
        $this->load->view('multiplier/admin', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Save configuration
     */
    public function save_config()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
        
        $config = [
            'enabled' => (bool) $this->input->post('enabled'),
            'active_provider' => $this->input->post('active_provider') ?: 'bustabit',
            'signal_interval' => (int) ($this->input->post('signal_interval') ?: 30),
            'history_size' => (int) ($this->input->post('history_size') ?: 200),
            'require_disclaimer' => (bool) $this->input->post('require_disclaimer'),
            'allow_anonymous' => (bool) $this->input->post('allow_anonymous'),
            'max_signals_per_hour' => (int) ($this->input->post('max_signals_per_hour') ?: 120),
            'enable_accuracy_tracking' => (bool) $this->input->post('enable_accuracy_tracking'),
        ];
        
        // Save to platform state
        $state = $this->platform->state();
        $state['multiplier_config'] = $config;
        $this->platform->saveState($state);
        
        $this->session->set_flashdata('notice', 'Configuration saved successfully');
        redirect('/multiplier/admin');
    }
    
    /**
     * Validate all pending predictions
     */
    public function validate_predictions()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
        
        try {
            $provider = \AIWorkforce\MultiplierIntelligence\CrashProviderFactory::fromPlatform($this->platform);
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine(
                $provider,
                $this->platform->model->db ?? null
            );
            
            $result = $engine->validatePredictions();
            
            $this->session->set_flashdata('notice', "Validated {$result['validated']} predictions");
        } catch (\Throwable $e) {
            $this->session->set_flashdata('error', 'Validation failed: ' . $e->getMessage());
        }
        
        redirect('/multiplier/admin');
    }
    
    /**
     * Toggle module enabled/disabled
     */
    public function toggle()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
        
        $state = $this->platform->state();
        $config = $state['multiplier_config'] ?? ['enabled' => true];
        $config['enabled'] = !(bool) ($config['enabled'] ?? true);
        $state['multiplier_config'] = $config;
        $this->platform->saveState($state);
        
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'enabled' => $config['enabled']]);
    }
    
    /**
     * Get accuracy report
     */
    public function accuracy_report()
    {
        try {
            $provider = \AIWorkforce\MultiplierIntelligence\CrashProviderFactory::fromPlatform($this->platform);
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine(
                $provider,
                $this->platform->model->db ?? null
            );
            
            $windows = [10, 50, 100, 500];
            $report = [];
            
            foreach ($windows as $window) {
                $report[$window] = $engine->accuracyStats($window);
            }
            
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'report' => $report]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Reset all predictions (admin only)
     */
    public function reset()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
        
        $confirm = $this->input->post('confirm');
        if ($confirm !== 'RESET') {
            $this->session->set_flashdata('error', 'Please type RESET to confirm');
            redirect('/multiplier/admin');
            return;
        }
        
        try {
            $db = $this->platform->model->db ?? null;
            if ($db) {
                $db->truncate('crash_game_predictions');
                $db->truncate('crash_game_agent_executions');
                $db->truncate('crash_game_accuracy_snapshots');
                $db->truncate('crash_game_active_signals');
            }
            
            $this->session->set_flashdata('notice', 'All prediction data has been reset');
        } catch (\Throwable $e) {
            $this->session->set_flashdata('error', 'Reset failed: ' . $e->getMessage());
        }
        
        redirect('/multiplier/admin');
    }
    
    /**
     * Get module configuration
     */
    private function getConfig(): array
    {
        $state = $this->platform->state();
        return $state['multiplier_config'] ?? [
            'enabled' => true,
            'active_provider' => 'bustabit',
            'signal_interval' => 30,
            'history_size' => 200,
            'require_disclaimer' => true,
            'allow_anonymous' => false,
            'max_signals_per_hour' => 120,
            'enable_accuracy_tracking' => true,
        ];
    }
    
    /**
     * Get module statistics
     */
    private function getStats(): array
    {
        $db = $this->platform->model->db ?? null;
        if (!$db) {
            return [
                'total_predictions' => 0,
                'validated_predictions' => 0,
                'total_agents' => 9,
                'accuracy_100' => null,
            ];
        }
        
        try {
            $total = $db->count_all('crash_game_predictions');
            $validated = $db->where('validated', 1)->count_all_results('crash_game_predictions');
            
            $accuracy = null;
            if ($validated > 0) {
                $accurate = $db->where('validated', 1)
                    ->where('error_pct <=', 20)
                    ->count_all_results('crash_game_predictions');
                $accuracy = round($accurate / $validated * 100, 1);
            }
            
            return [
                'total_predictions' => $total,
                'validated_predictions' => $validated,
                'total_agents' => 9,
                'accuracy_100' => $accuracy,
            ];
        } catch (\Throwable $e) {
            return [
                'total_predictions' => 0,
                'validated_predictions' => 0,
                'total_agents' => 9,
                'accuracy_100' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get available providers
     */
    private function getProviders(): array
    {
        return [
            [
                'code' => 'bustabit',
                'name' => 'Bustabit (Live)',
                'enabled' => true,
                'description' => 'Public Bustabit crash history — real completed rounds, no demo data',
            ],
            [
                'code' => 'simulation',
                'name' => 'Simulation (tests only)',
                'enabled' => false,
                'description' => 'Geometric demo generator — not used by the member dashboard',
            ],
        ];
    }
    
    /**
     * Get available models
     */
    private function getModels(): array
    {
        return [
            ['code' => 'MIXED-ENSEMBLE-v1', 'name' => 'Mixed Ensemble', 'enabled' => true],
            ['code' => 'STATISTICAL-BASELINE-v1', 'name' => 'Statistical Baseline', 'enabled' => true],
            ['code' => 'PATTERN-ENSEMBLE-v1', 'name' => 'Pattern Ensemble', 'enabled' => true],
            ['code' => 'ANOMALY-AWARE-v1', 'name' => 'Anomaly Aware', 'enabled' => true],
        ];
    }
    
    /**
     * Get recent predictions
     */
    private function getRecentPredictions(int $limit = 20): array
    {
        $db = $this->platform->model->db ?? null;
        if (!$db) return [];
        
        try {
            return $db->order_by('predicted_at', 'DESC')
                ->limit($limit)
                ->get('crash_game_predictions')
                ->result_array();
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Get accuracy history
     */
    private function getAccuracyHistory(): array
    {
        $db = $this->platform->model->db ?? null;
        if (!$db) return [];
        
        try {
            return $db->order_by('snapshot_at', 'DESC')
                ->limit(30)
                ->get('crash_game_accuracy_snapshots')
                ->result_array();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
