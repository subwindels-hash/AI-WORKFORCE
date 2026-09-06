<?php
namespace AIWorkforce\Tests;

/**
 * Apollo.io "Test Connection" behaviour in Admin → API Management.
 *
 * Regression guard for the `apollo.io ✕ Connection failed` report: Apollo keys
 * are scoped per endpoint, so the documented `auth/health` ping answers 403 for
 * a key that was only granted `mixed_people/api_search` — a key that works
 * perfectly for Lead Discovery. The test therefore probes the endpoint this app
 * really calls before declaring failure, and every refusal names the fix
 * instead of a bare "Connection failed".
 *
 * No network: the injectable ApiProviders::$http transport is staged.
 */
require_once __DIR__ . '/../bootstrap.php';

$tests = [];

/**
 * Stage the transport and run one Apollo connection test.
 *
 * @param array<int,array{status:int,body?:string,errno?:int,error?:string}> $responses
 * @return array{result:array,calls:array<int,array{url:string,headers:array,body:?string}>}
 */
function apollo_test_run(array $responses, string $key = 'apollo-key-abc123', array $row = []): array
{
    $calls = [];
    $i = 0;
    $previous = \AIWorkforce\ApiProviders::$http;
    try {
        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) use (&$calls, &$i, $responses) {
            $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            $r = $responses[$i] ?? ['status' => 0, 'error' => 'no staged response'];
            $i++;
            return $r;
        };
        $result = \AIWorkforce\ApiProviders::test(
            $row + ['driver' => 'apollo_io', 'base_url' => '', 'extra' => [], 'service' => 'lead_discovery'],
            $key !== '' ? ['api_key' => $key] : []
        );
    } finally {
        \AIWorkforce\ApiProviders::$http = $previous;
    }
    return ['result' => $result, 'calls' => $calls];
}

$tests[] = function (): array {
    // Documented, credit-free key check: GET /api/v1/auth/health with x-api-key.
    $run = apollo_test_run([['status' => 200, 'body' => '{"healthy":true,"is_logged_in":true}']]);
    $res = $run['result'];
    assert_true($res['ok'] === true, 'connected');
    assert_contains('Connected to Apollo.io', $res['message']);
    assert_eq(1, count($run['calls']), 'single_probe_when_the_key_check_passes');
    assert_eq('https://api.apollo.io/api/v1/auth/health', $run['calls'][0]['url'], 'documented_health_url');
    assert_eq(null, $run['calls'][0]['body'], 'health_check_is_a_get');
    assert_in_array('x-api-key: apollo-key-abc123', $run['calls'][0]['headers'], 'key_sent_in_the_x_api_key_header');
    assert_false(str_contains($run['calls'][0]['url'], 'api_key='), 'key_never_in_the_query_string');
    assert_false(str_contains($res['message'], 'apollo-key-abc123'), 'message_never_echoes_the_key');
    return ['msg' => 'auth/health happy path'];
};

$tests[] = function (): array {
    // THE regression: a scoped key gets 403 on auth/health but works on the
    // endpoint Lead Discovery calls. It must report Connected, not failed.
    $run = apollo_test_run([
        ['status' => 403, 'body' => '{"status":"403","error_code":"API_INACCESSIBLE","error_message":"Endpoint not in key scope"}'],
        ['status' => 200, 'body' => '{"pagination":{"page":1,"per_page":1,"total_entries":4821},"people":[{"id":"p1"}],"breadcrumbs":[]}'],
    ]);
    $res = $run['result'];
    assert_true($res['ok'] === true, 'scoped_key_is_still_connected');
    assert_contains('mixed_people/api_search', $res['message'], 'message_names_the_endpoint_that_verified_the_key');
    assert_contains('scoped', $res['message'], 'message_explains_why_auth_health_refused');
    assert_eq(2, count($run['calls']), 'second_probe_only_after_a_refusal');
    assert_contains('/api/v1/mixed_people/api_search?per_page=1', $run['calls'][1]['url'], 'functional_probe_url_is_credit_free');
    assert_eq('{}', $run['calls'][1]['body'], 'functional_probe_is_a_post');
    assert_in_array('x-api-key: apollo-key-abc123', $run['calls'][1]['headers'], 'key_sent_on_the_second_probe_too');
    return ['msg' => 'scoped key verified on the real endpoint'];
};

