<?php
/**
 * Schema ↔ code width contracts (added after the 2026-09-10 NO_QUALIFIED_TICKET).
 *
 * The deployed daily-ticket engine could not break its calibration cold
 * start because sports_calibrations.method is VARCHAR(16) and the bootstrap
 * marker 'identity-bootstrap' is 18 characters: MySQL truncated or rejected
 * the row, the read-back never matched, and the whole day died on
 * MODEL_NOT_CALIBRATED. The test harness runs on SQLite, where TEXT columns
 * enforce no length — so the suite was fully green around the bug.
 *
 * These cases pin the contract at the only place that can enforce it when
 * the dev database cannot: the shipped DDL and the literals the code writes.
 */
use AIWorkforce\Sports\CalibrationBootstrap;

/**
 * Width of one VARCHAR column inside a CREATE TABLE block, or null when the
 * dialect stores the column unbounded (e.g. TEXT) / the table is absent.
 */
function cbw_varchar_width(string $file, string $table, string $column): ?int
{
    $sql = (string) file_get_contents($file);
    $pattern = '/CREATE TABLE (?:IF NOT EXISTS )?[`"]?' . preg_quote($table, '/') . '[`"]?\s*\((.*?)^\)/sm';
    if (!preg_match($pattern, $sql, $block)) return null;
    $col = '/[`"]?' . preg_quote($column, '/') . '[`"]?\s+(?:character\s+varying|VARCHAR)\((\d+)\)/i';
    return preg_match($col, $block[1], $w) ? (int) $w[1] : null;
}

test('the calibration method column fits every marker the codebase can write', function () {
    $files = [
        FCPATH . 'database/production.sql',
        FCPATH . 'application/database/sports_intelligence.mysql.sql',
        FCPATH . 'application/database/sports_intelligence.pgsql.sql',
    ];
    $markers = ['platt', CalibrationBootstrap::method(), 'identity-bootstrap', 'identity']; // old + new, whole + any future
    $widestMarker = max(array_map('strlen', $markers));
    foreach ($files as $file) {
        $width = cbw_varchar_width($file, 'sports_calibrations', 'method');
        assert_true($width !== null, basename($file) . ' declares sports_calibrations.method as VARCHAR(n)');
        assert_true($width >= $widestMarker, basename($file) . ' method column must fit the widest marker the code writes (have ' . $width . ', need ' . $widestMarker . ')');
    }
});

test('the bootstrap marker fits the NARROWEST deployed column — un-migrated installs must keep working', function () {
    // The fix may not depend on operators running the ALTER: live installs
    // keep their VARCHAR(16) column until the boot-time upgrade widens it,
    // so the marker itself must always be deployable into 16 characters.
    assert_true(strlen(CalibrationBootstrap::method()) <= 16, 'marker must fit the legacy 16-char column');
});

test('audit_logs.actor fits every actor the application writes', function () {
    $actors = ['system:daily-ticket', 'system:cron-sweep', 'system', 'user'];
    $longest = max(array_map('strlen', $actors));
    foreach ([
        FCPATH . 'database/production.sql',
        FCPATH . 'application/database/schema.mysql.sql',
        FCPATH . 'application/database/schema.pgsql.sql',
    ] as $file) {
        $width = cbw_varchar_width($file, 'audit_logs', 'actor');
        assert_true($width !== null && $width >= $longest, basename($file) . ' audit_logs.actor must fit the longest system actor (have ' . var_export($width, true) . ', need ' . $longest . ')');
    }
});

