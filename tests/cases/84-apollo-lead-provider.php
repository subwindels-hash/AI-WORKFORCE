<?php
namespace AIWorkforce\Tests;

/**
 * Unit tests for the Apollo.io LeadDiscovery provider. Verifies payload
 * normalization, error-envelope rejection, disabled state without a key,
 * the documented x-api-key header auth, bracket-array query parameters,
 * the current `mixed_people/api_search` endpoint (with the deprecated
 * `mixed_people/search` only as a fallback), scoped-key 403 handling,
 * locked/placeholder email rejection, the opt-in credit-spending enrichment
 * through `people/bulk_match`, and the normalized contract used by the
 * search pipeline.
 */
require_once __DIR__ . '/../bootstrap.php';

use LeadDiscovery\ApolloProvider;
use LeadDiscovery\ProviderException;

class FakeTransportProvider extends ApolloProvider
{
    /** @var array<int,array{status?:int,body?:string,json?:array,network?:bool,code?:string,message?:string}> */
    public array $responses = [];
    public int $calls = 0;
    /** @var array<int,array{method:string,path:string,params:array,body:?string,headers:array}> */
    public array $sent = [];

    public function __construct(array $responses, string $key = 'test-key', array $options = [])
    {
        parent::__construct($key, null, 15, 2, $options);
        $this->responses = $responses;
    }

    protected function request(string $method, string $path, array $params = [], ?string $jsonBody = null): array
    {
        $idx = $this->calls++;
        $this->sent[] = ['method' => $method, 'path' => $path, 'params' => $params, 'body' => $jsonBody, 'headers' => []];
        if ($idx >= count($this->responses)) throw new ProviderException('out of staged responses for ' . $path);
        $r = $this->responses[$idx];
        if (!empty($r['network'])) throw new ProviderException('timeout', 503, true);
        $status = (int)($r['status'] ?? 200);
        $json = $r['json'] ?? null;
        // Hand the staged envelope back so the real post() parses success/error.
        if ($json === null && $status >= 400) {
            $json = ['error_code' => $r['code'] ?? ('HTTP_' . $status), 'error_message' => (string)($r['message'] ?? 'request failed')];
        }
        return ['status' => $status, 'raw' => '', 'json' => $json];
    }