$tests[] = function (): array {
    // 401 on the key check is final: the key itself is wrong, a second probe
    // would only waste a request.
    $run = apollo_test_run([['status' => 401, 'body' => '{"status":"401","code":"API_KEY_MISSING","message":"Invalid API key"}']]);
    assert_true($run['result']['ok'] === false, 'rejected');
    assert_contains('Invalid Apollo API key', $run['result']['message']);
    assert_contains('Regenerate', $run['result']['message'], 'message_says_how_to_fix_it');
    assert_eq(1, count($run['calls']), 'no_second_probe_on_401');
    return ['msg' => 'invalid key reported once, with the fix'];
};

$tests[] = function (): array {
    // Both probes refused: the honest reason is plan/scope, and the message must
    // tell the operator what to change in Apollo.
    $run = apollo_test_run([
        ['status' => 403, 'body' => '{"error_code":"API_INACCESSIBLE"}'],
        ['status' => 403, 'body' => '{"error_code":"API_INACCESSIBLE","error_message":"Your plan does not include API access"}'],
    ], 'apollo-key-abc123');
    $res = $run['result'];
    assert_true($res['ok'] === false, 'refused');
    assert_not_contains('Connection failed', $res['message'], 'never_the_bare_generic_message');
    assert_contains('master key', $res['message'], 'names_the_master_key_fix');
    assert_contains('mixed_people/api_search', $res['message'], 'names_the_scope_to_grant');
    assert_contains('work-email', $res['message'], 'names_the_free_account_rule');
    assert_false(str_contains($res['message'], 'apollo-key-abc123'), 'key_not_echoed');
    return ['msg' => 'plan/scope refusal explained'];
};

$tests[] = function (): array {
    // 404 on the key check (old proxy, wrong base) still falls through to the
    // functional probe instead of failing.
    $run = apollo_test_run([
        ['status' => 404, 'body' => ''],
        ['status' => 200, 'body' => '{"people":[]}'],
    ]);
    assert_true($run['result']['ok'] === true, 'still_connected_via_the_functional_probe');
    assert_eq(2, count($run['calls']), 'probed_twice');
    return ['msg' => 'missing auth/health falls through'];
};

$tests[] = function (): array {
    // auth/health answers 200 but says the key is not signed in.
    $run = apollo_test_run([['status' => 200, 'body' => '{"healthy":true,"is_logged_in":false}']]);
    assert_true($run['result']['ok'] === false, 'not_signed_in_is_a_failure');
    assert_contains('not signed in', $run['result']['message']);
    return ['msg' => 'is_logged_in=false honoured'];
};

$tests[] = function (): array {
    // A 200 that is not Apollo answering must not be reported as Connected:
    // healthy=false is a key/plan problem, and an HTML body means something
    // (a proxy, a wrong base URL) intercepted the request.
    $unhealthy = apollo_test_run([['status' => 200, 'body' => '{"healthy":false,"is_logged_in":true}']]);
    assert_true($unhealthy['result']['ok'] === false, 'healthy_false_is_a_failure');
    assert_contains('unhealthy', $unhealthy['result']['message']);

    $proxy = apollo_test_run([['status' => 200, 'body' => '<html><body>Access denied by firewall</body></html>']]);
    assert_true($proxy['result']['ok'] === false, 'non_json_200_is_not_connected');
    assert_contains('non-JSON', $proxy['result']['message']);
    assert_contains('proxy', $proxy['result']['message']);
    assert_eq(1, count($proxy['calls']), 'no second probe when the first answered 200');

    // An empty 200 body is still a pass — Apollo answered the documented URL.
    $empty = apollo_test_run([['status' => 200, 'body' => '']]);
    assert_true($empty['result']['ok'] === true, 'empty_200_body_still_connects');
    return ['msg' => 'a 200 that is not Apollo is not a pass'];
};

