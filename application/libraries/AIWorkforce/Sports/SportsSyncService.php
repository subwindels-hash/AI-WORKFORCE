<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\ProviderCircuitBreaker;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;

/** Idempotent fixture ingestion. Provider exceptions and malformed records are counted, never hidden. */
class SportsSyncService
{
    private FormResolver $formResolver;
    private ?ProviderCircuitBreaker $breaker = null;

    public function __construct(private SportsRepository $repo, private AuditRepository $audit, private DataQualityEngine $quality, ?FormResolver $formResolver = null)
    {
        $this->formResolver = $formResolver ?? new FormResolver();
    }

    /**
     * Share the provider manager's circuit breaker so per-provider syncs
     * (cron fixture sweep, web "Sync now") respect an OPEN circuit too — a
     * quota-dead api-football is skipped here exactly as in withFallback().
     */
    public function setCircuitBreaker(ProviderCircuitBreaker $breaker): void
    {
        $this->breaker = $breaker;
    }

    /**
     * Pre-flight for every sync: circuit check, live health probe, persisted
     * observation. Throws a CLASSIFIED ProviderException (never a bare
     * "provider is not ONLINE") so the sync run's error list says
     * "DAILY_QUOTA_EXHAUSTED: daily quota used (100/100 on the Free plan)".
     */
    private function preflight(SportsDataProvider $provider, int $sourceId): array
    {
        $id = $provider->id();
        if ($this->breaker !== null) {
            $circuit = $this->breaker->state($id);
            if ($circuit['state'] === ProviderCircuitBreaker::OPEN) {
                $status = (string) ($circuit['reason'] ?? ProviderException::OFFLINE);
                $this->repo->saveHealth($sourceId, ['status' => $status, 'lastFailureAt' => $circuit['lastFailureAt'], 'lastSuccessAt' => $circuit['lastSuccessAt'], 'missingFields' => ['circuit' => 'OPEN', 'retryAt' => $circuit['retryAt']]]);
                throw new ProviderException('circuit open until ' . ($circuit['retryAt'] ?? '?') . ' (skipped, no request sent)' . ($circuit['detail'] ? ' — ' . $circuit['detail'] : ''), $status, null, ['retryAt' => $circuit['retryAt']]);
            }
        }
        $health = $provider->health();
        $status = (string) ($health['status'] ?? ProviderException::DATA_ERROR);
        $this->repo->saveHealth($sourceId, array_merge($health, ['status' => $status]));
        if ($status !== 'ONLINE') {
            $e = new ProviderException((string) ($health['detail'] ?? 'provider self-reported ' . $status), in_array($status, [ProviderException::DAILY_QUOTA_EXHAUSTED, ProviderException::RATE_LIMITED, ProviderException::AUTHENTICATION_ERROR, ProviderException::BAD_REQUEST, ProviderException::NOT_FOUND, ProviderException::TIMEOUT, ProviderException::OFFLINE, ProviderException::DEGRADED, ProviderException::DATA_ERROR], true) ? $status : ProviderException::OFFLINE, null, isset($health['retryAt']) ? ['retryAt' => $health['retryAt']] : []);
            $this->breaker?->recordFailure($id, $e);
            throw $e;
        }
        return $health;
    }

    /** Feed the sync outcome back to the breaker; format the error for the run log. */
    private function settle(SportsDataProvider $provider, ?\Throwable $e): string
    {
        if ($e === null) { $this->breaker?->recordSuccess($provider->id()); return ''; }
        if ($e instanceof ProviderException) {
            $this->breaker?->recordFailure($provider->id(), $e);
            return $e->status . ': ' . mb_substr($e->getMessage(), 0, 180);
        }
        return mb_substr($e->getMessage(), 0, 200);
    }

    /**
     * A provider that just COMPLETED a sync proved it is reachable — mark it
     * enabled so enabled-only consumers (dashboard widgets, provider lists)
     * reflect reality. New provider rows boot disabled; operators can still
     * switch a flaky feed off. Never breaks a completed sync.
     */
    private function markProviderReachable(int $sourceId): void
    {
        try { $this->repo->setProviderEnabled($sourceId, true); } catch (\Throwable $e) { /* enabling must never break a completed sync */ }
    }

