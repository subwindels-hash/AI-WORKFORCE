<?php
namespace AIWorkforce\Football;

/**
 * "Last updated", as three separate facts rather than one.
 *
 * Football information ages on three independent clocks, and a page that shows a
 * single timestamp hides which one has run out. A prediction generated forty
 * minutes ago is only as good as the fixture data behind it — which may be
 * thirty hours old — and the price it was compared with, which may be from a feed
 * that stopped quoting yesterday. So the block answers three questions and never
 * merges them:
 *
 *  - **prediction** — when this row was calculated (`football_match_predictions.generated_at`);
 *  - **data** — when the provider last stamped new information on the fixture
 *    (`football_fixtures.source_timestamp`, with the last fixture sweep as the
 *    secondary reading);
 *  - **odds** — when the feed last quoted a price for the selection being shown
 *    (the newest `observed_at` on the odds rows that were actually used).
 *
 * Each field is judged against the window that governs it (`predictionTtlSeconds`,
 * `maxDataAgeSeconds('fixtures')`, `maxDataAgeSeconds('odds')`) and reported as
 * CURRENT, AGING, STALE or DATA_UNAVAILABLE. The last of those is the important
 * one: a missing timestamp is stated as missing, never as "now", because a board
 * that always looks freshly updated is a board nobody can reason about.
 *
 * The ages are computed at read time from stored timestamps, so reloading a page
 * re-measures the age — it does not refresh the data. Nothing in this class
 * touches a provider, and nothing in it makes a prediction newer than it is.
 */
final class FreshnessTracker
{
    public const CURRENT = 'CURRENT';
    public const AGING = 'AGING';
    public const STALE = 'STALE';
    public const UNAVAILABLE = 'DATA_UNAVAILABLE';

    /** A figure past half its window is already worth mentioning. */
    private const AGING_RATIO = 0.5;

    public function __construct(private FootballConfiguration $config) {}

    /**
     * @param array<string,mixed> $prediction the stored prediction row
     * @param array<string,mixed> $fixture the stored fixture row
     * @param array<string,mixed> $market the evaluated market block (its `pricing`
     *        carries the newest quote that was used)
     * @param array<string,mixed>|null $lastSweep the `football-fixtures` sync row,
     *        when the caller already has it — never fetched here
     * @return array<string,mixed>
     */
    public function stamp(array $prediction, array $fixture, array $market = [], ?array $lastSweep = null): array
    {
        $now = time();
        $fields = [
            'prediction' => $this->field($prediction['generated_at'] ?? null, $this->config->predictionTtlSeconds(), $now,
                'football_match_predictions.generated_at',
                'When WINDELS calculated this prediction. The row is frozen at kickoff, so it is not recalculated '
                    . 'by looking at it.'),
            'data' => $this->field($fixture['source_timestamp'] ?? null, $this->config->maxDataAgeSeconds('fixtures'), $now,
                'football_fixtures.source_timestamp',
                'The newest timestamp the provider put on this fixture — the age of the football information behind '
                    . 'the prediction, not of the prediction itself.', $lastSweep),
            'odds' => $this->field($market['pricing']['pricedAt'] ?? null, $this->config->maxDataAgeSeconds('odds'), $now,
                'sports_odds.observed_at (newest row used)',
                'When the connected odds feed last quoted the market this prediction is being compared with. A stale '
                    . 'price can turn a fair line into an apparent edge, so it is measured separately.'),
        ];
        $stale = array_values(array_filter(array_keys($fields), static fn(string $key): bool => ($fields[$key]['state'] ?? '') === self::STALE));
        $unknown = array_values(array_filter(array_keys($fields), static fn(string $key): bool => ($fields[$key]['state'] ?? '') === self::UNAVAILABLE));
        $titles = ['prediction' => 'Prediction generated', 'data' => 'Data refreshed', 'odds' => 'Odds refreshed'];
        $clocks = [];
        foreach ($fields as $key => $field) {
            $clocks[] = ['key' => $key, 'label' => $titles[$key] ?? $key] + $field;
        }
        return [
            'fields' => $fields,
            // The three clocks in reading order, each carrying its own state, age
            // and window. A surface renders this list; it never re-derives which
            // timestamp belongs to which clock, which is how two panels end up
            // labelling the same age differently.
            'clocks' => $clocks,
            'state' => $stale !== [] ? self::STALE : ($unknown !== [] ? self::UNAVAILABLE : self::CURRENT),
            'staleFields' => $stale,
            'unavailableFields' => $unknown,
            'verdict' => self::verdict($fields, $stale, $unknown),
            'labels' => self::labels($fields),
            // Reading a page re-measures the age; it does not fetch anything. The
            // module's refresh is scheduled and event-driven (RefreshPolicy +
            // RegenerationPolicy), never driven by how often someone looks.
            'note' => 'Ages are measured from stored timestamps at the moment this page was read. Loading the board '
                . 'does not refresh the data, the odds or the prediction.',
            'measuredAt' => gmdate('c', $now),
            'windows' => ['predictionSeconds' => $this->config->predictionTtlSeconds(),
                'dataSeconds' => $this->config->maxDataAgeSeconds('fixtures'),
                'oddsSeconds' => $this->config->maxDataAgeSeconds('odds')],
        ];
    }

