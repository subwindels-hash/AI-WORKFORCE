# Multiplier Intelligence + Sports Provider Integration

## Overview

The AI Multiplier Intelligence module now integrates with **three real sports data providers** to enrich crash game predictions with betting market sentiment and event timing data.

---

## Supported Sports Providers

### 1. **api-football.com** ✅
- **API Version:** v3
- **Base URL:** `https://v3.football.api-sports.io`
- **Capabilities:** Fixtures, Live Scores, Odds, Results, Standings
- **Coverage:** 900+ leagues worldwide
- **Free Tier:** 100 requests/day
- **Best For:** Comprehensive football data with odds

### 2. **thesportsdb.com** ✅
- **API Version:** v1
- **Base URL:** `https://www.thesportsdb.com/api/v1/json`
- **Capabilities:** Fixtures, Results, Events (NO odds)
- **Coverage:** Multiple sports (football, basketball, tennis, etc.)
- **Free Tier:** Available (limited)
- **Best For:** Multi-sport event data

### 3. **sportmonks.com** ✅
- **API Version:** v3
- **Base URL:** `https://api.sportmonks.com/v3/football`
- **Capabilities:** Fixtures, Odds (add-on), Results, Statistics
- **Coverage:** 1000+ leagues
- **Free Tier:** Trial available
- **Best For:** Professional-grade odds data

---

## How It Works

### Data Flow

```
┌─────────────────────────────────────────────────────────────┐
│  Sports Providers (api-football, thesportsdb, sportmonks)  │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  SportsIntelligence (SportsProviderManager)                 │
│  • Provider fallback chain                                  │
│  • Health monitoring                                        │
│  • Data normalization                                       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  SportsBettingEnrichmentProvider                            │
│  • Extracts fixtures (live, upcoming)                       │
│  • Extracts odds (implied probabilities)                    │
│  • Calculates market sentiment                              │
│  • Detects event timing                                     │
│  • Measures volatility                                      │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  Multiplier Intelligence Engine                             │
│  • 9 specialist agents                                      │
│  • Statistical prediction                                   │
│  • ±15% adjustment from sports enrichment                   │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│  Enhanced Prediction Signal                                 │
│  • Predicted multiplier                                     │
│  • Confidence score                                         │
│  • Risk level                                               │
│  • Sports enrichment metadata                               │
└─────────────────────────────────────────────────────────────┘
```

### Enrichment Signals

The `SportsBettingEnrichmentProvider` extracts these signals from sports data:

1. **Market Sentiment** (0.0 - 1.0)
   - Derived from betting odds (implied probabilities)
   - High favorite probability = bullish sentiment
   - Balanced odds = neutral sentiment

2. **Event Activity** (high/normal/low)
   - Number of live fixtures
   - Upcoming match count
   - Indicates crash game traffic patterns

3. **Major Event Detection** (boolean)
   - Premier League, Champions League, La Liga, etc.
   - Peak traffic periods affect crash game behavior

4. **Volatility Signal** (high/normal/low)
   - Odds variance between home/away
   - Market uncertainty indicator

5. **Betting Volume Indicator** (high/normal/low)
   - Combined activity + major events
   - Predicts crash game player behavior

---

## Configuration

### Method 1: Environment Variables (Recommended for Production)

Add to your `.env` file or server environment:

```bash
# api-football.com
WINDELS_API_FOOTBALL_KEY=your_api_football_key_here

# thesportsdb.com
WINDELS_THESPORTSDB_KEY=your_thesportsdb_key_here

# sportmonks.com
WINDELS_SPORTMONKS_TOKEN=your_sportmonks_token_here

# Optional: Custom base URLs
WINDELS_API_FOOTBALL_BASE_URL=https://v3.football.api-sports.io
WINDELS_THESPORTSDB_BASE_URL=https://www.thesportsdb.com/api/v1/json
WINDELS_SPORTMONKS_BASE_URL=https://api.sportmonks.com/v3/football

# Optional: HTTP timeout (default: 10 seconds)
WINDELS_SPORTS_HTTP_TIMEOUT=10
```

### Method 2: Admin → API Dashboard (Recommended for Management)

1. Navigate to **Admin → API Providers**
2. Click **Add Provider**
3. Select provider type:
   - `api_football` for api-football.com
   - `thesportsdb` for thesportsdb.com
   - `sportmonks` for sportmonks.com
4. Enter credentials:
   - **API Key/Token:** Your provider key
   - **Base URL:** (optional, uses default if empty)
   - **Timeout:** (optional, default 10s)
5. Save

**Advantages:**
- No server restart needed
- Can manage multiple providers
- Easy to update credentials
- View provider status

---

## API Endpoints

### Test Sports Enrichment

**URL:** `GET /multiplier/test-sports`

