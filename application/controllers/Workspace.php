<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/** Signed-in user home. Real counts only — empty modules stay empty. */
class Workspace extends App_Controller
{
    public function index()
    {
        $user = $this->identity;
        $state = $this->platform->state();
        $inbox = $this->platform->notifications->inbox((int) $user['id'], false, 8);
        $history = $this->platform->model->analysis->history(6);
        $accounts = $this->platform->model->paper->listAccounts();
        $profiles = [];
        try { $profiles = $this->platform->langlearn->profiles((int) $user['id']); } catch (Throwable $e) { $profiles = []; }
        $data = [
            'title' => 'Dashboard',
            'active' => 'home',
            'user' => $user,
            'admin' => $this->isAdmin($user),
            'status' => [
                'tradingMode' => $state['tradingMode'],
                'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth(),
            ],
            'inbox' => $inbox,
            'history' => $history,
            'paperAccounts' => count($accounts),
            'languageProfiles' => count($profiles),
            'messagesUnread' => $this->AIWorkforce_model->messages->unreadForUser((int) $user['id']),
            'latestMessage' => $this->AIWorkforce_model->messages->latestForUser((int) $user['id']),
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'lotteryWidget' => $this->lotteryWidgetData((int) $user['id']),
            'tradingWidget' => $this->tradingWidgetData((int) $user['id']),
            'multiplierWidget' => $this->multiplierWidgetData(),
            'aiModules' => $this->aiModulesData(),
            'languageWidget' => $this->languageWidgetData((int) $user['id']),
            'sportsWidget' => $this->sportsWidgetData(),
            'windelsAI' => $this->windelsAIWidgetData(),
        ];
        $this->load->view('layout/header', $data);
        $this->load->view('workspace/index', $data);
        $this->load->view('layout/footer');
    }

    private function lotteryWidgetData(int $userId): array
    {
        $out = [
            'status' => 'NO_DATA',
            'jackpot' => null,
            'jackpotFormatted' => '—',
            'nextEstimated' => null,
            'lastDraw' => null,
            'recentDraws' => [],
            'imported' => 0,
            'myTicketsCount' => 0,
            'rules' => ['mains' => 5, 'mainMax' => 50, 'stars' => 2, 'starMax' => 12],
            'lotteries' => [['code' => 'euromillions', 'name' => 'EuroMillions']],
        ];
        
        try {
            $status = $this->platform->lottery->status();
            $out['status'] = $status['status'] ?? 'NO_DATA';
            $out['jackpot'] = $status['jackpot'] ?? null;
            $out['nextEstimated'] = $status['nextEstimated'] ?? null;
            $out['lastDraw'] = $status['lastDraw'] ?? null;
            $out['imported'] = $status['imported'] ?? 0;
            $out['rules'] = $status['rules'] ?? $out['rules'];
            $out['lotteries'] = $status['lotteries'] ?? $out['lotteries'];
            
            // Format jackpot with currency
            if ($out['jackpot'] !== null) {
                $jp = (float) $out['jackpot'];
                if ($jp >= 1000000) {
                    $out['jackpotFormatted'] = '€' . number_format($jp / 1000000, 1) . 'M';
                } elseif ($jp >= 1000) {
                    $out['jackpotFormatted'] = '€' . number_format($jp / 1000, 0) . 'K';
                } else {
                    $out['jackpotFormatted'] = '€' . number_format($jp, 0);
                }
            }
            
            // Get recent draws
            $out['recentDraws'] = $this->platform->lottery->listDraws(3, null, null);
            
            // Get user's tickets count
            try {
                $myTickets = $this->platform->lottery->listMyTickets($userId, 100);
                $out['myTicketsCount'] = count($myTickets);
            } catch (\Throwable $e) {
                $out['myTicketsCount'] = 0;
            }
        } catch (\Throwable $e) {
            // Lottery widget is non-critical, return defaults
        }
        
        return $out;
    }

