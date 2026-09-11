<?php
namespace AIWorkforce\Sports;

/** Validates and converts a provider fixture into the WINDELS-neutral shape. */
class SportsDataNormalizer
{
    public const LIVE_STATUSES = ['LIVE', 'HALFTIME', 'EXTRA_TIME', 'PENALTIES'];
    public const TERMINAL_STATUSES = ['FINISHED', 'POSTPONED', 'CANCELLED', 'SUSPENDED'];
    public const ALLOWED_STATUSES = ['SCHEDULED', 'LIVE', 'HALFTIME', 'EXTRA_TIME', 'PENALTIES', 'FINISHED', 'POSTPONED', 'CANCELLED', 'SUSPENDED'];

    /**
     * Normalize a provider-specific status string into the canonical internal model.
     * Accepts provider short codes (NS, 1H, HT, FT, AET, PEN, etc.), SportMonks
     * developer names (INPLAY_1ST_HALF, FT, etc.) and already-canonical values.
     * Unknown / empty values fall back to SCHEDULED so they never appear as live.
     */
    public static function canonicalStatus(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '') return 'SCHEDULED';
        if (in_array($s, self::ALLOWED_STATUSES, true)) return $s;
        // Common variants and provider short codes
        return match ($s) {
            'NS', 'NOT STARTED', 'TBA', 'PENDING', 'DELAYED', 'AU' => 'SCHEDULED',
            '1H', '2H', 'LIVE', 'IN PROGRESS', 'INPROGRESS', 'INPLAY_1ST_HALF', 'INPLAY_2ND_HALF', 'FIRST HALF', 'SECOND HALF' => 'LIVE',
            'HT', 'HALFTIME', 'HALF TIME', 'HALF_TIME', 'HALF-TIME' => 'HALFTIME',
            'ET', 'EXTRA TIME', 'EXTRA_TIME', 'BT', 'BREAK', 'INPLAY_ET', 'EXTRA_TIME_BREAK', 'ET BREAK' => 'EXTRA_TIME',
            'P', 'PENALTIES', 'PENALTY', 'INPLAY_PENALTIES', 'PEN_BREAK', 'PENALTIES LIVE' => 'PENALTIES',
            'FT', 'FINISHED', 'ENDED', 'FULL TIME', 'FULLTIME', 'MATCH FINISHED', 'COMPLETE', 'AET', 'PEN', 'FT_PEN', 'WO', 'AWARDED' => 'FINISHED',
            'PST', 'POSTPONED' => 'POSTPONED',
            'CANC', 'CANCELLED', 'CANCELED', 'DELETED' => 'CANCELLED',
            'SUSP', 'SUSPENDED', 'ABANDONED', 'INTERRUPTED', 'INT' => 'SUSPENDED',
            default => 'SCHEDULED',
        };
    }

    public static function isLiveStatus(string $status): bool
    {
        return in_array(strtoupper($status), self::LIVE_STATUSES, true);
    }
    public static function fixture(array $raw, string $provider): array
    {
        foreach (['externalId', 'homeTeam', 'awayTeam', 'competition', 'kickoff'] as $field) {
            if (!isset($raw[$field]) || trim((string) $raw[$field]) === '') throw new \InvalidArgumentException("fixture missing {$field}");
        }
        try { $kickoff = (new \DateTimeImmutable((string) $raw['kickoff']))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
        catch (\Throwable $e) { throw new \InvalidArgumentException('fixture kickoff is invalid'); }
        $sourceStatus = strtoupper(trim((string) ($raw['statusShort'] ?? $raw['status'] ?? '')));
        $statusRaw = strtoupper(trim((string) ($raw['status'] ?? 'SCHEDULED')));
        $status = self::canonicalStatus($statusRaw);
        if (!in_array($status, self::ALLOWED_STATUSES, true)) throw new \InvalidArgumentException('fixture status is invalid');
        return [
            'provider' => $provider, 'externalId' => (string) $raw['externalId'],
            'sport' => strtolower((string) ($raw['sport'] ?? 'football')),
            'homeTeam' => trim((string) $raw['homeTeam']), 'awayTeam' => trim((string) $raw['awayTeam']),
            'competition' => trim((string) $raw['competition']),
            'leagueId' => isset($raw['leagueId']) ? trim((string) $raw['leagueId']) : '',
            'kickoff' => $kickoff, 'status' => $status,
            'timezone' => isset($raw['timezone']) && trim((string) $raw['timezone']) !== '' ? trim((string) $raw['timezone']) : null,
            'sourceTimestamp' => self::timestamp($raw['sourceTimestamp'] ?? null),
            'sourceStatus' => $sourceStatus !== '' ? $sourceStatus : $status,
            'simulated' => !empty($raw['simulated']),
            'context' => self::context($raw['context'] ?? null),
            'roundId' => (string) ($raw['roundId'] ?? ''),
            // Cross-provider fixture references (e.g. TheSportsDB's
            // idAPIfootball). These let the odds layer ask a SECOND provider
            // for the same fixture using an id in that provider's own
            // namespace — never by guessing with a foreign id.
            'crossReferences' => self::crossReferences($raw),
            // In-play state (minute + current goal score), copied through only
            // when the provider sent it. Absent stays absent: a live match the
            // provider gave no score for is never defaulted to 0-0 — the live
            // board reports "—" instead of inventing a score.
            'live' => self::liveState($raw),
            'fieldsPresent' => array_keys($raw),
        ];
    }

    /**
     * Verified fixture ids in OTHER providers' namespaces, when the supplier
     * payload carries them. Only explicit cross-references are kept — an id
     * is never assumed to be portable across providers.
     *
     * @return array<string,string> provider id → external fixture id
     */
    private static function crossReferences(array $raw): array
    {
        $out = [];
        foreach (['api-football' => 'apiFootballId'] as $provider => $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $id = trim((string) $raw[$key]);
                if ($id !== '') $out[$provider] = $id;
            }
        }
        return $out;
    }

    /**
     * Optional in-play state from a provider fixture/live payload. Only
     * numeric minute/score fields and a non-empty status detail are kept;
     * anything else the provider did not state is omitted, never guessed.
     */
    private static function liveState(array $raw): ?array
    {
        $out = [];
        foreach (['minute', 'extraMinute', 'homeScore', 'awayScore'] as $key) {
            $value = $raw[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value) && $value >= 0) $out[$key] = (int) $value;
        }
        $statusShort = $raw['statusShort'] ?? null;
        if (is_string($statusShort) && trim($statusShort) !== '') $out['statusShort'] = trim($statusShort);
        return $out === [] ? null : $out;
    }

    /**
     * Read the stored document behind a persisted row's `payload` column.
     *
     * Whether the caller gets back the decoded document or the raw JSON text
     * depends on which repository read produced the row (findMatchById /
     * listMatches decode, a verbatim row read does not) and on the storage
     * engine — it is the same data in two shapes. `is_array($row['payload'])`
     * alone therefore silently reads as "nothing stored" for every text row,
     * which is how a previous run's verified recent form was thrown away and
     * the daily run went back to INSUFFICIENT_DATA. Both shapes are accepted;
     * anything that is not a JSON object is NO document, and nothing in it is
     * invented.
     */
    public static function document(mixed $payload): array
    {
        if (is_array($payload)) return $payload;
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    /**
     * Validates optional verified match context. Missing or malformed context
     * is dropped (returned as null), never guessed or invented.
     */
    private static function context($raw): ?array
    {
        if (!is_array($raw)) return null;
        $out = [];
        if (isset($raw['recentForm']) && is_array($raw['recentForm'])) {
            $form = [];
            foreach (['homeGoalsPerMatch', 'awayGoalsPerMatch', 'homeConcededPerMatch', 'awayConcededPerMatch'] as $k) {
                if (isset($raw['recentForm'][$k]) && is_numeric($raw['recentForm'][$k]) && $raw['recentForm'][$k] >= 0) $form[$k] = (float) $raw['recentForm'][$k];
            }
            if (count($form) === 4) {
                $form['source'] = is_string($raw['recentForm']['source'] ?? null) ? $raw['recentForm']['source'] : null;
                // When the resolver stated WHEN the form was read, keep it.
                // The stored timestamp is what lets a later run tell verified
                // form that is still current from form that has gone stale —
                // without it, carried-forward context has no measurable age.
                $stamp = $raw['recentForm']['timestamp'] ?? null;
                if (is_string($stamp) && trim($stamp) !== '') {
                    try { $form['timestamp'] = (new \DateTimeImmutable($stamp))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
                    catch (\Throwable $e) { /* an unparseable stamp is simply dropped */ }
                }
                $out['recentForm'] = $form;
            }
        }
        foreach (['marketLiquidity', 'restDays'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k]) && $raw[$k] >= 0) $out[$k] = (float) $raw[$k];
        }
        return $out === null ? null : (count($out) ? $out : null);
    }
    /**
     * Validates a provider odds row into the WINDELS-neutral shape. The price
     * must be a real quotable decimal (numeric, finite, > 1.0) inside the
     * market's plausibility ceiling — zero/negative/null/absurd prices are
     * REJECTED here, at ingestion, so they can never reach an overround, an
     * expected-value computation or a ticket leg. Provider provenance that
     * travelled with the row (bookmaker, fixture id, the provider's own
     * update stamp, opening price, …) is preserved alongside the normalized
     * fields — it lands in the row's payload instead of being dropped, so a
     * decision record can still say exactly where its price came from.
     */
    public static function odds(array $raw, string $provider): array
    {
        foreach (['market', 'selection', 'decimalOdds', 'observedAt'] as $field) if (!isset($raw[$field]) || $raw[$field] === '') throw new \InvalidArgumentException("odds missing {$field}");
        $market = trim((string) $raw['market']);
        $selection = trim((string) $raw['selection']);
        if ($market === '' || $selection === '') throw new \InvalidArgumentException('odds market/selection is empty');
        if (!is_numeric($raw['decimalOdds']) || !is_finite((float) $raw['decimalOdds']) || (float) $raw['decimalOdds'] <= 1.0) throw new \InvalidArgumentException('decimal odds are invalid');
        $decimal = (float) $raw['decimalOdds'];
        $cap = OddsBounds::maxFor($market);
        if ($decimal > $cap) throw new \InvalidArgumentException(sprintf('decimal odds %s for market %s exceed the plausibility cap %s', (string) $decimal, $market, (string) $cap));
        $out = ['provider' => $provider, 'market' => $market, 'selection' => $selection, 'decimalOdds' => $decimal, 'observedAt' => self::timestamp($raw['observedAt'])];
        foreach (['bookmaker', 'fixtureId', 'updatedAt', 'impliedProbability', 'winning', 'openingDecimalOdds', 'suspended'] as $key) {
            if (array_key_exists($key, $raw) && (is_scalar($raw[$key]) || $raw[$key] === null)) $out[$key] = $raw[$key];
        }
        return $out;
    }

    private static function timestamp($value): string
    {
        if ($value === null || $value === '') return gmdate('c');
        try { return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
        catch (\Throwable $e) { throw new \InvalidArgumentException('sourceTimestamp is invalid'); }
    }
}
