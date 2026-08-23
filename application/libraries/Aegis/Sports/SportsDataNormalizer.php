<?php
namespace Aegis\Sports;

/** Validates and converts a provider fixture into the WINDELS-neutral shape. */
class SportsDataNormalizer
{
    public static function fixture(array $raw, string $provider): array
    {
        foreach (['externalId', 'homeTeam', 'awayTeam', 'competition', 'kickoff'] as $field) {
            if (!isset($raw[$field]) || trim((string) $raw[$field]) === '') throw new \InvalidArgumentException("fixture missing {$field}");
        }
        try { $kickoff = (new \DateTimeImmutable((string) $raw['kickoff']))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
        catch (\Throwable $e) { throw new \InvalidArgumentException('fixture kickoff is invalid'); }
        $status = strtoupper((string) ($raw['status'] ?? 'SCHEDULED'));
        if (!in_array($status, ['SCHEDULED', 'LIVE', 'FINISHED', 'POSTPONED', 'CANCELLED', 'SUSPENDED'], true)) throw new \InvalidArgumentException('fixture status is invalid');
        return [
            'provider' => $provider, 'externalId' => (string) $raw['externalId'],
            'sport' => strtolower((string) ($raw['sport'] ?? 'football')),
            'homeTeam' => trim((string) $raw['homeTeam']), 'awayTeam' => trim((string) $raw['awayTeam']),
            'competition' => trim((string) $raw['competition']), 'kickoff' => $kickoff, 'status' => $status,
            'sourceTimestamp' => self::timestamp($raw['sourceTimestamp'] ?? null),
            'fieldsPresent' => array_keys($raw),
        ];
    }
    private static function timestamp($value): string
    {
        if ($value === null || $value === '') return gmdate('c');
        try { return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
        catch (\Throwable $e) { throw new \InvalidArgumentException('sourceTimestamp is invalid'); }
    }
}
