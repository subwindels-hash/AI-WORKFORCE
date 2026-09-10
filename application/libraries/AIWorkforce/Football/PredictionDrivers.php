<?php
namespace AIWorkforce\Football;

/**
 * Why WINDELS selected what it selected — the same facts the model used, in the
 * order they were used, each with the stored figure behind it.
 *
 * A percentage alone invites a reader to trust the number; a list of the
 * measurements that produced it lets them check it. So every driver row carries
 * three things and cannot be emitted without them:
 *
 *  - `verdict` — one of STRONG / FAVOURABLE / NEUTRAL / WEAK / UNFAVOURABLE /
 *    DATA_UNAVAILABLE, produced by comparing a *stored* figure with a documented
 *    band;
 *  - `measure` — the figure itself (goals per match, meetings played, expected
 *    goals, the quoted price), so the verdict can be re-derived by hand;
 *  - `source` — where the figure was read from, including the provider's own
 *    derivation where the feature builder recorded one (`venue-split:home` as
 *    opposed to `season-total` is a materially better input, and hiding that
 *    would flatter weak inputs).
 *
 * Two constraints keep this honest as an *explanation* rather than a second model:
 *
 *  1. **Nothing here recomputes a probability.** The drivers are a reading of
 *     `feature_snapshot`, `evidence`, `quality_components` and the market block
 *     that are already stored. A driver can therefore never disagree with the
 *     prediction it describes, and adding one cannot change a figure.
 *  2. **A missing input is reported as missing.** A fixture whose provider sent
 *     no injury or lineup list gets `DATA_UNAVAILABLE` on that row — not "no
 *     injuries reported", which is a claim about squad news that the data does
 *     not support. Absence is listed as often as presence on purpose: knowing
 *     what the model could not see is half of knowing what a prediction is worth.
 *
 * The goal-rate bands below (`ATTACK_BANDS`, `DEFENSE_BANDS`) decide *wording
 * only*. They are never an input to the model, and they are stated here so that
 * "recent attacking output: strong" can be answered with "strong means 2.20+
 * goals per match at that venue, and this team averaged 2.48".
 */
final class PredictionDrivers
{
    public const STRONG = 'STRONG';
    public const FAVOURABLE = 'FAVOURABLE';
    public const NEUTRAL = 'NEUTRAL';
    public const WEAK = 'WEAK';
    public const UNFAVOURABLE = 'UNFAVOURABLE';
    public const UNAVAILABLE = 'DATA_UNAVAILABLE';

    /** Goals scored per match at the venue, band → word. */
    private const ATTACK_BANDS = [
        [2.20, self::STRONG, 'prolific'],
        [1.60, self::FAVOURABLE, 'productive'],
        [1.10, self::NEUTRAL, 'adequate'],
        [0.70, self::WEAK, 'thin'],
        [0.00, self::UNFAVOURABLE, 'blunt'],
    ];

    /** Goals conceded per match at the venue — lower is better. */
    private const DEFENSE_BANDS = [
        [0.80, self::STRONG, 'tight'],
        [1.20, self::FAVOURABLE, 'solid'],
        [1.70, self::NEUTRAL, 'conceding at an ordinary rate'],
        [2.40, self::WEAK, 'leaky'],
        [PHP_FLOAT_MAX, self::UNFAVOURABLE, 'conceding heavily'],
    ];

    public const DISCLAIMER = 'These are the stored measurements behind the prediction, restated as a reading. '
        . 'They describe how the evidence looks; they are not a probability of the outcome and they are not a guarantee.';

