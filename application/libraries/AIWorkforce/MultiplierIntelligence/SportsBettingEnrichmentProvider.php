<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Sports Betting Enrichment Provider
 * 
 * Connects the Multiplier Intelligence system with Sports Intelligence data:
 * 
 * While the Sports Intelligence system (api-football, thesportsdb, sportmonks) is
 * designed for traditional sports (football, basketball, etc.) and NOT for crash games,
 * it CAN provide valuable enrichment data for multiplier predictions:
 * 
 * 1. Betting Market Sentiment: Sports betting odds reflect overall market risk appetite
 *    - When sports bettors are aggressive (high stakes on favorites), crash game
 *      players may also be more aggressive
 *    - When sports markets are volatile, crash game patterns may shift
 * 
 * 2. Event Timing: Major sporting events correlate with crash game activity
 *    - During football matches, crash game volume increases
 *    - Halftime/intermission periods show different patterns
 * 
 * 3. Market Correlation: Sports betting odds can indicate general gambling sentiment
 *    - High-profile match days = more crash game players = different distribution
 * 
 * Architecture:
 *   Sports Intelligence (api-football/thesportsdb/sportmonks)
 *       ↓ (fixtures, odds, results)
 *   SportsBettingEnrichmentProvider
 *       ↓ (sentiment, timing, correlation signals)
 *   Multiplier Intelligence Engine
 *       ↓ (enriched predictions)
 *   Crash Game Signal
 * 
 * Note: This does NOT make Sports Intelligence predict crash games.
 * It uses sports DATA as one additional signal for the multiplier analysis.
 */
class SportsBettingEnrichmentProvider
{
    /** @var object|null Sports Intelligence service */
    private $sportsIntel;
    
    /** @var array Cached enrichment data */
    private array $cache = [];
    
    /** @var int Cache TTL in seconds */
    private int $cacheTtl = 300; // 5 minutes
    
    /** @var float Weight of sports enrichment in final prediction (0-1) */
    private float $enrichmentWeight = 0.15; // 15% max influence
    
    public function __construct($sportsIntel = null)
    {
        $this->sportsIntel = $sportsIntel;
    }
    
    /**
     * Get enrichment signals from sports betting data
     * 
     * @return array Enrichment signals that can influence multiplier predictions
     */
    public function getEnrichmentSignals(): array
    {
        $cacheKey = 'sports_enrichment_' . date('YmdHi');
        if (isset($this->cache[$cacheKey]) && (time() - $this->cache[$cacheKey]['time']) < $this->cacheTtl) {
            return $this->cache[$cacheKey]['data'];
        }
        
        $signals = [
            'market_sentiment' => 'neutral',  // bullish, bearish, neutral
            'sentiment_score' => 0.5,          // 0.0 (very bearish) to 1.0 (very bullish)
            'event_activity' => 'normal',       // high, normal, low
            'major_event' => false,             // Is there a major sporting event now?
            'betting_volume_indicator' => 'normal', // high, normal, low
            'volatility_signal' => 'normal',    // high, normal, low
            'data_available' => false,
            'source' => 'none',
        ];
        
        if ($this->sportsIntel === null) {
            return $signals;
        }
        
        try {
            $signals = $this->extractSignalsFromSports($signals);
            $signals['data_available'] = true;
        } catch (\Throwable $e) {
            $signals['error'] = $e->getMessage();
        }
        
        $this->cache[$cacheKey] = ['data' => $signals, 'time' => time()];
        return $signals;
    }
    
