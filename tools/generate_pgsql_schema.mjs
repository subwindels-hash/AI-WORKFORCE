/**
 * generate_pgsql_schema.mjs — deterministic MySQL → PostgreSQL schema translator.
 *
 * WINDELS AI_WORKFORCE keeps one canonical MySQL schema per module
 * (`application/database/*.mysql.sql`). PostgreSQL is a supported production
 * driver (AI_WORKFORCE_DB_DRIVER=pdo_pgsql); this tool derives the matching
 * `.pgsql.sql` for every module so the two dialects cannot drift by hand.
 *
 *   node tools/generate_pgsql_schema.mjs
 *
 * Rules (kept intentionally small and mechanical):
 *   - `--` comments and the trailing `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
 *     trailer are dropped;
 *   - every identifier is double-quoted (reserved-word safe);
 *   - `INT/BIGINT/… AUTO_INCREMENT PRIMARY KEY` → `SERIAL/BIGSERIAL PRIMARY KEY`;
 *   - `TINYINT` → SMALLINT, `DATETIME` → TIMESTAMP, `LONGTEXT/MEDIUMTEXT` → TEXT,
 *     `JSON` → JSONB, `FLOAT/DOUBLE` → DOUBLE PRECISION, `ENUM(...)` → VARCHAR;
 *   - inline `KEY`/`INDEX` defs become standalone `CREATE INDEX IF NOT EXISTS`;
 *   - `UNIQUE KEY name (cols)` → `CONSTRAINT name UNIQUE (cols)`;
 *   - `INSERT IGNORE` seeds → `INSERT … ON CONFLICT DO NOTHING`.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DB = path.join(ROOT, 'application', 'database');

// ---------------------------------------------------------------- text utils

/** Drop `-- …` line comments, honouring single-quoted strings. */
function stripComments(sql) {
  let out = '';
  let inQ = false;
  for (let i = 0; i < sql.length; i++) {
    const c = sql[i];
    if (c === "'" && sql[i - 1] !== '\\') inQ = !inQ;
    if (!inQ && c === '-' && sql[i + 1] === '-') {
      while (i < sql.length && sql[i] !== '\n') i++;
      out += '\n';
      continue;
    }
    out += c;
  }
  return out;
}

/** Split a comma list ignoring commas inside single quotes or parens. */
function splitTopLevel(str) {
  const parts = [];
  let cur = '';
  let depth = 0;
  let inQ = false;
  for (let i = 0; i < str.length; i++) {
    const c = str[i];
    if (c === "'" && str[i - 1] !== '\\') inQ = !inQ;
    if (!inQ) {
      if (c === '(') depth++;
      else if (c === ')') depth--;
      else if (c === ',' && depth === 0) {
        parts.push(cur);
        cur = '';
        continue;
      }
    }
    cur += c;
  }
  if (cur.trim() !== '') parts.push(cur);
  return parts;
}

/** Tokenise on whitespace, keeping parens/quoted groups together. */
function tokens(str) {
  const out = [];
  let cur = '';
  let depth = 0;
  let inQ = false;
  for (let i = 0; i < str.length; i++) {
    const c = str[i];
    if (c === "'" && str[i - 1] !== '\\') inQ = !inQ;
    if (!inQ && (c === '(' )) depth++;
    if (!inQ && (c === ')')) depth--;
    if (!inQ && /\s/.test(c) && depth === 0) {
      if (cur !== '') { out.push(cur); cur = ''; }
      continue;
    }
    cur += c;
  }
  if (cur !== '') out.push(cur);
  return out;
}

function ident(name) {
  name = name.replace(/^`|`$/g, '');
  return '"' + name + '"';
}

// ---------------------------------------------------------------- type maps

function enumToVarchar(type) {
  const m = /^ENUM\((.*)\)$/is.exec(type.trim());
  if (!m) return null;
  const labels = m[1].split(',').map((s) => s.trim().replace(/^'|'$/g, ''));
  const max = Math.max(8, ...labels.map((l) => l.length));
  return 'VARCHAR(' + max + ')';
}

