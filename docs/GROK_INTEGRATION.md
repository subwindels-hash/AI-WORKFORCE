# xAI Grok Integration

WINDELS AI Workforce integrates the **xAI Grok REST API** (via [console.x.ai](https://console.x.ai/)) as a first-class AI inference provider for LLM chat, multi-agent debate, structured reasoning, text translation, summarization, classification, vector embeddings, and language AI tutors. Everything is managed through the central encrypted credential store and **Admin → API Management**.

## Configuration

Navigate to **Admin → API Management → Create New Provider** (or select an existing provider row) and choose the **xAI Grok (x.ai)** driver:

| Field | Required | Notes |
|---|---|---|
| Base URL | No | Defaults to `https://api.x.ai/v1`. Accepts console URLs (e.g. `https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8`), markdown links, and naked domains (`x.ai`, `api.x.ai`). Normalizes automatically to `https://api.x.ai/v1`. |
| API Key | Yes | `xai-…` key generated in [xAI Console](https://console.x.ai/). Stored encrypted at rest via AES-256-GCM and masked everywhere in the UI and audit logs. |
| Model | No | Default: `grok-2-latest`. Supported models include `grok-2`, `grok-2-mini`, `grok-2-vision-1212`, `grok-beta`, `grok-3`, `grok-3-mini`. |
| Team ID | No | Optional xAI Team UUID (e.g. `97c85eb6-af25-4109-8554-4c6cbff12ee8`). Automatically extracted if a team console URL is pasted into Base URL. Sent via `X-Team-Id` header. |

### Assignable Services

A configured Grok provider can be assigned as **Primary** or **Fallback** for:
- `llm`: AI / LLM services (agent platform, chat assistant, market debate)
- `translation`: Language learning translation
- `language_ai`: Interactive AI language tutor
- `summarization`: News and transcript summarization
- `classification`: Sentiment and intent classification
- `text_embeddings`: Vector search and embeddings
- `moderation`: Content moderation

### Connection Test

**Test Connection** probes `GET https://api.x.ai/v1/models` using the configured API key and team ID:
- **Connected**: `Connected — the xAI API key lists models at https://api.x.ai/v1/models`
- **Key Rejected (401/403)**: `Connection failed: the xAI API key was rejected (HTTP 401). Check the key at https://console.x.ai/ and verify billing status.`
- **Network / DNS**: Reports actionable status and egress troubleshooting details.

## Capabilities

| Capability | xAI endpoint | Entry points |
|---|---|---|
| Chat completion | `/v1/chat/completions` | `ApiProviders::grokChat()` · `GrokProvider::chat()` · `ApiProviders::openaiChat()` |
| Responses API | `/v1/chat/completions` / `/v1/responses` | `ApiProviders::grokResponses()` · `GrokProvider::responses()` |
| Structured output (JSON schema) | `/v1/chat/completions` + `response_format` | `ApiProviders::grokStructured()` · `GrokProvider::structuredJson()` |
| JSON-object output | `/v1/chat/completions` + `response_format` | `ApiProviders::grokJsonObject()` · `GrokProvider::jsonObject()` |
| Embeddings | `/v1/embeddings` | `ApiProviders::grokEmbedding()` · `GrokProvider::embedding()` |
| Model listing | `/v1/models` | `ApiProviders::grokModels()` · `GrokProvider::models()` |

### Object API — `GrokProvider`

`application/libraries/AIWorkforce/Providers/GrokProvider.php` accepts the resolved configuration array or a plain array:

```php
use AIWorkforce\Providers\GrokProvider;

$grok = new GrokProvider([
    'api_key' => getenv('XAI_API_KEY'),
    'base_url' => 'https://api.x.ai/v1',
    'model' => 'grok-2-latest',
    'team_id' => '97c85eb6-af25-4109-8554-4c6cbff12ee8',
]);

// Chat completion
$reply = $grok->chat([
    ['role' => 'system', 'content' => 'You are a quantitative market analyst.'],
    ['role' => 'user', 'content' => 'Assess EUR/USD volatility regime.'],
]);
echo $reply['content'];

// Responses API / Quick single prompt
$reply = $grok->responses('Summarize the latest market developments.', [
    'instructions' => 'Keep output to 3 bullet points.',
]);

// Structured output with JSON schema
$analysis = $grok->structuredJson(
    [['role' => 'user', 'content' => 'Extract sentiment and key indicators from text.']],
    [
        'type' => 'object',
        'properties' => [
            'sentiment' => ['type' => 'string', 'enum' => ['bullish', 'bearish', 'neutral']],
            'confidence' => ['type' => 'number'],
            'drivers' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['sentiment', 'confidence', 'drivers'],
    ],
    ['schema_name' => 'market_regime']
);

// Text embeddings
$vectors = $grok->embedding('Breakout above 200 EMA with expanding volume');

// List available models
$models = $grok->models();
```

### Static API — `ApiProviders`

```php
use AIWorkforce\ApiProviders;

$cfg = ApiProviders::resolve('llm');            // Active LLM provider
$text = ApiProviders::grokChat($cfg, $messages);
$res  = ApiProviders::grokStructured($cfg, $messages, $schema);
$vec  = ApiProviders::grokEmbedding($cfg, 'search term');
```

Generic callers of `ApiProviders::openaiChat($cfg, ...)` automatically delegate to `GrokProvider` when `$cfg['driver'] === 'grok'`, preserving backwards compatibility across the entire platform.

## Model Router & Agent Platform

In `application/libraries/AIWorkforce/AgentPlatform/ModelRouter.php`, Grok is registered with high throughput defaults (`rpm: 120`, `tpm: 200,000`) and automatic failover across `grok-2-latest`, `grok-2`, `grok-2-mini`, `grok-2-vision-1212`, `grok-beta`, `grok-3`, and `grok-3-mini`.

## Security & Isolation

- **Encrypted at Rest**: API keys are encrypted with `ApiProviders::seal()` using AES-256-GCM.
- **Fail-Closed**: A missing key or unconfigured model fails immediately without network egress.
- **Inference Only**: Grok is used exclusively for analysis, reasoning, translation, and chat. It never receives broker keys or permissions to execute trades, bypass the kill switch, or override risk rules.