    /**
     * Extract signals from sports intelligence data
     */
    private function extractSignalsFromSports(array $signals): array
    {
        // Get current fixtures and odds from Sports Intelligence
        $fixtures = $this->getCurrentFixtures();
        if (empty($fixtures)) {
            return $signals;
        }
        
        $signals['source'] = 'sports_intelligence';
        $signals['active_fixtures'] = count($fixtures);
        
        // 1. Event Activity Level
        $now = time();
        $liveCount = 0;
        $upcomingCount = 0;
        foreach ($fixtures as $f) {
            $status = $f['status'] ?? $f['fixture_status'] ?? '';
            if (in_array(strtoupper($status), ['LIVE', 'IN_PROGRESS', 'HT'])) {
                $liveCount++;
            } else {
                $upcomingCount++;
            }
        }
        
        if ($liveCount > 5) {
            $signals['event_activity'] = 'high';
            $signals['betting_volume_indicator'] = 'high';
        } elseif ($liveCount > 0 || $upcomingCount > 10) {
            $signals['event_activity'] = 'normal';
            $signals['betting_volume_indicator'] = 'normal';
        } else {
            $signals['event_activity'] = 'low';
            $signals['betting_volume_indicator'] = 'low';
        }
        
        // 2. Major Event Detection
        $majorLeagues = ['premier league', 'la liga', 'champions league', 'serie a', 'bundesliga', 'world cup'];
        foreach ($fixtures as $f) {
            $league = strtolower($f['league'] ?? $f['league_name'] ?? '');
            foreach ($majorLeagues as $ml) {
                if (strpos($league, $ml) !== false) {
                    $signals['major_event'] = true;
                    $signals['betting_volume_indicator'] = 'high';
                    break 2;
                }
            }
        }
        
        // 3. Market Sentiment from Odds
        $oddsData = $this->getOddsSummary($fixtures);
        if (!empty($oddsData)) {
            $signals['sentiment_score'] = $oddsData['sentiment'];
            $signals['volatility_signal'] = $oddsData['volatility'];
            
            if ($oddsData['sentiment'] > 0.65) {
                $signals['market_sentiment'] = 'bullish';
            } elseif ($oddsData['sentiment'] < 0.35) {
                $signals['market_sentiment'] = 'bearish';
            } else {
                $signals['market_sentiment'] = 'neutral';
            }
        }
        
        return $signals;
    }
    
    /**
     * Get current fixtures from Sports Intelligence
     */
    private function getCurrentFixtures(): array
    {
        if ($this->sportsIntel === null) return [];
        
        try {
            // Use the Sports Intelligence service to get fixtures
            // This works with api-football, thesportsdb, sportmonks
            if (method_exists($this->sportsIntel, 'liveFixtures')) {
                return $this->sportsIntel->liveFixtures(20);
            }
            
            if (method_exists($this->sportsIntel, 'upcomingFixtures')) {
                return $this->sportsIntel->upcomingFixtures([
                    'limit' => 20,
                    'sport' => 'football',
                ]);
            }
        } catch (\Throwable $e) {
            // Non-critical, return empty
        }
        
        return [];
    }
    
