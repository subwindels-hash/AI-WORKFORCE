<?php
namespace AIWorkforce\Providers;

/**
 * Cloudflare Workers AI Provider
 * 
 * Supports Cloudflare's edge AI capabilities including:
 * - Text generation (Llama, Mistral, etc.)
 * - Text embeddings
 * - Image generation (Stable Diffusion)
 * - Speech recognition (Whisper)
 * - Translation
 * - Summarization
 * - Classification
 * - Object detection
 * 
 * @see https://developers.cloudflare.com/workers-ai/
 */
class CloudflareProvider
{
    private string $accountId;
    private string $apiToken;
    private string $baseUrl;
    private ?string $gateway;
    private int $timeout;
    
    public function __construct(array $config)
    {
        $this->accountId = (string)($config['account_id'] ?? '');
        $this->apiToken = (string)($config['token'] ?? $config['api_key'] ?? '');
        $this->gateway = $config['gateway'] ?? null;
        $this->timeout = (int)($config['timeout'] ?? 30);
        
        if (!empty($config['base_url'])) {
            $this->baseUrl = rtrim((string)$config['base_url'], '/');
        } elseif ($this->gateway && $this->accountId) {
            $this->baseUrl = "https://gateway.ai.cloudflare.com/v1/{$this->accountId}/{$this->gateway}/workers-ai";
        } else {
            $this->baseUrl = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run";
        }
    }
    
    /**
     * Check if provider is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->accountId !== '' && $this->apiToken !== '';
    }
    
    /**
     * Get provider status
     */
    public function status(): array
    {
        return [
            'provider' => 'cloudflare_workers_ai',
            'label' => 'Cloudflare Workers AI',
            'configured' => $this->isConfigured(),
            'accountId' => $this->accountId ? substr($this->accountId, 0, 8) . '...' : null,
            'baseUrl' => $this->baseUrl,
            'gateway' => $this->gateway,
            'timeout' => $this->timeout,
        ];
    }
    
