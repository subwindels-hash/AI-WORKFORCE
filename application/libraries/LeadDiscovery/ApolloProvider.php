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
 * Apollo authenticates with the API key in the **x-api-key request header** on
 * every call (https://docs.apollo.io/reference/authentication). Passing
 * `api_key` in the JSON body was retired in September 2024 and now fails.
 *
 * API keys are **scoped by default**: when a key is created Apollo lets you
 * tick the endpoints it may call, and any other endpoint answers `403`
 * (https://docs.apollo.io/docs/create-api-key). This adapter therefore only
 * calls the endpoints Lead Discovery needs and explains a `403` in terms of the
 * scope that is missing instead of reporting a generic connection failure.
 *
 * Endpoints
 * ---------
 *  • POST /mixed_people/api_search — the current documented people search
 *    (0 credits). `mixed_people/search` was deprecated and switched off
 *    (enforced from 15 Dec 2025): it answers 403 for current keys, so it is
 *    only used as a fallback for grandfathered keys, never as the first call.
 *    Search rows are privacy-safe: obfuscated surname, `has_email` /
 *    `has_direct_phone` flags, and no email addresses or phone numbers.
 *  • POST /people/bulk_match — the documented enrichment endpoint (up to 10
 *    Apollo person ids per call, spends credits). Opt-in: it is only called
 *    when contact reveal is enabled for the provider (Admin → API, or
 *    APOLLO_IO_REVEAL_CONTACTS=1), it is capped per search, and a refusal
 *    (403 scope / no credits / 429) degrades to the privacy-safe rows instead
 *    of failing the whole search.
 *
 * Filters are sent as query parameters in Apollo's documented bracket-array
 * form: person_titles[]=CEO&person_seniorities[]=c_suite …
 *
 * The adapter reads its key from ApiProviders config (driver `apollo_io`) or
 * the APOLLO_IO_API_KEY / APOLLO_API_KEY env var. It never fabricates data:
 * an API error becomes a ProviderException that the registry surfaces, and
 * locked placeholder values such as `email_not_unlocked@domain.com` are
 * discarded rather than stored as if they were real contacts.
 */
class ApolloProvider implements LeadDiscoveryProvider
{
    private const DEFAULT_URL = 'https://api.apollo.io';
    /** Current documented people search — 0 credits, privacy-safe rows. */
    public const PEOPLE_API_SEARCH = '/api/v1/mixed_people/api_search';
    /** Deprecated people search, kept only as a fallback for grandfathered keys. */
    public const PEOPLE_SEARCH = '/api/v1/mixed_people/search';
    /** Documented bulk enrichment — up to 10 ids per call, spends credits. */
    public const PEOPLE_BULK_MATCH = '/api/v1/people/bulk_match';
    /** Documented single enrichment — spends credits. */
    public const PEOPLE_MATCH = '/api/v1/people/match';
    /** Documented key-validation ping (https://docs.apollo.io/docs/test-api-key). */
    public const AUTH_HEALTH = '/api/v1/auth/health';

    /** Apollo caps bulk_match at 10 records per request. */
    private const MAX_BULK = 10;
    /** Default credit cap per search when reveal is enabled. */
    private const DEFAULT_REVEAL_LIMIT = 25;
    private const MAX_REVEAL_LIMIT = 100;

    private bool $revealContacts = false;
    private bool $revealPersonalEmails = true;
    private bool $revealPhoneNumber = false;
    private int $revealLimit = self::DEFAULT_REVEAL_LIMIT;

    /** Facts about the last search, surfaced to the API response (no secrets). */
    private array $lastSearchInfo = [];

    /** A provider row with a key exists but is switched off (Admin → API). */
    private bool $savedButDisabled = false;

    public function __construct(
        private ?string $apiKey = null,
        private ?string $baseUrl = null,
        private int $timeoutSeconds = 15,
        private int $maxAttempts = 2,
        array $options = [],
    ) {
        $cfg = $this->managedConfig();

        // First non-empty candidate wins: explicit argument → provider row →
        // env override → documented default. normalizeBase() then repairs the
        // marketing hosts and a duplicated /api/v1 suffix.
        $base = null;
        foreach ([
            $baseUrl,
            $options['base_url'] ?? null,
            $cfg['base_url'] ?? null,
            getenv('APOLLO_IO_API_BASE'),
            getenv('APOLLO_API_BASE'),
            self::DEFAULT_URL,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') { $base = $candidate; break; }
        }
        $this->baseUrl = self::normalizeBase($base);

        if ($this->apiKey === null || trim((string)$this->apiKey) === '') {
            $managed = (string)($cfg['secrets']['api_key'] ?? '');
            $this->apiKey = $managed !== '' ? $managed : null;
        }
        if ($this->apiKey === null || trim((string)$this->apiKey) === '') {
            $env = (string)(getenv('APOLLO_IO_API_KEY') ?: getenv('APOLLO_API_KEY') ?: '');
            $this->apiKey = $env !== '' ? $env : null;
        }
        if ($this->apiKey !== null) {
            $this->apiKey = trim($this->apiKey);
            if ($this->apiKey === '') $this->apiKey = null;
        }
        // Without a usable key, tell the operator whether Apollo was never
        // configured or is configured and switched off — "not configured" sent
        // people looking for an env var that was already saved in the database.
        if ($this->apiKey === null && class_exists(\AIWorkforce\ApiProviders::class)
            && method_exists(\AIWorkforce\ApiProviders::class, 'resolveDriverForRequest')) {
            try {
                $off = \AIWorkforce\ApiProviders::resolveDriverForRequest('lead_discovery', 'apollo_io', false);
                $this->savedButDisabled = is_array($off) && trim((string)($off['secrets']['api_key'] ?? '')) !== '';
            } catch (\Throwable $e) {
                $this->savedButDisabled = false;
            }
        }

        // Contact reveal is credit-spending, so it is opt-in and capped. The
        // per-call option wins over the provider row, which wins over env.
        $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];
        $this->revealContacts = self::flag(
            $options['reveal_contacts'] ?? $extra['reveal_contacts'] ?? self::envOrNull('APOLLO_IO_REVEAL_CONTACTS'),
            false
        );
        $this->revealPersonalEmails = self::flag(
            $options['reveal_personal_emails'] ?? $extra['reveal_personal_emails'] ?? self::envOrNull('APOLLO_IO_REVEAL_PERSONAL_EMAILS'),
            $this->revealContacts
        );
        $this->revealPhoneNumber = self::flag(
            $options['reveal_phone_number'] ?? $extra['reveal_phone_number'] ?? self::envOrNull('APOLLO_IO_REVEAL_PHONE'),
            false
        );
        $limit = $options['reveal_limit'] ?? $extra['reveal_limit'] ?? self::envOrNull('APOLLO_IO_REVEAL_LIMIT');
        if ($limit !== null && trim((string)$limit) !== '') {
            $this->revealLimit = max(0, min(self::MAX_REVEAL_LIMIT, (int)$limit));
        }
    }

    public function name(): string { return 'apollo_io'; }

    public function healthCheck(): array
    {
        if (!$this->apiKey || $this->apiKey === '') {
            return [
                'status' => 'DISABLED',
                'detail' => $this->savedButDisabled
                    ? 'Apollo.io is configured but switched off — enable the provider in Admin → API Management'
                    : 'APOLLO_IO_API_KEY not configured (save it in Admin → API Management → Lead Discovery → Apollo.io)',
            ];
        }
        return [
            'status' => 'IMPLEMENTED',
            'detail' => 'Apollo.io REST API (mixed_people/api_search) — key configured'
                . ($this->revealContacts ? ', contact reveal on (spends Apollo credits)' : ', contact reveal off (search rows carry no emails/phones)'),
        ];
    }

    /** Facts about the most recent search: endpoint used, counts, honest notes. */
    public function lastSearchInfo(): array { return $this->lastSearchInfo; }

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
     *   reveal_contacts — optional per-call override of the credit-spending
     *                 enrichment step (bool). Never enabled by a member's
     *                 request alone: the controller does not forward it.
     *   person_titles / person_locations / contact_email_status / etc. pass
     *                 through as documented Apollo parameters.
     */
    public function searchBusinesses(array $input): array
    {
        $health = $this->healthCheck();
        if ($health['status'] !== 'IMPLEMENTED') {
            throw new ProviderException($health['detail'], 503);
        }

        $params = $this->buildParams($input);
        $this->lastSearchInfo = [
            'endpoint' => null,
            'results' => 0,
            'reveal_enabled' => $this->revealContacts,
            'reveal_requested' => 0,
            'revealed' => 0,
            'notes' => [],
            'notice' => null,
        ];

        $payload = $this->fetchPeople($params);
        $rows = $this->normalize($payload);
        $this->lastSearchInfo['results'] = count($rows);

        if ($rows !== [] && $this->revealContacts) {
            $rows = $this->revealContactData($rows);
        } elseif ($rows !== [] && $this->noContactData($rows)) {
            // Shown to whoever ran the search, so it says who can change the
            // setting and what it costs. Docs link lives in docs/LEAD_DISCOVERY.md.
            $this->lastSearchInfo['notice'] = 'Apollo’s people search never returns email addresses or phone numbers. '
                . 'An administrator can switch on “Reveal emails/phones” for Apollo.io in Admin → API Management to '
                . 'enrich results — that spends Apollo credits.';
        }

        return $rows;
    }

    /* ---- request building ---- */

    /**
     * Translate the normalized Lead Discovery input into Apollo's documented
     * query parameters (bracket arrays for list filters).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildParams(array $input): array
    {
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $q = trim((string)($input['query'] ?? ''));

        $params = ['page' => 1, 'per_page' => $limit];
        if ($q !== '') $params['q_keywords'] = $q;

        $listKeys = [
            'person_titles', 'person_locations', 'organization_locations',
            'person_seniorities', 'seniorities', 'contact_email_status',
            'organizations', 'industries', 'departments', 'q_organization_domains_list',
            'organization_num_employees_ranges', 'person_organizations',
        ];
        foreach ($listKeys as $k) {
            $val = $input[$k] ?? null;
            if (is_array($val) && $val !== []) {
                $clean = array_values(array_filter(array_map('strval', $val), fn($s) => trim($s) !== ''));
                if ($clean !== []) $params[$k] = $clean;
            } elseif (is_string($val) && trim($val) !== '') {
                $params[$k] = [trim($val)];
            }
        }
        // Shorthand aliases the front-end can pass without learning Apollo's schema.
        if (!empty($input['titles']) && is_array($input['titles'])) {
            $params['person_titles'] = array_values(array_unique(array_merge(
                $params['person_titles'] ?? [],
                array_filter(array_map('strval', $input['titles']), fn($s) => trim($s) !== '')
            )));
        }
        if (!empty($input['locations']) && is_array($input['locations'])) {
            $params['person_locations'] = array_values(array_unique(array_merge(
                $params['person_locations'] ?? [],
                array_filter(array_map('strval', $input['locations']), fn($s) => trim($s) !== '')
            )));
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
        // Drop empty list filters — Apollo answers 422 on `key[]=` with no value.
        foreach ($params as $k => $v) {
            if (is_array($v) && $v === []) unset($params[$k]);
        }
        return $params;
    }

    /**
     * Call the documented search endpoint, falling back to the deprecated one
     * only when Apollo says the documented route does not exist for this key.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function fetchPeople(array $params): array
    {
        $last = null;
        foreach ([self::PEOPLE_API_SEARCH, self::PEOPLE_SEARCH] as $path) {
            for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
                try {
                    $payload = $this->post($path, $params);
                    $this->lastSearchInfo['endpoint'] = $path;
                    if ($path !== self::PEOPLE_API_SEARCH) {
                        $this->lastSearchInfo['notes'][] = 'mixed_people/api_search was unavailable (HTTP '
                            . ($last ? $last->httpStatus : 404) . '); used the deprecated mixed_people/search endpoint instead';
                    }
                    return $payload;
                } catch (ProviderException $e) {
                    $last = $e;
                    // 401 = the key itself is rejected, 403 = the key may not call
                    // this endpoint (Apollo scopes keys per endpoint). Neither is
                    // fixed by trying the other search route.
                    if ($e->httpStatus === 401 || $e->httpStatus === 403) throw $e;
                    // A parameter the documented endpoint refuses will not be
                    // accepted by the deprecated one either — surface it.
                    if (in_array($e->httpStatus, [400, 422], true)) throw $e;
                    // Route retired for this key → try the other one.
                    if (in_array($e->httpStatus, [404, 405, 410], true)) break;
                    // Transient: retry the same endpoint, then fall back.
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
     * @param string|null $jsonBody JSON request body (bulk_match sends `details`)
     * @return array{status:int,raw:string,json:?array}
     */
    protected function request(string $method, string $path, array $params = [], ?string $jsonBody = null): array
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
        $transportError = '';
        $body = strtoupper($method) === 'POST' ? ($jsonBody !== null && $jsonBody !== '' ? $jsonBody : '{}') : null;

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
                if ($body !== null) {
                    $opts[CURLOPT_POST] = true;
                    // Apollo reads filters from the query string; an explicit JSON
                    // body carries only what the endpoint documents (e.g. details).
                    $opts[CURLOPT_POSTFIELDS] = $body;
                }
                curl_setopt_array($ch, $opts);
                $raw = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($raw === false) {
                    // Keep the transport's own words: "could not connect" alone
                    // cannot tell a CA-bundle problem from a blocked port.
                    $cErrno = (int)curl_errno($ch);
                    $cErr = trim((string)curl_error($ch));
                    $transportError = $cErr !== '' ? ($cErrno > 0 ? 'cURL ' . $cErrno . ': ' : '') . mb_substr($cErr, 0, 120) : '';
                }
                curl_close($ch);
                if ($raw !== false && $raw !== null) {
                    return ['status' => $status, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
                }
                if ($status > 0) {
                    return ['status' => $status, 'raw' => '', 'json' => null];
                }
                // cURL reported a transport failure — fall through to streams.
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
            if ($body !== null) $http['content'] = $body;
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
            throw new ProviderException(
                'Apollo.io request timed out or could not connect' . ($transportError !== '' ? ' (' . $transportError . ')' : ''),
                503,
                true
            );
        }
        return ['status' => $status, 'raw' => (string)$raw, 'json' => json_decode((string)$raw, true)];
    }

    /**
     * Perform a request and turn Apollo's error envelopes into a ProviderException.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function post(string $path, array $params = [], ?string $jsonBody = null): array
    {
        $resp = $this->request('POST', $path, $params, $jsonBody);
        $status = (int)($resp['status'] ?? 0);
        $decoded = $resp['json'] ?? null;

        if (!is_array($decoded)) {
            if ($status >= 400) {
                throw new ProviderException(
                    $this->errorMessage($status, null, null, $path),
                    $status,
                    $status === 429 || $status >= 500
                );
            }
            throw new ProviderException(
                'Apollo.io returned a non-JSON response' . ($status > 0 ? ' (HTTP ' . $status . ')' : ''),
                $status > 0 ? 502 : 503,
                true
            );
        }

        // Apollo error envelopes: {status:"401", code:"API_KEY_MISSING", message:"…"},
        // {error_code:"API_INACCESSIBLE", error_message:"…"} or {error:"…"}.
        $code = null;
        foreach (['error_code', 'code'] as $k) {
            if (isset($decoded[$k]) && is_string($decoded[$k]) && trim($decoded[$k]) !== '') { $code = trim($decoded[$k]); break; }
        }
        $msg = null;
        foreach (['error_message', 'message', 'error'] as $k) {
            if (!isset($decoded[$k])) continue;
            if (is_string($decoded[$k]) && trim($decoded[$k]) !== '') { $msg = trim($decoded[$k]); break; }
            if (is_array($decoded[$k]) && $decoded[$k] !== []) {
                $encoded = json_encode($decoded[$k]);
                $msg = is_string($encoded) ? $encoded : 'unparsable error payload';
                break;
            }
        }
        $hasData = false;
        foreach (['people', 'contacts', 'matches', 'person', 'organizations', 'breadcrumbs'] as $k) {
            if (array_key_exists($k, $decoded)) { $hasData = true; break; }
        }
        $errorStatus = isset($decoded['status']) && is_string($decoded['status'])
            && in_array(strtolower($decoded['status']), ['error', 'failed', 'unauthorized', 'forbidden'], true);

        if ($status >= 400 || (!$hasData && ($errorStatus || $code !== null))) {
            $effective = $status >= 400 ? $status : 502;
            throw new ProviderException(
                $this->errorMessage($effective, $code, $msg, $path),
                $effective,
                $effective === 429 || $effective >= 500
            );
        }
        return $decoded;
    }

    /** Human, actionable error text — never echoes the API key. */
    private function errorMessage(int $status, ?string $code, ?string $msg, string $path = ''): string
    {
        $where = $path !== '' ? ' ' . $path : '';
        $detail = $code !== null && $msg !== null ? $code . ' — ' . $msg : ($code ?? $msg);
        switch ($status) {
            case 401:
                return 'Apollo.io' . $where . ': the API key was rejected (HTTP 401'
                    . ($detail ? ': ' . $detail : '') . '). Regenerate it in Apollo → Settings → Integrations → API Keys and save the full value again.';
            case 403:
                return 'Apollo.io' . $where . ': this API key may not call that endpoint (HTTP 403'
                    . ($detail ? ': ' . $detail : '') . '). Apollo keys are scoped per endpoint — add `mixed_people_api_search`'
                    . ' (and `people_bulk_match` for contact reveal) or toggle “Set as master key” in Apollo → Settings → Integrations → API Keys.'
                    . ' Free Apollo accounts must be registered with a work email address and the plan must include API access.';
            case 404:
                return 'Apollo.io' . $where . ': endpoint not found (HTTP 404' . ($detail ? ': ' . $detail : '')
                    . '). Check the base URL — it must be https://api.apollo.io.';
            case 405:
            case 410:
                return 'Apollo.io' . $where . ': endpoint retired for this key (HTTP ' . $status . ($detail ? ': ' . $detail : '') . ').';
            case 422:
                return 'Apollo.io' . $where . ': Apollo could not process these search parameters (HTTP 422'
                    . ($detail ? ': ' . $detail : '') . ').';
            case 429:
                return 'Apollo.io' . $where . ': rate limit reached (HTTP 429) — retry shortly.';
        }
        if ($status >= 500) {
            return 'Apollo.io' . $where . ': Apollo server error (HTTP ' . $status . ($detail ? ': ' . $detail : '') . ').';
        }
        return 'Apollo.io' . $where . ': ' . ($detail ?: 'request failed') . ' (HTTP ' . $status . ')';
    }

    /** Build Apollo's query string with bracket-array keys and proper encoding. */
    private function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $bracket = preg_match('/\[\]$/', (string)$key) ? (string)$key : ($key . '[]');
                foreach ($value as $item) {
                    if (is_array($item)) continue;
                    $parts[] = rawurlencode($bracket) . '=' . rawurlencode((string)$item);
                }
            } elseif (is_bool($value)) {
                $parts[] = rawurlencode((string)$key) . '=' . ($value ? 'true' : 'false');
            } else {
                $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
            }
        }
        return implode('&', $parts);
    }

    /* ---- enrichment (credit spending, opt-in) ---- */

    /**
     * Reveal real contact data for the privacy-safe search rows through the
     * documented bulk enrichment endpoint, in batches of 10 and inside the
     * configured credit cap. Any refusal degrades to the rows we already have.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function revealContactData(array $rows): array
    {
        $budget = max(0, min($this->revealLimit, count($rows)));
        if ($budget === 0) {
            $this->lastSearchInfo['notes'][] = 'Contact reveal is enabled but the cap is 0 — no records were enriched.';
            return $rows;
        }

        // Apollo says which rows actually hold contact data; enriching the
        // others would spend credits for nothing.
        $candidates = [];
        foreach ($rows as $i => $r) {
            $meta = is_array($r['metadata'] ?? null) ? $r['metadata'] : [];
            if (!empty($meta['has_email']) || !empty($meta['has_direct_phone'])) $candidates[] = $i;
        }
        if ($candidates === []) $candidates = array_keys($rows);
        $candidates = array_slice($candidates, 0, $budget);

        $requested = 0;
        $revealed = 0;
        foreach (array_chunk($candidates, self::MAX_BULK) as $batch) {
            $details = [];
            foreach ($batch as $i) {
                $pid = (string)($rows[$i]['metadata']['person_id'] ?? '');
                if ($pid === '') continue;
                $details[] = ['id' => $pid];
            }
            if ($details === []) continue;
            $requested += count($details);

            $query = [
                'reveal_personal_emails' => $this->revealPersonalEmails,
                'reveal_phone_number' => $this->revealPhoneNumber,
            ];
            // json_encode returns false on invalid UTF-8; post() takes ?string.
            $payload = json_encode(['details' => $details]);
            if (!is_string($payload)) {
                $this->lastSearchInfo['notes'][] = 'Contact reveal skipped: the enrichment request could not be encoded.';
                continue;
            }
            try {
                $resp = $this->post(self::PEOPLE_BULK_MATCH, $query, $payload);
            } catch (ProviderException $e) {
                // No credits, missing scope, rate limit — keep the privacy-safe
                // rows and say so instead of losing the whole search.
                $this->lastSearchInfo['notes'][] = 'Contact reveal skipped: ' . $e->getMessage();
                break;
            }

            $matches = is_array($resp['matches'] ?? null) ? $resp['matches'] : [];
            $byId = [];
            foreach ($matches as $m) {
                if (is_array($m) && !empty($m['id'])) $byId[(string)$m['id']] = $m;
            }
            foreach ($batch as $i) {
                $pid = (string)($rows[$i]['metadata']['person_id'] ?? '');
                if ($pid === '' || !isset($byId[$pid])) continue;
                $before = (string)($rows[$i]['metadata']['email'] ?? '') . '|' . (string)($rows[$i]['phone'] ?? '');
                $rows[$i] = $this->mergeEnrichment($rows[$i], $byId[$pid]);
                $after = (string)($rows[$i]['metadata']['email'] ?? '') . '|' . (string)($rows[$i]['phone'] ?? '');
                if ($before !== $after) $revealed++;
            }
        }

        $this->lastSearchInfo['reveal_requested'] = $requested;
        $this->lastSearchInfo['revealed'] = $revealed;
        if ($requested > 0 && $revealed === 0 && $this->lastSearchInfo['notes'] === []) {
            $this->lastSearchInfo['notes'][] = 'Apollo matched no contact data for ' . $requested . ' enriched record(s).';
        }
        return $rows;
    }

    /**
     * Merge an enriched person record into a normalized row. Only real values
     * are written; placeholders and obfuscated values are ignored.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function mergeEnrichment(array $row, array $person): array
    {
        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $org = is_array($person['organization'] ?? null) ? $person['organization'] : [];

        // Full name — search rows carry an obfuscated surname on free plans.
        $full = trim((string)($person['name'] ?? ''));
        if ($full === '') $full = trim((string)($person['first_name'] ?? '') . ' ' . (string)($person['last_name'] ?? ''));
        if ($full !== '' && !str_contains($full, '*') && $full !== (string)($row['name'] ?? '')) $row['name'] = $full;

        $email = is_string($person['email'] ?? null) ? trim($person['email']) : '';
        if ($email !== '' && self::isUsableEmail($email)) $meta['email'] = $email;
        if (isset($person['email_status']) && is_string($person['email_status']) && $person['email_status'] !== '') {
            $meta['email_status'] = $person['email_status'];
        }

        $phone = self::firstPhone($person);
        if ($phone !== null) $row['phone'] = $phone;

        if (!empty($person['linkedin_url']) && is_string($person['linkedin_url'])) $meta['linkedin_url'] = $person['linkedin_url'];
        if (!empty($person['title']) && is_string($person['title'])) {
            $meta['title'] = $person['title'];
            if (($row['category'] ?? '') === '' || ($row['category'] ?? null) === null) $row['category'] = $person['title'];
        }
        if (!empty($person['seniority']) && is_string($person['seniority'])) $meta['seniority'] = $person['seniority'];

        $address = implode(', ', array_filter([
            trim((string)($person['city'] ?? '')),
            trim((string)($person['state'] ?? '')),
            trim((string)($person['country'] ?? '')),
        ], fn(string $s): bool => $s !== ''));
        if ($address !== '' && (empty($row['address']))) $row['address'] = $address;

        if (!empty($org['name']) && is_string($org['name'])) $meta['company'] = $org['name'];
        if (!empty($org['website_url']) && is_string($org['website_url'])) {
            $row['website'] = $org['website_url'];
        } elseif (!empty($org['primary_domain']) && is_string($org['primary_domain'])) {
            $row['website'] = 'https://' . $org['primary_domain'];
        }
        if (!empty($org['industry']) && is_string($org['industry'])) $meta['industry'] = $org['industry'];
        if (isset($org['employee_count']) && $org['employee_count'] !== null) $meta['employee_count'] = $org['employee_count'];

        $meta['enriched'] = true;
        $meta['has_email'] = ($meta['email'] ?? '') !== '';
        $meta['has_direct_phone'] = ($row['phone'] ?? null) !== null && ($row['phone'] ?? '') !== '';
        $meta['privacy_safe'] = !$meta['has_email'] && !$meta['has_direct_phone'];
        $row['metadata'] = $meta;
        return $row;
    }

    /* ---- normalization ---- */

    /**
     * Apollo's people search returns `email_not_unlocked@domain.com` (and
     * obfuscated `j***@gmail.com` values) instead of real addresses when the
     * record is not unlocked. Storing those would put junk into leads and let
     * Person Mode's free-webmail filter "match" a placeholder, so they are
     * dropped and the row stays honestly contact-less.
     */
    public static function isUsableEmail(string $email): bool
    {
        $e = strtolower(trim($email));
        if ($e === '' || str_contains($e, '*') || str_contains($e, ' ')) return false;
        if (str_contains($e, 'email_not_unlocked@')) return false;
        if (str_contains($e, 'not_unlocked') || str_ends_with($e, '.invalid')) return false;
        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** First real phone number in an Apollo person/contact payload. */
    private static function firstPhone(array $p): ?string
    {
        foreach (['phone_number', 'sanitized_phone', 'direct_dial_phone', 'mobile_phone', 'raw_phone', 'phone_numbers'] as $k) {
            $v = $p[$k] ?? null;
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '' && !str_contains($v, '*')) return $v;
            } elseif (is_array($v)) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        $n = self::firstPhone($item);
                        if ($n !== null) return $n;
                    } elseif (is_string($item) && trim($item) !== '' && !str_contains($item, '*')) {
                        return trim($item);
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
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
            // Public search returns last_name_obfuscated ("Do***e"); the
            // deprecated search and enrichment return the full last_name.
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
            $phone = self::firstPhone($p);
            $email = null;
            if (is_string($p['email'] ?? null) && self::isUsableEmail((string)$p['email'])) $email = trim((string)$p['email']);
            $website = null;
            if (!empty($org['website_url'])) $website = (string)$org['website_url'];
            elseif (!empty($org['primary_domain'])) $website = 'https://' . (string)$org['primary_domain'];
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
                'provider' => 'Windels A',
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

    /** True when no row carries a usable email or phone. */
    private function noContactData(array $rows): bool
    {
        foreach ($rows as $r) {
            $meta = is_array($r['metadata'] ?? null) ? $r['metadata'] : [];
            if (!empty($meta['email']) || !empty($r['phone'])) return false;
        }
        return true;
    }

    /* ---- configuration helpers ---- */

    /**
     * The managed provider row for Lead Discovery (when this app runs inside
     * CodeIgniter with a database). Returns [] outside the app so the adapter
     * still works from env vars alone — e.g. in the unit tests.
     *
     * @return array<string,mixed>
     */
    private function managedConfig(): array
    {
        if (!class_exists(\AIWorkforce\ApiProviders::class)) return [];
        // Read the apollo_io row itself: lead_discovery also hosts google_places
        // and resolve() returns whichever provider happens to be primary, which
        // made a fallback-configured Apollo look unconfigured at runtime.
        if (method_exists(\AIWorkforce\ApiProviders::class, 'resolveDriverForRequest')) {
            try {
                $cfg = \AIWorkforce\ApiProviders::resolveDriverForRequest('lead_discovery', 'apollo_io');
                if (is_array($cfg)) return $cfg;
            } catch (\Throwable $e) {
                // fall through to the single-active-row lookup below
            }
        }
        try {
            $any = \AIWorkforce\ApiProviders::resolve('lead_discovery');
        } catch (\Throwable $e) {
            return [];
        }
        return (is_array($any) && ($any['driver'] ?? null) === 'apollo_io') ? $any : [];
    }

    /**
     * Normalize an operator-supplied base URL onto the API origin: marketing
     * hosts (apollo.io, app.apollo.io) are not API origins, a pasted
     * `/api/v1` suffix is stripped (endpoint constants carry it), and http is
     * upgraded to https.
     */
    public static function normalizeBase(?string $base): string
    {
        $b = trim((string)($base ?? ''));
        // Strip accidental markdown/link wrappers: [https://apollo.io](https://apollo.io)
        $b = (string)(preg_replace('#^\[[^\]]*\]\((https?://[^)\s]+)\)\s*$#i', '$1', $b) ?? $b);
        $b = (string)(preg_replace('#^<\s*(https?://[^>\s]+)\s*>$#i', '$1', $b) ?? $b);
        if ($b === '') $b = self::DEFAULT_URL;
        if (!preg_match('#^https?://#i', $b)) $b = 'https://' . ltrim($b, '/');
        if (stripos($b, 'http://') === 0) $b = 'https://' . substr($b, 7);
        $b = rtrim($b, "/ \t");
        $b = (string)(preg_replace('#/api/v\d+$#i', '', $b) ?? $b);
        $host = strtolower((string)(parse_url($b, PHP_URL_HOST) ?: ''));
        $notApiOrigins = ['apollo.io', 'www.apollo.io', 'app.apollo.io', 'developer.apollo.io', 'docs.apollo.io'];
        if (in_array($host, $notApiOrigins, true)) $b = self::DEFAULT_URL;
        return rtrim($b, '/');
    }

    /** getenv() without its `false` sentinel, so an unset var stays "no opinion". */
    private static function envOrNull(string $name): ?string
    {
        $v = getenv($name);
        return $v === false ? null : $v;
    }

    /**
     * Truthy parsing for "1", "true", "on", "yes" coming from env or the admin
     * form. Anything unrecognised is false: contact reveal spends credits, so
     * ambiguity must never switch it on.
     */
    private static function flag(mixed $value, bool $default): bool
    {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $s = strtolower(trim((string)$value));
        if ($s === '') return $default;
        if (in_array($s, ['0', 'false', 'off', 'no', 'n', 'disabled'], true)) return false;
        return in_array($s, ['1', 'true', 'on', 'yes', 'y', 'enabled'], true);
    }
}
