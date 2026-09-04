<?php
namespace AIWorkforce\Cloudflare;

/**
 * Cloudflare Browser Integration
 * 
 * Provides browser automation capabilities through Cloudflare:
 * - Web scraping and data extraction
 * - Screenshot capture
 * - Form interaction
 * - JavaScript execution
 * - Session management
 * 
 * Note: This is a placeholder for future Cloudflare Browser integration.
 * Currently uses local cURL for basic HTTP requests.
 */
class CloudflareBrowser
{
    private array $config;
    private array $cookies = [];
    private array $headers = [];
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (compatible; WINDELS-Bot/1.0)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
    }
    
    /**
     * Navigate to URL and get page content
     * 
     * @param string $url URL to navigate to
     * @param array $options Request options
     * @return array Page content and metadata
     */
    public function navigate(string $url, array $options = []): array
    {
        $ch = curl_init($url);
        
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 30,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 10,
            CURLOPT_HTTPHEADER => $this->formatHeaders(),
            CURLOPT_COOKIEFILE => '', // Enable cookie handling
        ];
        
        // Add POST data if present
        if (!empty($options['post'])) {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = $options['post'];
        }
        
        // Add custom headers
        if (!empty($options['headers'])) {
            $curlOptions[CURLOPT_HTTPHEADER] = array_merge(
                $this->formatHeaders(),
                $this->formatHeaders($options['headers'])
            );
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            return [
                'ok' => false,
                'error' => 'cURL error: ' . $error,
                'url' => $url,
            ];
        }
        
        return [
            'ok' => true,
            'url' => $finalUrl,
            'http_code' => $httpCode,
            'content' => $response,
            'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'size' => strlen($response),
        ];
    }
    
    /**
     * Extract text content from HTML
     * 
     * @param string $html HTML content
     * @return string Plain text
     */
    public function extractText(string $html): string
    {
        // Remove script and style elements
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        
        // Strip HTML tags
        $text = strip_tags($html);
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
    
    /**
     * Extract links from HTML
     * 
     * @param string $html HTML content
     * @param string $baseUrl Base URL for relative links
     * @return array Array of links
     */
    public function extractLinks(string $html, string $baseUrl = ''): array
    {
        $links = [];
        
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                // Convert relative URLs to absolute
                if (!preg_match('/^https?:\/\//i', $href)) {
                    $href = $this->resolveUrl($baseUrl, $href);
                }
                
                $links[] = [
                    'url' => $href,
                    'text' => preg_replace('/<[^>]+>/', '', $matches[0][$matches[1] === $href ? 0 : array_search($href, $matches[1])]),
                ];
            }
        }
        
        return $links;
    }
    
    /**
     * Extract metadata from HTML
     * 
     * @param string $html HTML content
     * @return array Metadata (title, description, keywords, etc.)
     */
    public function extractMetadata(string $html): array
    {
        $metadata = [];
        
        // Title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = trim($matches[1]);
        }
        
        // Meta description
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['description'] = trim($matches[1]);
        }
        
        // Meta keywords
        if (preg_match('/<meta\s+name=["\']keywords["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['keywords'] = array_map('trim', explode(',', $matches[1]));
        }
        
        // Open Graph
        if (preg_match_all('/<meta\s+property=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $metadata['og_' . $match[1]] = $match[2];
            }
        }
        
        return $metadata;
    }
    
    /**
     * Take a screenshot (placeholder - requires headless browser)
     * 
     * @param string $url URL to screenshot
     * @return array Screenshot data or error
     */
    public function screenshot(string $url): array
    {
        // Note: Real screenshot requires a headless browser like Puppeteer or Playwright
        // This is a placeholder for future Cloudflare Browser integration
        
        return [
            'ok' => false,
            'error' => 'Screenshot requires headless browser integration (not yet implemented)',
            'url' => $url,
        ];
    }
    
    /**
     * Execute JavaScript (placeholder - requires headless browser)
     * 
     * @param string $url URL to load
     * @param string $script JavaScript to execute
     * @return array Execution result or error
     */
    public function executeScript(string $url, string $script): array
    {
        // Note: Real JS execution requires a headless browser
        // This is a placeholder for future Cloudflare Browser integration
        
        return [
            'ok' => false,
            'error' => 'JavaScript execution requires headless browser integration (not yet implemented)',
            'url' => $url,
        ];
    }
    
    /**
     * Resolve relative URL to absolute
     * 
     * @param string $baseUrl Base URL
     * @param string $relativeUrl Relative URL
     * @return string Absolute URL
     */
    private function resolveUrl(string $baseUrl, string $relativeUrl): string
    {
        if (empty($baseUrl)) return $relativeUrl;
        
        // Already absolute
        if (preg_match('/^https?:\/\//i', $relativeUrl)) {
            return $relativeUrl;
        }
        
        // Protocol-relative
        if (strpos($relativeUrl, '//') === 0) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $relativeUrl;
        }
        
        $base = parse_url($baseUrl);
        $baseUri = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        
        // Absolute path
        if (strpos($relativeUrl, '/') === 0) {
            return $baseUri . $relativeUrl;
        }
        
        // Relative path
        $basePath = isset($base['path']) ? dirname($base['path']) : '/';
        return $baseUri . rtrim($basePath, '/') . '/' . $relativeUrl;
    }
    
    /**
     * Format headers for cURL
     * 
     * @param array $headers Headers array
     * @return array Formatted headers
     */
    private function formatHeaders(array $headers = []): array
    {
        $headers = array_merge($this->headers, $headers);
        $formatted = [];
        
        foreach ($headers as $name => $value) {
            $formatted[] = "$name: $value";
        }
        
        return $formatted;
    }
    
    /**
     * Set a header
     */
    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
    
    /**
     * Set multiple headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = array_merge($this->headers, $headers);
    }
}
