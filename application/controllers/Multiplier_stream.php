<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

use AIWorkforce\MultiplierIntelligence\SimulationProvider;
use AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine;

/**
 * Multiplier Intelligence - Server-Sent Events (SSE) Controller
 * 
 * Provides real-time streaming of multiplier data via SSE.
 * SSE is ideal for one-way server-to-client updates.
 */
class Multiplier_stream extends App_Controller
{
    /**
     * SSE endpoint for live multiplier stream
     * 
     * Clients connect via EventSource and receive:
     * - current_multiplier: Every second during round
     * - round_started: When new round begins
     * - round_crashed: When round crashes
     * - signal_generated: When new signal is generated
     */
    public function live()
    {
        // Disable output buffering
        if (ob_get_level()) ob_end_clean();
        
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Nginx
        
        $provider = new SimulationProvider();
        
        // Generate initial history
        if (empty($provider->allRounds())) {
            for ($i = 0; $i < 50; $i++) {
                $provider->startRound();
                $provider->endRound();
            }
        }
        
        $lastSignalTime = 0;
        $iterationCount = 0;
        $maxIterations = 300; // ~5 minutes
        
        while ($iterationCount < $maxIterations) {
            $iterationCount++;
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
            
            // Update round state
            $roundData = $provider->updateMultiplier();
            
            // Send current multiplier
            $this->sendSSE('multiplier', [
                'value' => $roundData['currentMultiplier'] ?? 1.0,
                'roundId' => $roundData['roundId'] ?? null,
                'inRound' => $roundData['inRound'] ?? false,
                'elapsed' => $roundData['elapsedMs'] ?? 0,
                'timestamp' => time(),
            ]);
            
            // Generate signal periodically (every 30 seconds)
            if (time() - $lastSignalTime > 30) {
                try {
                    $engine = new MultiplierIntelligenceEngine($provider);
                    $signal = $engine->generateSignal();
                    
                    $this->sendSSE('signal', [
                        'signalId' => $signal['signalId'],
                        'predicted' => $signal['predictedMultiplier'],
                        'min' => $signal['predictedMin'],
                        'max' => $signal['predictedMax'],
                        'confidence' => $signal['confidence'],
                        'risk' => $signal['risk'],
                        'agentCount' => count($signal['agents']),
                        'generatedAt' => $signal['generatedAt'],
                    ]);
                    
                    $lastSignalTime = time();
                } catch (\Throwable $e) {
                    // Skip signal generation on error
                }
            }
            
            // Send heartbeat every 15 seconds
            if ($iterationCount % 15 === 0) {
                $this->sendSSE('heartbeat', [
                    'timestamp' => time(),
                    'iteration' => $iterationCount,
                ]);
            }
            
            // Flush output
            if (ob_get_level()) ob_flush();
            flush();
            
            // Sleep 1 second
            sleep(1);
        }
        
        // Send close event
        $this->sendSSE('close', ['reason' => 'stream_ended']);
    }
    
    /**
     * SSE endpoint for signal-only stream
     * Generates signals less frequently (every 20 seconds)
     */
    public function signals()
    {
        if (ob_get_level()) ob_end_clean();
        
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        $provider = new SimulationProvider();
        
        // Generate initial history
        for ($i = 0; $i < 100; $i++) {
            $provider->startRound();
            $provider->endRound();
        }
        
        $iteration = 0;
        $maxIterations = 180; // ~1 hour
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            if (connection_aborted()) break;
            
            try {
                $engine = new MultiplierIntelligenceEngine($provider);
                $signal = $engine->generateSignal();
                
                $this->sendSSE('signal', [
                    'signalId' => $signal['signalId'],
                    'predicted' => $signal['predictedMultiplier'],
                    'min' => $signal['predictedMin'],
                    'max' => $signal['predictedMax'],
                    'confidence' => $signal['confidence'],
                    'risk' => $signal['risk'],
                    'agents' => array_map(function($a) {
                        return [
                            'name' => $a['agent_name'],
                            'type' => $a['agent_type'],
                            'estimate' => $a['estimate'],
                            'confidence' => $a['confidence'],
                            'reasoning' => $a['reasoning'],
                        ];
                    }, $signal['agents']),
                    'features' => $signal['features'],
                    'generatedAt' => $signal['generatedAt'],
                    'disclaimer' => $signal['disclaimer'],
                ]);
            } catch (\Throwable $e) {
                $this->sendSSE('error', ['message' => $e->getMessage()]);
            }
            
            if (ob_get_level()) ob_flush();
            flush();
            
            // Wait 20 seconds between signals
            sleep(20);
        }
    }
    
    /**
     * Send an SSE event
     */
    private function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    }
}
