<?php
namespace AIWorkforce\Sports;

/**
 * The single machine-readable failure-code vocabulary for the odds
 * prediction pipeline (Round 3b). Every internal reason string the engine
 * already produces (STALE_ODDS, LOW_CONFIDENCE, MODEL_NOT_CALIBRATED, …) is
 * translated here to one of these codes — never renamed in place, so every
 * existing test/consumer of the legacy string keeps working, and every new
 * consumer (the pipeline run summary, the admin diagnostic record, the
 * decision report) gets one small, exhaustive, exact vocabulary instead of a
 * different ad-hoc string per engine.
 *
 * A code that has no mapped legacy reason yet (PROVIDER_TIMEOUT, RATE_LIMITED,
 * DUPLICATE_MARKET, …) is still declared and used directly where the engine
 * already carries that exact fact (provider exception status, dedup count).
 */
final class FailureTaxonomy
{
    // ── The exact required vocabulary, plus the extra codes the real
    // implementation needs (never fewer than requested, only ever more). ──
    public const MATCH_NOT_FOUND = 'MATCH_NOT_FOUND';
    public const MATCH_ALREADY_STARTED = 'MATCH_ALREADY_STARTED';
    public const MATCH_FINISHED = 'MATCH_FINISHED';
    public const KICKOFF_TOO_CLOSE = 'KICKOFF_TOO_CLOSE';
    public const ODDS_UNAVAILABLE = 'ODDS_UNAVAILABLE';
    public const ODDS_STALE = 'ODDS_STALE';
    public const ODDS_INVALID = 'ODDS_INVALID';
    public const ODDS_PROVIDER_ERROR = 'ODDS_PROVIDER_ERROR';
    public const MARKET_UNAVAILABLE = 'MARKET_UNAVAILABLE';
    public const MARKET_SUSPENDED = 'MARKET_SUSPENDED';
    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';
    public const DATA_QUALITY_TOO_LOW = 'DATA_QUALITY_TOO_LOW';
    public const MODEL_UNAVAILABLE = 'MODEL_UNAVAILABLE';
    public const MODEL_DRAFT_BLOCKED = 'MODEL_DRAFT_BLOCKED';
    public const PREDICTION_FAILED = 'PREDICTION_FAILED';
    public const CONFIDENCE_TOO_LOW = 'CONFIDENCE_TOO_LOW';
    public const VALUE_TOO_LOW = 'VALUE_TOO_LOW';
    public const PROBABILITY_INVALID = 'PROBABILITY_INVALID';
    public const DUPLICATE_MARKET = 'DUPLICATE_MARKET';
    public const NO_SUPPORTED_MARKET = 'NO_SUPPORTED_MARKET';
    public const FALLBACK_FAILED = 'FALLBACK_FAILED';
    public const PROVIDER_TIMEOUT = 'PROVIDER_TIMEOUT';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    // Extra codes this implementation needs beyond the requested list, kept
    // in the same style so the vocabulary stays exact and machine-readable.
    public const MODEL_NOT_CALIBRATED = 'MODEL_NOT_CALIBRATED';
    public const RISK_NOT_APPROVED = 'RISK_NOT_APPROVED';
    public const MARKET_RESTRICTED_BY_DATA_TIER = 'MARKET_RESTRICTED_BY_DATA_TIER';
    public const CONFIGURATION_RESTRICTED = 'CONFIGURATION_RESTRICTED';
    public const LIQUIDITY_TOO_LOW = 'LIQUIDITY_TOO_LOW';
    public const NO_COMBINABLE_TICKET = 'NO_COMBINABLE_TICKET';

