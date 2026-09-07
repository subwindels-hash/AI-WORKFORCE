<?php
namespace AIWorkforce\TradingProtection;

/**
 * Automatic Kill Switch policy — the administrator-configurable rule set.
 *
 * Held in platform state (no schema change) and applied to every order-bound
 * surface. Defaults are the safe recommended configuration: news protection on
 * (5 min before / 30 min after), 3% daily loss, 10% max drawdown, spread,
 * slippage, connection-loss and stale-data protection on, automatic recovery
 * only when every condition is clear.
 *
 * Everything here is validated and clamped on write — a bad admin value can
 * never widen the protection (e.g. a 500% drawdown limit is clamped, never
 * accepted).
 */
final class ProtectionPolicy
{
    public const DEFAULTS = [
        'enabled' => true,
        'news' => [
            'enabled' => true,
            'minutesBefore' => 5,
            'minutesAfter' => 30,
            'warningLeadMinutes' => 15,
            'impacts' => ['high', 'red'],
            // §12 fail-safe: a calendar that IS configured but cannot be read
            // must pause trading rather than be assumed clear.
            'onFeedFailure' => 'pause',
            // Deliberate deviation, documented: with no calendar configured at
            // all there is nothing to verify, and pausing would freeze a fresh
            // install forever. Set true for the strictest reading of §12.
            'pauseWhenNoProvider' => false,
            'feedMaxAgeMinutes' => 180,
        ],
        'dailyLoss' => [
            'enabled' => true,
            'percentLimit' => 0.03,
            'fixedLimitUsd' => null,
            'warnAtFraction' => 0.8,
        ],
        'drawdown' => [
            'enabled' => true,
            'percentLimit' => 0.10,
            'warnAtFraction' => 0.8,
        ],
        'technical' => [
            'brokerDisconnect' => true,
            'staleData' => true,
            'dataFeedTimeoutSeconds' => 60,
            'maxConsecutiveOrderFailures' => 3,
            'escalateToKill' => false,
        ],
        'spread' => [
            'enabled' => true,
            'maxPoints' => 30.0,
            'perSymbol' => [],
            'warnAtFraction' => 0.8,
            // When true an unreadable spread blocks trading (§12). Off by
            // default: simulated/paper feeds carry no bid-ask spread, and
            // blocking on a reading that does not exist would freeze paper
            // trading rather than protect anything.
            'requireReading' => false,
        ],
        'slippage' => [
            'enabled' => true,
            'maxPoints' => 10.0,
            'sampleWindow' => 20,
        ],
        'emergency' => [
            // Opt-in: closing real positions is a money-moving action. Blocking
            // new trades is the default; the administrator chooses more.
            'closePositionsOnKill' => false,
            'cancelPendingOrdersOnKill' => false,
        ],
        'recovery' => [
            'enabled' => true,
            'requireAllClear' => true,
            'consecutiveClearScans' => 2,
            'maxStatusAgeSeconds' => 300,
        ],
    ];

    /** path => [type, min, max] — types: bool, int, float, nullableFloat, enum, list, pointsMap. */
    private const RULES = [
        'enabled' => ['bool'],
        'news.enabled' => ['bool'],
        'news.minutesBefore' => ['int', 0, 240],
        'news.minutesAfter' => ['int', 0, 480],
        'news.warningLeadMinutes' => ['int', 0, 240],
        'news.impacts' => ['list'],
        'news.onFeedFailure' => ['enum', ['pause', 'warn']],
        'news.pauseWhenNoProvider' => ['bool'],
        'news.feedMaxAgeMinutes' => ['int', 5, 1440],
        'dailyLoss.enabled' => ['bool'],
        'dailyLoss.percentLimit' => ['float', 0.0, 0.5],
        'dailyLoss.fixedLimitUsd' => ['nullableFloat', 0.0, 100000000.0],
        'dailyLoss.warnAtFraction' => ['float', 0.1, 1.0],
        'drawdown.enabled' => ['bool'],
        'drawdown.percentLimit' => ['float', 0.0, 0.9],
        'drawdown.warnAtFraction' => ['float', 0.1, 1.0],
        'technical.brokerDisconnect' => ['bool'],
        'technical.staleData' => ['bool'],
        'technical.dataFeedTimeoutSeconds' => ['int', 5, 3600],
        'technical.maxConsecutiveOrderFailures' => ['int', 1, 50],
        'technical.escalateToKill' => ['bool'],
        'spread.enabled' => ['bool'],
        'spread.maxPoints' => ['float', 0.0, 100000.0],
        'spread.perSymbol' => ['pointsMap'],
        'spread.warnAtFraction' => ['float', 0.1, 1.0],
        'spread.requireReading' => ['bool'],
        'slippage.enabled' => ['bool'],
        'slippage.maxPoints' => ['float', 0.0, 100000.0],
        'slippage.sampleWindow' => ['int', 1, 200],
        'emergency.closePositionsOnKill' => ['bool'],
        'emergency.cancelPendingOrdersOnKill' => ['bool'],
        'recovery.enabled' => ['bool'],
        'recovery.requireAllClear' => ['bool'],
        'recovery.consecutiveClearScans' => ['int', 1, 60],
        'recovery.maxStatusAgeSeconds' => ['int', 30, 3600],
    ];

