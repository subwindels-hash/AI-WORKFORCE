/**
 * Mock API-Football v3 server — TEST FIXTURE ONLY, never loaded by the app.
 *
 * Speaks the exact wire shapes of https://v3.football.api-sports.io so the
 * REAL ApiFootballProvider adapter (and the whole football pipeline behind
 * it) can be exercised end-to-end without network access or quota spend.
 *
 *   node tests/mock-api-football/server.mjs          # port 9377
 *   PORT=9400 node tests/mock-api-football/server.mjs
 *
 * Point the application at it with (server-side only):
 *   API_FOOTBALL_KEY=mock-key-12345
 *   API_FOOTBALL_BASE_URL=http://127.0.0.1:9377
 *
 * Scenario (deterministic per UTC date):
 *   - today:  6 SCHEDULED fixtures (+90min .. evening) + 1 POSTPONED
 *   - +1..+3: more SCHEDULED fixtures (the upcoming sweep)
 *   - past 21 days: FINISHED fixtures with deterministic scores (form/history)
 *
 * Admin controls (drive results/settlement tests):
 *   POST /__finish  {"id":100001,"home":2,"away":1}   flip a fixture to FT
 *   POST /__reset                                   clear all overrides
 *   GET  /__state                                   dump current overrides
 */
import http from 'node:http';

const PORT = Number(process.env.PORT ?? 9377);
const KEY = process.env.MOCK_API_KEY ?? 'mock-key-12345';
const SEASON = 2026;

const TEAMS = {
  42: { name: 'Arsenal', str: 1.55 },
  51: { name: 'Brighton', str: 1.05 },
  40: { name: 'Liverpool', str: 1.5 },
  45: { name: 'Everton', str: 0.95 },
  50: { name: 'Manchester City', str: 1.6 },
  55: { name: 'Brentford', str: 1.0 },
  49: { name: 'Chelsea', str: 1.3 },
  39: { name: 'Wolverhampton Wanderers', str: 0.9 },
  34: { name: 'Newcastle', str: 1.25 },
  63: { name: 'Leeds', str: 0.92 },
  47: { name: 'Tottenham', str: 1.2 },
  48: { name: 'West Ham', str: 1.0 },
};
const TEAM_IDS = Object.keys(TEAMS).map(Number);
const VENUES = { 42: 'Emirates Stadium|London', 40: 'Anfield|Liverpool', 50: 'Etihad Stadium|Manchester', 49: 'Stamford Bridge|London', 34: "St. James' Park|Newcastle", 47: 'Tottenham Hotspur Stadium|London' };

function hash(s) { let h = 2166136261; for (const c of s) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return (h >>> 0) / 4294967295; }
function utcDate(d) { return d.toISOString().slice(0, 10); }
function addDays(iso, n) { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return utcDate(d); }

const TODAY = () => utcDate(new Date());

/** Deterministic pair of teams for a synthetic date + slot. */
function pairFor(dateStr, idx) {
  const a = TEAM_IDS[Math.floor(hash(dateStr + ':a:' + idx) * TEAM_IDS.length)];
  let b = TEAM_IDS[Math.floor(hash(dateStr + ':b:' + idx) * TEAM_IDS.length)];
  if (b === a) b = TEAM_IDS[(TEAM_IDS.indexOf(a) + 1 + idx) % TEAM_IDS.length];
  return [a, b];
}

function goalsFor(dateStr, home, away) {
  const sh = TEAMS[home].str * (0.75 + hash(dateStr + ':h:' + home + ':' + away) * 0.85);
  const sa = TEAMS[away].str * (0.55 + hash(dateStr + ':a:' + home + ':' + away) * 0.75);
  return [Math.min(5, Math.floor(sh)), Math.min(5, Math.floor(sa))];
}

/** Today's board: fixed ids/pairs so tests can address them. */
function todaysFixtures() {
  const now = new Date();
  const at = (offsetMinutes) => new Date(Math.min(now.getTime() + offsetMinutes * 60000, Date.parse(TODAY() + 'T23:30:00Z')));
  const mk = (id, home, away, offsetMinutes, status = 'NS') => ({
    id, home, away, kickoff: at(offsetMinutes), status,
  });
  return [
    mk(100001, 42, 51, 120),        // Arsenal vs Brighton
    mk(100002, 40, 45, 200),        // Liverpool vs Everton
    mk(100003, 50, 55, 260),        // Man City vs Brentford
    mk(100004, 49, 39, 320, 'PST'), // Chelsea vs Wolves — POSTPONED
    mk(100005, 34, 63, 180),        // Newcastle vs Leeds
    mk(100006, 47, 48, 240),        // Tottenham vs West Ham
  ];
}