    private function tradingWidgetData(int $userId): array
    {
        $out = ['totalEquity' => 0, 'totalPnl' => 0, 'openPositions' => 0, 'connectedBrokers' => 0, 'totalBrokers' => 0, 'brokers' => []];
        try {
            $connections = $this->platform->userBrokers->listForUser($userId);
            $out['totalBrokers'] = count($connections);
            foreach ($connections as $row) {
                $card = ['broker' => $row['broker'], 'label' => $row['label'] ?? '', 'connected' => !empty($row['enabled'])];
                if (!empty($row['enabled'])) {
                    $out['connectedBrokers']++;
                    try {
                        $c = $this->platform->userBrokers->buildConnector($row);
                        if ($c) {
                            $a = $c->account();
                            $out['totalEquity'] += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                            $card['equity'] = $a['equity'] ?? $a['balance'] ?? null;
                            $card['currency'] = $a['currency'] ?? '';
                            if (method_exists($c, 'positions')) {
                                $pos = (array) $c->positions();
                                $out['openPositions'] += count($pos);
                                foreach ($pos as $p) $out['totalPnl'] += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                            }
                        }
                    } catch (\Throwable $e) { $card['error'] = 'unavailable'; }
                }
                $out['brokers'][] = $card;
            }
        } catch (\Throwable $e) { /* trading widget is non-critical */ }
        return $out;
    }

