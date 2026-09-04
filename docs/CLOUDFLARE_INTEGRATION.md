# Cloudflare Workers AI Integration

Comprehensive AI provider integration for the WINDELS AI Workforce platform using Cloudflare's edge AI infrastructure.

## 🚀 Overview

Cloudflare Workers AI provides serverless AI inference at the edge, offering low-latency access to a variety of AI models including:

- **Text Generation** (LLMs) — Llama 3.1, Mistral, Gemma, Phi-2
- **Text Embeddings** — BGE for semantic search and RAG
- **Image Generation** — Stable Diffusion XL, Dreamshaper
- **Speech Recognition** — Whisper transcription
- **Translation** — M2M100 (100+ languages)
- **Summarization** — BART text summarization
- **Classification** — Sentiment analysis, zero-shot classification
- **Object Detection** — DETR for computer vision

## 📦 Components

### 1. CloudflareProvider (`application/libraries/AIWorkforce/Providers/CloudflareProvider.php`)

Core provider class that interfaces with Cloudflare Workers AI API.

**Features:**
- Text generation with chat completion
- Text embeddings for semantic search
- Image generation with Stable Diffusion
- Speech recognition with Whisper
- Translation with M2M100
- Text summarization with BART
- Classification and sentiment analysis
- Object detection

**Usage:**
```php
$provider = new \AIWorkforce\Providers\CloudflareProvider([
    'account_id' => 'YOUR_ACCOUNT_ID',
    'token' => 'YOUR_API_TOKEN',
    'gateway' => 'optional-gateway-name',
]);

// Generate text
$result = $provider->generateText('Explain quantum computing', [
    'model' => '@cf/meta/llama-3.1-8b-instruct',
    'max_tokens' => 512,
]);

// Chat completion
$result = $provider->chat([
    ['role' => 'system', 'content' => 'You are a helpful assistant'],
    ['role' => 'user', 'content' => 'Hello!'],
]);

// Text embeddings
$embeddings = $provider->embedText('Sample text');

// Image generation
$image = $provider->generateImage('A beautiful sunset over mountains');
```

### 2. CloudflareAgentRuntime (`application/libraries/AIWorkforce/CloudflareAgentRuntime.php`)

Agent orchestration layer that integrates Cloudflare AI with the WINDELS agent system.

**Features:**
- Pre-configured AI agents (trading, language, lead discovery, lottery)
- Tool calling with approval workflow
- Multi-turn conversations
- Conversation memory
- Automatic tool detection and execution

**Registered Agents:**
- `trading_analyst` — Market analysis and trade proposals
- `language_tutor` — Language learning and translation
- `lead_scout` — Business discovery and lead enrichment
- `lottery_analyst` — Statistical lottery analysis

**Registered Tools:**
- `get_market_data` — Fetch market data
- `submit_trade_proposal` — Submit trades (requires approval)
- `translate` — Text translation
- `search_businesses` — Business discovery
- `get_draw_history` — Lottery draw history

**Usage:**
```php
$runtime = new \AIWorkforce\CloudflareAgentRuntime([
    'account_id' => 'YOUR_ACCOUNT_ID',
    'token' => 'YOUR_API_TOKEN',
    'tool_policy' => 'approval_required',
]);

// Execute an agent
$result = $runtime->execute('trading_analyst', 'Analyze EUR/USD for the last 24 hours', [
    'conversation' => [],
]);

echo $result['response'];
```

### 3. API Provider Integration

Cloudflare is registered as a driver in `ApiProviders.php` for multiple services:

- `llm` — AI/LLM services
- `translation` — Translation services
- `language_ai` — Language AI tutor
- `stt` — Speech-to-text (Whisper)
- `text_embeddings` — Vector embeddings
- `image_generation` — Image generation
- `summarization` — Text summarization
- `classification` — Text classification

## 🔧 Configuration

### Admin Dashboard

1. Navigate to **Admin → Manage AI Providers**
2. Click **Create New Provider**
3. Select **Cloudflare Workers AI** driver
4. Fill in configuration:
   - **Cloudflare Account ID** — From Cloudflare dashboard
   - **API Token** — Workers AI: Read permission
   - **Model** — Default model (e.g., `@cf/meta/llama-3.1-8b-instruct`)
   - **AI Gateway** (optional) — For observability and caching

### Environment Variables

Add to your `.env` file:
```
CLOUDFLARE_ACCOUNT_ID=your_account_id
CLOUDFLARE_API_TOKEN=your_api_token
CLOUDFLARE_AI_GATEWAY=optional_gateway
```

### Direct Configuration

```php
$config = [
    'account_id' => getenv('CLOUDFLARE_ACCOUNT_ID'),
    'token' => getenv('CLOUDFLARE_API_TOKEN'),
    'gateway' => getenv('CLOUDFLARE_AI_GATEWAY'),
    'base_url' => 'https://api.cloudflare.com/client/v4/accounts/{account}/ai/run',
    'timeout' => 30,
];
```

## 📊 Admin Dashboard Integration

