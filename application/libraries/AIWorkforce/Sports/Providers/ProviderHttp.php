<?php
namespace AIWorkforce\Sports\Providers;

/**
 * Shared HTTP classification + diagnostics for every sports-data adapter.
 *
 * One place decides what an upstream response MEANS (quota, throttle, auth,
 * bad request, not found, timeout, offline, server error) so the provider
 * manager, circuit breaker and dashboard all speak the same vocabulary, and
 * one place builds the request diagnostic that travels with the failure:
 *
 *   provider / endpoint / HTTP method / query parameters / HTTP status / body
 *
 * with the credential removed. Secrets travel in headers (api-football),
 * query strings (SportMonks `api_token`) and even the URL path (TheSportsDB
 * `/api/v1/json/{key}/`), so redaction covers all three.
 */
final class ProviderHttp
{
    public const BODY_SNIPPET = 240;

    /**
     * Classify a transport result. Returns null when the response is usable
     * (2xx/3xx), otherwise the ProviderException the adapter must throw.
     *
     * @param array{status?:int, body?:string, headers?:array<string,string>, error?:string, errno?:int} $resp
     */
    public static function classify(array $resp, string $url, string $method = 'GET'): ?ProviderException
    {
        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $headers = self::lowerKeys(is_array($resp['headers'] ?? null) ? $resp['headers'] : []);
        $diag = self::diagnostic($url, $method, $status, $body);

        if ($status === 0) {
            $err = strtolower((string) ($resp['error'] ?? ''));
            $errno = (int) ($resp['errno'] ?? 0);
            if ($errno === 28 || str_contains($err, 'timed out') || str_contains($err, 'timeout')) {
                return new ProviderException('request timed out', ProviderException::TIMEOUT, null, $diag + ['transportError' => self::redact((string) ($resp['error'] ?? ''))]);
            }
            return new ProviderException('no HTTP response (unreachable/DNS/TLS)' . ($err !== '' ? ': ' . self::redact((string) $resp['error']) : ''), ProviderException::OFFLINE, null, $diag);
        }
        if ($status === 401 || $status === 403) {
            return new ProviderException('authentication rejected (HTTP ' . $status . ')' . self::vendorMessage($body), ProviderException::AUTHENTICATION_ERROR, null, $diag);
        }
        if ($status === 429) {
            // A 429 is a per-minute throttle UNLESS the vendor says the DAY is used up.
            if (self::dailyQuotaExhausted($body, $headers)) {
                return new ProviderException('daily request quota exhausted (HTTP 429)' . self::vendorMessage($body), ProviderException::DAILY_QUOTA_EXHAUSTED, null, $diag + ['retryAt' => gmdate('c', self::nextUtcMidnight())]);
            }
            $retry = isset($headers['retry-after']) && is_numeric($headers['retry-after']) ? (int) $headers['retry-after'] : 60;
            return new ProviderException('rate limited (HTTP 429)' . self::vendorMessage($body), ProviderException::RATE_LIMITED, null, $diag + ['retryAfterSeconds' => $retry]);
        }
        if ($status === 400 || $status === 422) {
            return new ProviderException('provider rejected the request (HTTP ' . $status . ')' . self::vendorMessage($body) . ' — check the adapter\'s endpoint/parameters against the vendor documentation', ProviderException::BAD_REQUEST, null, $diag);
        }
        if ($status === 404 || $status === 410) {
            return new ProviderException('endpoint/resource not found (HTTP ' . $status . ')' . self::vendorMessage($body) . ' — check the configured base URL and path', ProviderException::NOT_FOUND, null, $diag);
        }
        if ($status >= 500) {
            return new ProviderException('provider server error (HTTP ' . $status . ')', ProviderException::DATA_ERROR, null, $diag);
        }
        if ($status >= 400) {
            return new ProviderException('provider client error (HTTP ' . $status . ')' . self::vendorMessage($body), ProviderException::BAD_REQUEST, null, $diag);
        }
        // 2xx/3xx — but api-football (and others) may still say "quota exhausted" with HTTP 200 headers.
        if (isset($headers['x-ratelimit-requests-remaining']) && is_numeric($headers['x-ratelimit-requests-remaining']) && (int) $headers['x-ratelimit-requests-remaining'] <= 0 && self::dailyQuotaExhausted($body, $headers)) {
            return new ProviderException('daily request quota exhausted', ProviderException::DAILY_QUOTA_EXHAUSTED, null, $diag + ['retryAt' => gmdate('c', self::nextUtcMidnight())]);
        }
        return null;
    }

