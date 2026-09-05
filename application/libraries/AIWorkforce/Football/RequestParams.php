<?php
namespace AIWorkforce\Football;

/**
 * Query-parameter reading for the football read models.
 *
 * The rule this class exists to enforce: **a malformed parameter is never
 * silently turned into a different request.** `?limit=abc` used to arrive as
 * `limit=1`, and `?date=2026-02-30` as a rolled-over day — a caller that typo'd a
 * parameter got a small, plausible-looking payload with no way to tell it had been
 * reinterpreted. On a module whose whole contract is that an absent number is
 * reported rather than invented, coercing the caller's own input is the same
 * mistake one layer up.
 *
 * So: an absent or empty parameter takes the documented default; a parameter that
 * is present but unusable takes the default *and records a note* the endpoint
 * returns in its `request` block; a numeric value outside the allowed span is
 * clamped and also recorded. The notes are what turn a silent fallback into a
 * stated one.
 */
final class RequestParams
{
    /**
     * @param array<string,mixed> $query
     * @param list<string> $notes collected by reference; safe to ignore
     */
    public static function int(array $query, string $key, int $default, int $min, int $max, array &$notes = []): int
    {
        if (self::absent($query, $key)) return $default;
        $raw = $query[$key];
        // Deliberately stricter than is_numeric(): '1e3' and '12.5' are numbers to
        // PHP and would arrive here as 1, which is a different request than the one
        // that was made.
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^[+-]?\d+$/', trim($raw)) === 1)) {
            $notes[] = $key . '=' . self::preview($raw) . ' is not a whole number; the documented default ' . $default . ' was used instead.';
            return $default;
        }
        $value = (int) trim((string) $raw);
        if ($value < $min || $value > $max) {
            $clamped = max($min, min($max, $value));
            $notes[] = $key . '=' . $value . ' is outside the allowed ' . $min . '–' . $max . '; ' . $clamped . ' was used.';
            return $clamped;
        }
        return $value;
    }

    /**
     * For a mutation: was a date supplied that cannot be honoured? Syncing a
     * provider costs quota, so a typo'd `date` must abort the action rather than
     * quietly refresh some other day.
     *
     * @param array<string,mixed> $input
     */
    public static function suppliedButInvalidDate(array $input, string $key = 'date'): bool
    {
        return array_key_exists($key, $input) && $input[$key] !== '' && $input[$key] !== null
            && !self::isCalendarDate($input[$key]);
    }

    /**
     * A calendar date, with the documented default when it is absent or unusable.
     * `2026-02-30` is refused rather than rolled over: a rolled-over date would
     * report a day nobody asked for.
     *
     * @param array<string,mixed> $query
     * @param list<string> $notes
     */
    public static function date(array $query, string $key, string $default, array &$notes = []): string
    {
        if (self::absent($query, $key)) return $default;
        if (self::isCalendarDate($query[$key])) return (string) $query[$key];
        $notes[] = self::isCalendarShape($query[$key])
            ? $key . '=' . self::preview($query[$key]) . ' is not a real calendar date; ' . $default . ' was used instead.'
            : $key . '=' . self::preview($query[$key]) . ' is not a YYYY-MM-DD date; ' . $default . ' was used instead.';
        return $default;
    }

    /**
     * The same validation with no default, for an endpoint where "absent" and
     * "today" must stay distinguishable (a `date` narrowing a from/to window).
     *
     * @param array<string,mixed> $query
     * @param list<string> $notes
     */
    public static function optionalDate(array $query, string $key, array &$notes = []): ?string
    {
        if (self::absent($query, $key)) return null;
        if (self::isCalendarDate($query[$key])) return (string) $query[$key];
        $notes[] = self::isCalendarShape($query[$key])
            ? $key . '=' . self::preview($query[$key]) . ' is not a real calendar date and was ignored.'
            : $key . '=' . self::preview($query[$key]) . ' is not a YYYY-MM-DD date and was ignored.';
        return null;
    }

    /**
     * A window bound: a date or a full timestamp, compared lexicographically
     * against the stored ISO kickoff stamps. A bound the database cannot read
     * matches nothing, which looks exactly like an empty feed — so it is dropped
     * with a note instead of passed through.
     *
     * @param array<string,mixed> $query
     * @param list<string> $notes
     */
    public static function timestamp(array $query, string $key, array &$notes = []): ?string
    {
        if (self::absent($query, $key)) return null;
        $raw = $query[$key];
        if (self::isCalendarDate($raw)) return (string) $raw;
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $raw) === 1) {
            try {
                new \DateTimeImmutable(str_replace(' ', 'T', $raw));
                return $raw;
            } catch (\Throwable) {
                $notes[] = $key . '=' . self::preview($raw) . ' is not a readable timestamp and was ignored.';
                return null;
            }
        }
        $notes[] = $key . '=' . self::preview($raw) . ' is not a date or timestamp and was ignored.';
        return null;
    }

    /** Absent means "the caller did not ask", which is not the same as "zero". */
    private static function absent(array $query, string $key): bool
    {
        return !array_key_exists($key, $query) || $query[$key] === '' || $query[$key] === null;
    }

    private static function isCalendarShape(mixed $raw): bool
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1;
    }

    private static function isCalendarDate(mixed $raw): bool
    {
        if (!self::isCalendarShape($raw)) return false;
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $raw);
        return $parsed !== false && $parsed->format('Y-m-d') === $raw;
    }

    /** Short, quoted rendering of whatever arrived, for the note text. */
    public static function preview(mixed $raw): string
    {
        if (is_array($raw)) return '[array]';
        if (is_bool($raw)) return $raw ? 'true' : 'false';
        if ($raw === null) return 'null';
        $text = is_scalar($raw) ? (string) $raw : gettype($raw);
        if (strlen($text) > 40) $text = substr($text, 0, 40) . '…';
        return '"' . str_replace(["\n", "\r", "\t", '"'], [' ', '', '', "'"], $text) . '"';
    }
}
