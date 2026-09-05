<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\SportsDataProvider;

/** Idempotent fixture ingestion. Provider exceptions and malformed records are counted, never hidden. */
class SportsSyncService
{
    private FormResolver $formResolver;

    public function __construct(private SportsRepository $repo, private AuditRepository $audit, private DataQualityEngine $quality, ?FormResolver $formResolver = null)
    {
        $this->formResolver = $formResolver ?? new FormResolver();
    }

    public function syncResults(SportsDataProvider $provider, string $fixtureExternalId, string $executionKey): array
    {
        $source=$this->repo->ensureProvider($provider->id(),$provider->id()); $run=['id'=>Backtester::uuid(),'providerId'=>(int)$source['id'],'jobType'=>'RESULTS','executionKey'=>$executionKey];
        if($this->repo->startSync($run)===null) return ['status'=>'DUPLICATE_SKIPPED','executionKey'=>$executionKey]; $processed=0;$errors=[];
        try { $health=$provider->health(); if(($health['status']??'')!=='ONLINE') throw new \RuntimeException('provider is not ONLINE'); $match=$this->repo->findMatch((int)$source['id'],$fixtureExternalId); if(!$match) throw new \RuntimeException('fixture is not synchronized for this provider'); foreach($provider->results($fixtureExternalId) as $raw){$processed++; try{$this->repo->saveResult((int)$match['id'],(int)$source['id'],SportsResultNormalizer::normalize($raw,$provider->id()));}catch(\Throwable $e){$errors[]=$e->getMessage();}} $result=['status'=>'COMPLETED','processed'=>$processed,'created'=>$processed-count($errors),'updated'=>0,'errors'=>$errors]; } catch(\Throwable $e){$result=['status'=>'FAILED','processed'=>$processed,'created'=>0,'updated'=>0,'errors'=>[$e->getMessage()]];}
        $this->repo->finishSync($run['id'],$result); $this->audit->emit($result['status']==='COMPLETED'?'SPORTS_RESULT_SYNC_COMPLETED':'SPORTS_RESULT_SYNC_FAILED','Sports result sync '.strtolower($result['status']),['provider'=>$provider->id(),'result'=>$result]); return array_merge(['runId'=>$run['id']],$result);
    }

    public function syncOdds(SportsDataProvider $provider, string $fixtureExternalId, string $executionKey): array
    {
        $source = $this->repo->ensureProvider($provider->id(), $provider->id());
        $run = ['id' => Backtester::uuid(), 'providerId' => (int) $source['id'], 'jobType' => 'ODDS', 'executionKey' => $executionKey];
        if ($this->repo->startSync($run) === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $executionKey];
        $processed = 0; $invalid = 0; $errors = [];
        try {
            $health = $provider->health(); $this->repo->saveHealth((int) $source['id'], array_merge($health, ['status' => $health['status'] ?? 'DATA_ERROR']));
            if (($health['status'] ?? '') !== 'ONLINE') throw new \RuntimeException('provider is not ONLINE');
            $match = $this->repo->findMatch((int) $source['id'], $fixtureExternalId);
            if (!$match) throw new \RuntimeException('fixture is not synchronized for this provider');
            foreach ($provider->odds($fixtureExternalId) as $raw) {
                $processed++;
                try { $this->repo->saveOdds((int) $match['id'], (int) $source['id'], SportsDataNormalizer::odds($raw, $provider->id())); }
                catch (\Throwable $e) { $invalid++; $errors[] = mb_substr($e->getMessage(), 0, 200); }
            }
            if ($processed === 0) $errors[] = 'provider returned no odds; no odds-dependent ticket may be generated';
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $processed - $invalid, 'updated' => 0, 'errors' => $errors];
        } catch (\Throwable $e) { $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => 0, 'updated' => 0, 'errors' => [mb_substr($e->getMessage(), 0, 200)]]; }
        $this->repo->finishSync($run['id'], $result);
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
            $health = $provider->health();
            $this->repo->saveHealth((int) $source['id'], array_merge($health, ['status' => $health['status'] ?? 'DATA_ERROR']));
            if (($health['status'] ?? '') !== 'ONLINE') throw new \RuntimeException('provider is not ONLINE');
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
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
        } catch (\Throwable $e) {
            $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => 0, 'updated' => 0, 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
        }
        $this->repo->finishSync($run['id'], $result);
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
            $health = $provider->health();
            $this->repo->saveHealth((int) $source['id'], array_merge($health, ['status' => $health['status'] ?? 'DATA_ERROR']));
            if (($health['status'] ?? '') !== 'ONLINE') throw new \RuntimeException('provider is not ONLINE');
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
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
        } catch (\Throwable $e) {
            $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
        }
        $this->repo->finishSync($run['id'], $result);
        $this->audit->emit($result['status'] === 'COMPLETED' ? 'SPORTS_FIXTURE_SYNC_COMPLETED' : 'SPORTS_FIXTURE_SYNC_FAILED', 'Sports fixture sync ' . strtolower($result['status']), ['provider' => $provider->id(), 'runId' => $run['id'], 'result' => $result]);
        return array_merge(['runId' => $run['id']], $result);
    }
}