    public function lastPath(): string { return $this->sent[count($this->sent) - 1]['path'] ?? ''; }
    public function lastParams(): array { return $this->sent[count($this->sent) - 1]['params'] ?? []; }
    public function lastBody(): ?string { return $this->sent[count($this->sent) - 1]['body'] ?? null; }
    /** @return array<int,string> */
    public function paths(): array { return array_map(static fn(array $c): string => (string)$c['path'], $this->sent); }
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
    $p = new FakeTransportProvider([['json' => [
        'pagination' => ['page' => 1, 'per_page' => 5, 'total_entries' => 2, 'total_pages' => 1],
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
                'email_status' => 'verified',
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
        'breadcrumbs' => [],
    ]]], 'test-key', ['reveal_contacts' => false]);
    $rows = $p->searchBusinesses(['query' => 'saas ceo london', 'limit' => 5]);
    assert_eq(count($rows), 2, 'two_rows');
    $r = $rows[0];
    assert_eq($r['sourceId'], 'apollo:pers_1', 'source_id_prefixed');
    assert_eq($r['name'], 'Jane Doe', 'name_concat');
    assert_eq($r['category'], 'CEO', 'category_title');
    assert_true(str_contains((string)$r['address'], 'London'), 'address');
    assert_eq($r['phone'], '+44 20 1234 5678', 'phone');
    assert_eq($r['website'], 'https://acme.example', 'website');
    assert_eq($r['metadata']['provider'], 'Windels A', 'metadata_provider');
    assert_eq($r['metadata']['title'], 'CEO', 'metadata_title');
    assert_eq($r['metadata']['company'], 'Acme Ltd', 'metadata_company');
    assert_eq($r['metadata']['email'], 'jane@example.com', 'email_in_metadata');
    assert_eq($r['metadata']['email_status'], 'verified', 'email_status_kept');
    assert_eq($r['metadata']['linkedin_url'], 'https://linkedin.com/in/janedoe', 'linkedin');
    assert_eq($r['metadata']['employee_count'], 120, 'employee_count');
    // Second row minimal: fall back to org name.
    assert_eq($rows[1]['name'], 'Bob Co', 'minimal_name_fallback');
    // The documented endpoint is called first — the deprecated one is fallback only.
    assert_eq($p->paths()[0], ApolloProvider::PEOPLE_API_SEARCH, 'documented_endpoint_first');
    assert_eq($p->lastPath(), ApolloProvider::PEOPLE_API_SEARCH, 'only_documented_endpoint_called');
    assert_eq($p->calls, 1, 'single_request_when_documented_endpoint_works');
    $params = $p->lastParams();
    assert_eq($params['q_keywords'], 'saas ceo london', 'keywords_passthrough');
    assert_eq($params['per_page'], 5, 'per_page_limit');
    assert_eq($params['page'], 1, 'page_param');
    // API key is NEVER in the body/params — auth is the x-api-key header.
    assert_true(!array_key_exists('api_key', $params), 'no_api_key_in_params');
    assert_eq($p->lastBody(), null, 'search sends no JSON body (filters are query params)');
    $info = $p->lastSearchInfo();
    assert_eq($info['results'], 2, 'info_results');
    assert_eq($info['reveal_enabled'], false, 'info_reveal_disabled');
    // Emails came back from the payload, so no "search never returns emails" notice.
    assert_eq($info['notice'], null, 'no_notice_when_contact_data_present');
    return ['msg' => 'normalization ok'];
};

$tests[] = function (): array {
    // Documented endpoint unavailable (404) → fallback to the deprecated search.
    $p = new FakeTransportProvider([
        ['status' => 404, 'message' => 'Not Found'],
        ['json' => ['people' => [[
            'id' => 'new_1', 'first_name' => 'Maria', 'last_name_obfuscated' => 'Ga***a',
            'title' => 'Founder', 'has_email' => true, 'has_direct_phone' => 'Yes',
            'organization' => ['name' => 'Twelve'],
        ]]]],
    ]);
    $rows = $p->searchBusinesses(['query' => 'founder']);
    assert_eq($p->calls, 2, 'fell_back_to_deprecated_search');
    assert_eq($p->paths()[0], ApolloProvider::PEOPLE_API_SEARCH, 'documented_endpoint_tried_first');
    assert_eq($p->lastPath(), ApolloProvider::PEOPLE_SEARCH, 'deprecated_search_used_as_fallback');
    assert_eq(count($rows), 1, 'one_row');
    assert_eq($rows[0]['name'], 'Maria Ga***a', 'obfuscated_name_kept');
    assert_eq(true, $rows[0]['metadata']['has_email'], 'has_email_flag');
    assert_eq(true, $rows[0]['metadata']['has_direct_phone'], 'has_phone_flag');
    assert_true(($rows[0]['metadata']['privacy_safe'] ?? false) === true, 'marked_privacy_safe');
    assert_eq(null, $rows[0]['metadata']['email'] ?? null, 'no_fabricated_email');
    $info = $p->lastSearchInfo();
    assert_true($info['notes'] !== [], 'fallback_is_reported_in_notes');
    assert_true(str_contains(implode(' ', $info['notes']), 'deprecated'), 'note_names_the_deprecated_endpoint');
    assert_true(is_string($info['notice']) && str_contains((string)$info['notice'], 'never returns email'), 'notice_explains_missing_emails');
    return ['msg' => 'documented → deprecated fallback ok'];
};