    /** Deep merge of defaults with a stored policy, then an optional patch. */
    public static function normalize(?array $patch = null, array $base = []): array
    {
        $policy = self::merge(self::DEFAULTS, self::merge($base, []));
        if (!is_array($patch)) return $policy;

        foreach ($patch as $section => $values) {
            if ($section === 'enabled') {
                $policy['enabled'] = self::coerce('enabled', $values, $policy['enabled']);
                continue;
            }
            if (!is_array($values) || !isset(self::DEFAULTS[$section])) continue;
            foreach ($values as $key => $value) {
                $path = $section . '.' . $key;
                if (!isset(self::RULES[$path])) continue;
                $policy[$section][$key] = self::coerce($path, $value, $policy[$section][$key]);
            }
        }
        return $policy;
    }

    private static function coerce(string $path, mixed $value, mixed $current): mixed
    {
        $rule = self::RULES[$path];
        $type = $rule[0];
        return match ($type) {
            'bool' => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) self::clamp(self::numeric($value), (float) $rule[1], (float) $rule[2]),
            'float' => (float) self::clamp(self::numeric($value), (float) $rule[1], (float) $rule[2]),
            'nullableFloat' => ($value === null || $value === '' || $value === '0')
                ? null
                : (float) self::clamp(self::numeric($value), (float) $rule[1], (float) $rule[2]),
            'enum' => in_array((string) $value, $rule[1], true) ? (string) $value : $current,
            'list' => self::stringList($value),
            'pointsMap' => self::pointsMap($value),
            default => $current,
        };
    }

    private static function numeric(mixed $value): float
    {
        if (is_bool($value)) return $value ? 1.0 : 0.0;
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    /** @return array<int,string> */
    private static function stringList(mixed $value): array
    {
        $items = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)));
        $out = [];
        foreach ($items as $item) {
            $item = strtolower(trim((string) $item));
            if ($item !== '' && !in_array($item, $out, true)) $out[] = $item;
        }
        return $out === [] ? ['high', 'red'] : $out;
    }

    /** @return array<string,float> symbol => max spread in points */
    private static function pointsMap(mixed $value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $symbol => $points) {
            $symbol = strtoupper(trim((string) $symbol));
            if ($symbol === '' || !is_numeric($points)) continue;
            $out[$symbol] = (float) self::clamp((float) $points, 0.0, 100000.0);
        }
        return $out;
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Convert a percentage as administrators type it (3 = 3%) into the
     * fraction the policy stores (0.03). Blank input means "no limit".
     *
     * Deliberately NOT done inside normalize(): 0.9 is a legitimate 90%
     * drawdown fraction and also a legitimate 0.9% percentage, so the unit is
     * only unambiguous at the boundary that owns the form.
     */
    public static function fromPercent(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? ((float) $value) / 100.0 : null;
    }

    /** The inverse of fromPercent(), for rendering policy values in a form. */
    public static function toPercent(mixed $value): float
    {
        return is_numeric($value) ? ((float) $value) * 100.0 : 0.0;
    }

    /** Max spread allowed for a symbol: per-symbol override wins, else global. */
    public static function maxSpreadPoints(array $policy, string $symbol): float
    {
        $symbol = strtoupper(trim($symbol));
        $perSymbol = $policy['spread']['perSymbol'] ?? [];
        if ($symbol !== '' && isset($perSymbol[$symbol])) return (float) $perSymbol[$symbol];
        return (float) ($policy['spread']['maxPoints'] ?? 0.0);
    }

    /**
     * Value of ONE POINT for a symbol, used to convert a raw price spread into
     * the points the administrator configures (§5: "max 30 points").
     *
     * A point is the smallest price increment on a standard broker feed — the
     * MT4/MT5 convention, which is what the spread and slippage limits are
     * written against:
     *
     *   EURUSD (5-digit)  0.00001  → 30 points = 3 pips
     *   USDJPY (3-digit)  0.001    → 30 points = 3 pips
     *   XAUUSD            0.01     → 30 points = $0.30
     *   US30              0.1      → 30 points = 3 index points
     *   BTCUSDT           1 bp of price (0.01%), because crypto and equities
     *                     are quoted at wildly different scales.
     */
    public static function pointSize(string $symbol, float $price = 0.0): float
    {
        $s = strtoupper(trim($symbol));
        if ($s === '') return 0.00001;
        if (str_contains($s, 'JPY')) return 0.001;
        if (preg_match('/^(XAU|XAG|XPT|XPD)/', $s)) return 0.01;
        if (preg_match('/^(US30|US500|SPX|SPX500|NAS|NAS100|NDX|US100|WS30|FRA40|GER40|UK100|AUS200|JPN225)/', $s)) return 0.1;
        // 6-character FX pairs (EURUSD, GBPUSD …) on a 5-digit feed.
        if (strlen($s) === 6 && preg_match('/^[A-Z]{6}$/', $s)) return 0.00001;
        // Crypto / equities: one basis point of price, floor of a cent.
        if ($price > 0.0) return max(0.00000001, $price * 0.0001);
        return 0.01;
    }

    /** Convert a raw price spread to points. Returns null when unreadable. */
    public static function toPoints(?float $spread, string $symbol, float $price = 0.0): ?float
    {
        if ($spread === null) return null;
        $size = self::pointSize($symbol, $price);
        return $size > 0 ? $spread / $size : null;
    }
}
