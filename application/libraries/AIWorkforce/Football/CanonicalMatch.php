<?php
namespace AIWorkforce\Football;

/**
 * Canonical match identity across providers.
 *
 * One real match arrives from three feeds under three ids — API-Football calls
 * it `12345`, SportMonks `987654`, TheSportsDB `55555`, and two of them spell
 * the home team differently. Without a canonical identity the module stores the
 * same match three times, generates three predictions for it, and bills three
 * times for it.
 *
 * This class answers two questions, and it is explicit about which one it used:
 *
 *  1. **What is this match called internally?** `identity()` builds
 *     `MANCHESTER_UNITED_ARSENAL_2026-09-12` from normalized team names and the
 *     kickoff date — the same string whichever provider the row came from.
 *  2. **Have we seen this match before?** `matchScore()` compares two
 *     candidates by provider id, then by normalized teams and kickoff date,
 *     then by fuzzy team names, and reports which rule fired.
 *
 * Fuzzy matching is deterministic — token comparison with prefix and acronym
 * rules ("Man Utd" ↔ "Manchester United" is a subsequence match on `utd`) — so
 * the same pair of names always resolves the same way. Nothing here is
 * probabilistic, and a near miss is reported as a near miss rather than
 * being forced into a match: the wrong merge would silently destroy a fixture.
 */
final class CanonicalMatch
{
    /** How a provider row was recognised (or not) as an already-known match. */
    public const MATCH_PROVIDER_ID = 'PROVIDER_ID';
    public const MATCH_TEAMS_DATE = 'TEAMS_AND_KICKOFF';
    public const MATCH_FUZZY_TEAMS = 'FUZZY_TEAM_NAMES';
    public const MATCH_NEW = 'NEW_FIXTURE';

    /** Below this, two team names are different clubs, not spellings of one. */
    public const FUZZY_THRESHOLD = 0.7;

    /**
     * Club suffixes that carry no identifying information. They are only
     * stripped from the end of a name, and only when something is left:
     * "Sporting CP" keeps its shape, "Manchester United" never loses "United".
     */
    private const SUFFIXES = ['football club', 'fc', 'afc', 'cf', 'sc', 'ac', 'as', 'ss', 'sv', 'bv', 'if', 'bk',
        'club', 'cd', 'ud', 'rc', 'real', 'deportivo', 'sporting', 'united fc'];