$tests[] = function (): array {
    // Error envelope → ProviderException, no fallback on auth errors.
    $p = new FakeTransportProvider([['status' => 401, 'message' => 'Invalid API key', 'code' => 'UNAUTHORIZED']]);
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'x']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'error_thrown');
    assert_true($thrown->httpStatus === 401, 'http_status');
    assert_true($thrown->retryable === false, 'unauthorized_not_retryable');
    assert_eq($p->calls, 1, 'no_fallback_on_401');
    assert_true(str_contains($thrown->getMessage(), 'Regenerate'), 'message_tells_the_operator_what_to_do');
    assert_false(str_contains($thrown->getMessage(), 'test-key'), 'message_never_echoes_the_key');
    return ['msg' => 'auth error rejected without fallback'];
};

$tests[] = function (): array {
    // Apollo keys are scoped per endpoint: a 403 on the documented search means
    // the key lacks that scope. It must not be retried against the deprecated
    // endpoint (which is 403 for current keys too) and must name the fix.
    $p = new FakeTransportProvider([['status' => 403, 'code' => 'API_INACCESSIBLE', 'message' => 'API is not accessible for this key']]);
    $thrown = null;
    try { $p->searchBusinesses(['query' => 'x']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'scoped_key_throws');
    assert_true($thrown->httpStatus === 403, 'http_403');
    assert_true($thrown->retryable === false, 'forbidden_not_retryable');
    assert_eq($p->calls, 1, 'no_wasted_call_to_the_deprecated_endpoint');
    $m = $thrown->getMessage();
    assert_true(str_contains($m, 'API_INACCESSIBLE'), 'error_code_surfaced');
    assert_true(str_contains($m, 'mixed_people_api_search') || str_contains($m, 'master key'), 'message_names_the_missing_scope');
    assert_true(str_contains($m, 'work email'), 'message_names_the_free_account_rule');
    return ['msg' => 'scoped-key 403 explained, not retried'];
};

$tests[] = function (): array {
    // A 200 body that is actually an error envelope must not be read as data.
    $p = new FakeTransportProvider([['json' => [
        'status' => 'error', 'error_code' => 'API_INACCESSIBLE', 'error_message' => 'Your plan does not include API access',
    ]]]);
    $thrown = null;
    try { $p->post(ApolloProvider::PEOPLE_API_SEARCH, ['per_page' => 1]); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown instanceof ProviderException, 'envelope_error_thrown');
    assert_true(str_contains($thrown->getMessage(), 'API access'), 'envelope_message_kept');
    return ['msg' => 'error envelope inside HTTP 200 rejected'];
};

$tests[] = function (): array {
    // 429 → retryable: retries the same endpoint (2 attempts), then falls
    // back to the deprecated search endpoint which succeeds.
    $p = new FakeTransportProvider([
        ['status' => 429, 'message' => 'rate'],
        ['status' => 429, 'message' => 'rate'],
        ['json' => ['people' => []]],
    ]);
    $rows = $p->searchBusinesses(['query' => 'test']);
    assert_eq(count($rows), 0, 'empty_after_retry');
    assert_eq($p->calls, 3, 'retried_then_fell_back');
    assert_eq($p->lastPath(), ApolloProvider::PEOPLE_SEARCH, '429_fallback_endpoint');
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
    // Empty/whitespace list filters are dropped — Apollo answers 422 on `key[]=`.
    $p = new FakeTransportProvider([['json' => ['people' => []]]]);
    $p->searchBusinesses(['query' => 'ceo', 'person_locations' => null, 'person_titles' => ['  ']]);
    $params = $p->lastParams();
    assert_true(!array_key_exists('person_locations', $params), 'null_location_filter_dropped');
    assert_true(!array_key_exists('person_titles', $params), 'blank_title_filter_dropped');
    return ['msg' => 'blank filters dropped'];
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
    assert_true(str_contains($caps['detail'], 'contact reveal off'), 'health_says_reveal_is_off_by_default');
    $on = new ApolloProvider('fake-key', null, 15, 1, ['reveal_contacts' => true]);
    assert_true(str_contains($on->healthCheck()['detail'], 'contact reveal on'), 'health_says_reveal_is_on');
    return ['msg' => 'health checks ok'];
};

$tests[] = function (): array {
    // Base URL canonicalisation: marketing hosts and a pasted /api/v1 suffix.
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase(null), 'default_base');
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase('https://api.apollo.io/api/v1'), 'version_suffix_stripped');
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase('https://app.apollo.io'), 'app_host_is_not_an_api_origin');
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase('apollo.io'), 'marketing_host_rewritten');
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase('http://api.apollo.io'), 'http_upgraded_to_https');
    assert_eq('https://proxy.example.test', ApolloProvider::normalizeBase('https://proxy.example.test/'), 'custom_proxy_kept');
    assert_eq('https://api.apollo.io', ApolloProvider::normalizeBase('[https://apollo.io](https://apollo.io)'), 'markdown_link_unwrapped');
    return ['msg' => 'base URL canonicalisation ok'];
};

