<?php
namespace AIWorkforce\Football;

use AIWorkforce\Sports\SportsDataNormalizer;

/**
 * The one match model the rest of the application sees.
 *
 * Three feeds describe the same match three ways: API-Football calls it a
 * `fixture` with `teams.home.id`, SportMonks a `fixture` with `participants`,
 * TheSportsDB an `event` with `strHomeTeam`. Nothing outside the provider layer
 * should have to know that, so every adapter's output is normalized into this
 * shape before it reaches the intelligence or prediction engine.
 *
 * `id` is the canonical identity, not any provider's number: the same match
 * keeps one identity whichever feed it arrived from, so a match is never
 * generated twice merely because a second provider also carries it.
 *
 * Every optional block stays empty when nobody supplied it. An absent odds
 * block is `DATA_UNAVAILABLE` in the interface — it is never an empty guess —
 * and merging two providers only fills slots that are still empty.
 */
final class FootballMatch
{
    /** @param array<string,mixed> $homeTeam @param array<string,mixed> $awayTeam
     *  @param array<string,string> $providers provider code => that provider's match id
     *  @param array<string,mixed> $odds @param array<string,mixed> $statistics
     *  @param array<int,mixed> $injuries @param array<int,mixed> $lineups
     *  @param array<string,mixed>|null $form @param array<string,mixed>|null $h2h
     *  @param array<int,array<string,mixed>> $dataSources */
    public function __construct(
        public readonly string $id,
        public readonly array $providers,
        public readonly ?string $competitionId,
        public readonly ?string $competitionName,
        public readonly ?string $season,
        public readonly array $homeTeam,
        public readonly array $awayTeam,
        public readonly string $kickoffTime,
        public readonly string $status,
        public readonly ?string $venue = null,
        public readonly array $odds = [],
        public readonly array $statistics = [],
        public readonly array $injuries = [],
        public readonly array $lineups = [],
        public readonly ?array $form = null,
        public readonly ?array $h2h = null,
        public readonly array $dataSources = [],
    ) {}

    /**
     * Build the model from one provider's normalized fixture row.
     *
     * @param array<string,mixed> $normalized a row from SportsDataNormalizer::fixture()
     */
    public static function fromNormalized(array $normalized, ?string $canonicalId = null): self
    {
        $provider = (string) ($normalized['provider'] ?? '');
        $external = (string) ($normalized['externalId'] ?? '');
        $home = trim((string) ($normalized['homeTeam'] ?? ''));
        $away = trim((string) ($normalized['awayTeam'] ?? ''));
        $kickoff = (string) ($normalized['kickoff'] ?? '');
        $id = $canonicalId !== null && $canonicalId !== ''
            ? $canonicalId
            : CanonicalMatch::identity($home, $away, $kickoff);
        $providers = [];
        if ($provider !== '' && $external !== '') $providers[$provider] = $external;
        return new self(
            id: $id,
            providers: $providers,
            competitionId: self::blankToNull($normalized['leagueId'] ?? null),
            competitionName: self::blankToNull($normalized['competition'] ?? null),
            season: self::blankToNull($normalized['season'] ?? null),
            homeTeam: ['id' => self::blankToNull($normalized['homeTeamId'] ?? null), 'name' => $home,
                'logo' => self::blankToNull($normalized['homeTeamLogo'] ?? null),
                'normalized' => CanonicalMatch::normalizeTeam($home)],
            awayTeam: ['id' => self::blankToNull($normalized['awayTeamId'] ?? null), 'name' => $away,
                'logo' => self::blankToNull($normalized['awayTeamLogo'] ?? null),
                'normalized' => CanonicalMatch::normalizeTeam($away)],
            kickoffTime: $kickoff,
            status: (string) ($normalized['status'] ?? 'SCHEDULED'),
            venue: self::blankToNull($normalized['venue'] ?? null),
            odds: is_array($normalized['odds'] ?? null) ? $normalized['odds'] : [],
            statistics: is_array($normalized['statistics'] ?? null) ? $normalized['statistics'] : [],
            injuries: is_array($normalized['injuries'] ?? null) ? array_values($normalized['injuries']) : [],
            lineups: is_array($normalized['lineups'] ?? null) ? array_values($normalized['lineups']) : [],
            form: is_array($normalized['form'] ?? null) ? $normalized['form'] : null,
            h2h: is_array($normalized['h2h'] ?? null) ? $normalized['h2h'] : null,
            dataSources: $provider === '' ? [] : [['provider' => $provider, 'classes' => ['fixtures'],
                'providerMatchId' => $external !== '' ? $external : null]],
        );
    }

