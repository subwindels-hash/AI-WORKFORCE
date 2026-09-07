<?php
namespace AIWorkforce\Providers;

/**
 * Speech-to-Text Provider
 * 
 * Provider-agnostic STT abstraction supporting:
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
        $this->driver = $config['driver'] ?? 'openai_compatible';
        $this->config = $config;
    }

    /**
     * Transcribe audio to text from a base64-encoded payload.
     *
     * @param string $audioData Base64-encoded audio
     * @param string $language Language code (optional, auto-detect if null)
     * @return array<string,mixed> Transcription result
     */
    public function transcribe(string $audioData, ?string $language = null): array
    {
        $binary = base64_decode($audioData, true);
        if ($binary === false || $binary === '') {
            return ['error' => 'Audio payload could not be decoded'];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'wf_stt_');
        if ($tmp === false) {
            return ['error' => 'Temporary storage for transcription is unavailable'];
        }
        @file_put_contents($tmp, $binary);
        try {
            return $this->transcribeFile($tmp, $language, 'audio.wav', 'audio/wav');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    /**
     * Transcribe an audio file already present on disk.
     *
     * @return array<string,mixed>
     */
    public function transcribeFile(string $path, ?string $language = null, ?string $filename = null, ?string $mime = null): array
    {
        return match ($this->driver) {
            'openai_compatible' => $this->transcribeFileWithOpenAI($path, $language, $filename, $mime),
            default => ['error' => 'Unsupported STT driver: ' . $this->driver]
        };
    }

    /** @return array<string,mixed> */
    private function transcribeFileWithOpenAI(string $path, ?string $language, ?string $filename, ?string $mime): array
    {
        $apiKey = $this->config['secrets']['api_key'] ?? '';
        if (!$apiKey) {
            return ['error' => 'OpenAI API key not configured'];
        }
        if (!is_file($path) || (int) @filesize($path) <= 0) {
            return ['error' => 'Audio file is unavailable'];
        }
        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            return ['error' => 'Audio transcription requires the PHP cURL extension'];
        }

        $url = $this->openAiAudioEndpoint();
        $file = new \CURLFile($path, $mime ?: 'application/octet-stream', $filename ?: basename($path));
        $postData = [
            'file' => $file,
            'model' => 'whisper-1',
            'response_format' => 'verbose_json',
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
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $message = $curlError !== '' ? $curlError : ('OpenAI API error: HTTP ' . $httpCode);
            return ['error' => $message];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['error' => 'OpenAI returned an unreadable transcription response'];
        }

        return [
            'text' => $data['text'] ?? '',
            'language' => $data['language'] ?? $language ?? 'auto',
            'duration' => isset($data['duration']) ? (float) $data['duration'] : null,
            'confidence' => 1.0,
            'provider' => 'openai',
            'model' => 'whisper-1',
        ];
    }

    private function openAiAudioEndpoint(): string
    {
        $base = trim((string) ($this->config['base_url'] ?? ''));
        $base = rtrim($base, '/');
        $base = (string) preg_replace('#/(chat/completions|responses|embeddings|images/generations|moderations|models|audio/transcriptions)$#i', '', $base);
        if ($base === '') {
            return 'https://api.openai.com/v1/audio/transcriptions';
        }
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));
        if (!preg_match('#/v\d+$#i', $base) && preg_match('#(^|\.)openai\.com$#', $host)) {
            $base .= '/v1';
        }
        return $base . '/audio/transcriptions';
    }
    
    /**
     * Check if provider is configured
     */
    public function isConfigured(): bool
    {
        return match($this->driver) {
            'openai_compatible' => !empty($this->config['secrets']['api_key']),
            default => false
        };
    }
}
