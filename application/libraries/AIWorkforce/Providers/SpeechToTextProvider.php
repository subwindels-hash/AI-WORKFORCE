<?php
namespace AIWorkforce\Providers;

/**
 * Speech-to-Text Provider
 * 
 * Provider-agnostic STT abstraction supporting:
 * - Cloudflare Workers AI (Whisper)
 * - OpenAI Whisper API
 * - Browser Web Speech API (client-side)
 * - Other STT providers
 */
class SpeechToTextProvider
{
    private string $driver;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'cloudflare_workers_ai';
        $this->config = $config;
    }
    
    /**
     * Transcribe audio to text
     * 
     * @param string $audioData Base64-encoded audio
     * @param string $language Language code (optional, auto-detect if null)
     * @return array Transcription result
     */
    public function transcribe(string $audioData, ?string $language = null): array
    {
        return match($this->driver) {
            'cloudflare_workers_ai' => $this->transcribeWithCloudflare($audioData, $language),
            'openai_compatible' => $this->transcribeWithOpenAI($audioData, $language),
            default => ['error' => 'Unsupported STT driver: ' . $this->driver]
        };
    }
    
    private function transcribeWithCloudflare(string $audioData, ?string $language): array
    {
        $provider = new CloudflareProvider($this->config);
        $result = $provider->transcribeAudio($audioData, '@cf/openai/whisper');
        
        if (isset($result['error'])) {
            return $result;
        }
        
        return [
            'text' => $result['result']['text'] ?? $result['text'] ?? '',
            'language' => $language ?? 'auto',
            'confidence' => $result['result']['confidence'] ?? 1.0,
            'provider' => 'cloudflare_workers_ai',
            'model' => '@cf/openai/whisper',
        ];
    }
    
    private function transcribeWithOpenAI(string $audioData, ?string $language): array
    {
        // OpenAI Whisper API integration
        $apiKey = $this->config['secrets']['api_key'] ?? '';
        if (!$apiKey) {
            return ['error' => 'OpenAI API key not configured'];
        }
        
        $url = 'https://api.openai.com/v1/audio/transcriptions';
        $postData = [
            'file' => base64_decode($audioData),
            'model' => 'whisper-1',
        ];
        
        if ($language) {
            $postData['language'] = $language;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'OpenAI API error: HTTP ' . $httpCode];
        }
        
        $data = json_decode($response, true);
        
        return [
            'text' => $data['text'] ?? '',
            'language' => $data['language'] ?? $language ?? 'auto',
            'confidence' => 1.0,
            'provider' => 'openai',
            'model' => 'whisper-1',
        ];
    }
    
    /**
     * Check if provider is configured
     */
    public function isConfigured(): bool
    {
        return match($this->driver) {
            'cloudflare_workers_ai' => !empty($this->config['account_id']) && !empty($this->config['token']),
            'openai_compatible' => !empty($this->config['secrets']['api_key']),
            default => false
        };
    }
}
