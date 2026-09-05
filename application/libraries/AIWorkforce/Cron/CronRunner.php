<?php
namespace AIWorkforce\Cron;

/** Builds the executable behind each registered cron job from the live stack. */
class CronRunner
{
    /**
     * @param object $ci CodeIgniter instance (platform + AIWorkforce_model)
     * @return array<string,callable>
     */
    public static function runners(object $ci): array
    {
        return [
            'ops' => fn() => self::ops($ci),
            'sports' => fn() => self::sports($ci),
            'football' => fn() => self::football($ci),
            'lottery' => fn() => self::lottery($ci),
        ];
    }

    /** Portfolio risk scan, broker transitions, proposal expiry. */
    public static function ops(object $ci): array
    {
        $scan = $ci->platform->monitor->scan();
        $expired = $ci->platform->execution->expireStaleProposals();
        $summary = [
            'ranAt' => gmdate('c'),
            'accountsScanned' => $scan['accountsScanned'] ?? 0,
            'riskAlerts' => count($scan['alerts'] ?? []),
            'proposalsExpired' => count($expired),
            'expiredIds' => $expired,
        ];
        $ci->AIWorkforce_model->audit->emit('CRON_RUN', sprintf(
            'Scheduled operations: %d account(s) scanned, %d risk alert(s) active, %d proposal(s) expired',
            $summary['accountsScanned'], $summary['riskAlerts'], $summary['proposalsExpired']
        ), $summary, 'system');
        return $summary;
    }

    /** Full sports sweep (fixtures → odds → results → quality → ticket …). */
    public static function sports(object $ci): array
    {
        $service = new \AIWorkforce\Sports\SportsCronService($ci->AIWorkforce_model->sports, $ci->AIWorkforce_model->audit, $ci->platform->sports);
        return $service->runAll();
    }

    /**
     * Football refresh sweep. The service asks RefreshPolicy before every job, so
     * a five-minute tick costs nothing when nothing is due, and each job records
     * its own row in football_provider_sync_logs for the diagnostics panel.
     */
    public static function football(object $ci): array
    {
        return $ci->platform->football->cron()->runAll();
    }

    /** Full lottery sweep (sync → health → statistics → systems …). */
    public static function lottery(object $ci): array
    {
        $service = new \AIWorkforce\Lottery\LotteryCronService($ci->AIWorkforce_model->lottery, $ci->AIWorkforce_model->audit, $ci->platform->lottery);
        return $service->runAll();
    }
}