$tests[] = function (): array {
    // Transport failures must be diagnosable: TLS/CA, DNS and firewall are
    // different fixes, and "Connection failed" hides all three.
    $tls = apollo_test_run([['status' => 0, 'body' => '', 'errno' => 60,
        'error' => 'SSL certificate problem: unable to get local issuer certificate']]);
    assert_true($tls['result']['ok'] === false, 'tls_failure');
    assert_contains('cacert.pem', $tls['result']['message'], 'names_the_ca_bundle_fix');

    $dns = apollo_test_run([['status' => 0, 'body' => '', 'errno' => 6, 'error' => 'Could not resolve host: api.apollo.io']]);
    assert_contains('resolve', $dns['result']['message'], 'dns_failure_named');

    $blocked = apollo_test_run([['status' => 0, 'body' => '', 'errno' => 7, 'error' => "Couldn't connect to server"]]);
    assert_contains('firewall', $blocked['result']['message'], 'blocked_egress_named');
    assert_eq(1, count($blocked['calls']), 'no_second_probe_without_a_network');
    return ['msg' => 'network failures are diagnosable'];
};

$tests[] = function (): array {
    $limited = apollo_test_run([['status' => 429, 'body' => '{"message":"Rate limited"}']]);
    assert_true($limited['result']['ok'] === false, 'rate_limited_is_not_connected');
    assert_contains('rate limit', strtolower($limited['result']['message']), 'rate_limit_named');

    $down = apollo_test_run([['status' => 503, 'body' => '']]);
    assert_true($down['result']['ok'] === false, 'apollo_outage_is_not_connected');
    assert_contains('server error', strtolower($down['result']['message']), 'server_error_named');
    return ['msg' => '429 and 5xx reported honestly'];
};

$tests[] = function (): array {
    $missing = apollo_test_run([], '');
    assert_true($missing['result']['ok'] === false, 'no_key_is_not_connected');
    assert_contains('API key is required', $missing['result']['message'], 'tells_the_operator_what_is_missing');
    assert_eq(0, count($missing['calls']), 'no_request_without_a_key');

    $masked = apollo_test_run([], "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}1a2b");
    assert_true($masked['result']['ok'] === false, 'masked_value_is_not_a_key');
    assert_contains('masked placeholder', $masked['result']['message'], 'masked_paste_explained');
    assert_eq(0, count($masked['calls']), 'no_request_with_a_masked_key');
    return ['msg' => 'missing and masked keys explained'];
};

$tests[] = function (): array {
    // Paste accidents are cleaned instead of failing auth.
    assert_eq('abc123def456ghi789', \AIWorkforce\ApiProviders::normalizeApolloKey("  \"abc123def456ghi789\"\n"), 'quotes_and_whitespace_stripped');
    assert_eq('abc123def456ghi789', \AIWorkforce\ApiProviders::normalizeApolloKey("abc123def\n456ghi789"), 'line_break_inside_the_token_removed');
    assert_eq('ZZZ123456789abcdef', \AIWorkforce\ApiProviders::normalizeApolloKey(
        "curl --request GET --url 'https://api.apollo.io/api/v1/auth/health' --header 'x-api-key: ZZZ123456789abcdef'"
    ), 'key_extracted_from_a_pasted_curl_command');
    assert_eq('', \AIWorkforce\ApiProviders::normalizeApolloKey('   '), 'blank_stays_blank');

    $run = apollo_test_run([['status' => 200, 'body' => '{"healthy":true,"is_logged_in":true}']], "  apollo-key-abc123 \n");
    assert_true($run['result']['ok'] === true, 'padded_key_still_connects');
    assert_in_array('x-api-key: apollo-key-abc123', $run['calls'][0]['headers'], 'padded_key_sent_trimmed');
    return ['msg' => 'key hygiene'];
};

