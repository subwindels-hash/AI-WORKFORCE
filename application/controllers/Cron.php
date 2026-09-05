<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public cron runner for hosting panels without CLI access.
 * CLI needs no key; web requests must carry the secret from /admin/cron
 * (?key=... or ?job=<id>&key=... for a single job). Only enabled + due jobs
 * execute, so hitting this URL every minute is cheap and safe.
 */
class Cron extends MY_Controller
{
    public function run()
    {
        $store = new \AIWorkforce\Cron\PlatformSettingsCronStore($this->AIWorkforce_model->db);
        $isCli = PHP_SAPI === 'cli';
        if (!$isCli && !\AIWorkforce\Cron\CronSecrets::check($store, (string) $this->input->get('key', true))) {
            return $this->jsonError('forbidden', 403);
        }
        @set_time_limit(600);
        $scheduler = new \AIWorkforce\Cron\CronScheduler($store);
        $runners = \AIWorkforce\Cron\CronRunner::runners($this);
        $only = $isCli ? trim((string) ($_SERVER['argv'][3] ?? '')) : trim((string) $this->input->get('job', true));
        try {
            if ($only !== '') {
                if (!isset($runners[$only])) throw new \InvalidArgumentException('unknown cron job: ' . $only);
                $result = [$only => $scheduler->runJob($only, $runners[$only])];
            } else {
                $result = $scheduler->runDue(fn(string $id) => $runners[$id] ?? null);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
        $ran = count(array_filter($result, fn($r) => !empty($r['ran'])));
        $this->json(['ranAt' => gmdate('c'), 'jobsRun' => $ran, 'jobs' => $result]);
    }
}