    /**
     * Classify a vendor "soft error" that arrived with HTTP 200 (api-football
     * style `{"errors": {...}}`). Returns the status constant to use.
     */
    public static function classifySoftError(string $field, string $message): string
    {
        $f = strtolower($field);
        $m = strtolower($message);
        if ($f === 'requests' || str_contains($m, 'request limit for the day') || str_contains($m, 'daily limit') || str_contains($m, 'quota')) {
            return ProviderException::DAILY_QUOTA_EXHAUSTED;
        }
        if ($f === 'ratelimit' || $f === 'rate_limit' || str_contains($m, 'too many requests') || str_contains($m, 'per minute')) {
            return ProviderException::RATE_LIMITED;
        }
        if ($f === 'token' || str_contains($m, 'api key') || str_contains($m, 'apikey') || str_contains($m, 'not subscribed') || str_contains($m, 'unauthori')) {
            return ProviderException::AUTHENTICATION_ERROR;
        }
        // Parameter complaints ("The From field need another parameter.") are
        // requests the vendor does not accept — the adapter, not the network.
        if (str_contains($m, 'field') || str_contains($m, 'parameter') || str_contains($m, 'invalid')) {
            return ProviderException::BAD_REQUEST;
        }
        return ProviderException::DATA_ERROR;
    }

    /** True when the body/headers say the DAILY allowance is gone (not just the per-minute burst). */
    public static function dailyQuotaExhausted(string $body, array $headers): bool
    {
        $headers = self::lowerKeys($headers);
        if (isset($headers['x-ratelimit-requests-remaining']) && is_numeric($headers['x-ratelimit-requests-remaining']) && (int) $headers['x-ratelimit-requests-remaining'] <= 0) {
            return true;
        }
        $b = strtolower($body);
        return str_contains($b, 'request limit for the day')
            || str_contains($b, 'daily limit')
            || str_contains($b, 'daily quota')
            || str_contains($b, 'quota exceeded')
            || str_contains($b, 'limit_day');
    }

    /** Machine-readable, secret-free request record attached to every failure. */
    public static function diagnostic(string $url, string $method, int $status, string $body): array
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $safeQuery = [];
        foreach ($query as $k => $v) {
            $safeQuery[(string) $k] = self::isSecretKey((string) $k) ? '[redacted]' : (is_scalar($v) ? (string) $v : json_encode($v));
        }
        return [
            'method' => $method,
            'endpoint' => self::redactUrl($url),
            'query' => $safeQuery,
            'httpStatus' => $status,
            'bodySnippet' => self::redact(mb_substr(trim($body), 0, self::BODY_SNIPPET)),
        ];
    }

    /** URL with credentials stripped from query, path (TheSportsDB) and userinfo. */
    public static function redactUrl(string $url): string
    {
        $out = preg_replace('#^(https?://)[^/@\s]+@#i', '$1', $url) ?? $url;                            // user:pass@
        $out = preg_replace('#(/api/v\d+/json/)[^/]+/#i', '$1[redacted]/', $out) ?? $out;                // thesportsdb key in path
        $out = preg_replace('/(?i)([?&](?:api_token|api[_-]?key|apikey|token|key|secret|access[_-]?token|x-[\w-]*key)=)[^&#\s"]+/', '$1[redacted]', $out) ?? $out;
        return $out;
    }

    /** Free-text redaction for vendor messages / transport errors. */
    public static function redact(string $text): string
    {
        $out = self::redactUrl($text);
        $out = preg_replace('/(?i)\b(bearer|basic)\s+([A-Za-z0-9._\-]{8,})/', '$1 [redacted]', $out) ?? $out;
        $out = preg_replace('/(?i)(\b(?:token|api[_-]?key|access[_-]?key|secret|x-[\w-]*key)\b\s*[=:]\s*)[A-Za-z0-9._\-]{6,}/', '$1[redacted]', $out) ?? $out;
        $out = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted]', $out) ?? $out;
        return $out;
    }

    public static function isSecretKey(string $k): bool
    {
        return (bool) preg_match('/^(api_token|api[_-]?key|apikey|token|key|secret|access[_-]?token|x-[\w-]*key)$/i', $k);
    }

    /** api-football's daily counter resets at 00:00 UTC; unused requests are lost. */
    public static function nextUtcMidnight(?int $now = null): int
    {
        $now ??= time();
        return (int) (floor($now / 86400) + 1) * 86400;
    }

    /** Short vendor explanation pulled from a JSON error body, redacted. */
    private static function vendorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return '';
        foreach (['message', 'error', 'detail', 'errors'] as $k) {
            if (!isset($decoded[$k])) continue;
            $v = $decoded[$k];
            if (is_array($v)) {
                $first = reset($v);
                $v = is_array($first) ? json_encode($first) : $first;
            }
            if (is_string($v) && trim($v) !== '') return ': ' . self::redact(mb_substr(trim($v), 0, 160));
        }
        return '';
    }

    /** @param array<string,string> $headers */
    private static function lowerKeys(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) $out[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
        return $out;
    }
}