    public function syncResults(SportsDataProvider $provider, string $fixtureExternalId, string $executionKey): array
    {
        $source=$this->repo->ensureProvider($provider->id(),$provider->id()); $run=['id'=>Backtester::uuid(),'providerId'=>(int)$source['id'],'jobType'=>'RESULTS','executionKey'=>$executionKey];
        if($this->repo->startSync($run)===null) return ['status'=>'DUPLICATE_SKIPPED','executionKey'=>$executionKey]; $processed=0;$errors=[];
        try { $health=$this->preflight($provider,(int)$source['id']); $match=$this->repo->findMatch((int)$source['id'],$fixtureExternalId); if(!$match) throw new \RuntimeException('fixture is not synchronized for this provider'); foreach($provider->results($fixtureExternalId) as $raw){$processed++; try{$this->repo->saveResult((int)$match['id'],(int)$source['id'],SportsResultNormalizer::normalize($raw,$provider->id()));}catch(\Throwable $e){$errors[]=$e->getMessage();}} $this->settle($provider,null); $result=['status'=>'COMPLETED','processed'=>$processed,'created'=>$processed-count($errors),'updated'=>0,'errors'=>$errors]; } catch(\Throwable $e){$result=['status'=>'FAILED','processed'=>$processed,'created'=>0,'updated'=>0,'errors'=>[$this->settle($provider,$e)]];}
        $this->repo->finishSync($run['id'],$result); if($result['status']==='COMPLETED')$this->markProviderReachable((int)$source['id']); $this->audit->emit($result['status']==='COMPLETED'?'SPORTS_RESULT_SYNC_COMPLETED':'SPORTS_RESULT_SYNC_FAILED','Sports result sync '.strtolower($result['status']),['provider'=>$provider->id(),'result'=>$result]); return array_merge(['runId'=>$run['id']],$result);
    }

