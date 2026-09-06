<?php
namespace AIWorkforce\Tests;

/**
 * Unit tests for the Apollo.io LeadDiscovery provider. Verifies payload
 * normalization, error-envelope rejection, disabled state without a key,
 * the documented x-api-key header auth, bracket-array query parameters,
 * legacy → public-search endpoint fallback, and the normalized contract
 * used by the search pipeline.
 */
require_once __DIR__ . '/../bootstrap.php';

use LeadDiscovery\ApolloProvider;
use LeadDiscovery\ProviderException;

class FakeTransportProvider extends ApolloProvider
{
    /** @var array<int,array{status?:int,body?:string,json?:array,network?:bool}> */
    public array $responses = [];
    public int $calls = 0;
    /** @var array<int,array{method:string,path:string,params:array,headers:array}> */
    public array $sent = [];

    public function __construct(array $responses, string $key = 'test-key')
    {
        parent::__construct($key);
        $this->responses = $responses;
    }

    protected function request(string $method, string $path, array $params = []): array
    {
        $idx = $this->calls++;
        $this->sent[] = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => []];
        if ($idx >= count($this->responses)) throw new ProviderException('out of responses');
        $r = $this->responses[$idx];
        if (!empty($r['network'])) throw new ProviderException('timeout', 503, true);
        $status = (int)($r['status'] ?? 200);
        $json = $r['json'] ?? null;
        // Hand the staged envelope back so the real post() parses success/error.
        if ($json === null && $status >= 400) {
            $json = ['code' => $r['code'] ?? ('HTTP_' . $status), 'message' => (string)($r['message'] ?? 'request failed')];
        }
        return ['status' => $status, 'raw' => '', 'json' => $json];
    }

    public function lastPath(): string { return $this->sent[count($this->sent) - 1]['path'] ?? ''; }
    public function lastParams(): array { return $this->sent[count($this->sent) - 1]['params'] ?? []; }
}

$tests = [];

$tests[] = function (): array {
    $p = new ApolloProvider(null, null, 15, 1); // no key
    $h = $p->healthCheck();
    assert_eq($h['status'], 'DISABLED', 'disabled_without_key');
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'ceo london']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'search_throws_when_disabled');
    return ['msg' => 'disabled state correct'];
};

$tests[] = function (): array {
    $p = new FakeTransportProvider([[
        'json' => [
            'people' => [
                [
                    'id' => 'pers_1',
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'title' => 'CEO',
                    'email' => 'jane@example.com',
                    'phone_number' => '+44 20 1234 5678',
                    'city' => 'London', 'state' => '', 'country' => 'GB',
                    'linkedin_url' => 'https://linkedin.com/in/janedoe',
                    'seniority' => 'c_suite',
                    'organization' => [
                        'id' => 'org_9', 'name' => 'Acme Ltd', 'website_url' => 'https://acme.example',
                        'industry' => 'SaaS', 'employee_count' => 120, 'city' => 'London', 'country' => 'GB',
                    ],
                ],
                [
                    'id' => 'pers_2', 'first_name' => '', 'last_name' => '',
                    'title' => '', 'organization' => ['name' => 'Bob Co'],
                ],
            ],
        ],
    ]]);
    $rows = $p->searchBusinesses(['query' => 'saas ceo london', 'limit' => 5]);
    assert_eq(count($rows), 2, 'two_rows');
    $r = $rows[0];
    assert_eq($r['sourceId'], 'apollo:pers_1', 'source_id_prefixed');
    assert_eq($r['name'], 'Jane Doe', 'name_concat');
    assert_eq($r['category'], 'CEO', 'category_title');
    assert_true(str_contains($r['address'], 'London'), 'address');
    assert_eq($r['phone'], '+44 20 1234 5678', 'phone');
    assert_eq($r['website'], 'https://acme.example', 'website');
    assert_eq($r['metadata']['provider'], 'Apollo.io', 'metadata_provider');
    assert_eq($r['metadata']['title'], 'CEO', 'metadata_title');
    assert_eq($r['metadata']['company'], 'Acme Ltd', 'metadata_company');
    assert_eq($r['metadata']['email'], 'jane@example.com', 'email_in_metadata');
    assert_eq($r['metadata']['linkedin_url'], 'https://linkedin.com/in/janedoe', 'linkedin');
    // Second row minimal: fall back to org name.
    assert_eq($rows[1]['name'], 'Bob Co', 'minimal_name_fallback');
    // Legacy full-data endpoint is tried first.
    assert_eq($p->lastPath(), '/api/v1/mixed_people/search', 'legacy_endpoint_tried_first');
    $params = $p->lastParams();
    assert_eq($params['q_keywords'], 'saas ceo london', 'keywords_passthrough');
    assert_eq($params['per_page'], 5, 'per_page_limit');
    // API key is NEVER in the body/params — auth is the x-api-key header.
    assert_true(!array_key_exists('api_key', $params), 'no_api_key_in_params');
    return ['msg' => 'normalization ok'];
};