    /**
     * One clock: its timestamp, its window, and the state that falls out of the
     * two. `$fallback` is the secondary source of the same fact (a sweep log row
     * for the fixture data), used only when the primary field is empty, and it
     * names itself when it is used.
     *
     * @return array<string,mixed>
     */
    private function field(mixed $timestamp, int $windowSeconds, int $now, string $source, string $meaning, ?array $fallback = null): array
    {
        $used = $fallback === null ? null : ($fallback['ended_at'] ?? $fallback['started_at'] ?? null);
        $basis = $source;
        $stamped = self::timeOf($timestamp);
        if ($stamped === null) {
            $stamped = self::timeOf($used);
            if ($stamped !== null) $basis = $source . ' was empty, so ' . (string) ($fallback['job_type'] ?? 'the last sweep') . ' was used instead';
        }
        if ($stamped === null) {
            return ['state' => self::UNAVAILABLE, 'at' => null, 'label' => DataState::UNAVAILABLE,
                'ageSeconds' => null, 'windowSeconds' => $windowSeconds, 'source' => $source,
                'meaning' => $meaning,
                'note' => 'No timestamp is stored for this, so its age cannot be stated. It is reported as unknown '
                    . 'rather than as current.'];
        }
        $age = max(0, $now - $stamped);
        $state = $age > $windowSeconds ? self::STALE : ($age > $windowSeconds * self::AGING_RATIO ? self::AGING : self::CURRENT);
        return [
            'state' => $state,
            'at' => gmdate('c', $stamped),
            'label' => gmdate('d M Y H:i', $stamped) . ' UTC',
            'ageSeconds' => $age,
            'ageLabel' => self::humanAge($age),
            'windowSeconds' => $windowSeconds,
            'windowLabel' => self::humanAge($windowSeconds),
            'source' => $basis,
            'meaning' => $meaning,
            'note' => $state === self::STALE
                ? 'Past the ' . self::humanAge($windowSeconds) . ' window this field is held to. The figure is still '
                    . 'the stored one; it is simply no longer current.'
                : ($state === self::AGING ? 'More than half of its ' . self::humanAge($windowSeconds) . ' window has passed.' : ''),
        ];
    }

    /** @param array<string,array<string,mixed>> $fields */
    private static function verdict(array $fields, array $stale, array $unknown): string
    {
        if ($stale !== []) {
            return 'At least one input is past its window (' . implode(', ', $stale) . '). Treat the figures as '
                . 'the last state WINDELS measured, not as the current one.';
        }
        if ($unknown !== []) {
            return 'The age of ' . implode(', ', $unknown) . ' cannot be stated from stored data; the prediction '
                . 'itself carries the timestamp it was written with.';
        }
        return 'All three clocks are inside their windows.';
    }

    /**
     * The three lines in display order, so a template never has to assemble the
     * block itself — and a surface that forgets to show one of them is visible.
     *
     * @param array<string,array<string,mixed>> $fields
     * @return list<array{key:string,title:string,value:string,state:string}>
     */
    private static function labels(array $fields): array
    {
        $titles = ['prediction' => 'Prediction generated', 'data' => 'Data refreshed', 'odds' => 'Odds refreshed'];
        $out = [];
        foreach ($titles as $key => $title) {
            $field = $fields[$key] ?? [];
            $out[] = ['key' => $key, 'title' => $title,
                'value' => (string) ($field['label'] ?? DataState::UNAVAILABLE),
                'age' => (string) (($field['ageLabel'] ?? '') !== '' ? $field['ageLabel'] . ' ago' : '—'),
                'state' => (string) ($field['state'] ?? self::UNAVAILABLE)];
        }
        return $out;
    }

    private static function timeOf(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') return null;
        $stamp = strtotime($value);
        return $stamp === false ? null : $stamp;
    }

    public static function humanAge(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return (int) round($seconds / 60) . ' min';
        // number_format, not round: "2 d" and "2.0 d" are the same age, and a
        // table that jitters between the two looks like it is recomputing.
        if ($seconds < 86400) return number_format($seconds / 3600, 1) . ' h';
        if ($seconds < 30 * 86400) return number_format($seconds / 86400, 1) . ' d';
        return number_format($seconds / (7 * 86400), 1) . ' weeks';
    }
}
