<?php
namespace AIWorkforce\Sports;

/**
 * Calendar/date boundary helper for the daily odds-prediction ticket.
 *
 * A ticket date is a calendar date in the configured system timezone. Fixture
 * timestamps remain UTC. Keeping those two concepts explicit prevents a late
 * evening UTC fixture (or a worker running in another OS timezone) from being
 * assigned to the wrong daily ticket.
 */
final class DailyTicketDate
{
    public const DEFAULT_TIMEZONE = 'UTC';

    public static function configuredTimezone(?string $configured = null): string
    {
        $candidates = [
            $configured,
            getenv('WINDELS_SYSTEM_TIMEZONE') ?: null,
            getenv('APP_TIMEZONE') ?: null,
            date_default_timezone_get(),
            self::DEFAULT_TIMEZONE,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') continue;
            try {
                new \DateTimeZone($candidate);
                return $candidate;
            } catch (\Throwable $e) {
                // Try the next configured/default source. Invalid explicit
                // configuration is rejected by ConfigurationService::update.
            }
        }
        return self::DEFAULT_TIMEZONE;
    }

    public static function today(?string $timezone = null, ?int $now = null): string
    {
        $zone = new \DateTimeZone(self::configuredTimezone($timezone));
        return (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($zone)->format('Y-m-d');
    }

    /** Validate and return a real local calendar date. */
    public static function normalize(?string $date, ?string $timezone = null, ?int $now = null): string
    {
        if ($date === null || trim($date) === '') return self::today($timezone, $now);
        $date = trim($date);
        $zone = new \DateTimeZone(self::configuredTimezone($timezone));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('ticket date must be a real calendar date in YYYY-MM-DD format');
        }
        return $date;
    }

    /**
     * UTC timestamp boundaries for one local ticket date. The upper boundary is
     * exclusive; it naturally handles 23/25-hour daylight-saving days.
     *
     * @return array{start:string,endExclusive:string,startTimestamp:int,endTimestamp:int,timezone:string}
     */
    public static function utcWindow(string $date, ?string $timezone = null): array
    {
        $timezone = self::configuredTimezone($timezone);
        $date = self::normalize($date, $timezone);
        $zone = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');
        $startLocal = new \DateTimeImmutable($date . ' 00:00:00', $zone);
        $endLocal = $startLocal->modify('+1 day');
        return [
            'start' => $startLocal->setTimezone($utc)->format('Y-m-d\TH:i:sP'),
            'endExclusive' => $endLocal->setTimezone($utc)->format('Y-m-d\TH:i:sP'),
            'startTimestamp' => $startLocal->getTimestamp(),
            'endTimestamp' => $endLocal->getTimestamp(),
            'timezone' => $timezone,
        ];
    }
}