/** All fixtures for a UTC date (synthetic + today's board + overrides). */
function fixturesForDate(dateStr) {
  const today = TODAY();
  if (dateStr === today) {
    return todaysFixtures().map((f) => ({
      ...f,
      kickoff: new Date(f.kickoff),
    }));
  }
  const diff = Math.round((Date.parse(dateStr) - Date.parse(today)) / 86400000);
  const out = [];
  if (diff > 0 && diff <= 3) {
    const slots = 4;
    for (let i = 0; i < slots; i++) {
      const [home, away] = pairFor(dateStr, i);
      out.push({ id: 200000 + Math.floor(hash(dateStr + ':' + i) * 7999) * 10 + i, home, away,
        kickoff: new Date(dateStr + 'T' + ['13:30', '16:00', '18:30', '20:45'][i] + ':00Z'), status: 'NS' });
    }
    return out;
  }
  if (diff < 0 && diff >= -21) {
    // Slot 4 (the fifth evening kick-off) rotates through TODAY's pairings in
    // both home/away orders so the synthetic past contains genuine repeat
    // meetings — without them `?h2h=` is always empty and the head-to-head
    // snapshot path of the statistics pipeline has nothing real to ingest.
    const H2H_ROTATION = [[51, 42], [45, 40], [55, 50], [39, 49], [63, 34], [48, 47]];
    const slots = 5;
    for (let i = 0; i < slots; i++) {
      const [home, away] = i === 4
        ? H2H_ROTATION[Math.abs(diff) % H2H_ROTATION.length]
        : pairFor(dateStr, i);
      const [hs, as] = goalsFor(dateStr, home, away);
      out.push({ id: 100000 + Math.floor(hash(dateStr + ':' + i) * 8999) * 10 + i, home, away,
        kickoff: new Date(dateStr + 'T' + ['14:00', '16:30', '19:00', '20:00', '21:15'][i] + ':00Z'),
        status: 'FT', homeScore: hs, awayScore: as,
        ht: [Math.floor(hs / 2), Math.floor(as / 2)] });
    }
    return out;
  }
  return out;
}

function fixtureJson(f) {
  const statusShort = f.status ?? 'NS';
  const statusLong = { NS: 'Not Started', FT: 'Match Finished', PST: 'Postponed', '1H': 'First Half' }[statusShort] ?? 'Not Started';
  const venue = (VENUES[f.home] ?? 'Stadium|City').split('|');
  const homeScore = f.homeScore ?? null;
  const awayScore = f.awayScore ?? null;
  return {
    fixture: {
      id: f.id, referee: 'M. Oliver', timezone: 'UTC',
      date: f.kickoff.toISOString(), timestamp: Math.floor(f.kickoff.getTime() / 1000),
      venue: { name: venue[0], city: venue[1] },
      status: { long: statusLong, short: statusShort, elapsed: statusShort === 'FT' ? 90 : null },
    },
    league: { id: 39, name: 'Premier League', country: 'England', logo: '', flag: '', season: SEASON, round: 'Regular Season - 12' },
    teams: {
      home: { id: f.home, name: TEAMS[f.home].name, logo: '', winner: statusShort === 'FT' ? (homeScore > awayScore) : null },
      away: { id: f.away, name: TEAMS[f.away].name, logo: '', winner: statusShort === 'FT' ? (awayScore > homeScore) : null },
    },
    goals: { home: homeScore, away: awayScore },
    score: {
      halftime: { home: f.ht ? f.ht[0] : null, away: f.ht ? f.ht[1] : null },
      fulltime: { home: statusShort === 'FT' ? homeScore : null, away: statusShort === 'FT' ? awayScore : null },
      extratime: { home: null, away: null }, penalty: { home: null, away: null },
    },
  };
}