function mapType(t) {
  const up = t.trim().toUpperCase();
  if (up.startsWith('ENUM(')) return enumToVarchar(t);
  if (/^INT(EGER)?$/.test(up)) return 'INTEGER';
  if (/^BIGINT$/.test(up)) return 'BIGINT';
  if (/^TINYINT(\(\d+\))?$/.test(up)) return 'SMALLINT';
  if (/^SMALLINT$/.test(up)) return 'SMALLINT';
  if (/^MEDIUMINT$/.test(up)) return 'INTEGER';
  if (/^DECIMAL\(/.test(up)) return up;
  if (/^FLOAT$/.test(up)) return 'DOUBLE PRECISION';
  if (/^DOUBLE$/.test(up)) return 'DOUBLE PRECISION';
  if (/^REAL$/.test(up)) return 'REAL';
  if (/^DATETIME$/.test(up)) return 'TIMESTAMP';
  if (/^TIMESTAMP$/.test(up)) return 'TIMESTAMP';
  if (/^TIME$/.test(up)) return 'TIME';
  if (/^DATE$/.test(up)) return 'DATE';
  if (/^YEAR$/.test(up)) return 'INTEGER';
  if (/^CHAR\(/.test(up)) return up.replace(/^CHAR/, 'CHARACTER');
  if (/^VARCHAR\(/.test(up)) return up;
  if (/^TEXT$/.test(up) || /^MEDIUMTEXT$/.test(up) || /^LONGTEXT$/.test(up)) return 'TEXT';
  if (/^JSON$/.test(up)) return 'JSONB';
  if (/^BOOLEAN$/.test(up) || /^BOOL$/.test(up)) return 'BOOLEAN';
  return up; // unknown → preserve
}

// ------------------------------------------------------------- table parsing

function parseTable(stmt) {
  const m = /^CREATE TABLE IF NOT EXISTS\s+([^\s(]+)\s*\(([\s\S]*)\)\s*(.*)$/i.exec(stmt);
  if (!m) return null;
  const table = m[1].replace(/`/g, '');
  // The last `)` of the column list closes the definition; the trailer
  // (ENGINE=… DEFAULT CHARSET=…;) may contain parens? No, but FKs do, so
  // we already captured the body via a greedy inner match — instead we
  // locate the matching close paren of the opening one.
  const openIdx = stmt.indexOf('(', stmt.indexOf(m[1]));
  let depth = 0, closeIdx = -1, inQ = false;
  for (let i = openIdx; i < stmt.length; i++) {
    const c = stmt[i];
    if (c === "'" && stmt[i - 1] !== '\\') inQ = !inQ;
    if (!inQ && c === '(') depth++;
    if (!inQ && c === ')') { depth--; if (depth === 0) { closeIdx = i; break; } }
  }
  const body = stmt.slice(openIdx + 1, closeIdx);
  return { table, body };
}

function translateCreateTable(stmt) {
  const parsed = parseTable(stmt);
  if (!parsed) return [stmt];
  const { table, body } = parsed;
  const items = splitTopLevel(body);
  const cols = [];
  const indexes = [];

  for (const raw of items) {
    const item = raw.trim();
    if (item === '') continue;
    const up = item.toUpperCase();

    if (/^PRIMARY KEY\b/.test(up)) {
      const inner = item.replace(/^PRIMARY KEY/i, '').trim();
      cols.push('PRIMARY KEY (' + inner.replace(/`/g, '').split(',').map((s) => ident(s.trim())).join(', ') + ')');
      continue;
    }
    if (/^UNIQUE KEY\b/.test(up)) {
      const mm = /^UNIQUE KEY\s+([^\s(]+)\s*\((.*)\)/i.exec(item);
      if (mm) cols.push('CONSTRAINT ' + ident(mm[1]) + ' UNIQUE (' + colList(mm[2]) + ')');
      else cols.push(translateItem(item));
      continue;
    }
    if (/^KEY\b/.test(up) || /^INDEX\b/.test(up)) {
      const mm = /^(?:KEY|INDEX)\s+([^\s(]+)\s*\((.*)\)/i.exec(item);
      if (mm) indexes.push({ name: mm[1].replace(/`/g, ''), cols: mm[2] });
      continue;
    }
    if (/^FOREIGN KEY\b/.test(up) || /^CONSTRAINT\b/.test(up)) {
      cols.push(item.replace(/`/g, '').replace(/\bDATETIME\b/gi, 'TIMESTAMP'));
      continue;
    }
    cols.push(translateItem(item));
  }

  const create = 'CREATE TABLE IF NOT EXISTS ' + ident(table) + ' (\n  ' + cols.join(',\n  ') + '\n);';
  const out = [create];
  for (const idx of indexes) {
    out.push('CREATE INDEX IF NOT EXISTS ' + ident(idx.name) + ' ON ' + ident(table) + ' (' + colList(idx.cols) + ');');
  }
  return out;
}

function colList(list) {
  return list.replace(/`/g, '').split(',').map((s) => ident(s.trim())).join(', ');
}

function translateItem(item) {
  const toks = tokens(item);
  if (toks.length < 2) return item;
  let name = toks[0];
  let typeTok = toks[1];
  let attrs = toks.slice(2).join(' ');

  // UNSIGNED/ZEROFILL ride along as attrs; AUTO_INCREMENT is a pseudo-type.
  const isAuto = /\bAUTO_INCREMENT\b/i.test(attrs) || /\bAUTO_INCREMENT\b/i.test(typeTok);
  const isPrimary = /\bPRIMARY KEY\b/i.test(attrs);

  let mapped;
  if (isAuto) {
    const big = /^BIGINT$/i.test(typeTok);
    mapped = big ? 'BIGSERIAL' : 'SERIAL';
    attrs = attrs.replace(/\bAUTO_INCREMENT\b/gi, '').replace(/\bPRIMARY KEY\b/gi, '').trim();
    if (isPrimary) attrs = (attrs + ' PRIMARY KEY').trim();
  } else {
    mapped = mapType(typeTok);
  }

  attrs = attrs
    .replace(/\bUNSIGNED\b/gi, '')
    .replace(/\bZEROFILL\b/gi, '')
    .replace(/\bON UPDATE CURRENT_TIMESTAMP\b/gi, '')
    .replace(/\s+/g, ' ')
    .trim();

  return [ident(name), mapped, attrs].filter(Boolean).join(' ');
}

function translateInsert(stmt) {
  // INSERT IGNORE INTO t (cols) VALUES (...) → INSERT INTO t (cols) VALUES (...) ON CONFLICT DO NOTHING
  return stmt
    .replace(/INSERT IGNORE INTO/i, 'INSERT INTO')
    .replace(/`/g, '')
    .replace(/;\s*$/, ' ON CONFLICT DO NOTHING;');
}

function translateStatement(stmt) {
  const s = stmt.trim();
  if (s === '') return [];
  if (/^CREATE TABLE\b/i.test(s)) return translateCreateTable(s);
  if (/^INSERT IGNORE\b/i.test(s)) return [translateInsert(s)];
  return [s.replace(/`/g, '').replace(/ENGINE=\w+\s+DEFAULT CHARSET=\w+;?\s*$/i, ';')];
}

function translateFile(srcPath, dstPath) {
  const sql = stripComments(fs.readFileSync(srcPath, 'utf8'));
  const stmts = sql.split(';').map((s) => s.trim()).filter((s) => s !== '');
  const out = [
    '-- PostgreSQL schema — generated from ' + path.basename(srcPath) + ' by tools/generate_pgsql_schema.mjs.',
    '-- Column sets are identical to the canonical MySQL schema; types are the closest',
    '-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).',
    '',
  ];
  for (const stmt of stmts) {
    for (const line of translateStatement(stmt)) out.push(line);
  }
  fs.writeFileSync(dstPath, out.join('\n') + '\n');
}

const files = fs.readdirSync(DB).filter((f) => f.endsWith('.mysql.sql')).sort();
for (const f of files) {
  const dst = f.replace(/\.mysql\.sql$/, '.pgsql.sql');
  translateFile(path.join(DB, f), path.join(DB, dst));
  console.log('wrote ' + dst);
}