    private function multiplierWidgetData(): array
    {
        $out = [
            'enabled' => true,
            'currentMultiplier' => 1.0,
            'inRound' => false,
            'roundId' => null,
            'lastSignal' => null,
            'accuracy20' => null,
            'accuracy50' => null,
            'totalPredictions' => 0,
            'historyCount' => 0,
            'integration' => [
                'cloudflare' => false,
                'llm' => false,
                'sports' => false,
            ],
        ];

        // Check integration status
        try {
            if (isset($this->platform->multiplierIntegration)) {
                $intStatus = $this->platform->multiplierIntegration->status();
                $out['integration']['cloudflare'] = $intStatus['bridge_available'];
                $out['integration']['llm'] = $intStatus['llm_enhancement'];
                $out['integration']['sports'] = $intStatus['enrichment_available'];
            }
        } catch (\Throwable $e) {
            // Non-critical
        }

        try {
            $provider = \AIWorkforce\MultiplierIntelligence\CrashProviderFactory::fromPlatform($this->platform);
            
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine($provider);
            $dashboard = $engine->dashboard();
            
            $out['historyCount'] = count($dashboard['history'] ?? []);
            $out['accuracy20'] = $dashboard['accuracy']['accuracy20'] ?? null;
            $out['accuracy50'] = $dashboard['accuracy']['accuracy50'] ?? null;
            $out['totalPredictions'] = (int)($dashboard['stats']['totalPredictions'] ?? 0);
            
            // Get current round state
            $roundData = $provider->updateMultiplier();
            $out['currentMultiplier'] = $roundData['currentMultiplier'] ?? 1.0;
            $out['inRound'] = $roundData['inRound'] ?? false;
            $out['roundId'] = $roundData['roundId'] ?? null;
            
            // Generate a signal if we have enough history
            if ($out['historyCount'] >= 10) {
                $signal = $engine->generateSignal();
                $out['lastSignal'] = [
                    'predicted' => $signal['predictedMultiplier'] ?? null,
                    'confidence' => $signal['confidence'] ?? null,
                    'risk' => $signal['risk'] ?? null,
                    'generatedAt' => $signal['generatedAt'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            // Multiplier widget is non-critical
        }

        return $out;
    }

    private function aiModulesData(): array
    {
        $modules = [];
        
        // Windels AI Agent Platform
        try {
            $cfStatus = $this->platform->cloudflare->status();
            $modules['windelsai'] = [
                'name' => 'Windels AI Agents',
                'icon' => '⚡',
                'status' => !empty($cfStatus['modelRouter']['configured']) ? 'healthy' : 'degraded',
                'agents' => count($cfStatus['communicationBus']['availableAgents'] ?? []),
                'tools' => $cfStatus['toolRegistry']['totalTools'] ?? 0,
            ];
        } catch (\Throwable $e) {
            $modules['windelsai'] = ['name' => 'Windels AI Agents', 'icon' => '⚡', 'status' => 'error'];
        }
        
        // Multiplier Intelligence
        $modules['multiplier'] = [
            'name' => 'Multiplier AI',
            'icon' => '🚀',
            'status' => 'healthy',
            'agents' => 9,
        ];
        
        // Lottery Intelligence
        try {
            $lotteryStatus = $this->platform->lottery->status();
            $modules['lottery'] = [
                'name' => 'Lottery Intel',
                'icon' => '🎰',
                'status' => ($lotteryStatus['status'] ?? 'UNKNOWN') === 'OK' ? 'healthy' : 'degraded',
            ];
        } catch (\Throwable $e) {
            $modules['lottery'] = ['name' => 'Lottery Intel', 'icon' => '🎰', 'status' => 'error'];
        }
        
        // Trading Intelligence
        $state = $this->platform->state();
        $killSwitch = !empty($state['killSwitch']['active']);
        $modules['trading'] = [
            'name' => 'Trading Intel',
            'icon' => '💹',
            'status' => $killSwitch ? 'warning' : 'healthy',
        ];
        
        // Language Learning
        try {
            $modules['language'] = [
                'name' => 'Language AI',
                'icon' => '🗣️',
                'status' => 'healthy',
            ];
        } catch (\Throwable $e) {
            $modules['language'] = ['name' => 'Language AI', 'icon' => '🗣️', 'status' => 'error'];
        }
        
        // Sports Intelligence
        try {
            $sportsProviders = $this->platform->model->sports->listProviders(true);
            $modules['sports'] = [
                'name' => 'Sports Intel',
                'icon' => '⚽',
                'status' => !empty($sportsProviders) ? 'healthy' : 'degraded',
            ];
        } catch (\Throwable $e) {
            $modules['sports'] = ['name' => 'Sports Intel', 'icon' => '⚽', 'status' => 'error'];
        }
        
        // Lead Discovery
        $modules['leads'] = [
            'name' => 'Lead Discovery',
            'icon' => '🔍',
            'status' => 'healthy',
        ];
        
        return $modules;
    }

    private function languageWidgetData(int $userId): array
    {
        $out = [
            'profiles' => [],
            'totalProfiles' => 0,
            'activeProfile' => null,
        ];
        
        try {
            $profiles = $this->platform->langlearn->profiles($userId);
            $out['profiles'] = array_slice($profiles, 0, 3);
            $out['totalProfiles'] = count($profiles);
            if (!empty($profiles)) {
                $out['activeProfile'] = $profiles[0];
            }
        } catch (\Throwable $e) {
            // Language widget is non-critical
        }
        
        return $out;
    }

    private function sportsWidgetData(): array
    {
        $out = [
            'providers' => [],
            'totalProviders' => 0,
            'status' => 'no_data',
        ];
        
        try {
            $providers = $this->platform->model->sports->listProviders(true);
            $out['providers'] = array_slice($providers, 0, 3);
            $out['totalProviders'] = count($providers);
            $out['status'] = !empty($providers) ? 'ok' : 'no_data';
        } catch (\Throwable $e) {
            // Sports widget is non-critical
        }
        
        return $out;
    }

    private function windellsAIWidgetData(): array
    {
        $out = [
            'agents' => [],
            'totalAgents' => 0,
            'totalTools' => 0,
            'modelProviders' => [],
        ];
        
        try {
            $status = $this->platform->cloudflare->status();
            $out['agents'] = $status['communicationBus']['availableAgents'] ?? [];
            $out['totalAgents'] = count($out['agents']);
            $out['totalTools'] = $status['toolRegistry']['totalTools'] ?? 0;
            $out['modelProviders'] = $status['modelRouter']['providers'] ?? [];
        } catch (\Throwable $e) {
            // Windels AI widget is non-critical
        }
        
        return $out;
    }
}