/** Season aggregates across the synthetic past, for standings/statistics. */
function seasonRows(teamId) {
  const rows = [];
  for (let i = 1; i <= 21; i++) {
    const dateStr = addDays(TODAY(), -i);
    for (const f of fixturesForDate(dateStr)) {
      if (f.home !== teamId && f.away !== teamId) continue;
      rows.push({ date: dateStr, home: f.home === teamId, gf: f.home === teamId ? f.homeScore : f.awayScore, ga: f.home === teamId ? f.awayScore : f.homeScore });
    }
  }
  return rows;
}

function teamStatisticsJson(teamId) {
  const rows = seasonRows(teamId);
  const agg = { played: rows.length, win: 0, draw: 0, lose: 0, gf: 0, ga: 0, cs: 0, fts: 0, homeWin: 0, awayWin: 0, homePlayed: 0, awayPlayed: 0 };
  for (const r of rows) {
    r.home ? agg.homePlayed++ : agg.awayPlayed++;
    agg.gf += r.gf; agg.ga += r.ga;
    if (r.gf > r.ga) { agg.win++; r.home ? agg.homeWin++ : agg.awayWin++; }
    else if (r.gf === r.ga) agg.draw++;
    else agg.lose++;
    if (r.ga === 0) agg.cs++;
    if (r.gf === 0) agg.fts++;
  }
  const form = rows.slice(0, 5).map((r) => (r.gf > r.ga ? 'W' : r.gf === r.ga ? 'D' : 'L')).join('');
  const avg = (v, d) => (d === 0 ? '0.0' : (v / d).toFixed(1));
  return [{
    league: { id: 39, name: 'Premier League', country: 'England', logo: '', flag: '', season: SEASON },
    team: { id: teamId, name: TEAMS[teamId].name, logo: '' },
    form: form,
    fixtures: {
      played: { home: agg.homePlayed, away: agg.awayPlayed, total: agg.played },
      wins: { home: agg.homeWin, away: agg.awayWin, total: agg.win },
      draws: { home: agg.draw, away: agg.draw, total: agg.draw },
      loses: { home: agg.lose, away: agg.lose, total: agg.lose },
    },
    goals: {
      for: { total: { home: agg.gf, away: agg.gf, average: { home: avg(agg.gf, agg.homePlayed), away: avg(agg.gf, agg.awayPlayed), total: avg(agg.gf, agg.played) } } },
      against: { total: { home: agg.ga, away: agg.ga, average: { home: avg(agg.ga, agg.homePlayed), away: avg(agg.ga, agg.awayPlayed), total: avg(agg.ga, agg.played) } } },
    },
    biggest: { streak: { wins: Math.max(0, agg.win - agg.lose) }, wins: { home: agg.homeWin, away: agg.awayWin }, loses: { home: agg.lose, away: agg.lose } },
    clean_sheet: { home: agg.cs, away: agg.cs, total: agg.cs },
    failed_to_score: { home: agg.fts, away: agg.fts, total: agg.fts },
    penalty: { scored: 2, missed: 1, total: 3 },
    lineups: [{ formation: '4-3-3', played: agg.played }],
    cards: { yellow: { '0-15': 1, '76-90': 3 }, red: { '76-90': 1 } },
  }];
}

function standingsJson() {
  const table = TEAM_IDS.map((id) => {
    const rows = seasonRows(id);
    const win = rows.filter((r) => r.gf > r.ga).length;
    const draw = rows.filter((r) => r.gf === r.ga).length;
    const lose = rows.filter((r) => r.gf < r.ga).length;
    const gf = rows.reduce((a, r) => a + r.gf, 0);
    const ga = rows.reduce((a, r) => a + r.ga, 0);
    // Venue splits from the stored per-date rows (the real API always sends
    // them; the football model's home/away rates need them).
    const split = (venue) => {
      const rs = rows.filter((r) => (venue === 'home') === r.home);
      return {
        played: rs.length,
        win: rs.filter((r) => r.gf > r.ga).length,
        draw: rs.filter((r) => r.gf === r.ga).length,
        lose: rs.filter((r) => r.gf < r.ga).length,
        goals: { for: rs.reduce((a, r) => a + r.gf, 0), against: rs.reduce((a, r) => a + r.ga, 0) },
      };
    };
    return { id, name: TEAMS[id].name, played: rows.length, win, draw, lose, gf, ga, points: win * 3 + draw, home: split('home'), away: split('away') };
  }).sort((a, b) => b.points - a.points || (b.gf - b.ga) - (a.gf - a.ga));
  const standings = table.map((t, i) => ({
    rank: i + 1, team: { id: t.id, name: t.name, logo: '' },
    all: { played: t.played, win: t.win, draw: t.draw, lose: t.lose, goals: { for: t.gf, against: t.ga } },
    home: t.home, away: t.away,
    goals_diff: t.gf - t.ga, points: t.points, form: '', status: 'same', description: null,
  }));
  return [{
    league: { id: 39, name: 'Premier League', country: 'England', logo: '', flag: '', season: SEASON, standings: [standings] },
  }];
}