    /** @var array<string,string> legacy internal reason → exact taxonomy code */
    private const LEGACY_MAP = [
        // Fixture/eligibility
        'FIXTURE_NOT_NS_OR_TOO_SOON' => self::KICKOFF_TOO_CLOSE,
        'MATCH_STATUS_INVALID' => self::MATCH_FINISHED,
        'MATCH_DATA_INVALID' => self::MATCH_NOT_FOUND,
        'MATCH_NOT_FINISHED' => self::MATCH_ALREADY_STARTED,

        // Odds / market coverage
        'ODDS_UNAVAILABLE' => self::ODDS_UNAVAILABLE,
        'ODDS_TIMESTAMP_INVALID' => self::ODDS_INVALID,
        'STALE_ODDS' => self::ODDS_STALE,
        'MARKET_UNAVAILABLE' => self::MARKET_UNAVAILABLE,
        'MARKET_SUSPENDED' => self::MARKET_SUSPENDED,
        'UNPRICEABLE_MARKET' => self::ODDS_INVALID,
        'NO_QUOTABLE_PRICE' => self::ODDS_INVALID,
        'UNREALISTIC_ODDS' => self::ODDS_INVALID,
        'UNSUPPORTED_MARKET' => self::NO_SUPPORTED_MARKET,
        'MARKET_NOT_ALLOWED' => self::CONFIGURATION_RESTRICTED,
        'LEAGUE_NOT_ALLOWED' => self::CONFIGURATION_RESTRICTED,
        'OUTSIDE_CONFIGURATION' => self::CONFIGURATION_RESTRICTED,
        'MARKET_RESTRICTED_AT_DATA_TIER' => self::MARKET_RESTRICTED_BY_DATA_TIER,

        // Data / model
        'INSUFFICIENT_DATA' => self::INSUFFICIENT_DATA,
        'MANDATORY_MODEL_DATA' => self::INSUFFICIENT_DATA,
        'LOW_DATA_QUALITY' => self::DATA_QUALITY_TOO_LOW,
        'DATA_QUALITY_BELOW_MINIMUM' => self::DATA_QUALITY_TOO_LOW,
        'DATA_UNAVAILABLE' => self::INSUFFICIENT_DATA,
        'MODEL_NOT_CALIBRATED' => self::MODEL_NOT_CALIBRATED,
        'NO_PREDICTION' => self::PREDICTION_FAILED,

        // Confidence
        'LOW_CONFIDENCE' => self::CONFIDENCE_TOO_LOW,
        'CONFIDENCE_UNMEASURED' => self::CONFIDENCE_TOO_LOW,
        'INSUFFICIENT_CONFIDENCE_EVIDENCE' => self::CONFIDENCE_TOO_LOW,

        // Value / edge
        'LOW_MODEL_EDGE' => self::VALUE_TOO_LOW,
        'NO_POSITIVE_VALUE' => self::VALUE_TOO_LOW,

        // Risk / liquidity
        'RISK_NOT_APPROVED' => self::RISK_NOT_APPROVED,
        'HIGH_RISK' => self::RISK_NOT_APPROVED,
        'INSUFFICIENT_LIQUIDITY' => self::LIQUIDITY_TOO_LOW,
        'ODDS_VOLATILE' => self::RISK_NOT_APPROVED,

        // Duplicates / combination
        'DUPLICATE_SKIPPED' => self::DUPLICATE_MARKET,
        'NO_QUALIFIED_TICKET' => self::NO_COMBINABLE_TICKET,
        'NOT_IN_BEST_COMBINATION' => self::NO_COMBINABLE_TICKET,
        'BELOW_PREFERRED_CRITERIA' => self::NO_COMBINABLE_TICKET,

        // Provider exceptions (AIWorkforce\Sports\Providers\ProviderException::status)
        'TIMEOUT' => self::PROVIDER_TIMEOUT,
        'RATE_LIMITED' => self::RATE_LIMITED,
        'DAILY_QUOTA_EXHAUSTED' => self::RATE_LIMITED,
        'OFFLINE' => self::ODDS_PROVIDER_ERROR,
        'DEGRADED' => self::ODDS_PROVIDER_ERROR,
        'AUTHENTICATION_ERROR' => self::ODDS_PROVIDER_ERROR,
        'BAD_REQUEST' => self::ODDS_PROVIDER_ERROR,
        'NOT_FOUND' => self::ODDS_PROVIDER_ERROR,
        'DATA_ERROR' => self::ODDS_PROVIDER_ERROR,
    ];

    /** Every code this taxonomy can emit — the exhaustive machine-readable vocabulary. */
    public const ALL_CODES = [
        self::MATCH_NOT_FOUND, self::MATCH_ALREADY_STARTED, self::MATCH_FINISHED, self::KICKOFF_TOO_CLOSE,
        self::ODDS_UNAVAILABLE, self::ODDS_STALE, self::ODDS_INVALID, self::ODDS_PROVIDER_ERROR,
        self::MARKET_UNAVAILABLE, self::MARKET_SUSPENDED, self::INSUFFICIENT_DATA, self::DATA_QUALITY_TOO_LOW,
        self::MODEL_UNAVAILABLE, self::MODEL_DRAFT_BLOCKED, self::PREDICTION_FAILED, self::CONFIDENCE_TOO_LOW,
        self::VALUE_TOO_LOW, self::PROBABILITY_INVALID, self::DUPLICATE_MARKET, self::NO_SUPPORTED_MARKET,
        self::FALLBACK_FAILED, self::PROVIDER_TIMEOUT, self::RATE_LIMITED, self::UNKNOWN_ERROR,
        self::MODEL_NOT_CALIBRATED, self::RISK_NOT_APPROVED, self::MARKET_RESTRICTED_BY_DATA_TIER,
        self::CONFIGURATION_RESTRICTED, self::LIQUIDITY_TOO_LOW, self::NO_COMBINABLE_TICKET,
    ];

    /** Codes that name a data/provider/timing gap worth retrying later. */
    private const RETRYABLE_CODES = [
        self::ODDS_UNAVAILABLE, self::ODDS_STALE, self::ODDS_PROVIDER_ERROR, self::PROVIDER_TIMEOUT,
        self::RATE_LIMITED, self::INSUFFICIENT_DATA, self::MODEL_NOT_CALIBRATED, self::FALLBACK_FAILED,
        self::MODEL_UNAVAILABLE,
    ];