$tests[] = function (): array {
    // Locked placeholders and obfuscated addresses are not contact data.
    assert_false(ApolloProvider::isUsableEmail('email_not_unlocked@acme.example'), 'locked_placeholder_rejected');
    assert_false(ApolloProvider::isUsableEmail('j***e@gmail.com'), 'obfuscated_email_rejected');
    assert_false(ApolloProvider::isUsableEmail(''), 'empty_rejected');
    assert_false(ApolloProvider::isUsableEmail('not-an-email'), 'invalid_rejected');
    assert_true(ApolloProvider::isUsableEmail('Jane.Doe@Gmail.com'), 'real_email_accepted');

    $p = new FakeTransportProvider([['json' => ['people' => [
        ['id' => 'locked_1', 'first_name' => 'Ann', 'last_name' => 'Lee', 'title' => 'CTO',
         'email' => 'email_not_unlocked@apollo.io', 'has_email' => true, 'organization' => ['name' => 'Locked Co']],
        ['id' => 'obf_1', 'first_name' => 'Bo', 'last_name_obfuscated' => 'Sm***h', 'title' => 'CEO',
         'email' => 'b***h@gmail.com', 'organization' => ['name' => 'Obf Co']],
    ]]]]);
    $rows = $p->searchBusinesses(['query' => 'cto']);
    assert_eq(2, count($rows), 'both_rows_kept');
    assert_eq(null, $rows[0]['metadata']['email'] ?? null, 'placeholder_email_not_stored');
    assert_eq(null, $rows[1]['metadata']['email'] ?? null, 'obfuscated_email_not_stored');
    assert_eq(true, $rows[0]['metadata']['has_email'], 'has_email_flag_survives');
    return ['msg' => 'locked/obfuscated emails rejected'];
};