/** Bookmaker prices derived from team strength with a ~5% overround. */
function oddsJson(fixtureId) {
  const f = allKnownFixtures().find((x) => String(x.id) === String(fixtureId));
  if (!f) return [];
  const sh = TEAMS[f.home].str, sa = TEAMS[f.away].str;
  const pH = Math.max(0.08, Math.min(0.8, 0.42 + (sh - sa) * 0.22));
  const pD = 0.26; const pA = Math.max(0.05, 1 - pH - pD);
  const total = Math.max(0.9, sh + sa);
  const pOver25 = Math.max(0.12, Math.min(0.85, (total - 1.6) / 2.2));
  const pOver15 = Math.min(0.92, pOver25 + 0.24);
  const pOver35 = Math.max(0.1, pOver25 - 0.2);
  const pBTTS = Math.max(0.2, Math.min(0.8, 0.48 + (total - 2.4) * 0.12));
  const price = (p) => (Math.round((0.95 / p) * 100) / 100).toFixed(2);
  const iso = new Date().toISOString();
  const bet = (name, values) => ({ id: 1, name, update: iso, values: values.map(([value, odd]) => ({ value, odd })) });
  const mkBook = (shiftPct) => ({
    bets: [
      bet('Match Winner', [['Home', price(pH * (1 + shiftPct))], ['Draw', price(pD * (1 + shiftPct))], ['Away', price(pA * (1 + shiftPct))]]),
      bet('Goals Over/Under', [['Over 1.5', price(pOver15)], ['Over 2.5', price(pOver25)], ['Over 3.5', price(pOver35)], ['Under 3.5', price(1 - pOver35)]]),
      bet('Both Teams Score', [['Yes', price(pBTTS)], ['No', price(1 - pBTTS)]]),
    ],
  });
  return [{
    fixture: { id: f.id, update: iso },
    league: { id: 39, name: 'Premier League', country: 'England', season: SEASON },
    bookmakers: [
      { id: 1, name: 'Bet365', ...mkBook(0) },
      { id: 2, name: 'Bwin', ...mkBook(0.03) },
    ],
  }];
}

function allKnownFixtures() {
  const out = [...todaysFixtures()];
  for (let d = 1; d <= 3; d++) out.push(...fixturesForDate(addDays(TODAY(), d)));
  for (let d = 1; d <= 21; d++) out.push(...fixturesForDate(addDays(TODAY(), -d)));
  return out;
}

// ── mutable result overrides (drive the settlement path) ────────────────────
const overrides = new Map();

