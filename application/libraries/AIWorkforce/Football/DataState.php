<?php
namespace AIWorkforce\Football;

/**
 * The three states every football field can be in, and the only vocabulary the
 * module uses for "we do not have this".
 *
 * The contract that keeps the no-fabrication promise:
 *  - a field a provider did not return is DATA_UNAVAILABLE — never 0, never '—'
 *    disguised as a number, never carried into a model input;
 *  - a field that exists but is too thin to rely on (e.g. two matches of form)
 *    is LIMITED_DATA: it may still be shown, and it caps data quality, but it
 *    can never lift a prediction into the QUALIFIED band on its own.
 */
final class DataState
{
    public const AVAILABLE = 'AVAILABLE';
    public const LIMITED = 'LIMITED_DATA';
    public const UNAVAILABLE = 'DATA_UNAVAILABLE';

    public static function isReal(?string $state): bool
    {
        return $state === self::AVAILABLE;
    }

    /** Human-facing value or the explicit unavailable marker. */
    public static function value(mixed $value, ?string $state = null): mixed
    {
        if ($value === null || $value === '' || $value === []) return self::UNAVAILABLE;
        if ($state === self::UNAVAILABLE) return self::UNAVAILABLE;
        return $value;
    }

    /** Derive a state from how many of the wanted fields actually arrived. */
    public static function fromCoverage(array $present, int $expected): string
    {
        $have = count(array_filter($present, static fn($v) => $v !== null && $v !== '' && $v !== []));
        if ($expected <= 0 || $have === 0) return $have === 0 && $expected > 0 ? self::UNAVAILABLE : self::LIMITED;
        if ($have >= $expected) return self::AVAILABLE;
        return $have >= (int) ceil($expected * 0.6) ? self::LIMITED : self::UNAVAILABLE;
    }
}

/**
 * Numeric model of a "0 – 100" data-quality assessment: one component per data
 * family, each with an explicit weight, so a score can always be explained as
 * "which family was missing" rather than as an opaque number.
 */
final class QualityBand
{
    public const QUALIFIED = 'QUALIFIED';   // score >= 70 — prediction may be published
    public const LIMITED = 'LIMITED';      // 50 – 69   — shown, never high-confidence
    public const REJECTED = 'REJECTED';    // < 50      — no prediction at all

    public const QUALIFIED_MIN = 70;
    public const LIMITED_MIN = 50;

    public static function forScore(int $score): string
    {
        return $score >= self::QUALIFIED_MIN ? self::QUALIFIED
            : ($score >= self::LIMITED_MIN ? self::LIMITED : self::REJECTED);
    }
}
