<?php
namespace LeadDiscovery;

/**
 * Apollo.io people/business search adapter for Lead Discovery.
 *
 * Apollo exposes a REST search API at https://api.apollo.io/v1/mixed_people/search
 * (and /organizations/search) that returns enriched people/company records with
 * verified business emails (when the calling plan includes them). We use the
 * mixed_people/search endpoint because it returns people with their current
 * organization — which is the shape AI_WORKFORCE needs for B2B lead discovery
 * (decision-maker name + title + company + email/phone + LinkedIn).
 *
 * Docs: https://apolloio.github.io/apollo-api-docs/
 *
 * Auth: API key passed as `api_key` in the POST JSON body OR via the
 * X-Api-Key header. We send it in the body per Apollo's canonical examples.
 *
 * The adapter:
 *   - Reads its key from ApiProviders config under driver `apollo_io` (preferred)
 *     or from the APOLLO_IO_API_KEY env var.
 *   - Returns the same normalized LeadDiscoveryProvider contract Google Places
 *     uses; metadata carries apollo-specific fields (person name, title,
 *     seniority, linkedin, email_status, employee_count).
 *   - Never fabricates — throws ProviderException when the API reports an
 *     error, and the ProviderRegistry surface fails over or returns an honest
 *     error.
 */
class ApolloProvider implements LeadDiscoveryProvider
{
    private const DEFAULT_URL = 'https://api.apollo.io';
    private const PEOPLE_SEARCH = '/api/v1/mixed_people/search';
    private const ORG_SEARCH = '/api/v1/mixed_companies/search';

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
            ? ['status' => 'IMPLEMENTED', 'detail' => 'Apollo.io REST API (mixed_people/search) — key configured']
            : ['status' => 'DISABLED', 'detail' => 'APOLLO_IO_API_KEY not configured'];
    }

    /**
     * Input:
     *   query            — free-text search (person_titles, q_organization_name,
     *                      locations etc. are supported as additional keys).
     *   limit            — max results (1-100, default 20).
     *   titles           — optional array of job titles (e.g. ["CEO","Founder"]).
     *   locations        — optional array of locations (e.g. ["London, UK"]).
     *   organizations    — optional array of company name fragments.
     *   seniorities      — optional array (e.g. ["owner","founder","c_suite"]).
     *   person_locations / organization_locations / etc. passed through as-is.
     */
    public function searchBusinesses(array $input): array
    {
        $health = $this->healthCheck();
        if ($health['status'] !== 'IMPLEMENTED') {
            throw new ProviderException($health['detail'], 503);
        }

        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $q = trim((string)($input['query'] ?? ''));
        $payload = [
            'api_key' => $this->apiKey,
            'page' => 1,
            'per_page' => $limit,
        ];
        // Apollo uses q_keywords for generic keyword matching, but we also
        // support explicit titles / organizations / locations filters for
        // structured UI inputs.
        if ($q !== '') $payload['q_keywords'] = $q;
        foreach (['person_titles','person_locations','organization_locations','organizations','seniorities','industries','contact_email_status','departments'] as $k) {
            $val = $input[$k] ?? null;
            if (is_array($val) && $val !== []) $payload[$k] = array_values($val);
            elseif (is_string($val) && $val !== '') $payload[$k] = [$val];
        }
        // Shorthand aliases the front-end can pass without learning Apollo's schema.
        if (!empty($input['titles']) && is_array($input['titles'])) {
            $payload['person_titles'] = array_values(array_unique(array_merge($payload['person_titles'] ?? [], $input['titles'])));
        }
        if (!empty($input['locations']) && is_array($input['locations'])) {
            $payload['person_locations'] = array_values(array_unique(array_merge($payload['person_locations'] ?? [], $input['locations'])));
        }
        if (!empty($input['organizations']) && is_array($input['organizations'])) {
            $payload['organizations'] = array_values(array_unique($payload['organizations']));
        }
        // Person-mode first-name lists are folded into q_keywords so Apollo
        // returns people whose name starts with any of the requested names.
        if (!empty($input['first_names']) && is_array($input['first_names'])) {
            $kw = $payload['q_keywords'] ?? '';
            $extra = implode(' ', array_values(array_filter($input['first_names'], 'is_string')));
            $payload['q_keywords'] = trim($kw . ' ' . $extra);
            // Apollo offers a per-contact first_name parameter for single-value
            // lookups; when multiple names are requested we rely on q_keywords
            // and rely on the controller's post-filter for strict starts-with.
        }

        $last = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                return $this->normalize($this->post(self::PEOPLE_SEARCH, $payload));
            } catch (ProviderException $e) {
                $last = $e;
                if (!$e->retryable || $attempt === $this->maxAttempts) throw $e;
                usleep(250000 * $attempt);
            }
        }
        throw $last ?: new ProviderException('Apollo.io request failed');
    }

    /* ---- transport ---- */

    protected function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $body = json_encode($payload);
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\nCache-Control: no-cache\r\n";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => $headers,
                'content' => $body,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $respBody = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int)$m[1]; break; }
        }
        if ($respBody === false) {
            throw new ProviderException('Apollo.io request timed out or could not connect', 503, true);
        }
        $decoded = json_decode($respBody, true);
        if (!is_array($decoded)) {
            throw new ProviderException('Apollo.io returned a non-JSON response', 502, true);
        }
        // Apollo errors: {status: "401", code: "API_KEY_MISSING", message: "..."}
        // or {message: "..."} on 4xx/5xx.
        $errMsg = null;
        if (isset($decoded['message']) && is_string($decoded['message'])) $errMsg = $decoded['message'];
        if (isset($decoded['error']) && is_string($decoded['error'])) $errMsg = $decoded['error'];
        if (isset($decoded['code']) && is_string($decoded['code']) && !isset($decoded['people'])) {
            $errMsg = $decoded['code'] . ': ' . ($errMsg ?? 'request failed');
        }
        if ($status >= 400 && $errMsg !== null) {
            $retryable = $status === 429 || $status >= 500;
            throw new ProviderException('Apollo.io: ' . $errMsg, $status ?: 502, $retryable);
        }
        if ($errMsg !== null && !isset($decoded['people']) && !isset($decoded['contacts'])) {
            throw new ProviderException('Apollo.io: ' . $errMsg, 502, true);
        }
        return $decoded;
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
            $last = (string)($p['last_name'] ?? '');
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
            foreach (['phone_number','sanitized_phone','direct_dial_phone','mobile_phone'] as $k) {
                if (!empty($p[$k]) && is_string($p[$k])) { $phone = $p[$k]; break; }
            }
            $email = null;
            if (!empty($p['email']) && is_string($p['email'])) $email = $p['email'];
            elseif (!empty($p['email_status']) && is_array($p['email_status'])) {
                // Apollo sometimes returns an array of candidate emails; pick the first verified.
                foreach ($p['email_status'] as $cand) {
                    if (is_array($cand) && !empty($cand['email']) && ($cand['verified'] ?? false)) { $email = $cand['email']; break; }
                }
            }
            $website = null;
            if (!empty($org['website_url'])) $website = (string)$org['website_url'];
            elseif (!empty($p['organization_website_url'])) $website = (string)$p['organization_website_url'];
            $linkedin = null;
            if (!empty($p['linkedin_url'])) $linkedin = (string)$p['linkedin_url'];
            $category = $title !== '' ? $title : ((string)($org['industry'] ?? null) ?: 'business');
            $sourceId = 'apollo:' . $id;
            $meta = array_filter([
                'provider' => 'Apollo.io',
                'source' => 'apollo',
                'person_id' => $id,
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
            ], fn($v) => $v !== null && $v !== '' && $v !== []);
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
