<?php
namespace Aegis\Brokers;

/** Canonical, read-only broker data contracts used beyond individual bridges. */
class BrokerDataNormalizer
{
    public static function account(array $raw, string $broker): array
    {
        $currency = strtoupper((string) ($raw['currency'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) throw new \RuntimeException('broker account currency is invalid');
        $balance = self::number($raw, 'balance');
        $equity = self::number($raw, 'equity');
        $margin = self::number($raw, 'margin', 0.0);
        $freeMargin = self::number($raw, 'freeMargin', max(0.0, $equity - $margin));
        if ($balance < 0 || $equity < 0 || $margin < 0 || $freeMargin < 0) throw new \RuntimeException('broker account values cannot be negative');
        return [
            'broker' => $broker,
            'accountId' => (string) ($raw['accountId'] ?? $raw['login'] ?? ''),
            'currency' => $currency,
            'balance' => $balance,
            'equity' => $equity,
            'margin' => $margin,
            'freeMargin' => $freeMargin,
            'leverage' => self::number($raw, 'leverage', 0.0),
            'timestamp' => self::timestamp($raw['timestamp'] ?? null),
        ];
    }

    public static function quote(array $raw, string $broker): array
    {
        $symbol = strtoupper((string) ($raw['symbol'] ?? ''));
        if (!preg_match('/^[A-Z0-9._-]{1,32}$/', $symbol)) throw new \RuntimeException('broker quote symbol is invalid');
        $bid = self::number($raw, 'bid');
        $ask = self::number($raw, 'ask');
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) throw new \RuntimeException('broker quote bid/ask is invalid');
        $last = isset($raw['last']) ? self::number($raw, 'last') : ($bid + $ask) / 2;
        return [
            'broker' => $broker, 'symbol' => $symbol, 'bid' => $bid, 'ask' => $ask,
            'last' => $last, 'spread' => $ask - $bid,
            'timestamp' => self::timestamp($raw['timestamp'] ?? null),
        ];
    }

    private static function number(array $raw, string $key, ?float $default = null): float
    {
        if (!array_key_exists($key, $raw)) {
            if ($default !== null) return $default;
            throw new \RuntimeException("broker response missing {$key}");
        }
        if (!is_numeric($raw[$key]) || !is_finite((float) $raw[$key])) throw new \RuntimeException("broker response {$key} is invalid");
        return (float) $raw[$key];
    }

    private static function timestamp($value): string
    {
        if ($value === null || $value === '') return gmdate('c');
        if (is_numeric($value)) return gmdate('c', (int) $value);
        try { return (new \DateTimeImmutable((string) $value))->setTimezone(new \DateTimeZone('UTC'))->format('c'); }
        catch (\Throwable $e) { throw new \RuntimeException('broker response timestamp is invalid'); }
    }
}