$tests[] = function (): array {
    // Legacy endpoint retired (404) -> transparent fallback to documented api_search.
    $p = new FakeTransportProvider([
        ['status' => 404, 'message' => 'Not Found'],
        ['json' => ['people' => [[
            'id' => 'new_1', 'first_name' => 'Maria', 'last_name_obfuscated' => 'Ga***a',
            'title' => 'Founder', 'has_email' => true, 'has_direct_phone' => 'Yes',
            'organization' => ['name' => 'Twelve'],
        ]]]],
    ]);
    $rows = $p->searchBusinesses(['query' => 'founder']);
    assert_eq($p->calls, 2, 'fell_back_to_api_search');
    assert_eq($p->lastPath(), '/api/v1/mixed_people/api_search', 'public_search_endpoint');
    assert_eq(count($rows), 1, 'one_row');
    assert_eq($rows[0]['name'], 'Maria Ga***a', 'obfuscated_name_kept');
    assert_eq(true, $rows[0]['metadata']['has_email'], 'has_email_flag');
    assert_eq(true, $rows[0]['metadata']['has_direct_phone'], 'has_phone_flag');
    assert_true(($rows[0]['metadata']['privacy_safe'] ?? false) === true, 'marked_privacy_safe');
    return ['msg' => 'legacy -> public fallback ok'];
};

$tests[] = function (): array {
    // Error envelope -> ProviderException, no fallback on auth errors.
    $p = new FakeTransportProvider([['status' => 401, 'message' => 'Invalid API key', 'json' => ['code' => 'UNAUTHORIZED']]]);
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'x']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'error_thrown');
    assert_true($thrown->httpStatus === 401, 'http_status');
    assert_true($thrown->retryable === false, 'unauthorized_not_retryable');
    assert_eq($p->calls, 1, 'no_fallback_on_401');
    return ['msg' => 'auth error rejected without fallback'];
};

$tests[] = function (): array {
    // 429 -> retryable: retries the same endpoint (2 attempts), then falls
    // back to the public search endpoint which succeeds.
    $p = new FakeTransportProvider([
        ['status' => 429, 'message' => 'rate'],
        ['status' => 429, 'message' => 'rate'],
        ['json' => ['people' => []]],
    ]);
    $rows = $p->searchBusinesses(['query' => 'test']);
    assert_eq(count($rows), 0, 'empty_after_retry');
    assert_eq($p->calls, 3, 'retried_then_fell_back');
    assert_eq($p->lastPath(), '/api/v1/mixed_people/api_search', '429_fallback_endpoint');
    return ['msg' => 'retry on 429'];
};

$tests[] = function (): array {
    // Filter parameters: titles/locations/seniorities mapped to Apollo bracket keys.
    $p = new FakeTransportProvider([['json' => ['people' => []]]]);
    $p->searchBusinesses(['query' => 'vp sales', 'titles' => ['VP Sales'], 'locations' => ['New York, NY'], 'seniorities' => ['vp']]);
    $params = $p->lastParams();
    assert_true(in_array('VP Sales', $params['person_titles'] ?? []), 'titles_map');
    assert_true(in_array('New York, NY', $params['person_locations'] ?? []), 'locations_map');
    assert_true(in_array('vp', $params['person_seniorities'] ?? []), 'seniorities_aliased_to_person_seniorities');
    assert_true(!isset($params['seniorities']), 'raw_seniorities_alias_removed');
    return ['msg' => 'filter passthrough ok'];
};

$tests[] = function (): array {
    // Single first-name maps to q_person_name; multiple names fold into q_keywords.
    $single = new FakeTransportProvider([['json' => ['people' => []]]]);
    $single->searchBusinesses(['query' => '', 'first_names' => ['Emma']]);
    assert_eq('Emma', $single->lastParams()['q_person_name'] ?? null, 'single_name_q_person_name');

    $multi = new FakeTransportProvider([['json' => ['people' => []]]]);
    $multi->searchBusinesses(['query' => 'ceo', 'first_names' => ['Mark', 'David']]);
    $kw = $multi->lastParams()['q_keywords'] ?? '';
    assert_true(str_contains($kw, 'Mark') && str_contains($kw, 'David'), 'multi_names_in_keywords');
    return ['msg' => 'name search params ok'];
};

$tests[] = function (): array {
    $p = new ApolloProvider('fake-key');
    assert_eq($p->name(), 'apollo_io', 'provider_name');
    $caps = $p->healthCheck();
    assert_eq($caps['status'], 'IMPLEMENTED', 'health_when_keyed');
    return ['msg' => 'health checks ok'];
};

$tests[] = function (): array {
    // Empty query still sends a payload (controller validates minimum length);
    // Apollo just returns broad records — we don't throw.
    $p = new FakeTransportProvider([['json' => ['people' => []]]]);
    $rows = $p->searchBusinesses(['query' => '', 'limit' => 1]);
    assert_eq(count($rows), 0, 'empty_query_no_results');
    return ['msg' => 'empty query handled without crash'];
};

$tests[] = function (): array {
    // Every returned row satisfies the contract keys expected by Api_lead_discovery::search().
    $p = new FakeTransportProvider([[
        'json' => ['people' => [[
            'id' => 'p', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'CTO',
            'email' => 'a@b.co', 'phone_number' => '+1', 'organization' => ['name' => 'Z', 'website_url' => 'https://z'],
        ]]],
    ]]);
    $rows = $p->searchBusinesses(['query' => 'cto']);
    $required = ['sourceId', 'name', 'category', 'address', 'phone', 'website', 'latitude', 'longitude', 'metadata'];
    foreach ($rows as $r) {
        foreach ($required as $k) assert_true(array_key_exists($k, $r), "has_key_$k");
    }
    return ['msg' => 'contract keys present'];
};

run('84-apollo-lead-provider', $tests);