    /**
     * Validate and normalize a raw provider row, then build the model.
     *
     * @param array<string,mixed> $raw
     */
    public static function fromProviderRow(string $providerCode, array $raw, ?string $canonicalId = null): ?self
    {
        try {
            $normalized = SportsDataNormalizer::fixture($raw, $providerCode);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return self::fromNormalized($normalized, $canonicalId);
    }

    /**
     * Combine another provider's view of the same match into this one.
     *
     * Only empty slots are filled. A second feed is a second source of facts,
     * not a second opinion that overwrites the first: the identity, the teams
     * and the kickoff of the match that was already resolved stay as they are,
     * and what the other feed adds is recorded as a data source.
     *
     * @param list<string> $classes the data classes the other provider supplied
     */
    public function merge(self $other, array $classes = [], ?string $matchedBy = null, ?float $confidence = null): self
    {
        $sources = $this->dataSources;
        foreach ($other->dataSources as $source) {
            $sources[] = array_merge($source, [
                'classes' => $classes !== [] ? $classes : (array) ($source['classes'] ?? []),
                'matchedBy' => $matchedBy,
                'confidence' => $confidence,
            ]);
        }
        return new self(
            id: $this->id,
            providers: array_merge($this->providers, $other->providers),
            competitionId: $this->competitionId ?? $other->competitionId,
            competitionName: $this->competitionName ?? $other->competitionName,
            season: $this->season ?? $other->season,
            homeTeam: self::richer($this->homeTeam, $other->homeTeam),
            awayTeam: self::richer($this->awayTeam, $other->awayTeam),
            kickoffTime: $this->kickoffTime,
            status: $this->status !== 'SCHEDULED' ? $this->status : $other->status,
            venue: $this->venue ?? $other->venue,
            odds: $this->odds !== [] ? $this->odds : $other->odds,
            statistics: $this->statistics !== [] ? $this->statistics : $other->statistics,
            injuries: $this->injuries !== [] ? $this->injuries : $other->injuries,
            lineups: $this->lineups !== [] ? $this->lineups : $other->lineups,
            form: $this->form ?? $other->form,
            h2h: $this->h2h ?? $other->h2h,
            dataSources: $sources,
        );
    }

    /** Add one provenance entry (which provider supplied which data class). */
    public function withSource(string $provider, array $classes, ?string $operation = null, ?string $detail = null): self
    {
        $sources = $this->dataSources;
        $sources[] = ['provider' => $provider, 'classes' => $classes, 'operation' => $operation, 'detail' => $detail];
        return new self(
            id: $this->id, providers: $this->providers, competitionId: $this->competitionId,
            competitionName: $this->competitionName, season: $this->season, homeTeam: $this->homeTeam,
            awayTeam: $this->awayTeam, kickoffTime: $this->kickoffTime, status: $this->status, venue: $this->venue,
            odds: $this->odds, statistics: $this->statistics, injuries: $this->injuries, lineups: $this->lineups,
            form: $this->form, h2h: $this->h2h, dataSources: $sources,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'providers' => $this->providers,
            'competitionId' => $this->competitionId,
            'competitionName' => $this->competitionName,
            'season' => $this->season,
            'homeTeam' => $this->homeTeam,
            'awayTeam' => $this->awayTeam,
            'kickoffTime' => $this->kickoffTime,
            'status' => $this->status,
            'venue' => $this->venue,
            'odds' => $this->odds,
            'statistics' => $this->statistics,
            'injuries' => $this->injuries,
            'lineups' => $this->lineups,
            'form' => $this->form,
            'h2h' => $this->h2h,
            'dataSources' => $this->dataSources,
        ];
    }

    /** Keep the more complete of two team blocks; never drop a known name. */
    private static function richer(array $current, array $other): array
    {
        $out = $current;
        foreach (['id', 'logo'] as $key) {
            if (($out[$key] ?? null) === null && ($other[$key] ?? null) !== null) $out[$key] = $other[$key];
        }
        if (trim((string) ($out['name'] ?? '')) === '' && trim((string) ($other['name'] ?? '')) !== '') {
            $out['name'] = $other['name'];
            $out['normalized'] = $other['normalized'] ?? CanonicalMatch::normalizeTeam((string) $other['name']);
        }
        return $out;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