The super admin dashboard displays comprehensive Cloudflare information:

- ✅ Configuration status
- ✅ Account ID (masked)
- ✅ Registered agents count
- ✅ Registered tools count
- ✅ Tool policy
- ✅ Available AI capabilities
- ✅ Quick links to documentation

Navigate to `/admin` to view the Cloudflare Agent Runtime section.

## 🎯 Use Cases

### 1. AI-Powered Trading Analysis

```php
$runtime = new \AIWorkforce\CloudflareAgentRuntime($config);
$result = $runtime->execute('trading_analyst', 'What is the sentiment for BTC/USD?');
```

### 2. Language Learning

```php
$provider = new \AIWorkforce\Providers\CloudflareProvider($config);
$translation = $provider->translate('Hello, how are you?', 'es', 'en');
```

### 3. Lead Discovery

```php
$runtime = new \AIWorkforce\CloudflareAgentRuntime($config);
$result = $runtime->execute('lead_scout', 'Find restaurants in San Francisco');
```

### 4. Lottery Analysis

```php
$runtime = new \AIWorkforce\CloudflareAgentRuntime($config);
$result = $runtime->execute('lottery_analyst', 'Analyze EuroMillions frequency patterns');
```

### 5. Image Generation

```php
$provider = new \AIWorkforce\Providers\CloudflareProvider($config);
$image = $provider->generateImage('A professional headshot', [
    'width' => 1024,
    'height' => 1024,
]);
```

## 🔒 Security & Compliance

- **API tokens are encrypted** and never exposed in logs or views
- **Tool approval workflow** for sensitive operations (trades, purchases)
- **RBAC integration** — Only authorized users can configure providers
- **Audit logging** — All API calls are logged
- **Rate limiting** — Via Cloudflare AI Gateway (optional)
- **Data residency** — Edge inference in Cloudflare's global network

## 📈 Performance

- **Low latency** — Edge inference, no cold starts
- **Global distribution** — 300+ locations worldwide
- **Caching** — AI Gateway supports response caching
- **Observability** — Built-in analytics and monitoring

## 🧪 Testing

Test your Cloudflare configuration:

```php
$provider = new \AIWorkforce\Providers\CloudflareProvider($config);
$status = $provider->status();

if ($status['configured']) {
    $result = $provider->generateText('Say hello');
    if (!isset($result['error'])) {
        echo "✅ Cloudflare is working!";
    }
}
```

Or use the admin dashboard: **Admin → Manage AI Providers → Test**

## 📚 Available Models

### Text Generation
- `@cf/meta/llama-3.1-8b-instruct` — Llama 3.1 8B
- `@cf/meta/llama-3.1-70b-instruct` — Llama 3.1 70B
- `@cf/mistral/mistral-7b-instruct-v0.1` — Mistral 7B
- `@cf/google/gemma-7b-it` — Google Gemma 7B
- `@hf/microsoft/phi-2` — Microsoft Phi-2

### Embeddings
- `@cf/baai/bge-base-en-v1.5` — BGE Base
- `@cf/baai/bge-large-en-v1.5` — BGE Large

### Image Generation
- `@cf/stabilityai/stable-diffusion-xl-base-1.0` — SDXL
- `@cf/lykon/dreamshaper-8-lcm` — Dreamshaper

### Speech Recognition
- `@cf/openai/whisper` — Whisper
- `@cf/openai/whisper-large-v3` — Whisper Large v3

### Translation
- `@cf/meta/m2m100-1.2b` — M2M100 (100 languages)

### Summarization
- `@cf/facebook/bart-large-cnn` — BART CNN

### Classification
- `@cf/huggingface/distilbert-sst-2-int8` — Sentiment
- `@cf/huggingface/bart-large-mnli` — Zero-shot

### Object Detection
- `@cf/facebook/detr-resnet-50` — DETR

## 🔗 Resources

- [Cloudflare Workers AI Documentation](https://developers.cloudflare.com/workers-ai/)
- [Workers AI Models](https://developers.cloudflare.com/workers-ai/models/)
- [AI Gateway](https://developers.cloudflare.com/ai-gateway/)
- [API Reference](https://developers.cloudflare.com/api/operations/workers-ai-post-inference)

## 💰 Pricing

Cloudflare Workers AI offers:
- **Free tier** — 10,000 neurons/day
- **Paid** — $0.01 per 1,000 neurons

See [Cloudflare Pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/) for details.

## 🛠️ Troubleshooting

### "Not configured" error
- Verify Account ID and API token
- Ensure token has Workers AI: Read permission
- Check network connectivity to Cloudflare API

### "Model not found" error
- Verify model name is correct (case-sensitive)
- Check model availability in your Cloudflare account
- See [Available Models](https://developers.cloudflare.com/workers-ai/models/)

### Timeout errors
- Increase timeout in configuration
- Check Cloudflare status page
- Consider using AI Gateway for caching

## 📝 License

Part of the WINDELS AI Workforce platform.

---

**Built with ❤️ for the WINDELS AI Workforce**
