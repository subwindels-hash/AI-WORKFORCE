<?php
namespace AIWorkforce\Sports\Providers;

/**
 * Typed provider failure. Carries the classified status so the manager and
 * health monitor never have to guess what went wrong.
 *
 * Statuses: OFFLINE | DEGRADED | RATE_LIMITED | AUTHENTICATION_ERROR | DATA_ERROR
 *
 * `details` carries machine-readable extras alongside the human message so
 * callers can branch on facts instead of parsing prose — e.g. api-football
 * keys its soft errors by the offending query parameter, which arrives as
 * `['errorField' => 'page']` for "The Page field do not exist.".
 */
final class ProviderException extends \RuntimeException
{
    public const OFFLINE = 'OFFLINE';
    public const DEGRADED = 'DEGRADED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const AUTHENTICATION_ERROR = 'AUTHENTICATION_ERROR';
    public const DATA_ERROR = 'DATA_ERROR';

    /** @param array<string,mixed> $details machine-readable extras (errorField, ...) */
    public function __construct(
        string $message,
        public readonly string $status = self::DATA_ERROR,
        ?\Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