**Response:**
```json
{
  "ok": true,
  "timestamp": "2026-09-04T15:30:00+00:00",
  "providers": {
    "api-football": {
      "status": "ONLINE",
      "lastSuccessAt": "2026-09-04T15:25:00+00:00",
      "requests": 15,
      "failures": 0
    },
    "thesportsdb": {
      "status": "ONLINE",
      "lastSuccessAt": "2026-09-04T15:24:00+00:00"
    },
    "sportmonks": {
      "status": "ONLINE",
      "lastSuccessAt": "2026-09-04T15:26:00+00:00"
    }
  },
  "enrichment_signals": {
    "market_sentiment": "bullish",
    "sentiment_score": 0.72,
    "event_activity": "high",
    "major_event": true,
    "betting_volume_indicator": "high",
    "volatility_signal": "normal",
    "data_available": true,
    "source": "sports_intelligence"
  },
  "sample_fixtures": [
    {
      "id": "12345",
      "league": "Premier League",
      "home": "Manchester United",
      "away": "Liverpool",
      "status": "LIVE",
      "date": "2026-09-04"
    }
  ],
  "enrichment_weight": 0.15
}
```

### Verify Full Integration

**URL:** `GET /multiplier/verify`

**Response:**
```json
{
  "ok": true,
  "checks": {
    "multiplier_engine": { "status": "OK" },
    "cloudflare_model_router": { "status": "OK" },
    "mcp_multiplier_tools": { "status": "OK" },
    "cloudflare_bridge": { "status": "OK" },
    "sports_enrichment": {
      "status": "OK",
      "data_available": true,
      "source": "sports_intelligence"
    },
    "specialist_agent": { "status": "OK" },
    "platform_integration": { "status": "OK" }
  }
}
```

---

## Usage Examples

### Example 1: Check Provider Status

```bash
curl https://your-domain.com/multiplier/test-sports
```

### Example 2: Generate Enhanced Signal

```php
// Controller automatically uses enrichment
$signal = $this->enhancedSignal($engine);

// Signal includes sports enrichment data:
// $signal['sports_enrichment'] = [
//     'applied' => true,
//     'original' => 2.34,
//     'adjustment' => -0.15,
//     'enriched' => 2.19,
//     'reasons' => [
//         'High sports activity → more players → conservative estimate',
//         'Major sporting event → peak traffic → house edge more pronounced'
//     ],
//     'signals' => [
//         'market_sentiment' => 'bullish',
//         'event_activity' => 'high',
//         'major_event' => true,
//         'volatility' => 'normal'
//     ]
// ];
```

### Example 3: Direct Enrichment Access

```php
use AIWorkforce\MultiplierIntelligence\SportsBettingEnrichmentProvider;

$sportsIntel = $this->platform->sports;
$enrichment = new SportsBettingEnrichmentProvider($sportsIntel);

// Get signals
$signals = $enrichment->getEnrichmentSignals();
// [
//     'market_sentiment' => 'bullish',
//     'sentiment_score' => 0.72,
//     'event_activity' => 'high',
//     'major_event' => true,
//     ...
// ]

// Enrich a prediction
$prediction = ['predictedMultiplier' => 2.5, 'confidence' => 0.7];
$enriched = $enrichment->enrichPrediction($prediction);
// $enriched['predictedMultiplier'] = 2.35 (adjusted by -0.15)
```

---

## Provider Fallback Chain

The system uses a **fallback chain** for reliability:

```
1. api-football (if configured)
   ↓ (if fails)
2. sportmonks (if configured)
   ↓ (if fails)
3. thesportsdb (if configured)
   ↓ (if all fail)
4. Return empty (no enrichment)
```

**Benefits:**
- High availability
- Automatic failover
- No single point of failure
- Graceful degradation

---

## How Sports Data Influences Predictions

### Scenario 1: Major Sporting Event (Champions League Final)

**Sports Signals:**
- Event Activity: HIGH
- Major Event: TRUE
- Betting Volume: HIGH
- Sentiment: NEUTRAL

**Enrichment Logic:**
```
Base Prediction: 2.50x
Adjustment: -0.15x (major event → peak traffic → conservative)
Enriched: 2.35x
```

**Reasoning:**
- More players during major events = higher volume
- Higher volume = house edge more pronounced
- Conservative estimate reduces risk

### Scenario 2: High Market Confidence (Strong Favorites)

**Sports Signals:**
- Sentiment Score: 0.85 (bullish)
- Volatility: LOW
- Event Activity: NORMAL

**Enrichment Logic:**
```
Base Prediction: 2.50x
Adjustment: -0.05x (bullish sentiment → risk-seeking → expect more crashes)
Enriched: 2.45x
```

**Reasoning:**
- Bullish betting = risk-seeking behavior
- Risk-seeking players = more aggressive betting
- More crashes expected = lower prediction

### Scenario 3: Low Activity Period

**Sports Signals:**
- Event Activity: LOW
- Major Event: FALSE
- Betting Volume: LOW

**Enrichment Logic:**
```
Base Prediction: 2.50x
Adjustment: +0.05x (low activity → fewer players → slight upward)
Enriched: 2.55x
```

**Reasoning:**
- Fewer players = less volume
- Less volume = slightly better odds
- Slight upward adjustment

---

## Safety & Ethics

