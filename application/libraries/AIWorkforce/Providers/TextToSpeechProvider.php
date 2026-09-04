<?php
namespace AIWorkforce\Providers;

/**
 * Text-to-Speech Provider
 * 
 * Provider-agnostic TTS abstraction supporting:
 * - Cloudflare Workers AI
 * - OpenAI TTS API
 * - Browser Speech Synthesis (client-side)
 * - Other TTS providers
 */
class TextToSpeechProvider
{
    private string $driver;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'cloudflare_workers_ai';
        $this->config = $config;
    }
    
    /**
     * Synthesize text to audio
     * 
     * @param string $text Text to synthesize
     * @param string $voice Voice ID (optional)
     * @param string $language Language code (optional)
     * @return array Audio data (base64) or error
     */
    public static function speakableText(string $text): string
    {
        return (string) preg_replace('/\bWINDELS\b/i', 'Win-dels', $text);
    }

    public function synthesize(string $text, ?string $voice = null, ?string $language = null): array
    {
        $text = self::speakableText($text);
        return match($this->driver) {
            'cloudflare_workers_ai' => $this->synthesizeWithCloudflare($text, $voice, $language),
            'openai_compatible' => $this->synthesizeWithOpenAI($text, $voice, $language),
            default => ['error' => 'Unsupported TTS driver: ' . $this->driver]
        };
    }
    
    private function synthesizeWithCloudflare(string $text, ?string $voice, ?string $language): array
    {
        // Note: Cloudflare Workers AI doesn't have a native TTS model yet
        // This would use an external TTS service or a custom Worker
        // For now, return a placeholder indicating browser TTS should be used
        
        return [
            'status' => 'USE_BROWSER_TTS',
            'message' => 'Cloudflare Workers AI does not yet support TTS. Use browser SpeechSynthesis API.',
            'text' => $text,
            'voice' => $voice,
            'language' => $language,
        ];
    }
    
    private function synthesizeWithOpenAI(string $text, ?string $voice, ?string $language): array
    {
        $apiKey = $this->config['secrets']['api_key'] ?? '';
        if (!$apiKey) {
            return ['error' => 'OpenAI API key not configured'];
        }
        
        $url = 'https://api.openai.com/v1/audio/speech';
        $postData = [
            'model' => 'tts-1',
            'input' => $text,
            'voice' => $voice ?? 'alloy', // alloy, echo, fable, onyx, nova, shimmer
            'response_format' => 'mp3',
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return ['error' => 'OpenAI API error: HTTP ' . $httpCode];
        }
        
        return [
            'audio' => base64_encode($response),
            'format' => 'mp3',
            'voice' => $voice ?? 'alloy',
            'language' => $language ?? 'en',
            'provider' => 'openai',
            'model' => 'tts-1',
        ];
    }
    
    /**
     * Get available voices
     */
    public function getAvailableVoices(): array
    {
        return match($this->driver) {
            'openai_compatible' => [
                'alloy' => 'Neutral (American)',
                'echo' => 'Male (American)',
                'fable' => 'Female (British)',
                'onyx' => 'Deep Male (American)',
                'nova' => 'Female (American)',
                'shimmer' => 'Soft Female (American)',
            ],
            default => [
                'default' => 'System default voice',
            ]
        };
    }
    
    /**
     * Check if provider is configured
     */
    public function isConfigured(): bool
    {
        return match($this->driver) {
            'openai_compatible' => !empty($this->config['secrets']['api_key']),
            default => false // Browser TTS is always available client-side
        };
    }
}