$tests[] = function (): array {
    // Base URL handling: a pasted /api/v1, a marketing host, or a proxy.
    assert_eq('https://api.apollo.io', \AIWorkforce\ApiProviders::apolloApiRoot([]), 'default_root');
    assert_eq('https://api.apollo.io', \AIWorkforce\ApiProviders::apolloApiRoot(['base_url' => 'https://api.apollo.io/api/v1']), 'version_suffix_not_doubled');
    assert_eq('https://api.apollo.io', \AIWorkforce\ApiProviders::apolloApiRoot(['base_url' => 'https://app.apollo.io']), 'app_host_is_not_an_api_origin');
    assert_eq('https://api.apollo.io', \AIWorkforce\ApiProviders::apolloApiRoot(['base_url' => 'http://api.apollo.io']), 'http_upgraded');
    assert_eq('https://proxy.example.test', \AIWorkforce\ApiProviders::apolloApiRoot(['base_url' => 'https://proxy.example.test/']), 'proxy_kept');

    $run = apollo_test_run([['status' => 200, 'body' => '{"healthy":true,"is_logged_in":true}']], 'apollo-key-abc123',
        ['base_url' => 'https://api.apollo.io/api/v1']);
    assert_eq('https://api.apollo.io/api/v1/auth/health', $run['calls'][0]['url'], 'no_doubled_api_v1_in_the_probe');
    return ['msg' => 'base URL canonicalisation'];
};

$tests[] = function (): array {
    // The runtime adapter and the connection test must agree on the endpoints,
    // otherwise the test can pass while Lead Discovery still fails.
    $lead = file_get_contents(APPPATH . 'libraries/LeadDiscovery/ApolloProvider.php');
    assert_contains('/api/v1/mixed_people/api_search', $lead, 'adapter_uses_the_documented_search_endpoint');
    assert_contains('/api/v1/people/bulk_match', $lead, 'adapter_uses_the_documented_enrichment_endpoint');
    assert_contains('x-api-key', $lead, 'adapter_authenticates_with_the_x_api_key_header');
    assert_false(str_contains($lead, "'api_key' =>"), 'adapter_never_puts_the_key_in_the_payload');

    $providers = file_get_contents(APPPATH . 'libraries/AIWorkforce/ApiProviders.php');
    assert_contains('/api/v1/auth/health', $providers, 'test_uses_the_documented_key_check');
    assert_contains('x-api-key', $providers, 'test_authenticates_with_the_x_api_key_header');
    return ['msg' => 'adapter and connection test agree'];
};

$tests[] = function (): array {
    // A 422 from the functional probe means Apollo authenticated the key and
    // only disliked the probe filters — that is a working connection, not a
    // failed one.
    $run = apollo_test_run([
        ['status' => 403, 'body' => '{"error_code":"API_INACCESSIBLE"}'],
        ['status' => 422, 'body' => '{"message":"q_keywords is not valid"}'],
    ]);
    assert_true($run['result']['ok'] === true, 'authenticated_but_validation_error_is_connected');
    assert_contains('authenticated', $run['result']['message']);

    // Contact reveal switched on: a passing test also names the extra scope the
    // enrichment endpoint needs, so the first search does not fail silently.
    $reveal = apollo_test_run([['status' => 200, 'body' => '{"healthy":true,"is_logged_in":true}']], 'apollo-key-abc123',
        ['extra' => ['reveal_contacts' => '1']]);
    assert_true($reveal['result']['ok'] === true, 'connected_with_reveal_on');
    assert_contains('people/bulk_match', $reveal['result']['message'], 'names_the_extra_scope_reveal_needs');
    return ['msg' => '422 probe + reveal scope note'];
};

run('112-apollo-connection-test', $tests);
