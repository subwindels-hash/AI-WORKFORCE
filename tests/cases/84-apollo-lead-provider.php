<?php
namespace AIWorkforce\Tests;

/**
 * Unit tests for the Apollo.io LeadDiscovery provider. Verifies payload
 * normalization, error-envelope rejection, disabled state without a key,
 * and that results comply with the LeadDiscoveryProvider contract used by
 * the search pipeline.
 */
require_once __DIR__ . '/../bootstrap.php';

use LeadDiscovery\ApolloProvider;
use LeadDiscovery\ProviderException;

class FakeTransportProvider extends ApolloProvider
{
    public array $responses = [];
    public int $calls = 0;
    public string $lastUrl = '';
    public ?string $lastBody = null;
    public function __construct(array $responses, string $key = 'test-key') {
        parent::__construct($key);
        $this->responses = $responses;
    }
    protected function post(string $path, array $payload): array
    {
        $this->lastUrl = $path;
        $this->lastBody = json_encode($payload);
        $idx = $this->calls++;
        if ($idx >= count($this->responses)) throw new ProviderException('out of responses');
        $resp = $this->responses[$idx];
        if (is_string($resp) && $resp === 'network') throw new ProviderException('timeout', 503, true);
        if (isset($resp['_status'])) {
            // Fake HTTP status — emulate error flow by throwing.
            throw new ProviderException($resp['message'] ?? 'error', (int)$resp['_status'], ($resp['_status']??500) === 429 || ($resp['_status']??0) >= 500);
        }
        return $resp;
    }
}

$tests = [];

$tests[] = function(): array {
    $p = new ApolloProvider(null, null, 15, 1); // no key
    $h = $p->healthCheck();
    assert_eq($h['status'], 'DISABLED', 'disabled_without_key');
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'ceo london']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'search_throws_when_disabled');
    return ['msg' => 'disabled state correct'];
};

$tests[] = function(): array {
    $p = new FakeTransportProvider([[
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
        ]
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
    assert_true($p->lastUrl === '/api/v1/mixed_people/search', 'endpoint_correct');
    $body = json_decode($p->lastBody, true);
    assert_eq($body['api_key'], 'test-key', 'api_key_in_body');
    assert_eq($body['q_keywords'], 'saas ceo london', 'keywords_passthrough');
    assert_eq($body['per_page'], 5, 'per_page_limit');
    return ['msg' => 'normalization ok'];
};

$tests[] = function(): array {
    // Error envelope -> ProviderException.
    $p = new FakeTransportProvider([['_status' => 401, 'message' => 'Invalid API key', 'code' => 'UNAUTHORIZED']]);
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'x']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'error_thrown');
    assert_true(str_contains($thrown->getMessage(), 'Invalid API key'), 'error_message');
    assert_true($thrown->httpStatus === 401, 'http_status');
    assert_true($thrown->retryable === false, 'unauthorized_not_retryable');
    return ['msg' => 'error envelope rejected'];
};

$tests[] = function(): array {
    // 429 -> retryable.
    $p = new FakeTransportProvider([['_status'=>429,'message'=>'rate'],['people'=>[]]]);
    $rows = $p->searchBusinesses(['query'=>'test']);
    assert_eq($p->calls, 2, 'retried_on_429');
    assert_eq(count($rows), 0, 'empty_after_retry');
    return ['msg' => 'retry on 429'];
};

$tests[] = function(): array {
    // Filter parameters: titles/locations/seniorities mapped correctly.
    $p = new FakeTransportProvider([['people'=>[]]]);
    $p->searchBusinesses(['query'=>'vp sales', 'titles'=>['VP Sales'], 'locations'=>['New York, NY'], 'seniorities'=>['vp']]);
    $body = json_decode($p->lastBody, true);
    assert_true(in_array('VP Sales', $body['person_titles']), 'titles_map');
    assert_true(in_array('New York, NY', $body['person_locations']), 'locations_map');
    assert_true(in_array('vp', $body['seniorities']), 'seniorities_map');
    return ['msg' => 'filter passthrough ok'];
};

$tests[] = function(): array {
    $p = new ApolloProvider('fake-key');
    assert_eq($p->name(), 'apollo_io', 'provider_name');
    $caps = $p->healthCheck();
    assert_eq($caps['status'], 'IMPLEMENTED', 'health_when_keyed');
    return ['msg' => 'health checks ok'];
};

$tests[] = function(): array {
    // Empty/short query not rejected here (Api_lead_discovery handles it),
    // but passing an empty query still sends a payload; Apollo just
    // returns popular records — we don't throw.
    $p = new FakeTransportProvider([['people'=>[]]]);
    $rows = $p->searchBusinesses(['query' => '', 'limit' => 1]);
    assert_eq(count($rows), 0, 'empty_query_no_results');
    return ['msg' => 'empty query handled without crash'];
};

$tests[] = function(): array {
    // Ensure every returned row satisfies the contract keys expected by Api_lead_discovery::search().
    $p = new FakeTransportProvider([[
        'people' => [[
            'id' => 'p', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'CTO',
            'email' => 'a@b.co', 'phone_number' => '+1', 'organization' => ['name' => 'Z', 'website_url' => 'https://z']
        ]]
    ]]);
    $rows = $p->searchBusinesses(['query' => 'cto']);
    $required = ['sourceId','name','category','address','phone','website','latitude','longitude','metadata'];
    foreach ($rows as $r) {
        foreach ($required as $k) assert_true(array_key_exists($k, $r), "has_key_$k");
    }
    return ['msg' => 'contract keys present'];
};

run('84-apollo-lead-provider', $tests);