let requests = 0;

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const path = url.pathname;

  if (path === '/__finish' && req.method === 'POST') {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      const j = JSON.parse(body || '{}');
      overrides.set(Number(j.id), { status: 'FT', homeScore: Number(j.home), awayScore: Number(j.away), ht: [Math.floor(Number(j.home) / 2), Math.floor(Number(j.away) / 2)] });
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ ok: true, overrides: [...overrides.entries()] }));
    });
    return;
  }
  if (path === '/__reset') { overrides.clear(); res.writeHead(200); res.end('{"ok":true}'); return; }
  if (path === '/__state') { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(Object.fromEntries(overrides))); return; }

  // Auth exactly like the real API: header required.
  if ((req.headers['x-apisports-key'] ?? '') !== KEY) {
    res.writeHead(403, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ get: '', parameters: [], errors: { token: 'Missing/invalid application key' }, results: 0, paging: { current: 1, total: 1 }, response: [] }));
    return;
  }
  requests++;
  const envelope = (response, params = {}) => {
    res.writeHead(200, { 'content-type': 'application/json', 'x-ratelimit-requests-limit': '500', 'x-ratelimit-requests-remaining': String(Math.max(0, 500 - requests)) });
    res.end(JSON.stringify({ get: path.replace('/', '').split('?')[0], parameters: params, errors: [], results: Array.isArray(response) ? response.length : 0, paging: { current: 1, total: 1 }, response }));
  };

  const applyOverride = (list) => list.map((f) => {
    const o = overrides.get(f.id);
    return o ? { ...f, ...o } : f;
  });

  if (path === '/status') {
    res.writeHead(200, { 'content-type': 'application/json' });
    return res.end(JSON.stringify({
      get: 'status', parameters: [], errors: [], results: 0, paging: { current: 1, total: 1 },
      response: [{ account: { firstname: 'Mock', lastname: 'Vendor' }, subscription: { plan: 'Test', end: '2027-01-01', active: true }, requests: { server: 1, current: requests, limit_day: 500 } }],
    }));
  }
  if (path === '/fixtures') {
    const id = url.searchParams.get('id');
    if (id) return envelope(applyOverride(allKnownFixtures().filter((f) => String(f.id) === String(id))).map(fixtureJson));
    const h2h = url.searchParams.get('h2h');
    if (h2h) {
      const [a, b] = h2h.split('-').map(Number);
      const last = Number(url.searchParams.get('last') ?? 10);
      const rows = [];
      for (let d = 1; d <= 21 && rows.length < last; d++) {
        const dateStr = addDays(TODAY(), -d);
        for (const f of fixturesForDate(dateStr)) {
          if ((f.home === a && f.away === b) || (f.home === b && f.away === a)) rows.push(f);
        }
      }
      return envelope(rows.slice(0, last).map(fixtureJson));
    }
    const team = url.searchParams.get('team');
    if (team && url.searchParams.get('last')) {
      const last = Number(url.searchParams.get('last'));
      const rows = [];
      for (let d = 1; d <= 21 && rows.length < last; d++) {
        for (const f of fixturesForDate(addDays(TODAY(), -d))) if (f.home === Number(team) || f.away === Number(team)) rows.push(f);
      }
      return envelope(rows.slice(0, last).map(fixtureJson));
    }
    if (url.searchParams.get('live')) return envelope([]);
    const date = url.searchParams.get('date');
    if (date) return envelope(applyOverride(fixturesForDate(date)).map(fixtureJson));
    return envelope([]);
  }
  if (path === '/odds') {
    const fixture = url.searchParams.get('fixture');
    return envelope(oddsJson(fixture));
  }
  if (path === '/standings') return envelope(standingsJson());
  if (path === '/teams/statistics') {
    // The real v3 API answers /teams/statistics with response as a single
    // OBJECT (not a list) — mirror that exactly.
    res.writeHead(200, { 'content-type': 'application/json', 'x-ratelimit-requests-limit': '500', 'x-ratelimit-requests-remaining': String(Math.max(0, 500 - requests)) });
    return res.end(JSON.stringify({
      get: 'teams/statistics', parameters: { team: url.searchParams.get('team'), league: url.searchParams.get('league'), season: url.searchParams.get('season') },
      errors: [], results: 1, paging: { current: 1, total: 1 },
      response: teamStatisticsJson(Number(url.searchParams.get('team')))[0],
    }));
  }
  if (path === '/fixtures/statistics') return envelope([]);
  if (path === '/leagues') {
    return envelope([{
      league: { id: 39, name: 'Premier League', country: 'England', logo: '', flag: '' },
      country: { name: 'England', code: 'GB', flag: '' },
      seasons: [{ year: SEASON, start: '2026-08-01', end: '2027-05-31', current: true, coverage: { fixtures: { events: true, lineups: true, statistics_fixtures: true }, standings: true, players: true, top_scorers: true, injuries: true, predictions: true, odds: true } }],
    }]);
  }
  // Unknown endpoint: api-football answers HTTP 200 with a soft error envelope.
  envelope([]);
});

server.listen(PORT, '127.0.0.1', () => console.log(`[mock-api-football] listening on http://127.0.0.1:${PORT} (key: ${KEY})`));
