<?php
/**
 * Verify an Apollo.io key against the endpoints Lead Discovery uses, and show
 * exactly what Apollo answered — so a "✕ HTTP 403 API_INACCESSIBLE" verdict in
 * Admin → API Management can be confirmed (or disproved) against the live API.
 *
 * Usage:
 *   APOLLO_IO_API_KEY=... php tools/apollo_probe.php
 *   php tools/apollo_probe.php --key=... [--base=https://api.apollo.io] [--reveal]
 *
 * Spends 0 credits: auth/health and mixed_people/api_search are both free.
 * The key is never printed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) $opts[$m[1]] = $m[2] ?? '1';
}

$root = __DIR__ . '/..';
require_once $root . '/application/config/env.php';
vp_load_env($root . '/.env');

$key = trim((string) ($opts['key'] ?? ''));
foreach (['APOLLO_IO_API_KEY', 'APOLLO_API_KEY'] as $var) {
    if ($key !== '') break;
    $v = getenv($var);
    if ($v !== false) $key = trim((string) $v);
}
if ($key === '') {
    fwrite(STDERR, "No Apollo key. Pass --key=... or set APOLLO_IO_API_KEY (env or .env).\n");
    exit(2);
}

$base = rtrim((string) ($opts['base'] ?? getenv('APOLLO_IO_API_BASE') ?: 'https://api.apollo.io'), '/');
$base = preg_replace('#/api/v1/?$#', '', $base) ?? $base;

$headers = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Cache-Control: no-cache',
    'x-api-key: ' . $key,
];

/** @return array{status:int,body:string,error:string} */
function probe(string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $res = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => is_string($out) ? $out : '',
        'error' => (string) curl_error($ch),
    ];
    curl_close($ch);
    return $res;
}

function report(string $label, string $url, array $r): array
{
    $json = json_decode($r['body'], true);
    $isJson = is_array($json);
    echo str_pad($label, 26) . ' ' . $url . "\n";
    echo '  HTTP ' . $r['status'] . ($r['error'] !== '' ? '  transport: ' . $r['error'] : '')
        . '  body: ' . ($isJson ? 'JSON' : 'non-JSON') . "\n";
    echo '  ' . mb_substr(trim(preg_replace('/\s+/', ' ', $r['body']) ?? ''), 0, 220) . "\n\n";
    return $isJson ? $json : [];
}

echo "Apollo probe — base " . $base . " (0 credits, key not printed)\n\n";

$h = probe($base . '/api/v1/auth/health', $headers);
report('1. auth/health', $base . '/api/v1/auth/health', $h);

$searchUrl = $base . '/api/v1/mixed_people/api_search?per_page=1&q_keywords=apollo';
$s = probe($searchUrl, $headers, '{}');
$sj = report('2. people api_search', $searchUrl, $s);

if (!empty($opts['reveal'])) {
    $bulkUrl = $base . '/api/v1/people/bulk_match';
    $b = probe($bulkUrl, $headers, json_encode(['details' => [['domain' => 'apollo.io']]]) ?: '{}');
    report('3. people/bulk_match', $bulkUrl, $b);
}

echo "Verdict: ";
$code = strtoupper(trim((string) ($sj['error_code'] ?? $sj['code'] ?? '')));
if ($s['status'] >= 200 && $s['status'] < 300) {
    echo "OK — this key CAN call People Search. Any \"403\" shown in the app is stale; re-run Test Connection.\n";
} elseif ($s['status'] === 422) {
    echo "OK — the key authenticated; only the probe filters were rejected (422).\n";
} elseif ($s['status'] === 401) {
    echo "The key itself is invalid (401). Regenerate it in Apollo → Settings → Integrations → API Keys.\n";
} elseif ($s['status'] === 403 && $code === '') {
    echo "403 with no Apollo error envelope — likely a proxy/WAF, not Apollo. Check outbound HTTPS and the base URL.\n";
} elseif ($s['status'] === 403) {
    echo "Confirmed by Apollo: " . $code . ". Grant the mixed_people_api_search scope (or master key), "
        . "and make sure the plan includes API access (work-email signup for free accounts).\n";
} elseif ($s['status'] === 429) {
    echo "Rate limited (429) — retry in a minute.\n";
} else {
    echo "Unexpected HTTP " . $s['status'] . " — see the body above.\n";
}