$tests[] = function (): array {
    // Opt-in enrichment: privacy-safe search rows are completed through the
    // documented people/bulk_match endpoint (10 ids max per call).
    $search = ['json' => ['people' => [[
        'id' => 'p1', 'first_name' => 'Jane', 'last_name_obfuscated' => 'Do***e', 'title' => 'Founder',
        'has_email' => true, 'has_direct_phone' => 'Yes',
        'organization' => ['name' => 'Acme Ltd', 'primary_domain' => 'acme.example'],
    ]]]];
    $enrich = ['json' => [
        'status' => 'success', 'error_code' => null, 'error_message' => null,
        'total_requested_enrichments' => 1, 'unique_enriched_records' => 1, 'missing_records' => 0,
        'matches' => [[
            'id' => 'p1', 'first_name' => 'Jane', 'last_name' => 'Doe', 'name' => 'Jane Doe',
            'email' => 'jane.doe@gmail.com', 'email_status' => 'verified',
            'phone_number' => '+1 555 0100', 'linkedin_url' => 'https://www.linkedin.com/in/janedoe',
            'city' => 'Lisbon', 'country' => 'PT',
            'organization' => ['name' => 'Acme Ltd', 'primary_domain' => 'acme.example', 'industry' => 'SaaS'],
        ]],
    ]];
    $p = new FakeTransportProvider([$search, $enrich], 'test-key', ['reveal_contacts' => true]);
    $rows = $p->searchBusinesses(['query' => 'founder lisbon']);

    assert_eq(1, count($rows), 'one_row');
    assert_eq('Jane Doe', $rows[0]['name'], 'full_name_revealed');
    assert_eq('jane.doe@gmail.com', $rows[0]['metadata']['email'], 'email_revealed');
    assert_eq('+1 555 0100', $rows[0]['phone'], 'phone_revealed');
    assert_eq('https://www.linkedin.com/in/janedoe', $rows[0]['metadata']['linkedin_url'], 'linkedin_revealed');
    assert_eq('https://acme.example', $rows[0]['website'], 'website_from_primary_domain');
    assert_true(str_contains((string)$rows[0]['address'], 'Lisbon'), 'address_enriched');
    assert_eq('SaaS', $rows[0]['metadata']['industry'], 'industry_enriched');
    assert_eq(true, $rows[0]['metadata']['enriched'], 'marked_enriched');
    assert_eq(false, $rows[0]['metadata']['privacy_safe'], 'no_longer_privacy_safe');
    assert_eq(true, $rows[0]['metadata']['has_direct_phone'], 'phone_flag_updated');
    assert_eq('verified', $rows[0]['metadata']['email_status'], 'email_status_from_enrichment');

    assert_eq([ApolloProvider::PEOPLE_API_SEARCH, ApolloProvider::PEOPLE_BULK_MATCH], $p->paths(), 'search_then_bulk_match');
    $body = json_decode((string)$p->lastBody(), true);
    assert_true(is_array($body) && isset($body['details']), 'bulk_match_sends_details');
    assert_eq([['id' => 'p1']], $body['details'], 'bulk_match_sends_apollo_ids');
    assert_eq(true, $p->lastParams()['reveal_personal_emails'] ?? null, 'personal_emails_requested');
    assert_eq(false, $p->lastParams()['reveal_phone_number'] ?? null, 'phone_reveal_off_by_default');

    $info = $p->lastSearchInfo();
    assert_eq(1, $info['reveal_requested'], 'one_enrichment_requested');
    assert_eq(1, $info['revealed'], 'one_contact_revealed');
    assert_eq(null, $info['notice'], 'no_notice_after_successful_reveal');
    return ['msg' => 'opt-in enrichment ok'];
};

$tests[] = function (): array {
    // The credit cap is honoured: 12 eligible rows, reveal_limit 10 → one batch.
    $people = [];
    for ($i = 1; $i <= 12; $i++) {
        $people[] = ['id' => 'p' . $i, 'first_name' => 'F' . $i, 'last_name_obfuscated' => 'L***i',
            'title' => 'Owner', 'has_email' => true, 'organization' => ['name' => 'Co ' . $i]];
    }
    $p = new FakeTransportProvider([
        ['json' => ['people' => $people]],
        ['json' => ['status' => 'success', 'matches' => []]],
    ], 'test-key', ['reveal_contacts' => '1', 'reveal_limit' => '10']);
    $rows = $p->searchBusinesses(['query' => 'owner']);
    assert_eq(12, count($rows), 'all_search_rows_kept');
    assert_eq(2, $p->calls, 'one_search_plus_one_bulk_batch');
    $body = json_decode((string)$p->lastBody(), true);
    assert_eq(10, count($body['details'] ?? []), 'cap_limits_the_batch_to_10');
    assert_eq(10, $p->lastSearchInfo()['reveal_requested'], 'reveal_requested_capped');
    assert_eq(0, $p->lastSearchInfo()['revealed'], 'nothing_matched');
    return ['msg' => 'reveal cap honoured'];
};

