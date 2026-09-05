<?php
namespace AIWorkforce\Football;

/**
 * Expected goals per side, computed only from stored provider statistics.
 *
 * Two documented methods, chosen by what the data actually supports:
 *
 *  LEAGUE_BASELINE_VENUE (preferred)
 *      λ_home = homeAttackRate(home team, at home) × awayDefenseRate(away team, away)
 *               ÷ leagueHomeGoalsAverage      ... and symmetrically for the away side,
 *      i.e. a venue-adjusted Poisson pairing using the league's own scoring
 *      environment as the neutral reference. The neutral reference is measured
 *      from the stored table, never from a hard-coded constant.
 *
 *  TEAM_BASELINE (fallback)
 *      λ_home = (homeAttackRate(home) + awayDefenseRate(away)) / 2 — used when the
 *      league aggregate was not collected. Documented in the prediction's
 *      `xgMethod` so a reader can see which assumption was made.
 *
 * If neither side yields a rate, the resolver returns NO_RATE and the caller
 * must not publish an expected-goals number. Nothing is imputed here.
 */
final class ExpectedGoalsResolver
{
    public const METHOD_LEAGUE_BASELINE = 'LEAGUE_BASELINE_VENUE';
    public const METHOD_TEAM_BASELINE = 'TEAM_BASELINE';
    public const METHOD_NO_RATE = 'NO_RATE_AVAILABLE';

    public function __construct(private FootballConfiguration $config) {}

    /**
     * @param array{HOME:array,AWAY:array} $teams        feature rows from FeatureBuilder
     * @param array<string,mixed>|null      $competition  feature competition block
     * @return array{home:?float, away:?float, method:string, neutral:bool,
     *               components:array<string,mixed>, notes:list<string>}
     */
    public function resolve(array $teams, ?array $competition): array
    {
        $home = $teams['HOME'] ?? [];
        $away = $teams['AWAY'] ?? [];
        $notes = [];
        $homeAttack = self::num($home['attackStrength'] ?? null);
        $homeDefense = self::num($home['defenseWeakness'] ?? null);
        $awayAttack = self::num($away['attackStrength'] ?? null);
        $awayDefense = self::num($away['defenseWeakness'] ?? null);
        $aggregate = is_array($competition['aggregate'] ?? null) ? $competition['aggregate'] : [];
        $leagueHomeAttack = self::num($aggregate['avgHomeGoals'] ?? null);
        $leagueHomeDefense = self::num($aggregate['avgHomeConceded'] ?? null);
        $leagueAwayAttack = self::num($aggregate['avgAwayGoals'] ?? null);
        $leagueAwayDefense = self::num($aggregate['avgAwayConceded'] ?? null);

        $components = [
            'homeAttackRate' => $homeAttack, 'homeDefenseRate' => $homeDefense,
            'awayAttackRate' => $awayAttack, 'awayDefenseRate' => $awayDefense,
            'leagueHomeGoals' => $leagueHomeAttack, 'leagueAwayGoals' => $leagueAwayAttack,
            'leagueHomeConceded' => $leagueHomeDefense, 'leagueAwayConceded' => $leagueAwayDefense,
            'homeAttackSource' => $home['attackSource'] ?? DataState::UNAVAILABLE,
            'awayAttackSource' => $away['attackSource'] ?? DataState::UNAVAILABLE,
            'homeDefenseSource' => $home['defenseSource'] ?? DataState::UNAVAILABLE,
            'awayDefenseSource' => $away['defenseSource'] ?? DataState::UNAVAILABLE,
        ];

        $leagueReady = null !== $homeAttack && null !== $awayDefense && null !== $awayAttack && null !== $homeDefense
            && null !== $leagueHomeAttack && $leagueHomeAttack > 0
            && null !== $leagueAwayAttack && $leagueAwayAttack > 0;
        if ($leagueReady) {
            // Ratio form of the venue-adjusted Poisson pairing:
            //   λ_home = (homeAttack / leagueHomeGoals) × (awayConcededAway / leagueHomeGoals) × leagueHomeGoals
            //          = homeAttack × awayConcededAway / leagueHomeGoals
            // and symmetrically for the away side, using the league's own
            // away-side scoring norm as the neutral reference.
            $lambdaHome = ($homeAttack * $awayDefense) / $leagueHomeAttack;
            $lambdaAway = ($awayAttack * $homeDefense) / $leagueAwayAttack;
            $components['leagueHomeGoalsPerMatch'] = $leagueHomeAttack;
            $components['leagueAwayGoalsPerMatch'] = $leagueAwayAttack;
            return [
                'home' => round(max(0.05, min($this->config->maxGoals(), $lambdaHome)), 4),
                'away' => round(max(0.05, min($this->config->maxGoals(), $lambdaAway)), 4),
                'method' => self::METHOD_LEAGUE_BASELINE, 'neutral' => false,
                'components' => $components, 'notes' => $notes,
            ];
        }

        if (null !== $homeAttack && null !== $awayDefense && null !== $awayAttack && null !== $homeDefense) {
            $lambdaHome = max(0.05, min($this->config->maxGoals(), ($homeAttack + $awayDefense) / 2));
            $lambdaAway = max(0.05, min($this->config->maxGoals(), ($awayAttack + $homeDefense) / 2));
            $notes[] = 'League scoring baseline unavailable; expected goals use each team\'s own venue rates (TEAM_BASELINE).';
            return ['home' => round($lambdaHome, 4), 'away' => round($lambdaAway, 4), 'method' => self::METHOD_TEAM_BASELINE, 'neutral' => false, 'components' => $components, 'notes' => $notes];
        }

        $notes[] = 'Not enough stored rate data to model expected goals for both sides.';
        return ['home' => null, 'away' => null, 'method' => self::METHOD_NO_RATE, 'neutral' => false, 'components' => $components, 'notes' => $notes];
    }

    /**
     * Optional market blend. Only ever applied when a probability genuinely came
     * from the provider: absent data leaves the model estimate untouched, and the
     * blend weight (0–1, configured) is what the caller sees, so a small sample
     * of odds cannot dominate.
     *
     * @return array{home:?float, away:?float, blended:bool, weight:?float, source:string}
     */
    public function blendWithMarket(array $expected, ?array $market): array
    {
        $weight = $this->config->marketBlendWeight();
        $marketHome = self::num($market['expectedHomeGoals'] ?? null);
        $marketAway = self::num($market['expectedAwayGoals'] ?? null);
        if ($expected['home'] === null || $expected['away'] === null) {
            return ['home' => $expected['home'], 'away' => $expected['away'], 'blended' => false, 'weight' => null, 'source' => $expected['method']];
        }
        if ($marketHome === null || $marketAway === null || $weight <= 0.0) {
            return ['home' => $expected['home'], 'away' => $expected['away'], 'blended' => false, 'weight' => null, 'source' => $expected['method'] . ' (no market data)'];
        }
        return [
            'home' => round($expected['home'] * (1 - $weight) + $marketHome * $weight, 4),
            'away' => round($expected['away'] * (1 - $weight) + $marketAway * $weight, 4),
            'blended' => true,
            'weight' => $weight,
            'source' => $expected['method'] . ' + market(' . $weight . ')',
        ];
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }
}
