<?php
namespace AIWorkforce\Sports\Providers;

/**
 * Typed provider failure. Carries the classified status so the manager, the
 * circuit breaker and the health monitor never have to guess what went wrong.
 *
 * Statuses:
 *   OFFLINE                – no HTTP response / transport failure
 *   TIMEOUT                – request exceeded the configured timeout
 *   DEGRADED               – partial / slow service
 *   RATE_LIMITED           – per-minute throttle (HTTP 429); retry after ~60s
 *   DAILY_QUOTA_EXHAUSTED  – the plan's daily allowance is used up; do NOT retry
 *                            until the vendor's quota reset (api-football: 00:00 UTC)
 *   AUTHENTICATION_ERROR   – HTTP 401/403 or a rejected key; configuration fix
 *   BAD_REQUEST            – HTTP 400; the adapter sent something the vendor
 *                            does not accept — configuration/adapter fix, not a retry
 *   NOT_FOUND              – HTTP 404; wrong base URL / path — configuration fix
 *   DATA_ERROR             – 5xx, malformed payload, unexpected shape
 *
 * `details` carries machine-readable extras alongside the human message so
 * callers can branch on facts instead of parsing prose — e.g. api-football
 * keys its soft errors by the offending query parameter, which arrives as
 * `['errorField' => 'page']` for "The Page field do not exist.". The shared
 * transport also attaches a redacted request diagnostic (`endpoint`, `method`,
 * `httpStatus`, `bodySnippet`) — never the credential.
 */
final class ProviderException extends \RuntimeException
{
    public const OFFLINE = 'OFFLINE';
    public const TIMEOUT = 'TIMEOUT';
    public const DEGRADED = 'DEGRADED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const DAILY_QUOTA_EXHAUSTED = 'DAILY_QUOTA_EXHAUSTED';
    public const AUTHENTICATION_ERROR = 'AUTHENTICATION_ERROR';
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const NOT_FOUND = 'NOT_FOUND';
    public const DATA_ERROR = 'DATA_ERROR';

    /** Statuses that mean "fix the configuration" — retrying will not help. */
    public const CONFIGURATION_STATUSES = [self::AUTHENTICATION_ERROR, self::BAD_REQUEST, self::NOT_FOUND];

    /** Statuses that mean "the vendor is throttling us" — back off, do not hammer. */
    public const THROTTLE_STATUSES = [self::RATE_LIMITED, self::DAILY_QUOTA_EXHAUSTED];

    /** @param array<string,mixed> $details machine-readable extras (errorField, endpoint, httpStatus, ...) */
    public function __construct(
        string $message,
        public readonly string $status = self::DATA_ERROR,
        ?\Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isConfigurationError(): bool
    {
        return in_array($this->status, self::CONFIGURATION_STATUSES, true);
    }

    public function isThrottled(): bool
    {
        return in_array($this->status, self::THROTTLE_STATUSES, true);
    }

    /** Copy of this exception with extra details merged in (details are readonly). */
    public function withDetails(array $extra): self
    {
        return new self($this->getMessage(), $this->status, $this->getPrevious(), array_merge($this->details, $extra));
    }
}
