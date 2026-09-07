<?php
/**
 * MT4/MT5 Expert Advisor source checker (Automatic Kill Switch §10).
 *
 * The MQL sources in mt4-mt5/ cannot be compiled here — MetaEditor runs on
 * Windows and needs a terminal. What CAN go wrong without a compiler is
 * silent drift: someone adds a condition to the PHP engine, or renames a
 * decision key, and the EA quietly stops enforcing it. This script pins the
 * contract between the three languages so that drift fails loudly.
 *
 * It checks, for MQL5 and MQL4 alike:
 *
 *   1. balanced braces / parentheses / brackets (strings and comments ignored)
 *   2. the library exposes the API every EA calls
 *   3. EVERY condition code the platform engine can emit (read from
 *      EaProtection.php) is implemented in both libraries
 *   4. every order-sending call sits inside a function that asks
 *      AIWF_AllowNewTrades() first (§11 — nothing bypasses the gate)
 *   5. every heartbeat field the platform ingests (read from
 *      EaProtection::normalizeHeartbeat()) is produced by both libraries
 *   6. every decision key the MQL parses is written by the Python bridge
 *
 * Usage:  php tools/check_mql.php            (exit 0 = clean, 1 = drift)
 *
 * It is deliberately dependency-free: no CodeIgniter, no database, no MQL.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$failures = [];
$checks = 0;

/** "MQL5" or "MQL4" — the flavour a path belongs to. */
function mql_label(string $rel): string
{
    return strpos($rel, 'MQL5') === 0 ? 'MQL5' : 'MQL4';
}

/** The body of the function whose name contains $needle (brace-matched). */
function mql_block(string $src, string $needle): string
{
    $at = strpos($src, $needle);
    if ($at === false) return '';
    $open = strpos($src, '{', $at);
    if ($open === false) return '';
    $depth = 0;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $open, $i - $open + 1);
        }
    }
    return '';
}

function mql_fail(array &$failures, string $message): void
{
    $failures[] = $message;
}

function mql_ok(int &$checks, string $label): void
{
    $checks++;
    echo "  ok    {$label}\n";
}

/** Strip comments and string literals so punctuation can be counted. */
function mql_strip(string $src): string
{
    $out = '';
    $len = strlen($src);
    $i = 0;
    while ($i < $len) {
        $ch = $src[$i];
        if ($ch === '/' && $i + 1 < $len && $src[$i + 1] === '/') {
            while ($i < $len && $src[$i] !== "\n") $i++;
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $src[$i + 1] === '*') {
            $i += 2;
            while ($i + 1 < $len && !($src[$i] === '*' && $src[$i + 1] === '/')) $i++;
            $i += 2;
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $i++;
            while ($i < $len) {
                if ($src[$i] === '\\') { $i += 2; continue; }
                if ($src[$i] === $quote) { $i++; break; }
                $i++;
            }
            continue;
        }
        $out .= $ch;
        $i++;
    }
    return $out;
}

/**
 * Split a file into top-level blocks: ['header' => …, 'func name(args)' => body].
 * Good enough for "is this call inside a function that also calls X?".
 */
function mql_functions(string $src): array
{
    $code = mql_strip($src);
    $functions = ['(file)' => $code];
    $depth = 0;
    $start = null;
    $signature = '';
    $len = strlen($code);

    for ($i = 0; $i < $len; $i++) {
        if ($depth === 0 && $start === null) {
            // A signature ends where its body begins.
            if ($code[$i] === '{') {
                $signature = trim(substr($code, max(0, $i - 200), $i - max(0, $i - 200)));
                $signature = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('#//.*#', '', $signature)));
                $start = $i;
            }
        }
        if ($code[$i] === '{') $depth++;
        elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0 && $start !== null) {
                $functions[$signature !== '' ? $signature : '(anon@' . $start . ')'] = substr($code, $start, $i - $start + 1);
                $start = null;
                $signature = '';
            }
        }
    }
    return $functions;
}

echo "MT4/MT5 Expert Advisor source check (§10)\n";

// ─── Files ─────────────────────────────────────────────────────────
$files = [
    'MQL5/Include/AIWorkforceProtection.mqh',
    'MQL5/Experts/AIWorkforceTradeManager.mq5',
    'MQL5/Experts/AIWorkforceEquityProtector.mq5',
    'MQL5/Experts/AIWorkforceNewsFilter.mq5',
    'MQL5/Experts/AIWorkforceRiskManager.mq5',
    'MQL4/Include/AIWorkforceProtection.mqh',
    'MQL4/Experts/AIWorkforceTradeManager.mq4',
    'MQL4/Experts/AIWorkforceEquityProtector.mq4',
    'MQL4/Experts/AIWorkforceNewsFilter.mq4',
    'MQL4/Experts/AIWorkforceRiskManager.mq4',
];

$sources = [];
foreach ($files as $rel) {
    $path = $root . '/mt4-mt5/' . $rel;
    if (!is_file($path)) {
        mql_fail($failures, "missing source file: mt4-mt5/{$rel}");
        continue;
    }
    $sources[$rel] = (string) file_get_contents($path);
}