test('audit types emitted across the app fit the stored audit_logs.type width', function () {
    // A too-long type is silently swallowed by the audit write's try/catch
    // (db_debug=false) — exactly how the daily-ticket audit trail vanished,
    // and how 'AUTOMATIC_PROTECTION_AUTOMATIC_PAUSED' (35 chars, dynamic
    // concatenation) vanished before it. The contract is measured against
    // the shipped DDL, not a remembered constant.
    $width = null;
    foreach ([FCPATH . 'database/production.sql', FCPATH . 'application/database/schema.mysql.sql'] as $file) {
        $w = cbw_varchar_width($file, 'audit_logs', 'type');
        assert_true($w !== null && ($width === null || $w === $width), basename($file) . ' declares audit_logs.type VARCHAR(n) consistently');
        $width = $w;
    }
    assert_true($width >= 64, 'audit_logs.type must be at least VARCHAR(64) — deployed installs had 32');
    // The longest type the protection engines can compose at runtime.
    $dynamic = max(
        strlen('AUTOMATIC_PROTECTION_' . \AIWorkforce\TradingProtection\AutomaticProtection::PAUSED),
        strlen('AUTOMATIC_PROTECTION_' . \AIWorkforce\TradingProtection\AutomaticProtection::KILL),
        strlen('EA_PROTECTION_' . 'DISCONNECTED_TIMEOUT')
    );
    assert_true($dynamic <= $width, 'the longest dynamic audit type (' . $dynamic . ' chars) must fit audit_logs.type');
    // And every static literal the app emits.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH));
    $longest = 0; $worst = '';
    foreach ($it as $f) {
        if ($f->isDir() || !str_ends_with($f->getFilename(), '.php')) continue;
        $src = (string) file_get_contents($f->getPathname());
        if (preg_match_all('/->emit\(\s*\'([A-Z][A-Z0-9_]+)\'/', $src, $m)) {
            foreach ($m[1] as $type) {
                if (strlen($type) > $longest) { $longest = strlen($type); $worst = $type; }
            }
        }
    }
    assert_true($longest <= $width, 'longest static audit type is ' . $longest . ' chars (' . $worst . ') — must fit audit_logs.type(' . $width . ')');
});

test('the boot-time upgrade widens the historically too-narrow columns', function () {
    $src = (string) file_get_contents(APPPATH . 'libraries/AIWorkforce/SchemaInstaller.php');
    assert_contains('ALTER TABLE sports_calibrations MODIFY method VARCHAR(32)', $src, 'existing MySQL installs are repaired at boot');
    assert_contains('ALTER TABLE audit_logs MODIFY actor VARCHAR(64)', $src, 'existing MySQL installs keep their audit trail');
    assert_contains('ALTER TABLE audit_logs MODIFY type VARCHAR(64)', $src, 'and their dynamic transition types');
    assert_contains('ALTER COLUMN "method" TYPE VARCHAR(32)', $src, 'PostgreSQL equivalents are present');
    assert_contains('ALTER COLUMN "actor" TYPE VARCHAR(64)', $src, 'PostgreSQL equivalents are present');
    assert_contains('ALTER COLUMN "type" TYPE VARCHAR(64)', $src, 'PostgreSQL equivalents are present');
});

test('the audit repository clamps type and actor to the stored widths', function () {
    $src = (string) file_get_contents(APPPATH . 'models/AIWorkforce_model.php');
    assert_true(preg_match('/public function emit\([^)]*\)[^{]*\{[^}]*mb_substr\(\$type, 0, 64\)[^}]*mb_substr\(\$actor, 0, 64\)/s', $src) === 1,
        'emit() clamps type and actor to their stored widths so a too-long value can never be swallowed as a failed write');
});

test('the persisted configuration is layered over defaults so no consumer sees an absent key', function () {
    $repo = new SportsRepositoryStub();
    $audit = new class implements \AIWorkforce\Persistence\AuditRepository {
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {}
        public function recent(int $l = 100): array { return []; }
    };
    $svc = new \AIWorkforce\Sports\ConfigurationService($repo, $audit);

    // No stored row at all: defaults, every key present.
    foreach (array_keys(\AIWorkforce\Sports\ConfigurationService::defaults()) as $key) {
        assert_true(array_key_exists($key, $svc->active()), 'active() carries ' . $key . ' even with no stored row');
    }

    // A stored row written before later columns existed: it wins where it
    // has values, the defaults fill the rest — no consumer may resolve an
    // absent key on its own (that asymmetry is the 2026-09-10 lock-out).
    $repo->saveConfiguration(['version' => 3, 'module_enabled' => 1, 'min_confidence' => 40.0, 'reason' => 'partial legacy row']);
    $active = $svc->active();
    assert_equals(3, $active['version'], 'the stored row is the active configuration');
    assert_equals(40.0, $active['min_confidence'], 'stored values are not overwritten by defaults');
    assert_equals(1, $active['require_calibration'], 'absent keys arrive as the documented default');
    assert_equals('USER_APPROVAL_REQUIRED', $active['engine_mode'], 'and for enums too');
    assert_equals([], $active['allowed_leagues'], 'JSON-encoded list fields decode to arrays');
});