    /**
     * The driver rows for one prediction.
     *
     * @param array<string,mixed> $prediction the stored prediction row (with
     *        `feature_snapshot`, `evidence` and `quality_components` decoded)
     * @param array<string,mixed> $market the evaluated market block, if any
     * @param array<string,mixed> $fixture the stored fixture row
     * @return array{drivers:list<array<string,mixed>>,headline:?string,counts:array<string,int>,disclaimer:string}
     */
    public function describe(array $prediction, array $market = [], array $fixture = []): array
    {
        $snapshot = self::decode($prediction['feature_snapshot'] ?? null, []);
        $evidence = self::decode($prediction['evidence'] ?? null, []);
        $quality = self::decode($prediction['quality_components'] ?? null, []);
        $teams = (array) ($snapshot['teams'] ?? []);
        $home = (array) ($teams['HOME'] ?? []);
        $away = (array) ($teams['AWAY'] ?? []);
        $bySide = [];
        $headToHead = [];
        $league = [];
        foreach ((array) $evidence as $row) {
            if (($row['kind'] ?? '') === 'TEAM_FORM') $bySide[(string) ($row['side'] ?? '')] = (array) $row;
            if (($row['kind'] ?? '') === 'HEAD_TO_HEAD') $headToHead = (array) $row;
            if (($row['kind'] ?? '') === 'LEAGUE_STATISTICS') $league = (array) $row;
        }
        $predicted = strtoupper((string) ($prediction['predicted_result'] ?? ''));
        $drivers = [];

        // 1–2. Each side's performance at the venue that matters: home form for
        // the hosts, away form for the visitors. Both the goal rates and the last
        // five results are quoted, because a strong season average and a poor run
        // of form are different facts and the model weighs both.
        foreach ([
            'HOME' => ['label' => 'Home performance', 'team' => $home, 'form' => $bySide['HOME'] ?? []],
            'AWAY' => ['label' => 'Away performance', 'team' => $away, 'form' => $bySide['AWAY'] ?? []],
        ] as $venue => $part) {
            $drivers[] = self::performanceDriver($venue, (string) $part['label'], (array) $part['team'], (array) $part['form'], $predicted);
        }

        // 3. The expected-goals pair the score grid was built from. Named with its
        // method because LEAGUE_BASELINE and TEAM_BASELINE carry different weight.
        $expected = (array) ($snapshot['expectedGoals'] ?? []);
        $drivers[] = self::goalsDriver($expected, $home, $away, $predicted);

        // 4. Head-to-head. Its contribution is capped by configuration, and the
        // cap is stated here so a reader knows how much weight this line has.
        $drivers[] = self::headToHeadDriver($headToHead, (array) ($snapshot['headToHead'] ?? []));

        // 5. Squad availability. The football module stores no injury or lineup
        // feed, so this row is normally DATA_UNAVAILABLE — which is the honest
        // answer, and is exactly what the provider adapter contract requires:
        // report the gap rather than fill it.
        $drivers[] = self::availabilityDriver($fixture, $snapshot);

        // 6. Form as its own line: the last-five string the provider sent.
        $drivers[] = self::formDriver($home, $away, $bySide);

        // 7. The market's price, judged by the fair-value engine rather than here.
        $drivers[] = self::priceDriver($market);

        // 8. What the model could not see. Every family the quality assessment
        // scored at zero is listed, because "we had eight of ten inputs" is the
        // part of an explanation a reader most often needs.
        $drivers[] = self::evidenceDriver($quality, $snapshot);

        $counts = [];
        foreach ($drivers as $driver) {
            $verdict = (string) $driver['verdict'];
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
        }
        return [
            'drivers' => $drivers,
            'headline' => self::headline($drivers, $predicted, $home, $away),
            'counts' => $counts,
            'disclaimer' => self::DISCLAIMER,
            'generatedAt' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    private static function performanceDriver(string $venue, string $label, array $team, array $form, string $predicted): array
    {
        $attack = is_numeric($team['attackStrength'] ?? null) ? (float) $team['attackStrength'] : null;
        $defense = is_numeric($team['defenseWeakness'] ?? null) ? (float) $team['defenseWeakness'] : null;
        $side = $venue === 'HOME' ? 'home' : 'away';
        if ($attack === null && $defense === null) {
            return ['key' => strtolower($venue) . '_performance', 'label' => $label, 'verdict' => self::UNAVAILABLE,
                'detail' => 'No ' . $side . ' goal rates are stored for this team, so nothing about its ' . $label
                    . ' can be claimed.',
                'measure' => null, 'source' => 'feature_snapshot.teams.' . $venue];
        }
        $attackBand = $attack === null ? null : self::band($attack, self::ATTACK_BANDS);
        $defenseBand = $defense === null ? null : self::band($defense, self::DEFENSE_BANDS);
        // Each half of the sentence exists only if its rate does: a missing
        // figure must not be printed as 0.00, which would read as a team that
        // scores nothing rather than a team nobody measured.
        $clauses = [];
        if ($attack !== null) {
            $clauses[] = sprintf('%s %s %.2f goals per match %s (%s)', (string) ($team['name'] ?? ucfirst($side)),
                in_array((string) $attackBand[1], [self::STRONG, self::FAVOURABLE], true) ? 'scores'
                    : ($attackBand[1] === self::NEUTRAL ? 'scores' : 'manages'),
                $attack, 'at ' . $side, (string) $attackBand[2]);
        } else {
            $clauses[] = sprintf('%s has no measured %s attacking rate', (string) ($team['name'] ?? ucfirst($side)), $side);
        }
        $clauses[] = $defense !== null
            ? sprintf('concedes %.2f per match (%s)', $defense, (string) $defenseBand[2])
            : 'has no measured rate of concession';
        $detail = implode(', ', $clauses) . '.';
        $played = (int) ($team['played'] ?? 0);
        if ($played > 0) $detail .= sprintf(' Over %d stored matches: %dW %dD %dL, position %s.',
            $played, (int) ($team['wins'] ?? 0), (int) ($team['draws'] ?? 0), (int) ($team['losses'] ?? 0),
            isset($team['position']) && $team['position'] !== null ? (string) (int) $team['position'] : '—');
        $verdict = self::combine($attackBand === null ? null : $attackBand[1], $defenseBand === null ? null : $defenseBand[1]);
        $supports = $predicted === '' ? null : ($venue === 'HOME' ? $predicted === 'HOME' : $predicted === 'AWAY');
        if ($supports === false) {
            $detail .= ' This is against the selection rather than for it: the model still chose the other side '
                . 'because the paired rates and the opponent\'s rates are read together.';
        }
        return ['key' => strtolower($venue) . '_performance', 'label' => $label, 'verdict' => $verdict,
            'detail' => $detail,
            'measure' => ['goalsPerMatch' => $attack, 'goalsConcededPerMatch' => $defense, 'played' => $played,
                'wins' => $team['wins'] ?? null, 'draws' => $team['draws'] ?? null, 'losses' => $team['losses'] ?? null,
                'position' => $team['position'] ?? null],
            'source' => 'feature_snapshot.teams.' . $venue
                . ' (attack: ' . (string) ($team['attackSource'] ?? DataState::UNAVAILABLE)
                . ', defense: ' . (string) ($team['defenseSource'] ?? DataState::UNAVAILABLE) . ')',
            'supportsSelection' => $supports];
    }

    /** @return array<string,mixed> */
    private static function goalsDriver(array $expected, array $home, array $away, string $predicted): array
    {
        $homeXg = is_numeric($expected['home'] ?? null) ? (float) $expected['home'] : null;
        $awayXg = is_numeric($expected['away'] ?? null) ? (float) $expected['away'] : null;
        if ($homeXg === null || $awayXg === null) {
            return ['key' => 'expected_goals', 'label' => 'Expected-goals differential', 'verdict' => self::UNAVAILABLE,
                'detail' => 'The goal rates one or both sides need were not available, so no expected-goal pair exists '
                    . 'for this match.',
                'measure' => ['home' => $homeXg, 'away' => $awayXg], 'source' => 'feature_snapshot.expectedGoals'];
        }
        $difference = round($homeXg - $awayXg, 4);
        $method = (string) ($expected['method'] ?? DataState::UNAVAILABLE);
        $verdict = match (true) {
            $difference >= 1.0 => self::STRONG,
            $difference >= 0.35 => self::FAVOURABLE,
            $difference > -0.35 => self::NEUTRAL,
            $difference > -1.0 => self::WEAK,
            default => self::UNFAVOURABLE,
        };
        $favouring = $difference > 0 ? 'the hosts' : ($difference < 0 ? 'the visitors' : 'neither side');
        return ['key' => 'expected_goals', 'label' => 'Expected-goals differential', 'verdict' => $verdict,
            'detail' => sprintf('The model expects %.2f–%.2f (%+.2f differential), which favours %s. Rates from %s.',
                $homeXg, $awayXg, $difference, $favouring, $method),
            'measure' => ['home' => $homeXg, 'away' => $awayXg, 'difference' => $difference,
                'total' => round($homeXg + $awayXg, 4), 'method' => $method],
            'source' => 'feature_snapshot.expectedGoals (' . $method . ')',
            'supportsSelection' => $predicted === '' ? null : (
                ($predicted === 'HOME' && $difference > 0) || ($predicted === 'AWAY' && $difference < 0)
                    || ($predicted === 'DRAW' && abs($difference) < 0.35))];
    }

    /** @return array<string,mixed> */
    private static function headToHeadDriver(array $row, array $snapshot): array
    {
        $meetings = (int) ($row['meetings'] ?? $snapshot['meetings'] ?? 0);
        if ($meetings <= 0) {
            return ['key' => 'head_to_head', 'label' => 'Head-to-head record', 'verdict' => self::UNAVAILABLE,
                'detail' => 'No head-to-head meetings are stored for this pairing, so history contributes nothing to '
                    . 'this prediction — it is not assumed to be neutral, it is absent.',
                'measure' => null, 'source' => 'evidence.HEAD_TO_HEAD'];
        }
        $weight = is_numeric($row['weight'] ?? $snapshot['weight'] ?? null) ? (float) ($row['weight'] ?? $snapshot['weight']) : null;
        $detail = sprintf('%d stored meetings: %s', $meetings, (string) ($row['summary'] ?? $snapshot['summary'] ?? 'no summary'));
        if ($weight !== null) $detail .= sprintf('; head-to-head carries at most %s%% of the model input, and %s%% here.',
            number_format(12.0, 0), number_format($weight * 100.0, 1));
        return ['key' => 'head_to_head', 'label' => 'Head-to-head record',
            // No stored meetings is not an even contest; it is no head-to-head at all.
            'verdict' => $meetings <= 0 ? self::UNAVAILABLE : ($meetings >= 6 ? self::FAVOURABLE : self::NEUTRAL),
            'detail' => $detail,
            'measure' => ['meetings' => $meetings, 'homeWins' => $snapshot['homeWins'] ?? null,
                'draws' => $snapshot['draws'] ?? null, 'awayWins' => $snapshot['awayWins'] ?? null,
                'weight' => $weight !== null ? round($weight, 4) : null],
            'source' => 'evidence.HEAD_TO_HEAD + feature_snapshot.headToHead'];
    }

    /**
     * Squad availability, from whatever the stored fixture actually carries.
     *
     * The provider contract does not guarantee an injury or lineup endpoint, and
     * this module's adapter never assumes one: the row reports the fields the
     * fixture holds and says so. A fixture with no team-news payload is
     * DATA_UNAVAILABLE with an explicit note that the model therefore assumed
     * nothing about availability — which is the opposite of assuming everyone is
     * fit.
     *
     * @return array<string,mixed>
     */
    private static function availabilityDriver(array $fixture, array $snapshot): array
    {
        $payload = self::decode($fixture['payload'] ?? null, []);
        $injuries = (array) ($payload['injuries'] ?? $snapshot['injuries'] ?? []);
        $lineups = (array) ($payload['lineups'] ?? $snapshot['lineups'] ?? []);
        $confirmed = (bool) ($payload['lineupConfirmed'] ?? $snapshot['lineupConfirmed'] ?? false);
        if ($injuries === [] && $lineups === [] && !$confirmed) {
            return ['key' => 'squad_availability', 'label' => 'Squad availability', 'verdict' => self::UNAVAILABLE,
                'detail' => 'No injury list and no lineup — confirmed or projected — is stored for this fixture, so '
                    . 'the prediction assumes nothing about availability. It does not assume everyone is fit either: '
                    . 'the input is simply absent, and a late team news item can move it.',
                'measure' => ['injuries' => 0, 'lineups' => 0, 'confirmed' => false],
                'source' => 'fixture payload (no team-news field supplied by the provider)'];
        }
        $parts = [];
        if ($injuries !== []) $parts[] = count($injuries) . ' unavailable player' . (count($injuries) === 1 ? '' : 's');
        if ($lineups !== []) $parts[] = count($lineups) . ' lineup row' . (count($lineups) === 1 ? '' : 's');
        if ($confirmed) $parts[] = 'lineup confirmed';
        return ['key' => 'squad_availability', 'label' => 'Squad availability',
            'verdict' => $confirmed ? self::STRONG : (count($injuries) > 2 ? self::WEAK : self::NEUTRAL),
            'detail' => 'Stored team news: ' . implode('; ', $parts) . '.',
            'measure' => ['injuries' => count($injuries), 'lineups' => count($lineups), 'confirmed' => $confirmed],
            'source' => 'fixture payload'];
    }

    /** @return array<string,mixed> */
    private static function formDriver(array $home, array $away, array $bySide): array
    {
        $lines = [];
        $played = 0;
        foreach (['HOME' => $home, 'AWAY' => $away] as $venue => $team) {
            $form = (array) ($bySide[$venue]['form'] ?? []);
            $string = (string) ($form['string'] ?? '');
            if ($string === '') {
                $lines[] = (string) ($team['name'] ?? ucfirst(strtolower($venue))) . ': ' . DataState::UNAVAILABLE;
                continue;
            }
            $lines[] = sprintf('%s: %s (%dW %dD %dL over %d matches, %d scored, %d conceded)',
                (string) ($team['name'] ?? ucfirst(strtolower($venue))), $string,
                (int) ($form['wins'] ?? 0), (int) ($form['draws'] ?? 0), (int) ($form['losses'] ?? 0),
                (int) ($form['played'] ?? 0), (int) ($form['goalsFor'] ?? 0), (int) ($form['goalsAgainst'] ?? 0));
            $played = max($played, (int) ($form['played'] ?? 0));
        }
        $both = $played >= 5;
        return ['key' => 'recent_form', 'label' => 'Recent form',
            'verdict' => $played >= 8 ? self::FAVOURABLE : ($both ? self::NEUTRAL : ($played > 0 ? self::WEAK : self::UNAVAILABLE)),
            'detail' => 'Recent results as the provider sent them — ' . implode('; ', $lines) . '.',
            'measure' => ['matchesUsed' => $played, 'home' => $home['form']['last5']['string'] ?? null,
                'away' => $away['form']['last5']['string'] ?? null],
            'source' => 'evidence.TEAM_FORM (last five stored completed matches per side)'];
    }

    /** @return array<string,mixed> */
    private static function priceDriver(array $market): array
    {
        $value = (array) ($market['value'] ?? []);
        $class = (string) ($value['valueClass'] ?? OddsIntelligence::CLASS_UNPRICED);
        $odds = is_numeric($value['odds'] ?? null) ? (float) $value['odds'] : null;
        $points = is_numeric($value['edgePoints'] ?? null) ? (float) $value['edgePoints'] : null;
        $ev = is_numeric($value['expectedValue'] ?? null) ? (float) $value['expectedValue'] : null;
        $verdict = match ($class) {
            OddsIntelligence::CLASS_STRONG_VALUE => self::STRONG,
            OddsIntelligence::CLASS_POSITIVE_VALUE => self::FAVOURABLE,
            OddsIntelligence::CLASS_FAIR => self::NEUTRAL,
            OddsIntelligence::CLASS_NEGATIVE_VALUE => self::WEAK,
            OddsIntelligence::CLASS_AVOID => self::UNFAVOURABLE,
            default => self::UNAVAILABLE,
        };
        $detail = $odds === null
            ? 'The connected odds feed has quoted no price for this selection, so there is nothing to judge the '
                . 'prediction against. The absence is stated rather than filled with the model\'s own price.'
            : sprintf('The market charges %s for it (%s%% implied). %s', number_format($odds, 2),
                is_numeric($value['impliedProbability'] ?? null) ? number_format((float) $value['impliedProbability'] * 100, 1) : '—',
                $points === null ? '' : sprintf('WINDELS is %+0.1f points from that, an expected return of %+0.1f%% per unit.',
                    $points, (float) $ev * 100.0));
        if ($class === OddsIntelligence::CLASS_UNPRICED && $odds !== null) {
            $verdict = self::NEUTRAL;
            $detail .= ' ' . (string) ($value['valueReason'] ?? '');
        }
        return ['key' => 'market_price', 'label' => 'Market price', 'verdict' => $verdict,
            'detail' => $detail,
            'measure' => ['odds' => $odds, 'edgePoints' => $points, 'expectedValue' => $ev,
                'valueClass' => $class === '' ? OddsIntelligence::CLASS_UNPRICED : $class,
                'marginPoints' => is_numeric($market['pricing']['marginPoints'] ?? null) ? (float) $market['pricing']['marginPoints'] : null,
                'fairOdds' => $value['fairOdds'] ?? null],
            'source' => 'OddsIntelligence over the quoted odds rows',
            'note' => (string) ($value['valueReason'] ?? '')];
    }

    /** @return array<string,mixed> */
    private static function evidenceDriver(array $quality, array $snapshot): array
    {
        $present = [];
        $absent = [];
        foreach ($quality as $key => $component) {
            if (!is_array($component)) continue;
            $value = (float) ($component['value'] ?? 0);
            if ($value >= 70.0) $present[] = (string) $key;
            elseif ($value >= 40.0) $present[] = (string) $key . ' (partial)';
            else $absent[] = (string) $key;
        }
        $coverage = (array) ($snapshot['coverage'] ?? []);
        $unavailable = [];
        foreach ($coverage as $key => $state) {
            if ((string) $state === DataState::UNAVAILABLE) $unavailable[] = (string) $key;
        }
        // An empty assessment is not a clean one. With no component detail stored
        // there is nothing to grade, and saying STRONG would grade an absence as
        // the best possible evidence — the mistake this whole layer exists to avoid.
        $known = $present !== [] || $absent !== [] || $unavailable !== [];
        $verdict = !$known ? self::UNAVAILABLE
            : ($absent === [] && $unavailable === [] ? self::STRONG : ($absent === [] ? self::FAVOURABLE
            : (count($absent) <= 1 ? self::NEUTRAL : self::WEAK)));
        $parts = [];
        if ($present !== []) $parts[] = 'available: ' . implode(', ', $present);
        if ($absent !== []) $parts[] = 'no usable figure: ' . implode(', ', $absent);
        if ($unavailable !== []) $parts[] = 'fields the provider did not send: ' . implode(', ', $unavailable);
        return ['key' => 'evidence', 'label' => 'Evidence available to the model', 'verdict' => $verdict,
            'detail' => $parts === []
                ? 'The quality assessment recorded no component detail for this prediction, so the strength of the '
                    . 'evidence behind it cannot be stated either way.'
                : implode('; ', $parts) . '.',
            'measure' => ['present' => $present, 'absent' => $absent, 'unavailableFields' => $unavailable],
            'source' => 'quality_components + feature_snapshot.coverage'];
    }

    /**
     * One sentence that reads like an answer rather than a table. Built from the
     * drivers only, so it can never assert something the rows do not show.
     *
     * @param list<array<string,mixed>> $drivers
     */
    private static function headline(array $drivers, string $predicted, array $home, array $away): ?string
    {
        if ($predicted === '') return null;
        $for = [];
        $against = [];
        $missing = [];
        foreach ($drivers as $driver) {
            $key = (string) $driver['key'];
            if ($key === 'evidence' || $key === 'head_to_head') continue;
            if ((string) $driver['verdict'] === self::UNAVAILABLE) { $missing[] = (string) $driver['label']; continue; }
            if (in_array((string) $driver['verdict'], [self::STRONG, self::FAVOURABLE], true)) $for[] = (string) $driver['label'];
            elseif (in_array((string) $driver['verdict'], [self::WEAK, self::UNFAVOURABLE], true)) $against[] = (string) $driver['label'];
        }
        $side = match ($predicted) {
            'HOME' => (string) ($home['name'] ?? 'the home side'),
            'AWAY' => (string) ($away['name'] ?? 'the away side'),
            default => 'the draw',
        };
        $sentence = 'WINDELS selected ' . $side . ' on ' . count($for) . ' supporting reading'
            . (count($for) === 1 ? '' : 's');
        if ($for !== []) $sentence .= ' (' . implode(', ', $for) . ')';
        if ($against !== []) $sentence .= ', against ' . count($against) . ' (' . implode(', ', $against) . ')';
        if ($missing !== []) $sentence .= ', with ' . count($missing) . ' unavailable (' . implode(', ', $missing) . ')';
        return $sentence . '.';
    }

    /** @param list<array{0:float,1:string,2:string}> $bands */
    private static function band(float $value, array $bands): array
    {
        foreach ($bands as $band) {
            if ($value >= (float) $band[0]) return [$band[0], $band[1], $band[2]];
        }
        $last = $bands[count($bands) - 1];
        return [$last[0], $last[1], $last[2]];
    }

    /** The worse of two readings is the one a pair of rates deserves. */
    private static function combine(?string $attack, ?string $defense): string
    {
        $rank = [self::UNFAVOURABLE => 0, self::WEAK => 1, self::NEUTRAL => 2, self::FAVOURABLE => 3, self::STRONG => 4];
        $parts = array_filter([$attack, $defense], static fn(?string $v): bool => $v !== null && isset($rank[$v]));
        if ($parts === []) return self::UNAVAILABLE;
        $worst = $parts[0];
        foreach ($parts as $part) {
            if ($rank[$part] < $rank[$worst]) $worst = $part;
        }
        return (string) $worst;
    }

    /** @return array<string,mixed>|list<mixed> */
    private static function decode(mixed $value, array $default): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return $decoded;
        }
        return $default;
    }
}
