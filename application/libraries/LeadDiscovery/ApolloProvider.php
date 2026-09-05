<?php
namespace LeadDiscovery;

/**
 * Apollo.io people search adapter for Lead Discovery.
 *
 * Docs: https://docs.apollo.io/reference/apollo-api
 * Base URL: https://api.apollo.io/api/v1
 *
 * Authentication
 * --------------
 * Apollo authenticates with the API key passed in the **x-api-key request
 * header** on every call (https://docs.apollo.io/reference/authentication).
 * The old `api_key`-in-body mechanism is deprecated and is rejected by the
 * current API, which is why the legacy build of this adapter reported
 * "Connection failed" even with a valid key.
 *
 * Endpoints
 * ---------
 *  • POST /mixed_people/api_search  — current documented search. Free accounts
 *    (registered with a work email) get search results, but the public search
 *    returns privacy-safe rows (obfuscated surname, has_email/has_direct_phone
 *    flags) and does not hand back emails/phone numbers.
 *  • POST /mixed_people/search      — legacy search that still returns full
 *    contact data (email/phone/LinkedIn) on paid plans. We try it first for
 *    paying workspaces and transparently fall back to api_search when Apollo
 *    answers 404/410/405 (endpoint retired for this key) so free workspaces
 *    still get real people results. Auth/plan errors (401/403/422) surface
 *    immediately — retrying them against another endpoint cannot help.
 *
 * Filters are sent as query parameters in Apollo's documented bracket-array
 * form: person_titles[]=CEO&person_seniorities[]=c_suite …
 *
 * The adapter reads its key from ApiProviders config (driver `apollo_io`) or
 * the APOLLO_IO_API_KEY / APOLLO_API_KEY env var. It never fabricates data:
 * an API error becomes a ProviderException that the registry surfaces.
 */
class ApolloProvider implements LeadDiscoveryProvider
{
    private const DEFAULT_URL = 'https://api.apollo.io';
    /** Current documented search endpoint (free-tier friendly, privacy-safe rows). */
    private const PEOPLE_API_SEARCH = '/api/v1/mixed_people/api_search';
    /** Legacy search endpoint that returns enriched email/phone on paid plans. */
    private const PEOPLE_SEARCH = '/api/v1/mixed_people/search';
    /** Documented key-validation ping (https://docs.apollo.io/docs/test-api-key). */
    public const AUTH_HEALTH = '/api/v1/auth/health';