    public function syncOdds(SportsDataProvider $provider, string $fixtureExternalId, string $executionKey): array
    {
        $source = $this->repo->ensureProvider($provider->id(), $provider->id());
        $run = ['id' => Backtester::uuid(), 'providerId' => (int) $source['id'], 'jobType' => 'ODDS', 'executionKey' => $executionKey];
        if ($this->repo->startSync($run) === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $executionKey];
        $processed = 0; $invalid = 0; $errors = [];
        try {
            $health = $this->preflight($provider, (int) $source['id']);
            $match = $this->repo->findMatch((int) $source['id'], $fixtureExternalId);
            if (!$match) throw new \RuntimeException('fixture is not synchronized for this provider');
            foreach ($provider->odds($fixtureExternalId) as $raw) {
                $processed++;
                try { $this->repo->saveOdds((int) $match['id'], (int) $source['id'], SportsDataNormalizer::odds($raw, $provider->id())); }
                catch (\Throwable $e) { $invalid++; $errors[] = mb_substr($e->getMessage(), 0, 200); }
            }
            if ($processed === 0) $errors[] = 'provider returned no odds; no odds-dependent ticket may be generated';
            $this->settle($provider, null);
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $processed - $invalid, 'updated' => 0, 'errors' => $errors];
        } catch (\Throwable $e) { $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => 0, 'updated' => 0, 'errors' => [$this->settle($provider, $e)]]; }
        $this->repo->finishSync($run['id'], $result);
        if ($result['status'] === 'COMPLETED') $this->markProviderReachable((int) $source['id']);
        $this->audit->emit($result['status'] === 'COMPLETED' ? 'SPORTS_ODDS_SYNC_COMPLETED' : 'SPORTS_ODDS_SYNC_FAILED', 'Sports odds sync ' . strtolower($result['status']), ['provider' => $provider->id(), 'fixture' => $fixtureExternalId, 'runId' => $run['id'], 'result' => $result]);
        return array_merge(['runId' => $run['id']], $result);
    }

    /**
     * Bulk-sync a whole matchday (round) in ONE provider request: fixtures,
     * bookmaker odds and results together (SportMonks round endpoint).
     * Idempotent per execution key like the per-fixture sync jobs; one bad
     * fixture is counted, never hidden.
     *
     * $options: 'odds' (default true) persist the round's odds; 'results'
     * (default true) persist the round's results. Callers that only refresh
     * odds (e.g. the cron odds job) pass ['results' => false] so already
     * verified results are never re-written.
     */
    public function syncRound(SportsDataProvider $provider, string $roundExternalId, string $executionKey, array $options = []): array
    {
        $withOdds = (bool) ($options['odds'] ?? true);
        $withResults = (bool) ($options['results'] ?? true);
        $source = $this->repo->ensureProvider($provider->id(), $provider->id());
        $run = ['id' => Backtester::uuid(), 'providerId' => (int) $source['id'], 'jobType' => 'ROUND', 'executionKey' => $executionKey];
        if ($this->repo->startSync($run) === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $executionKey];
        $processed = 0; $created = 0; $updated = 0; $invalid = 0; $errors = [];
        try {
            $health = $this->preflight($provider, (int) $source['id']);
            if (!method_exists($provider, 'round')) throw new \RuntimeException('provider does not support round sync (no round endpoint)');
            $round = $provider->round($roundExternalId);
            $oddsByFixture = [];
            if ($withOdds) {
                foreach (($round['odds'] ?? []) as $o) {
                    if (is_array($o) && !empty($o['fixtureId'])) $oddsByFixture[(string) $o['fixtureId']][] = $o;
                }
            }
            $resultsByFixture = [];
            if ($withResults) {
                foreach (($round['results'] ?? []) as $r) {
                    if (is_array($r) && isset($r['externalId'])) $resultsByFixture[(string) $r['externalId']] = $r;
                }
            }
            foreach (($round['fixtures'] ?? []) as $raw) {
                $processed++;
                try {
                    $match = SportsDataNormalizer::fixture($raw, $provider->id());
                    $existing = $this->repo->saveMatch((int) $source['id'], $match);
                    !empty($existing['created_at']) && $existing['created_at'] === $existing['updated_at'] ? $created++ : $updated++;
                    $assessment = $this->quality->assess($match, ['oddsAvailable' => $withOdds && isset($oddsByFixture[$match['externalId']]), 'recentFormAvailable' => !empty($match['context']['recentForm']), 'providerReliability' => (float) ($health['reliability'] ?? 0), 'dataAgeSeconds' => 0]);
                    $this->repo->saveQuality((int) $existing['id'], $assessment);
                    // Odds and result for this fixture come from the same round call.
                    foreach (($oddsByFixture[$match['externalId']] ?? []) as $rawOdds) {
                        $this->repo->saveOdds((int) $existing['id'], (int) $source['id'], SportsDataNormalizer::odds($rawOdds, $provider->id()));
                    }
                    $rawResult = $resultsByFixture[$match['externalId']] ?? null;
                    if (is_array($rawResult) && ($rawResult['homeScore'] !== null || $rawResult['awayScore'] !== null)) {
                        $this->repo->saveResult((int) $existing['id'], (int) $source['id'], SportsResultNormalizer::normalize($rawResult, $provider->id()));
                    }
                } catch (\Throwable $e) { $invalid++; $errors[] = mb_substr($e->getMessage(), 0, 200); }
            }
            if ($processed === 0) $errors[] = 'round returned no fixtures; nothing synchronized';
            $this->settle($provider, null);
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
        } catch (\Throwable $e) {
            $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => 0, 'updated' => 0, 'errors' => [$this->settle($provider, $e)]];
        }
        $this->repo->finishSync($run['id'], $result);
        if ($result['status'] === 'COMPLETED') $this->markProviderReachable((int) $source['id']);
        $this->audit->emit($result['status'] === 'COMPLETED' ? 'SPORTS_ROUND_SYNC_COMPLETED' : 'SPORTS_ROUND_SYNC_FAILED', 'Sports round sync ' . strtolower($result['status']) . ' for round ' . $roundExternalId, ['provider' => $provider->id(), 'round' => $roundExternalId, 'runId' => $run['id'], 'result' => $result]);
        return array_merge(['runId' => $run['id']], $result);
    }

    public function syncFixtures(SportsDataProvider $provider, array $query, string $executionKey): array
    {
        $source = $this->repo->ensureProvider($provider->id(), $provider->id());
        $run = ['id' => Backtester::uuid(), 'providerId' => (int) $source['id'], 'jobType' => 'FIXTURES', 'executionKey' => $executionKey];
        if ($this->repo->startSync($run) === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $executionKey];
        $created = 0; $updated = 0; $invalid = 0; $processed = 0; $errors = [];
        try {
            $health = $this->preflight($provider, (int) $source['id']);
            $rawFixtures = $provider->fixtures($query);
            // Enrich fixtures with recentForm context from team statistics
            $rawFixtures = $this->formResolver->enrich($provider, $rawFixtures);
            foreach ($rawFixtures as $raw) {
                $processed++;
                try {
                    $match = SportsDataNormalizer::fixture($raw, $provider->id());
                    $existing = $this->repo->saveMatch((int) $source['id'], $match);
                    // Repository upserts; source payload decides whether this was logically created/updated.
                    !empty($existing['created_at']) && $existing['created_at'] === $existing['updated_at'] ? $created++ : $updated++;
                    $assessment = $this->quality->assess($match, ['oddsAvailable' => false, 'recentFormAvailable' => !empty($match['context']['recentForm']), 'providerReliability' => (float) ($health['reliability'] ?? 0), 'dataAgeSeconds' => 0]);
                    $this->repo->saveQuality((int) $existing['id'], $assessment);
                } catch (\Throwable $e) { $invalid++; $errors[] = mb_substr($e->getMessage(), 0, 200); }
            }
            $this->settle($provider, null);
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
        } catch (\Throwable $e) {
            $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => [$this->settle($provider, $e)]];
        }
        $this->repo->finishSync($run['id'], $result);
        if ($result['status'] === 'COMPLETED') $this->markProviderReachable((int) $source['id']);
        $this->audit->emit($result['status'] === 'COMPLETED' ? 'SPORTS_FIXTURE_SYNC_COMPLETED' : 'SPORTS_FIXTURE_SYNC_FAILED', 'Sports fixture sync ' . strtolower($result['status']), ['provider' => $provider->id(), 'runId' => $run['id'], 'result' => $result]);
        return array_merge(['runId' => $run['id']], $result);
    }
}
