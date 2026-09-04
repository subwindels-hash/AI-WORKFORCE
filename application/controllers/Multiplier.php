<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

use AIWorkforce\MultiplierIntelligence\SimulationProvider;
use AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine;

/**
 * Multiplier Intelligence Console
 * 
 * AI-powered crash game analytics with multi-agent prediction system.
 * Demonstrates the WINDELS AI agent orchestration for real-time prediction.
 * 
 * IMPORTANT DISCLAIMER: This system uses statistical analysis for
 * educational and analytical purposes. Crash games are inherently
 * random and no system can guarantee predictions. Always gamble
 * responsibly.
 */
class Multiplier extends App_Controller
{
    private function engine(): MultiplierIntelligenceEngine
    {
        // Use simulation provider by default
        $provider = new SimulationProvider();
        
        // Generate some historical data if empty
        if (empty($provider->allRounds())) {
            for ($i = 0; $i < 100; $i++) {
                $provider->startRound();
                // Simulate round progression
                usleep(1000);
                $provider->endRound();
            }
        }
        
        // Get database from platform model
        $db = null;
        try {
            $db = $this->platform->model->db ?? null;
        } catch (\Throwable $e) {
            // DB optional for this module
        }
        
        return new MultiplierIntelligenceEngine($provider, $db);
    }
    
    public function index()
    {
        $user = $this->identity;
        $data = $this->base('AI Multiplier Intelligence', 'multiplier');
        
        $engine = $this->engine();
        $dashboard = $engine->dashboard();
        
        // Generate initial signal
        $signal = $engine->generateSignal();
        
        $data['dashboard'] = $dashboard;
        $data['signal'] = $signal;
        $data['accuracy'] = $engine->accuracyStats(100);
        $data['history'] = $engine->provider()->history(20);
        
        $this->load->view('layout/header', $data);
        $this->load->view('multiplier/index', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Generate a new signal via AJAX
     */
    public function generate_signal()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        
        try {
            $engine = $this->engine();
            $signal = $engine->generateSignal();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'signal' => $signal]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get live multiplier data
     */
    public function live()
    {
        try {
            $engine = $this->engine();
            $provider = $engine->provider();
            
            // Update current round
            if ($provider instanceof SimulationProvider) {
                $provider->updateMultiplier();
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'currentMultiplier' => $provider->currentMultiplier(),
                'isInRound' => $provider->isInRound(),
                'latestRound' => $provider->latestRound(),
            ]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get dashboard data
     */
    public function dashboard_data()
    {
        try {
            $engine = $this->engine();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'dashboard' => $engine->dashboard()]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get accuracy statistics
     */
    public function accuracy()
    {
        try {
            $engine = $this->engine();
            $window = (int) ($this->input->get('window') ?: 100);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'accuracy' => $engine->accuracyStats($window)]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Validate past predictions
     */
    public function validate()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        
        try {
            $engine = $this->engine();
            $result = $engine->validatePredictions();
            header('Content-Type: application/json');
            echo json_encode($result);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