// ─── 1 — balance ───────────────────────────────────────────────────
echo "\n1 — syntax shape\n";
foreach ($sources as $rel => $src) {
    $code = mql_strip($src);
    $braces = substr_count($code, '{') - substr_count($code, '}');
    $parens = substr_count($code, '(') - substr_count($code, ')');
    $brackets = substr_count($code, '[') - substr_count($code, ']');
    if ($braces !== 0 || $parens !== 0 || $brackets !== 0) {
        mql_fail($failures, sprintf('%s is unbalanced (braces %d, parens %d, brackets %d)', $rel, $braces, $parens, $brackets));
        continue;
    }
    mql_ok($checks, "{$rel} balanced");
}

// ─── 2 — the library API every EA relies on ────────────────────────
echo "\n2 — library API\n";
$libraryApi = [
    'AIWF_Init', 'AIWF_OnTick', 'AIWF_AllowNewTrades', 'AIWF_RecordOrderResult',
    'AIWF_EvaluateLocal', 'AIWF_EmergencyActions', 'AIWF_ApplyState',
    'AIWF_SendHeartbeat', 'AIWF_ApplyDecision', 'AIWF_HeartbeatJson',
    'AIWF_MinutesToNextEvent', 'AIWF_DrawdownPercent', 'AIWF_DailyPnl',
    'AIWF_MarginLevel', 'AIWF_LotSize', 'AIWF_SpreadPoints', 'AIWF_EaId',
    'AIWF_IsBlocking', 'AIWF_BlockReason', 'AIWF_PointSize',
];
foreach (['MQL5/Include/AIWorkforceProtection.mqh', 'MQL4/Include/AIWorkforceProtection.mqh'] as $lib) {
    if (!isset($sources[$lib])) continue;
    $missing = [];
    foreach ($libraryApi as $fn) {
        if (!preg_match('/(^|[^A-Za-z0-9_])' . preg_quote($fn, '/') . '\s*\(/m', $sources[$lib])) $missing[] = $fn;
    }
    if ($missing !== []) {
        mql_fail($failures, $lib . ' is missing: ' . implode(', ', $missing));
        continue;
    }
    mql_ok($checks, mql_label($lib) . ' library exposes all ' . count($libraryApi) . ' entry points');
}

// ─── 3 — every platform condition is implemented locally ───────────
echo "\n3 — condition coverage (platform ⇄ terminal)\n";
$enginePath = $root . '/application/libraries/AIWorkforce/TradingProtection/EaProtection.php';
if (!is_file($enginePath)) {
    mql_fail($failures, 'cannot read EaProtection.php — condition coverage not checked');
} else {
    $engine = (string) file_get_contents($enginePath);
    // Only the codes conditions() can raise are terminal conditions; the
    // EA_PROTECTION_* names are audit/notification event types.
    $conditionsBody = mql_block($engine, 'private function conditions(');
    if ($conditionsBody === '') {
        mql_fail($failures, 'cannot locate conditions() in EaProtection.php');
        $codes = [];
    } else {
        preg_match_all("/'(EA_[A-Z0-9_]+)'/", $conditionsBody, $matches);
        $codes = array_values(array_unique($matches[1] ?? []));
    }
    // Platform-only codes: emitted by the engine, not by the terminal itself.
    // Emitted by the engine itself, not by the terminal: there is no heartbeat
    // staleness or unreadable-spread concept inside an EA.
    $platformOnly = ['EA_HEARTBEAT_STALE', 'EA_DECISION_UNAVAILABLE', 'EA_SPREAD_UNREADABLE'];
    $required = array_values(array_diff($codes, $platformOnly));
    $required = array_values(array_unique($required));
    sort($required);

    if (count($required) < 10) {
        mql_fail($failures, 'only ' . count($required) . ' EA_* condition codes found in EaProtection.php — did the constant naming change?');
    }

    foreach (['MQL5/Include/AIWorkforceProtection.mqh', 'MQL4/Include/AIWorkforceProtection.mqh'] as $lib) {
        if (!isset($sources[$lib])) continue;
        $missing = [];
        foreach ($required as $quoted) {
            $code = trim($quoted, "'");
            if (strpos($sources[$lib], $code) === false) $missing[] = $code;
        }
        if ($missing !== []) {
            mql_fail($failures, $lib . ' does not implement: ' . implode(', ', $missing));
            continue;
        }
        mql_ok($checks, mql_label($lib) . ' implements all ' . count($required) . ' terminal conditions');
    }
}

