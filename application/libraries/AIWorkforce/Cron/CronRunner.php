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
            'sports-live' => fn() => self::sportsLive($ci),
            'football' => fn() => self::football($ci),
            'lottery' => fn() => self::lottery($ci),
            'protection' => fn() => self::protection($ci),
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

    /**
     * AUTOMATIC KILL SWITCH scan (§1–§13). The engine audits and notifies on
     * every state transition, so the runner only reports the outcome.
     *
     * The same pass reconciles MT4/MT5 Expert Advisors (§10): heartbeats are
     * pulled from the bridge, evaluated against the administrator's policy and
     * the decisions are pushed back for the EAs to obey.
     */
    public static function protection(object $ci): array
    {
        $report = $ci->platform->protection->evaluate();

        $sync = ['ok' => false, 'skipped' => true, 'reason' => 'Expert Advisor sync not available.', 'accepted' => 0, 'blocked' => 0];
        if (isset($ci->platform->eaProtection, $ci->platform->eaBridge)) {
            $sync = $ci->platform->eaBridge->sync($ci->platform->eaProtection);
        }
        if (!($sync['skipped'] ?? true) && ($sync['ok'] ?? false) !== true) {
            $ci->AIWorkforce_model->audit->emit('EA_PROTECTION_SYNC_FAILED',
                sprintf('Expert Advisor sync failed: %s', (string) ($sync['reason'] ?? 'unknown error')),
                $sync, 'system');
        }

        return [
            'ranAt' => gmdate('c'),
            'state' => $report['status']['state'],
            'reason' => $report['status']['reason'],
            'code' => $report['status']['code'] ?? null,
            'triggers' => count($report['triggers']),
            'equity' => $report['metrics']['equity'] ?? 0.0,
            'dailyLossPct' => $report['metrics']['dailyLossPct'] ?? 0.0,
            'drawdownPct' => $report['metrics']['drawdownPct'] ?? 0.0,
            'ea' => [
                'synced' => ($sync['ok'] ?? false) === true,
                'skipped' => (bool) ($sync['skipped'] ?? false),
                'reason' => $sync['reason'] ?? null,
                'heartbeats' => (int) ($sync['accepted'] ?? 0),
                'registered' => $sync['registered'] ?? [],
                'blocked' => (int) ($sync['blocked'] ?? 0),
            ],
        ];
    }

    /** Full sports sweep (fixtures → odds → live → results → quality → ticket …). */
    public static function sports(object $ci): array
    {
        $service = new \AIWorkforce\Sports\SportsCronService($ci->AIWorkforce_model->sports, $ci->AIWorkforce_model->audit, $ci->platform->sports);
        return $service->runAll();
    }

    /**
     * Live goal-score sweep. Self-gated by LiveScoreService's refresh
     * interval, so a minute tick is harmless when the interval has not
     * elapsed: it reports THROTTLED and never touches the provider.
     */
    public static function sportsLive(object $ci): array
    {
        $service = new \AIWorkforce\Sports\SportsCronService($ci->AIWorkforce_model->sports, $ci->AIWorkforce_model->audit, $ci->platform->sports);
        return $service->run('live');
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