    public function __construct(
        private ?string $apiKey = null,
        private ?string $baseUrl = null,
        private int $timeoutSeconds = 15,
        private int $maxAttempts = 2,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('APOLLO_IO_API_BASE') ?: (getenv('APOLLO_API_BASE') ?: self::DEFAULT_URL)), '/');
        if ($this->apiKey === null || $this->apiKey === '') {
            $cfg = class_exists(\AIWorkforce\ApiProviders::class) ? \AIWorkforce\ApiProviders::resolve('lead_discovery') : null;
            if (is_array($cfg) && ($cfg['driver'] ?? null) === 'apollo_io') {
                $managed = (string)($cfg['secrets']['api_key'] ?? '');
                $this->apiKey = $managed !== '' ? $managed : null;
            }
            if (!$this->apiKey) {
                $this->apiKey = (string)(getenv('APOLLO_IO_API_KEY') ?: getenv('APOLLO_API_KEY') ?: '') ?: null;
            }
        }
    }

    public function name(): string { return 'apollo_io'; }

    public function healthCheck(): array
    {
        return $this->apiKey && $this->apiKey !== ''
            ? ['status' => 'IMPLEMENTED', 'detail' => 'Apollo.io REST API (mixed_people/api_search) — key configured']
            : ['status' => 'DISABLED', 'detail' => 'APOLLO_IO_API_KEY not configured'];
    }

    /**
     * Input:
     *   query       — free-text keyword search (q_keywords).
     *   limit       — max results (1-100, default 20).
     *   titles      — optional array of job titles (person_titles[]).
     *   locations   — optional array of locations (person_locations[]).
     *   seniorities — optional array (owner|founder|c_suite|partner|vp|head|
     *                 director|manager|senior|entry|intern) (person_seniorities[]).
     *   names       — optional first-name list (folded into q_person_name /
     *                 q_keywords); controller post-filters strict starts-with.
     *   person_titles / person_locations / contact_email_status / etc. pass
     *                 through as documented Apollo parameters.
     */
    public function searchBusinesses(array $input): array
    {
        $health = $this->healthCheck();
        if ($health['status'] !== 'IMPLEMENTED') {
            throw new ProviderException($health['detail'], 503);
        }

        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $q = trim((string)($input['query'] ?? ''));

        // Build Apollo's documented query parameters. Arrays use the [] suffix
        // Apollo expects; scalars pass straight through.
        $params = ['page' => 1, 'per_page' => $limit];
        if ($q !== '') $params['q_keywords'] = $q;

        $listKeys = [
            'person_titles', 'person_locations', 'organization_locations',
            'person_seniorities', 'seniorities', 'contact_email_status',
            'organizations', 'industries', 'departments', 'q_organization_domains_list',
        ];
        foreach ($listKeys as $k) {
            $val = $input[$k] ?? null;
            if (is_array($val) && $val !== []) {
                $params[$k] = array_values(array_filter(array_map('strval', $val), fn($s) => trim($s) !== ''));
            } elseif (is_string($val) && trim($val) !== '') {
                $params[$k] = [trim($val)];
            }
        }
        // Shorthand aliases the front-end can pass without learning Apollo's schema.
        if (!empty($input['titles']) && is_array($input['titles'])) {
            $params['person_titles'] = array_values(array_unique(array_merge($params['person_titles'] ?? [], array_map('strval', $input['titles']))));
        }
        if (!empty($input['locations']) && is_array($input['locations'])) {
            $params['person_locations'] = array_values(array_unique(array_merge($params['person_locations'] ?? [], array_map('strval', $input['locations']))));
        }
        // `seniorities` is the controller's alias for Apollo's person_seniorities[].
        if (!empty($params['seniorities'])) {
            $params['person_seniorities'] = array_values(array_unique(array_merge($params['person_seniorities'] ?? [], $params['seniorities'])));
            unset($params['seniorities']);
        }
        // Name search: Apollo exposes q_person_name (matches all words). A single
        // name maps to it; multiple names are folded into q_keywords and the
        // controller post-filters strict starts-with.
        if (!empty($input['first_names']) && is_array($input['first_names'])) {
            $names = array_values(array_filter(array_map('strval', $input['first_names']), fn($s) => trim($s) !== ''));
            if (count($names) === 1) {
                $params['q_person_name'] = trim($names[0]);
            } elseif ($names !== []) {
                $params['q_keywords'] = trim(($params['q_keywords'] ?? '') . ' ' . implode(' ', $names));
            }
        }

        // Paid/legacy search first (full emails/phones), then documented public
        // search as fallback. Auth errors are not retried against a 2nd endpoint.
        $last = null;
        foreach ([self::PEOPLE_SEARCH, self::PEOPLE_API_SEARCH] as $path) {
            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                try {
                    return $this->normalize($this->post($path, $params));
                } catch (ProviderException $e) {
                    $last = $e;
                    // Auth/plan-validation errors won't succeed on the other endpoint.
                    if (in_array($e->httpStatus, [400, 401, 403, 422], true)) throw $e;
                    // Endpoint retired for this key — fall through to api_search.
                    if (in_array($e->httpStatus, [404, 405, 410], true)) break;
                    // Transient: retry same endpoint, then fall back.
                    if (!$e->retryable || $attempt === $this->maxAttempts) break;
                    usleep(250000 * $attempt);
                }
            }
        }
        throw $last ?: new ProviderException('Apollo.io request failed');
    }

    /* ---- transport ---- */

    /**
     * Issue an authenticated Apollo request. Filters are query parameters
     * (bracket arrays), the key is in the x-api-key header.
     *
     * Separated as protected so tests can stage a deterministic transport.
     *
     * @param array<string,mixed> $params
     * @return array{status:int,raw:string,json:?array}
     */
    protected function request(string $method, string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        $query = $this->buildQuery($params);
        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Cache-Control: no-cache',
            'x-api-key: ' . (string)$this->apiKey,
            'User-Agent: WINDELS-AIWorkforce/1.0 (+lead-discovery)',
        ];
        $status = 0;
        $raw = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                    CURLOPT_TIMEOUT => $this->timeoutSeconds,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING => '',
                ];
                if (strtoupper($method) === 'POST') {
                    $opts[CURLOPT_POST] = true;
                    // Empty JSON body keeps the Content-Type honest; Apollo reads
                    // filters from the query string.
                    $opts[CURLOPT_POSTFIELDS] = '{}';
                }
                curl_setopt_array($ch, $opts);
                $raw = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $errno = curl_errno($ch);
                curl_close($ch);
                if ($raw !== false && $raw !== null) {
                    return ['status' => $status, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
                }
                if ($status > 0) {
                    return ['status' => $status, 'raw' => '', 'json' => null];
                }
                // cURL reported a transport failure — fall through to streams.
                unset($errno);
            }
        }

        if (ini_get('allow_url_fopen')) {
            $hdr = implode("\r\n", $headers) . "\r\n";
            $http = [
                'method' => strtoupper($method),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => $hdr,
            ];
            if (strtoupper($method) === 'POST') $http['content'] = '{}';
            $ctx = stream_context_create([
                'http' => $http,
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $respBody = @file_get_contents($url, false, $ctx);
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; break; }
            }
            $raw = is_string($respBody) ? $respBody : false;
        }

        if ($raw === false || $raw === null) {
            throw new ProviderException('Apollo.io request timed out or could not connect', 503, true);
        }
        return ['status' => $status, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
    }

    /**
     * Perform a request and turn Apollo's error envelopes into a ProviderException.
     *
     * @param array<string,mixed> $params
     */
    public function post(string $path, array $params = []): array
    {
        $resp = $this->request('POST', $path, $params);
        $status = (int)($resp['status'] ?? 0);
        $decoded = $resp['json'] ?? null;
        if (!is_array($decoded)) {
            throw new ProviderException('Apollo.io returned a non-JSON response', 502, true);
        }
        // Apollo errors look like: {status:"401", code:"API_KEY_MISSING", message:"…"}
        // or {error:"…", message:"…"} / {error_code:"API_INACCESSIBLE", …}.
        $errMsg = null;
        if (isset($decoded['message']) && is_string($decoded['message'])) $errMsg = $decoded['message'];
        if (isset($decoded['error']) && is_string($decoded['error'])) $errMsg = $decoded['error'];
        $code = $decoded['code'] ?? $decoded['error_code'] ?? null;
        if (is_string($code) && $code !== '' && !isset($decoded['people']) && !isset($decoded['contacts'])) {
            $errMsg = $code . ': ' . ($errMsg ?? 'request failed');
        }
        $isHealthy = isset($decoded['people']) || isset($decoded['contacts']) || isset($decoded['breadcrumbs'])
            || ($status >= 200 && $status < 300 && ($errMsg === null || isset($decoded['people'])));
        if ($status >= 400) {
            $retryable = $status === 429 || $status >= 500;
            throw new ProviderException('Apollo.io: ' . ($errMsg ?? ('request failed (HTTP ' . $status . ')')), $status ?: 502, $retryable);
        }
        if ($errMsg !== null && !$isHealthy) {
            throw new ProviderException('Apollo.io: ' . $errMsg, 502, true);
        }
        return $decoded;
    }

    /** Build Apollo's query string with bracket-array keys and proper encoding. */
    private function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $bracket = preg_match('/\[\]$/', $key) ? $key : ($key . '[]');
                foreach ($value as $item) {
                    $parts[] = rawurlencode($bracket) . '=' . rawurlencode((string)$item);
                }
            } else {
                $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
            }
        }
        return implode('&', $parts);
    }

    /* ---- normalization ---- */

    private function normalize(array $payload): array
    {
        $rows = $payload['people'] ?? $payload['contacts'] ?? [];
        if (!is_array($rows)) $rows = [];
        $out = [];
        foreach ($rows as $p) {
            if (!is_array($p)) continue;
            $id = (string)($p['id'] ?? '');
            if ($id === '') continue;
            $org = is_array($p['organization'] ?? null) ? $p['organization'] : [];
            $first = (string)($p['first_name'] ?? '');
            // Public search returns last_name_obfuscated ("Do***e"); legacy
            // search returns full last_name.
            $last = (string)($p['last_name'] ?? '');
            if ($last === '' && isset($p['last_name_obfuscated'])) $last = (string)$p['last_name_obfuscated'];
            $name = trim($first . ' ' . $last);
            if ($name === '' && isset($p['name'])) $name = (string)$p['name'];
            if ($name === '' && isset($org['name'])) $name = (string)$org['name'];
            $title = (string)($p['title'] ?? $p['headline'] ?? '');
            $company = (string)($org['name'] ?? $p['organization_name'] ?? '');
            $city = trim((string)($p['city'] ?? $org['city'] ?? ''));
            $state = trim((string)($p['state'] ?? $org['state'] ?? ''));
            $country = trim((string)($p['country'] ?? $org['country'] ?? ''));
            $addressParts = array_filter([$city, $state, $country], fn($s) => $s !== '');
            $address = implode(', ', $addressParts) ?: null;
            $phone = null;
            foreach (['phone_number', 'sanitized_phone', 'direct_dial_phone', 'mobile_phone'] as $k) {
                if (!empty($p[$k]) && is_string($p[$k])) { $phone = $p[$k]; break; }
            }
            $email = null;
            if (!empty($p['email']) && is_string($p['email'])) $email = $p['email'];
            $website = null;
            if (!empty($org['website_url'])) $website = (string)$org['website_url'];
            elseif (!empty($p['organization_website_url'])) $website = (string)$p['organization_website_url'];
            $linkedin = null;
            if (!empty($p['linkedin_url'])) $linkedin = (string)$p['linkedin_url'];
            $category = $title !== '' ? $title : ((string)($org['industry'] ?? null) ?: 'business');
            $sourceId = 'apollo:' . $id;

            // Privacy flags from the documented public search (emails/phones are
            // not returned, but Apollo tells us whether enrichment exists).
            $hasEmail = array_key_exists('has_email', $p) ? (bool)$p['has_email'] : ($email !== null);
            $hasPhone = null;
            if (array_key_exists('has_direct_phone', $p)) {
                $v = $p['has_direct_phone'];
                $hasPhone = is_string($v) ? stripos($v, 'yes') === 0 : (bool)$v;
            } elseif ($phone !== null) {
                $hasPhone = true;
            }
            $obfuscated = !empty($p['last_name_obfuscated']) && empty($p['last_name']);
            $privacySafe = $obfuscated || ($email === null && $phone === null && $linkedin === null);

            // Boolean capability flags are always present (even when false);
            // optional scalar fields are dropped when empty so the lead record
            // stays compact.
            $meta = [
                'provider' => 'Apollo.io',
                'source' => 'apollo',
                'person_id' => $id,
                'has_email' => (bool)$hasEmail,
                'has_direct_phone' => (bool)$hasPhone,
                'privacy_safe' => (bool)$privacySafe,
            ];
            foreach ([
                'title' => $title !== '' ? $title : null,
                'company' => $company !== '' ? $company : null,
                'email' => $email,
                'email_status' => $p['email_status'] ?? null,
                'linkedin_url' => $linkedin,
                'seniority' => $p['seniority'] ?? null,
                'departments' => $p['departments'] ?? null,
                'organization_id' => $org['id'] ?? ($p['organization_id'] ?? null),
                'employee_count' => $org['employee_count'] ?? null,
                'industry' => $org['industry'] ?? null,
            ] as $mk => $mv) {
                if ($mv !== null && $mv !== '' && $mv !== []) $meta[$mk] = $mv;
            }
            $out[] = [
                'sourceId' => $sourceId,
                'name' => $name !== '' ? $name : ($company ?: 'Unknown'),
                'category' => $category,
                'address' => $address,
                'phone' => $phone,
                'website' => $website,
                'latitude' => null,
                'longitude' => null,
                'metadata' => $meta,
            ];
        }
        return $out;
    }
}