    /** Codes that name a fixed business/configuration rule — retrying the same run will not help. */
    private const NON_RETRYABLE_CODES = [
        self::MATCH_NOT_FOUND, self::MATCH_ALREADY_STARTED, self::MATCH_FINISHED, self::KICKOFF_TOO_CLOSE,
        self::MARKET_UNAVAILABLE, self::MARKET_SUSPENDED, self::NO_SUPPORTED_MARKET, self::DUPLICATE_MARKET,
        self::CONFIGURATION_RESTRICTED, self::PROBABILITY_INVALID,
    ];

    /** @var array<string,string> failure code → plain, non-technical user-facing message */
    private const USER_MESSAGES = [
        self::MATCH_NOT_FOUND => 'Prediction unavailable — this match could not be verified.',
        self::MATCH_ALREADY_STARTED => 'Prediction unavailable — this match has already kicked off.',
        self::MATCH_FINISHED => 'Prediction unavailable — this match has finished.',
        self::KICKOFF_TOO_CLOSE => 'Prediction unavailable — kickoff is too close for a verified prediction.',
        self::ODDS_UNAVAILABLE => 'Prediction unavailable — no verified bookmaker odds for this market yet.',
        self::ODDS_STALE => 'Prediction unavailable — the available odds are out of date.',
        self::ODDS_INVALID => 'Prediction unavailable — the odds available for this market could not be verified.',
        self::ODDS_PROVIDER_ERROR => 'Prediction unavailable — the odds provider is temporarily unavailable.',
        self::MARKET_UNAVAILABLE => 'Prediction unavailable — this market is not offered for this match.',
        self::MARKET_SUSPENDED => 'Prediction unavailable — this market is currently suspended.',
        self::INSUFFICIENT_DATA => 'Prediction unavailable — insufficient verified data for this match.',
        self::DATA_QUALITY_TOO_LOW => 'Prediction unavailable — the verified data for this match does not meet the required quality.',
        self::MODEL_UNAVAILABLE => 'Prediction unavailable — the prediction model is temporarily unavailable.',
        self::MODEL_DRAFT_BLOCKED => 'Prediction unavailable — the prediction model is not yet active.',
        self::PREDICTION_FAILED => 'Prediction unavailable — a prediction could not be generated for this match.',
        self::CONFIDENCE_TOO_LOW => 'Prediction unavailable — confidence in this prediction is below the required minimum.',
        self::VALUE_TOO_LOW => 'Prediction unavailable — this selection does not offer sufficient value at the current odds.',
        self::PROBABILITY_INVALID => 'Prediction unavailable — the computed probability could not be verified.',
        self::DUPLICATE_MARKET => 'Prediction unavailable — this market was already covered by another selection.',
        self::NO_SUPPORTED_MARKET => 'Prediction unavailable — this market is not currently supported.',
        self::FALLBACK_FAILED => 'Prediction unavailable — no alternative source could supply this data.',
        self::PROVIDER_TIMEOUT => 'Prediction unavailable — the data provider did not respond in time.',
        self::RATE_LIMITED => 'Prediction unavailable — the data provider is temporarily rate-limited.',
        self::MODEL_NOT_CALIBRATED => 'Prediction unavailable — the prediction model has not yet been calibrated and approved.',
        self::RISK_NOT_APPROVED => 'Prediction unavailable — this selection did not meet the risk requirements.',
        self::MARKET_RESTRICTED_BY_DATA_TIER => 'Prediction unavailable — this market needs stronger verified data than is currently available.',
        self::CONFIGURATION_RESTRICTED => 'Prediction unavailable — this market or league is outside the configured scope.',
        self::LIQUIDITY_TOO_LOW => 'Prediction unavailable — market liquidity is too low to trust the price.',
        self::NO_COMBINABLE_TICKET => 'A combined ticket could not be assembled today, but individual match predictions below are still available.',
        self::UNKNOWN_ERROR => 'Prediction unavailable — an unexpected issue occurred while evaluating this match.',
    ];

    /** Translate a legacy internal reason string to the exact taxonomy code. Idempotent. */
    public static function translate(?string $legacyReason): string
    {
        $reason = strtoupper(trim((string) $legacyReason));
        if ($reason === '') return self::UNKNOWN_ERROR;
        if (in_array($reason, self::ALL_CODES, true)) return $reason; // already exact
        return self::LEGACY_MAP[$reason] ?? self::UNKNOWN_ERROR;
    }

    /** The plain, non-technical message a regular user sees for this failure code. */
    public static function userMessage(string $failureCode): string
    {
        return self::USER_MESSAGES[$failureCode] ?? self::USER_MESSAGES[self::UNKNOWN_ERROR];
    }

    /** Whether this class of failure is worth retrying (data/provider/timing), vs a fixed rule. */
    public static function retryable(string $failureCode): bool
    {
        if (in_array($failureCode, self::RETRYABLE_CODES, true)) return true;
        if (in_array($failureCode, self::NON_RETRYABLE_CODES, true)) return false;
        // Soft/business-rule gates (confidence, value, risk, data quality) are
        // retryable in the sense that fresh data/odds on a later run can clear
        // them — they are not permanent configuration facts like a finished
        // match or an unsupported market.
        return true;
    }
}