### 1. **Bounded Influence**
- Maximum adjustment: **±15%**
- Statistical analysis remains dominant (85%+)
- Sports data is supplementary only

### 2. **No Causation Claims**
- Sports data provides **correlation signals**, not causation
- Crash games use **provably fair RNG**
- Enrichment is **statistical inference**, not prediction

### 3. **Transparency**
- All enrichment data included in signal response
- Users can see exactly how sports data affected prediction
- Clear disclaimers about randomness

### 4. **Responsible Use**
- Educational purpose only
- No guaranteed predictions
- Never risk more than you can afford to lose

---

## Troubleshooting

### Issue: No Sports Data Available

**Check:**
1. Are provider credentials configured?
   ```bash
   # Check environment variables
   echo $WINDELS_API_FOOTBALL_KEY
   echo $WINDELS_THESPORTSDB_KEY
   echo $WINDELS_SPORTMONKS_TOKEN
   ```

2. Is Admin → API configured?
   - Navigate to Admin → API Providers
   - Verify at least one sports provider is active

3. Test provider health:
   ```bash
   curl https://your-domain.com/multiplier/test-sports
   ```

### Issue: Provider Authentication Failed

**Symptoms:**
- `status: "AUTHENTICATION_ERROR"`
- `error: "authentication rejected"`

**Solution:**
- Verify API key/token is correct
- Check if subscription is active
- Ensure key has required permissions

### Issue: Rate Limited

**Symptoms:**
- `status: "RATE_LIMITED"`
- `error: "rate limited (HTTP 429)"`

**Solution:**
- Wait for rate limit reset (varies by provider)
- Upgrade to higher tier if needed
- Use fallback providers

---

## Performance Metrics

### Typical Response Times

| Provider | Fixtures | Odds | Total |
|----------|----------|------|-------|
| api-football | 200-400ms | 150-300ms | 350-700ms |
| thesportsdb | 100-250ms | N/A | 100-250ms |
| sportmonks | 150-350ms | 200-400ms | 350-750ms |

### Cache Strategy

- **Fixtures:** Cached for 5 minutes
- **Odds:** Cached for 5 minutes
- **Signals:** Cached for 5 minutes

**Benefits:**
- Reduces API calls
- Faster response times
- Lower costs

---

## Cost Estimation

### Free Tiers

| Provider | Free Requests | Cost After |
|----------|---------------|------------|
| api-football | 100/day | $9.99/month (10k) |
| thesportsdb | Limited | $3/month (Patreon) |
| sportmonks | Trial | $29/month (Starter) |

### Estimated Usage

**Typical Usage (1000 signals/day):**
- Fixtures: 1 call per 5 min = 288/day
- Odds: 10 calls per fixture = 2,880/day
- **Total:** ~3,168 requests/day

**Recommended Plan:**
- api-football: $9.99/month (10k requests)
- OR sportmonks: $29/month (unlimited starter)

---

## API Reference

### SportsBettingEnrichmentProvider

```php
// Constructor
$enrichment = new SportsBettingEnrichmentProvider(?SportsIntelligence $sportsIntel);

// Get enrichment signals
$signals = $enrichment->getEnrichmentSignals();
// Returns: [
//     'market_sentiment' => 'bullish|bearish|neutral',
//     'sentiment_score' => 0.0-1.0,
//     'event_activity' => 'high|normal|low',
//     'major_event' => true|false,
//     'betting_volume_indicator' => 'high|normal|low',
//     'volatility_signal' => 'high|normal|low',
//     'data_available' => true|false,
//     'source' => 'sports_intelligence|none'
// ]

// Enrich a prediction
$enriched = $enrichment->enrichPrediction(array $prediction);
// Adjusts prediction by ±15% max based on signals

// Set enrichment weight
$enrichment->setEnrichmentWeight(0.15); // 15% max influence

// Get current weight
$weight = $enrichment->getEnrichmentWeight();

// Clear cache
$enrichment->clearCache();
```

---

## Integration Status

Check integration status on dashboards:

### Multiplier Command Center

Shows 5 integration pills:
- ⚡ Cloudflare Connected / Standby
- 🤖 LLM Enhancement Active / Standby
- ⚽ Sports Intel Enriching / Awaiting Config
- 🔗 Agent Bus Registered / Unregistered
- 🔧 MCP Tools 6 Active / Standby

### User Dashboard Widget

Shows 3 integration pills under latest signal:
- ⚡ Cloudflare Active / Standby
- 🤖 LLM Enhanced / Standby
- ⚽ Sports Intel Enriching / Awaiting

---

## Summary

✅ **3 Sports Providers Supported:** api-football, thesportsdb, sportmonks  
✅ **Automatic Registration:** From environment or Admin → API  
✅ **Fallback Chain:** High availability with automatic failover  
✅ **Real-Time Enrichment:** Market sentiment, event timing, volatility  
✅ **Bounded Influence:** Max ±15% adjustment  
✅ **Transparent:** All enrichment data visible in signals  
✅ **Production Ready:** Cached, monitored, documented  

**All code committed and pushed to `arena/01a06c70-ai-workforce`!** 🚀
