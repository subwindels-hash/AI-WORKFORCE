/**
 * Strict-MySQL/MariaDB round-trip verification for the identity
 * calibration bootstrap.
 *
 * The PHP test suite runs on pdo_sqlite, where every column is untyped
 * TEXT — it cannot reproduce the 2026-09-10 lock-out: a STRICT
 * MySQL/MariaDB server rejects the RFC-3339 literal gmdate('c') produces
 * ('2026-09-10T19:10:08+00:00') in a DATETIME column with error 1292,
 * and CodeIgniter (db_debug=false) silently swallows the failed INSERT,
 * so the bootstrap row never exists and every fixture dies on
 * MODEL_NOT_CALIBRATED.
 *
 * This runs the shipped MySQL DDL against a real MariaDB engine
 * (compiled to WebAssembly, InnoDB included), in STRICT mode exactly
 * like the production connection ('stricton' => true), and proves:
 *
 *   1. the old RFC-3339 literal FAILS the strict DATETIME insert (the bug);
 *   2. the canonical 'Y-m-d H:i:s' literal the repository now writes
 *      succeeds on BOTH the shipped VARCHAR(32) method column and the
 *      legacy VARCHAR(16) column un-migrated databases still have;
 *   3. the bootstrap marker ('identity') and 0/1 values read back
 *      intact — insert → read-back on the SAME record;
 *   4. the APPROVE update round-trips on the same record and the
 *      "latest APPROVED calibration" query finds it.
 *
 * Usage: node runtime/verify-calibration-mysql.mjs   (exit 0 = verified)
 */
import fs from 'node:fs';
import path from 'node:path';
import Lite4MariaDB from 'lite4mariadb';

const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const DDL_FILE = path.join(APP_ROOT, 'application', 'database', 'sports_intelligence.mysql.sql');
const MARKER = 'identity'; // CalibrationBootstrap::method()
const CANONICAL_TS = '2026-09-10 19:10:08';
const RFC3339_TS = '2026-09-10T19:10:08+00:00';

let failures = 0;
const ok = (cond, label, extra = '') => {
  console.log(`${cond ? 'OK  ' : 'FAIL'} ${label}${extra ? ' — ' + extra : ''}`);
  if (!cond) failures++;
};

/** Extract the shipped CREATE TABLE block for a table. */
function shippedCreateTable(sql, table) {
  const m = sql.match(new RegExp(`CREATE TABLE IF NOT EXISTS ${table}\\s*\\([\\s\\S]*?\\n\\)`, 'i'));
  if (!m) throw new Error(`shipped DDL for ${table} not found`);
  return m[0].replace(/ENGINE=\w+.*$/i, '');
}

const columnsList = `model_version_id,method,intercept,slope,brier,ece,samples,bins,status,created_by,created_at`;
const values = (ts) =>
  `(1,'${MARKER}',0,1,NULL,NULL,0,'[]','PENDING','system:daily-ticket','${ts}')`;

function insertStatement(ts) {
  return `INSERT INTO sports_calibrations (${columnsList}) VALUES ${values(ts)}`;
}

async function main() {
  const ddl = fs.readFileSync(DDL_FILE, 'utf8');
  const db = await Lite4MariaDB.create({ dataDir: 'memory://' });
  const exec = async (sql, label) => {
    try {
      const r = await db.exec(sql);
      return { ok: true, ...r };
    } catch (e) {
      return { ok: false, error: String(e.message || e).split('\n')[0] };
    }
  };

  for (const variant of ['shipped VARCHAR(32)', 'legacy VARCHAR(16)']) {
    console.log(`\n── ${variant} method column, STRICT_TRANS_TABLES ──`);
    let create = shippedCreateTable(ddl, 'sports_calibrations');
    if (variant.startsWith('legacy')) create = create.replace(/method VARCHAR\(32\)/, 'method VARCHAR(16)');
    let r = await exec(create, 'create');
    ok(r.ok, 'shipped table DDL installs', r.error || '');
    await exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

    // 1. The old literal is rejected (the incident).
    r = await exec(insertStatement(RFC3339_TS));
    ok(!r.ok && /Incorrect datetime value/i.test(r.error || ''),
      "gmdate('c') RFC-3339 literal is REJECTED in strict mode", r.error || 'unexpectedly accepted');

    // 2. The canonical literal the fixed repository writes is accepted.
    r = await exec(insertStatement(CANONICAL_TS));
    ok(r.ok, "canonical 'Y-m-d H:i:s' insert succeeds", r.error || '');

    // 3. Read back the SAME record, intact.
    const rows = await db.query(
      'SELECT method,intercept,slope,status,created_by,created_at FROM sports_calibrations'
    );
    const row = rows[0] || {};
    ok(rows.length === 1, 'exactly one bootstrap row exists', `got ${rows.length}`);
    ok(row.method === MARKER, `marker '${MARKER}' reads back exactly`, row.method);
    ok(Number(row.intercept) === 0 && Number(row.slope) === 1, 'identity 0/1 reads back', `${row.intercept}/${row.slope}`);
    ok(String(row.status) === 'PENDING', 'PENDING reads back', String(row.status));
    ok(String(row.created_at) === CANONICAL_TS, 'created_at reads back canonical', String(row.created_at));

    // 4. Auto-approval UPDATE round-trip on the SAME record, and the
    //    "active calibration" query the engine actually uses finds it.
    r = await exec(`UPDATE sports_calibrations SET status='APPROVED',approved_by='system:daily-ticket',approved_at='${CANONICAL_TS}' WHERE id=1`);
    ok(r.ok, 'approve update succeeds', r.error || '');
    const active = await db.query(
      "SELECT method,status,approved_at FROM sports_calibrations WHERE model_version_id=1 AND status='APPROVED' ORDER BY created_at DESC LIMIT 1"
    );
    ok(active.length === 1 && String(active[0].status) === 'APPROVED' && String(active[0].method) === MARKER,
      'the latest APPROVED identity calibration is the row the engine reads back');
    ok(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(String(active[0].approved_at)), 'approved_at is canonical', String(active[0].approved_at));

    // 5. The full 18-char legacy marker would still be rejected by the
    //    narrow column — documenting why the engine marker MUST stay short
    //    (case 136) rather than depending on the migration.
    const legacyLong = `INSERT INTO sports_calibrations (${columnsList}) VALUES (1,'identity-bootstrap',0,1,NULL,NULL,0,'[]','PENDING','system:daily-ticket','${CANONICAL_TS}')`;
    r = await exec(legacyLong);
    if (variant.startsWith('legacy')) {
      ok(!r.ok, 'the OLD 18-char marker is rejected by the legacy VARCHAR(16) column', r.error || 'unexpectedly accepted');
    }
    await exec('DROP TABLE sports_calibrations');
  }

  console.log(`\n${failures === 0 ? 'STRICT-MYSQL ROUND-TRIP VERIFIED' : failures + ' CHECK(S) FAILED'}`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
  console.error(e);
  process.exit(2);
});
