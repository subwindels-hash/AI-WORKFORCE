# OpenAI Integration

WINDELS AI Workforce uses the **OpenAI REST API** (and any OpenAI-compatible
endpoint) as its AI inference layer: LLM chat, structured output, embeddings,
image generation, speech recognition (Whisper), text-to-speech and content
moderation. Everything is driven by the same credential store and
**Admin → API** provider management used by the other integrations.

## Configuration

Navigate to **Admin → API Management → Create New Provider** and pick the
**OpenAI (or OpenAI-compatible) API** driver, then fill in:

| Field | Required | Notes |
|---|---|---|
| Base URL | No | Defaults to `https://api.openai.com/v1`. Accepts a pasted `/v1` or `/v1/chat/completions` URL — it is normalized automatically. |
| API Key | Yes | `sk-…`. Stored encrypted at rest and masked everywhere it is rendered. |
| Model | No | e.g. `gpt-4o-mini` (chat/LLM), `text-embedding-3-small` (embeddings), `dall-e-3` (images). |
| Organization ID | No | Optional `OpenAI-Organization` header. |
| Project ID | No | Optional `OpenAI-Project` header. |

The same provider can be assigned to any of these services (primary or
fallback): `llm`, `language_ai`, `translation`, `stt`, `tts`, `pronunciation`,
`text_embeddings`, `image_generation`, `summarization`, `classification` and
`moderation`.

**Test Connection** probes `GET /models` with the key and reports an actionable
message: it distinguishes a valid key, a rejected key (401/403), and a wrong
base URL / blocked egress.

## Capabilities

| Capability | OpenAI endpoint | Class / entry point |
|---|---|---|
| Chat completion | `/v1/chat/completions` | `ApiProviders::openaiChat()` · `OpenAIProvider::chat()` |
| Responses API | `/v1/responses` | `ApiProviders::openaiResponses()` · `OpenAIProvider::responses()` |
| Structured output (JSON schema) | `/v1/chat/completions` + `response_format` | `ApiProviders::openaiStructured()` · `OpenAIProvider::structuredJson()` |
| JSON-object output | `/v1/chat/completions` + `response_format` | `ApiProviders::openaiJsonObject()` · `OpenAIProvider::jsonObject()` |
| Embeddings | `/v1/embeddings` | `ApiProviders::openaiEmbedding()` · `OpenAIProvider::embedding()` |
| Image generation | `/v1/images/generations` | `ApiProviders::openaiImage()` · `OpenAIProvider::image()` |
| Moderation | `/v1/moderations` | `ApiProviders::openaiModeration()` · `OpenAIProvider::moderate()` |
| Model listing | `/v1/models` | `ApiProviders::openaiModels()` · `OpenAIProvider::models()` |
| Speech-to-text (Whisper) | `/v1/audio/transcriptions` | `SpeechToTextProvider` |
| Text-to-speech | `/v1/audio/speech` | `TextToSpeechProvider` |

### Object API — `OpenAIProvider`

`application/libraries/AIWorkforce/Providers/OpenAIProvider.php` accepts the
same hydrated config `ApiProviders::resolve()` returns, or a plain array:

```php
use AIWorkforce\Providers\OpenAIProvider;

$openai = new OpenAIProvider([
    'api_key' => getenv('OPENAI_API_KEY'),
    'base_url' => 'https://api.openai.com/v1',
    'model' => 'gpt-4o-mini',
]);

// Chat
$reply = $openai->chat([['role' => 'user', 'content' => 'Summarize this quarter']]);
echo $reply['content'];

// Responses API (the primary OpenAI API)
$reply = $openai->responses('Write a one-sentence bedtime story.', [
    'instructions' => 'Be concise.',
]);

// Structured output — guarantees a JSON object matching the schema
$analysis = $openai->structuredJson(
    [['role' => 'user', 'content' => 'Classify the supplied facts.']],
    [
        'type' => 'object',
        'properties' => [
            'sentiment' => ['type' => 'string', 'enum' => ['bullish', 'bearish', 'neutral']],
            'confidence' => ['type' => 'number'],
        ],
        'required' => ['sentiment', 'confidence'],
    ],
    ['schema_name' => 'market_sentiment']
);

// Embeddings
$vectors = $openai->embedding('BTC breakout above resistance');

// Image generation (returns base64 when the model supports b64_json)
$image = $openai->image('A lighthouse at dusk', ['size' => '1024x1024']);

// Moderation
$screening = $openai->moderate('User-submitted message');
```

### Static API — `ApiProviders`

For callers that already hold a resolved provider config, the static helpers
keep the same idiom as the existing `openaiChat($cfg, …)`:

```php
use AIWorkforce\ApiProviders;

$cfg = ApiProviders::resolve('llm');            // primary llm provider config
$text = ApiProviders::openaiChat($cfg, $messages);
$vec  = ApiProviders::openaiEmbedding($cfg, 'text to embed');
$img  = ApiProviders::openaiImage($cfg, 'a prompt');
$mod  = ApiProviders::openaiModeration($cfg, 'text to screen');
```

## Error contract

Every method returns `['error' => '…']` on failure (missing key, missing model,
non-2xx response, unparseable reply) and **never throws** for an HTTP error.
`OpenAIProvider::lastError()` / `lastStatus()` expose the most recent failure.
The provider is fail-closed: with no key or model it refuses to call out.

## Security

- The API key is encrypted at rest (`ApiProviders::seal`) and masked in every
  view, the API JSON, audit output and user-facing errors.
- Requests are sent over HTTPS with certificate verification; the shared
  `ApiProviders::http()` transport is reused so connection tests and runtime
  calls behave identically.
- OpenAI is used for inference only. It never receives broker credentials,
  does not execute trades, approve tickets, or bypass the execution supervisor,
  risk engine, kill switch, or lottery compliance boundary.

## Not covered

These OpenAI surfaces are intentionally out of scope for this PHP/CodeIgniter
deployment — they are long-running or asynchronous services that do not fit the
request/response provider model:

- **Assistants API**, **Files** and **fine-tuning jobs** (persistent async jobs)
- **Realtime API** (WebSocket, voice/video streaming)
- **Batch API** (async bulk workloads)
- **Evals / prompt optimization**

They would belong behind dedicated workers/jobs rather than the synchronous
provider layer, and can be added the same way if they are ever needed.
