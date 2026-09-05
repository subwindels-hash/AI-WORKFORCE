<?php
namespace AIWorkforce\Cron;

/**
 * Best-effort auto-run: a super-admin dashboard visit fires off due cron jobs
 * in the background via a fire-and-forget request to the secret cron URL, so
 * the page never waits for the jobs. Throttled to one trigger attempt per
 * window. This is a fallback — a real hosting cron hitting the URL (or the
 * `tools scheduler` CLI) every minute is the primary driver.
 */
class CronAutoRun
{
    public const TRIGGER_KEY = 'cron.last_trigger';
    public const THROTTLE_SECONDS = 120;

    /**
     * @param callable|null $dispatch fn(string $url): bool — injectable for tests
     * @return string cli|no_url|disabled|throttled|not_due|triggered|dispatch_failed
     */
    public static function maybeTrigger(
        CronScheduler $scheduler,
        CronStateStore $store,
        string $runUrl,
        ?callable $dispatch = null,
        ?int $now = null,
        ?string $sapi = null
    ): string {
        if (($sapi ?? PHP_SAPI) === 'cli') return 'cli';
        if ($runUrl === '') return 'no_url';
        if ($store->get('cron.auto_run') !== '1') return 'disabled';
        $now = $now ?? time();
        $last = (int) ($store->get(self::TRIGGER_KEY) ?? 0);
        if ($now - $last < self::THROTTLE_SECONDS) return 'throttled';
        $due = false;
        foreach ($scheduler->status() as $job) {
            if (!empty($job['enabled']) && !empty($job['due'])) { $due = true; break; }
        }
        if (!$due) return 'not_due';
        $store->set(self::TRIGGER_KEY, (string) $now);
        $dispatch = $dispatch ?? [self::class, 'fireAndForget'];
        try {
            $ok = (bool) $dispatch($runUrl);
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok ? 'triggered' : 'dispatch_failed';
    }

    /** Open a connection, send the request, close without reading the reply. */
    public static function fireAndForget(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) return false;
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $ssl = $scheme === 'https';
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if (!empty($parts['query'])) $path .= '?' . $parts['query'];
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client(($ssl ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 3, STREAM_CLIENT_CONNECT);
        if ($fp === false) return false;
        $req = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: Close\r\nUser-Agent: AIWorkforce-CronTrigger\r\n\r\n";
        @fwrite($fp, $req);
        @fclose($fp);
        return true;
    }
}