    /**
     * Text Generation (LLM)
     * 
     * Supported models:
     * - @cf/meta/llama-3.1-8b-instruct
     * - @cf/meta/llama-3.1-70b-instruct
     * - @cf/mistral/mistral-7b-instruct-v0.1
     * - @cf/google/gemma-7b-it
     * - @hf/microsoft/phi-2
     * 
     * @param string $prompt The prompt to complete
     * @param array $options Additional options (max_tokens, temperature, etc.)
     * @return array|null Response with generated text
     */
    public function generateText(string $prompt, array $options = []): ?array
    {
        $model = $options['model'] ?? '@cf/meta/llama-3.1-8b-instruct';
        $maxTokens = (int)($options['max_tokens'] ?? 512);
        $temperature = (float)($options['temperature'] ?? 0.7);
        
        $payload = [
            'prompt' => $prompt,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];
        
        if (!empty($options['system'])) {
            $payload['messages'] = [
                ['role' => 'system', 'content' => $options['system']],
                ['role' => 'user', 'content' => $prompt],
            ];
            unset($payload['prompt']);
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Chat Completion
     * 
     * @param array $messages Array of messages [{role, content}]
     * @param array $options Additional options
     * @return array|null Response with generated text
     */
    public function chat(array $messages, array $options = []): ?array
    {
        $model = $options['model'] ?? '@cf/meta/llama-3.1-8b-instruct';
        $maxTokens = (int)($options['max_tokens'] ?? 512);
        
        $payload = [
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];
        
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float)$options['temperature'];
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Text Embeddings
     * 
     * Supported models:
     * - @cf/baai/bge-base-en-v1.5
     * - @cf/baai/bge-large-en-v1.5
     * 
     * @param string|array $text Text or array of texts to embed
     * @param string $model Embedding model
     * @return array|null Response with embeddings
     */
    public function embedText($text, string $model = '@cf/baai/bge-base-en-v1.5'): ?array
    {
        $payload = is_array($text) 
            ? ['text' => $text]
            : ['inputs' => [$text]];
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Image Generation
     * 
     * Supported models:
     * - @cf/stabilityai/stable-diffusion-xl-base-1.0
     * - @cf/lykon/dreamshaper-8-lcm
     * 
     * @param string $prompt Image description
     * @param array $options Additional options (width, height, num_steps, etc.)
     * @return array|null Response with image data (base64)
     */
    public function generateImage(string $prompt, array $options = []): ?array
    {
        $model = $options['model'] ?? '@cf/stabilityai/stable-diffusion-xl-base-1.0';
        
        $payload = [
            'prompt' => $prompt,
            'width' => (int)($options['width'] ?? 1024),
            'height' => (int)($options['height'] ?? 1024),
        ];
        
        if (isset($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }
        
        if (isset($options['num_steps'])) {
            $payload['num_steps'] = (int)$options['num_steps'];
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Speech Recognition (Transcription)
     * 
     * Supported models:
     * - @cf/openai/whisper
     * - @cf/openai/whisper-large-v3
     * 
     * @param string $audioData Base64-encoded audio data
     * @param string $model Whisper model
     * @return array|null Response with transcription
     */
    public function transcribeAudio(string $audioData, string $model = '@cf/openai/whisper'): ?array
    {
        $payload = [
            'audio' => $audioData,
        ];
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Translation
     * 
     * @param string $text Text to translate
     * @param string $targetLang Target language code (e.g., 'es', 'fr', 'de')
     * @param string $sourceLang Source language code (optional, auto-detect if null)
     * @return array|null Response with translation
     */
    public function translate(string $text, string $targetLang, ?string $sourceLang = null): ?array
    {
        $model = '@cf/meta/m2m100-1.2b';
        
        $payload = [
            'text' => $text,
            'target_lang' => $targetLang,
        ];
        
        if ($sourceLang) {
            $payload['source_lang'] = $sourceLang;
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Text Summarization
     * 
     * @param string $text Text to summarize
     * @param array $options Additional options
     * @return array|null Response with summary
     */
    public function summarize(string $text, array $options = []): ?array
    {
        $model = $options['model'] ?? '@cf/facebook/bart-large-cnn';
        
        $payload = [
            'inputs' => $text,
        ];
        
        if (isset($options['max_length'])) {
            $payload['parameters'] = ['max_length' => (int)$options['max_length']];
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Text Classification
     * 
     * @param string $text Text to classify
     * @param array $labels Possible labels
     * @return array|null Response with classification scores
     */
    public function classify(string $text, array $labels = []): ?array
    {
        $model = '@cf/huggingface/distilbert-sst-2-int8';
        
        $payload = [
            'inputs' => $text,
        ];
        
        if (!empty($labels)) {
            $payload['parameters'] = ['candidate_labels' => $labels];
            $model = '@cf/huggingface/bart-large-mnli';
        }
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Object Detection
     * 
     * @param string $imageData Base64-encoded image data
     * @param string $model Detection model
     * @return array|null Response with detected objects
     */
    public function detectObjects(string $imageData, string $model = '@cf/facebook/detr-resnet-50'): ?array
    {
        $payload = [
            'image' => $imageData,
        ];
        
        return $this->runModel($model, $payload);
    }
    
    /**
     * Run a model with the given payload
     */
    private function runModel(string $model, array $payload): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($model, '/');
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiToken,
        ];
        
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                return ['error' => 'cURL error: ' . $error, 'http_code' => $httpCode];
            }
            
            $decoded = json_decode($response, true);
            
            if ($httpCode >= 400) {
                return [
                    'error' => $decoded['errors'][0]['message'] ?? 'API error',
                    'http_code' => $httpCode,
                    'response' => $decoded,
                ];
            }
            
            return $decoded;
        } catch (\Throwable $e) {
            return ['error' => 'Exception: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get available models by category
     */
    public function getAvailableModels(): array
    {
        return [
            'text_generation' => [
                '@cf/meta/llama-3.1-8b-instruct' => 'Llama 3.1 8B Instruct',
                '@cf/meta/llama-3.1-70b-instruct' => 'Llama 3.1 70B Instruct',
                '@cf/mistral/mistral-7b-instruct-v0.1' => 'Mistral 7B Instruct',
                '@cf/google/gemma-7b-it' => 'Google Gemma 7B',
                '@hf/microsoft/phi-2' => 'Microsoft Phi-2',
            ],
            'embeddings' => [
                '@cf/baai/bge-base-en-v1.5' => 'BGE Base English',
                '@cf/baai/bge-large-en-v1.5' => 'BGE Large English',
            ],
            'image_generation' => [
                '@cf/stabilityai/stable-diffusion-xl-base-1.0' => 'Stable Diffusion XL',
                '@cf/lykon/dreamshaper-8-lcm' => 'Dreamshaper 8 LCM',
            ],
            'speech_recognition' => [
                '@cf/openai/whisper' => 'Whisper',
                '@cf/openai/whisper-large-v3' => 'Whisper Large v3',
            ],
            'translation' => [
                '@cf/meta/m2m100-1.2b' => 'M2M100 1.2B (100 languages)',
            ],
            'summarization' => [
                '@cf/facebook/bart-large-cnn' => 'BART Large CNN',
            ],
            'classification' => [
                '@cf/huggingface/distilbert-sst-2-int8' => 'DistilBERT Sentiment',
                '@cf/huggingface/bart-large-mnli' => 'BART Zero-shot Classification',
            ],
            'object_detection' => [
                '@cf/facebook/detr-resnet-50' => 'DETR ResNet-50',
            ],
        ];
    }
}