    /**
     * The internal match id: `HOME_AWAY_YYYY-MM-DD`, uppercased, from
     * normalized team names and the UTC kickoff date.
     */
    public static function identity(string $home, string $away, string $kickoff, bool $withDate = true): string
    {
        $parts = [self::slug($home), self::slug($away)];
        if ($withDate) $parts[] = self::kickoffDate($kickoff);
        return strtoupper(implode('_', array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    /** `Manchester United` → `manchester_united`; punctuation and accents removed. */
    public static function slug(string $name): string
    {
        $normalized = self::normalizeTeam($name);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '', '_');
    }

    /**
     * A team name reduced to what identifies it: lowercase, unaccented, without
     * punctuation or legal suffixes. Used for identity and for matching, never
     * for display — the display name stays exactly as the provider sent it.
     */
    public static function normalizeTeam(string $name): string
    {
        $text = self::transliterate(mb_strtolower(trim($name)));
        $text = (string) preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') return '';
        // Suffixes are dropped only from the end, and only when a stem remains.
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($text, ' ' . $suffix) && strlen($text) > strlen($suffix) + 2) {
                $text = trim(substr($text, 0, -strlen($suffix)));
                break;
            }
        }
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** The UTC calendar date of a kickoff stamp, for the identity and for grouping. */
    public static function kickoffDate(string $kickoff): string
    {
        $kickoff = trim($kickoff);
        if ($kickoff === '') return '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $kickoff, $m)) return $m[1];
        try {
            return (new \DateTimeImmutable($kickoff))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * How strongly two candidates are the same match, 0–1, plus the rule that
     * produced the answer. Provider ids are decisive; otherwise the teams and
     * the kickoff date decide.
     *
     * @return array{score:float, matchedBy:string, homeScore:float, awayScore:float}
     */
    public static function matchScore(array $candidate, array $existing): array
    {
        $candidateProvider = (string) ($candidate['providerCode'] ?? '');
        $candidateExternal = (string) ($candidate['providerMatchId'] ?? '');
        if ($candidateProvider !== '' && $candidateExternal !== ''
            && $candidateProvider === (string) ($existing['providerCode'] ?? '')
            && $candidateExternal === (string) ($existing['providerMatchId'] ?? '')) {
            return ['score' => 1.0, 'matchedBy' => self::MATCH_PROVIDER_ID, 'homeScore' => 1.0, 'awayScore' => 1.0];
        }
        $home = self::teamScore((string) ($candidate['homeTeam'] ?? ''), (string) ($existing['homeTeam'] ?? ''));
        $away = self::teamScore((string) ($candidate['awayTeam'] ?? ''), (string) ($existing['awayTeam'] ?? ''));
        $score = min($home, $away);
        $sameDate = self::kickoffDate((string) ($candidate['kickoff'] ?? '')) === self::kickoffDate((string) ($existing['kickoff'] ?? ''))
            && self::kickoffDate((string) ($candidate['kickoff'] ?? '')) !== '';
        if (!$sameDate) {
            // A different day is a different match however similar the names:
            // the same pair of clubs meet again in the reverse fixture.
            return ['score' => 0.0, 'matchedBy' => self::MATCH_NEW, 'homeScore' => $home, 'awayScore' => $away];
        }
        $matchedBy = $home >= 0.999 && $away >= 0.999 ? self::MATCH_TEAMS_DATE : self::MATCH_FUZZY_TEAMS;
        return ['score' => $score, 'matchedBy' => $matchedBy, 'homeScore' => $home, 'awayScore' => $away];
    }

    /** Do these two candidates describe the same fixture? */
    public static function isSameMatch(array $candidate, array $existing): bool
    {
        $result = self::matchScore($candidate, $existing);
        return $result['score'] >= self::FUZZY_THRESHOLD;
    }

    /**
     * Similarity of two club names, 0–1. Tokens match when they are equal, when
     * one is a prefix of the other (min 3 characters — "man" / "manchester"),
     * or when the shorter is a subsequence of the longer (min 3 —
     * "utd" inside "united", which is how an abbreviation resolves).
     */
    public static function teamScore(string $left, string $right): float
    {
        $a = self::normalizeTeam($left);
        $b = self::normalizeTeam($right);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $tokensA = array_values(array_filter(explode(' ', $a), static fn(string $t): bool => $t !== ''));
        $tokensB = array_values(array_filter(explode(' ', $b), static fn(string $t): bool => $t !== ''));
        if ($tokensA === [] || $tokensB === []) return 0.0;
        $matched = 0;
        $used = [];
        foreach ($tokensA as $indexA => $tokenA) {
            foreach ($tokensB as $indexB => $tokenB) {
                if (isset($used[$indexB])) continue;
                if (self::tokensMatch($tokenA, $tokenB)) { $matched++; $used[$indexB] = true; break; }
            }
        }
        // Both sides are scored against the longer name, so an abbreviation is
        // never worth as much as the full name it stands for.
        $max = max(count($tokensA), count($tokensB));
        return $max > 0 ? round($matched / $max, 4) : 0.0;
    }

    private static function tokensMatch(string $a, string $b): bool
    {
        if ($a === $b) return true;
        $short = strlen($a) <= strlen($b) ? $a : $b;
        $long = strlen($a) <= strlen($b) ? $b : $a;
        if (strlen($short) < 3) return false;
        if (str_starts_with($long, $short)) return true;
        return self::isSubsequence($short, $long);
    }

    /** Is every character of `$needle` present in `$haystack`, in order? */
    private static function isSubsequence(string $needle, string $haystack): bool
    {
        $position = 0;
        foreach (str_split($needle) as $character) {
            $found = strpos($haystack, $character, $position);
            if ($found === false) return false;
            $position = $found + 1;
        }
        return true;
    }

    /** Accents folded to ASCII when the platform can; otherwise left as sent. */
    private static function transliterate(string $text): string
    {
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($folded) && $folded !== '') return $folded;
        }
        $pairs = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ç' => 'c', 'í' => 'i', 'ì' => 'i',
            'î' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u',
            'ù' => 'u', 'û' => 'u', 'ø' => 'o', 'å' => 'a', 'æ' => 'ae', 'œ' => 'oe', 'ł' => 'l',
            'ż' => 'z', 'ź' => 'z', 'ś' => 's', 'ć' => 'c', 'ę' => 'e', 'ą' => 'a', 'č' => 'c',
            'š' => 's', 'ž' => 'z', 'đ' => 'd', 'ğ' => 'g', 'ı' => 'i', 'ş' => 's'];
        return strtr($text, $pairs);
    }
}