// ─── 4 — every order goes through the gate ─────────────────────────
echo "\n4 — order gate (§11)\n";
foreach ($files as $rel) {
    if (!isset($sources[$rel]) || strpos($rel, 'Experts/') === false) continue;
    $isMql4 = substr($rel, -4) === '.mq4';
    $senders = $isMql4 ? ['OrderSend('] : ['.Buy(', '.Sell('];
    $functions = mql_functions($sources[$rel]);

    $unguarded = [];
    $sendsOrders = false;
    foreach ($functions as $signature => $body) {
        $sends = false;
        foreach ($senders as $sender) {
            if (strpos($body, $sender) !== false) { $sends = true; break; }
        }
        if (!$sends) continue;
        $sendsOrders = true;
        if (strpos($body, 'AIWF_AllowNewTrades()') === false) {
            $unguarded[] = $signature === '' ? '(anonymous)' : substr($signature, 0, 60);
        }
    }
    if ($unguarded !== []) {
        mql_fail($failures, $rel . ' sends orders without asking AIWF_AllowNewTrades() in: ' . implode(' | ', $unguarded));
        continue;
    }
    if ($sendsOrders && strpos($sources[$rel], 'AIWF_RecordBlockedOrder()') === false) {
        mql_fail($failures, $rel . ' can refuse an entry but never records it (AIWF_RecordBlockedOrder) — refusals would be invisible in the audit trail (§13)');
        continue;
    }
    mql_ok($checks, basename($rel) . ($sendsOrders ? ' gates every order it sends and records every refusal' : ' sends no orders'));
}

// ─── 5 — heartbeat fields (terminal ⇄ platform) ────────────────────
echo "\n5 — heartbeat contract\n";
if (isset($engine)) {
    // Every key normalizeHeartbeat() reads must be produced by the EA's JSON.
    $normalizerBody = mql_block($engine, 'private function normalizeHeartbeat(');
    if ($normalizerBody === '') {
        mql_fail($failures, 'cannot locate normalizeHeartbeat() in EaProtection.php');
        $fields = [];
    } else {
        // Every 'key' => in the normaliser is a field the platform reads, so
        // every one of them has to be produced by the terminal.
        preg_match_all("/'([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/", $normalizerBody, $m);
        $containers = ['eaId', 'metrics', 'connection', 'news', 'actions'];
        $fields = array_values(array_unique(array_diff($m[1] ?? [], $containers)));
    }
    foreach (['MQL5/Include/AIWorkforceProtection.mqh', 'MQL4/Include/AIWorkforceProtection.mqh'] as $lib) {
        if (!isset($sources[$lib])) continue;
        // The JSON is an MQL string literal, so its quotes arrive escaped.
        $json = str_replace('\\"', '"', mql_block($sources[$lib], 'string AIWF_HeartbeatJson'));
        $missing = [];
        foreach ($fields as $field) {
            if ($json === '' || strpos($json, '"' . $field . '"') === false) $missing[] = $field;
        }
        if ($missing !== []) {
            mql_fail($failures, mql_label($lib) . ' heartbeat JSON does not include: ' . implode(', ', $missing));
            continue;
        }
        mql_ok($checks, mql_label($lib) . ' heartbeat carries all ' . count($fields) . ' fields the platform reads');
    }
}

// ─── 6 — decision keys (platform → bridge → terminal) ──────────────
echo "\n6 — decision contract\n";
$bridgePath = $root . '/python-services/mt5-bridge/app.py';
$decisionKeys = ['state', 'code', 'reason', 'allowNewTrades', 'closePositions', 'cancelPendingOrders'];
if (!is_file($bridgePath)) {
    mql_fail($failures, 'cannot read python-services/mt5-bridge/app.py — decision contract not checked');
} else {
    $bridge = (string) file_get_contents($bridgePath);
    if (!preg_match('/def decision_to_text.*?(?=\n@app|\Z)/s', $bridge, $bm)) {
        mql_fail($failures, 'decision_to_text() not found in the bridge');
    } else {
        $missing = [];
        foreach ($decisionKeys as $key) {
            if (strpos($bm[0], $key . '=') === false) $missing[] = $key;
        }
        if ($missing !== []) {
            mql_fail($failures, 'the bridge decision text omits: ' . implode(', ', $missing));
        } else {
            mql_ok($checks, 'bridge emits every decision key the terminal parses');
        }
    }
    foreach (['MQL5/Include/AIWorkforceProtection.mqh', 'MQL4/Include/AIWorkforceProtection.mqh'] as $lib) {
        if (!isset($sources[$lib])) continue;
        $missing = [];
        foreach ($decisionKeys as $key) {
            if (strpos($sources[$lib], '"' . $key . '"') === false) $missing[] = $key;
        }
        if ($missing !== []) {
            mql_fail($failures, mql_label($lib) . ' does not parse decision key(s): ' . implode(', ', $missing));
            continue;
        }
        mql_ok($checks, mql_label($lib) . ' parses every decision key the bridge writes');
    }
}

// ─── Result ────────────────────────────────────────────────────────
echo "\n";
if ($failures !== []) {
    foreach ($failures as $failure) echo "  FAIL  {$failure}\n";
    echo "\nRESULT: {$checks} passed, " . count($failures) . " failed\n";
    exit(1);
}
echo "RESULT: {$checks} passed, 0 failed — the MT4/MT5 sources agree with the platform.\n";
exit(0);