    /**
     * Get odds summary from fixtures
     */
    private function getOddsSummary(array $fixtures): array
    {
        if (empty($fixtures) || $this->sportsIntel === null) return [];
        
        $totalOdds = 0;
        $count = 0;
        $oddsVariance = [];
        
        foreach (array_slice($fixtures, 0, 10) as $f) {
            try {
                $fixtureId = $f['id'] ?? $f['fixture_id'] ?? null;
                if (!$fixtureId) continue;
                
                $odds = null;
                if (method_exists($this->sportsIntel, 'getOdds')) {
                    $odds = $this->sportsIntel->getOdds($fixtureId);
                }
                
                if (!empty($odds)) {
                    // Extract implied probabilities
                    $homeProb = $odds['home']['implied_probability'] ?? $odds['home_prob'] ?? null;
                    $awayProb = $odds['away']['implied_probability'] ?? $odds['away_prob'] ?? null;
                    
                    if ($homeProb !== null && $awayProb !== null) {
                        // Favorite strength indicates market confidence
                        $favoriteProb = max($homeProb, $awayProb);
                        $totalOdds += $favoriteProb;
                        $count++;
                        $oddsVariance[] = abs($homeProb - $awayProb);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        if ($count === 0) return [];
        
        $avgFavoriteProb = $totalOdds / $count;
        $avgVariance = count($oddsVariance) > 0 ? array_sum($oddsVariance) / count($oddsVariance) : 0;
        
        return [
            'sentiment' => round($avgFavoriteProb, 3),
            'volatility' => $avgVariance > 0.4 ? 'high' : ($avgVariance > 0.2 ? 'normal' : 'low'),
            'avg_favorite_prob' => round($avgFavoriteProb, 3),
            'odds_variability' => round($avgVariance, 3),
        ];
    }
    
    /**
     * Apply sports enrichment to a multiplier prediction
     * 
     * @param array $prediction Base prediction from statistical agents
     * @return array Enriched prediction
     */
    public function enrichPrediction(array $prediction): array
    {
        $signals = $this->getEnrichmentSignals();
        
        if (!$signals['data_available']) {
            $prediction['sports_enrichment'] = ['applied' => false, 'reason' => 'no_data'];
            return $prediction;
        }
        
        $originalPrediction = $prediction['predictedMultiplier'] ?? $prediction['estimate'] ?? 2.0;
        $adjustment = 0;
        $reasons = [];
        
        // 1. Event Activity Impact
        if ($signals['event_activity'] === 'high') {
            // High activity = more players = slightly lower median (more variance)
            $adjustment -= 0.1;
            $reasons[] = 'High sports activity → more players → conservative estimate';
        } elseif ($signals['event_activity'] === 'low') {
            // Low activity = fewer players = can be slightly more optimistic
            $adjustment += 0.05;
            $reasons[] = 'Low sports activity → fewer players → slight upward bias';
        }
        
        // 2. Major Event Impact
        if ($signals['major_event']) {
            $adjustment -= 0.15;
            $reasons[] = 'Major sporting event → peak traffic → house edge more pronounced';
        }
        
        // 3. Market Sentiment Impact
        if ($signals['market_sentiment'] === 'bullish') {
            // Bullish betting = risk-seeking behavior = crash game may see more crashes
            $adjustment -= 0.05;
            $reasons[] = 'Bullish betting sentiment → risk-seeking → expect more low crashes';
        } elseif ($signals['market_sentiment'] === 'bearish') {
            $adjustment += 0.05;
            $reasons[] = 'Bearish betting sentiment → conservative play → slightly higher median';
        }
        
        // 4. Volatility Impact
        if ($signals['volatility_signal'] === 'high') {
            // High volatility in sports odds = unpredictable market
            $reasons[] = 'High odds volatility → reduce confidence';
            $prediction['confidence'] = ($prediction['confidence'] ?? 0.5) * 0.9;
        }
        
        // Apply adjustment (capped)
        $maxAdjustment = $originalPrediction * $this->enrichmentWeight;
        $adjustment = max(-$maxAdjustment, min($maxAdjustment, $adjustment));
        
        $enrichedPrediction = round(max(1.01, $originalPrediction + $adjustment), 2);
        
        $prediction['predictedMultiplier'] = $enrichedPrediction;
        $prediction['sports_enrichment'] = [
            'applied' => true,
            'original' => $originalPrediction,
            'adjustment' => round($adjustment, 3),
            'enriched' => $enrichedPrediction,
            'weight' => $this->enrichmentWeight,
            'reasons' => $reasons,
            'signals' => [
                'market_sentiment' => $signals['market_sentiment'],
                'event_activity' => $signals['event_activity'],
                'major_event' => $signals['major_event'],
                'volatility' => $signals['volatility_signal'],
            ],
        ];
        
        return $prediction;
    }
    
    /**
     * Set the enrichment weight (how much sports data influences predictions)
     */
    public function setEnrichmentWeight(float $weight): void
    {
        $this->enrichmentWeight = max(0.0, min(0.5, $weight));
    }
    
    /**
     * Get enrichment weight
     */
    public function getEnrichmentWeight(): float
    {
        return $this->enrichmentWeight;
    }
    
    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