$tests[] = function (): array {
    // Enrichment refusal (no credits / missing people_bulk_match scope) must not
    // lose the search results — it degrades and reports honestly.
    $p = new FakeTransportProvider([
        ['json' => ['people' => [['id' => 'p1', 'first_name' => 'Jane', 'last_name_obfuscated' => 'Do***e',
            'title' => 'Founder', 'has_email' => true, 'organization' => ['name' => 'Acme']]]]],
        ['status' => 403, 'code' => 'API_INACCESSIBLE', 'message' => 'This endpoint is not in your key scope'],
    ], 'test-key', ['reveal_contacts' => true]);
    $rows = $p->searchBusinesses(['query' => 'founder']);
    assert_eq(1, count($rows), 'search_results_survive_a_refused_enrichment');
    assert_eq(null, $rows[0]['metadata']['email'] ?? null, 'no_fabricated_email');
    assert_eq(true, $rows[0]['metadata']['privacy_safe'], 'still_privacy_safe');
    $info = $p->lastSearchInfo();
    assert_eq(0, $info['revealed'], 'nothing_revealed');
    assert_true(str_contains(implode(' ', $info['notes']), 'Contact reveal skipped'), 'refusal_reported_in_notes');
    return ['msg' => 'enrichment refusal degrades gracefully'];
};

$tests[] = function (): array {
    // Reveal stays off unless an operator switches it on: no credits are spent
    // and the response explains why Person Mode finds no free-webmail contacts.
    $p = new FakeTransportProvider([['json' => ['people' => [
        ['id' => 'p1', 'first_name' => 'Jane', 'last_name_obfuscated' => 'Do***e', 'title' => 'Founder',
         'has_email' => true, 'organization' => ['name' => 'Acme']],
    ]]]]);
    $rows = $p->searchBusinesses(['query' => 'founder']);
    assert_eq(1, $p->calls, 'no_enrichment_call_when_reveal_is_off');
    assert_eq(null, $rows[0]['metadata']['email'] ?? null, 'no_email_without_reveal');
    $info = $p->lastSearchInfo();
    assert_eq(false, $info['reveal_enabled'], 'reveal_disabled');
    assert_true(is_string($info['notice']) && str_contains($info['notice'], 'credits'), 'notice_mentions_credits');
    return ['msg' => 'reveal is opt-in'];
};

$tests[] = function (): array {
    // Empty query still sends a payload (controller validates minimum length);
    // Apollo just returns broad records — we don't throw.
    $p = new FakeTransportProvider([['json' => ['people' => []]]]);
    $rows = $p->searchBusinesses(['query' => '', 'limit' => 1]);
    assert_eq(count($rows), 0, 'empty_query_no_results');
    assert_true(!array_key_exists('q_keywords', $p->lastParams()), 'no_empty_keyword_filter');
    return ['msg' => 'empty query handled without crash'];
};

$tests[] = function (): array {
    // Every returned row satisfies the contract keys expected by Api_lead_discovery::search().
    $p = new FakeTransportProvider([['json' => ['people' => [[
        'id' => 'p', 'first_name' => 'A', 'last_name' => 'B', 'title' => 'CTO',
        'email' => 'a@b.co', 'phone_number' => '+1', 'organization' => ['name' => 'Z', 'website_url' => 'https://z'],
    ]]]]]);
    $rows = $p->searchBusinesses(['query' => 'cto']);
    $required = ['sourceId', 'name', 'category', 'address', 'phone', 'website', 'latitude', 'longitude', 'metadata'];
    foreach ($rows as $r) {
        foreach ($required as $k) assert_true(array_key_exists($k, $r), "has_key_$k");
    }
    return ['msg' => 'contract keys present'];
};

run('84-apollo-lead-provider', $tests);
