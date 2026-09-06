<?php
namespace AIWorkforce\Football;

/**
 * Category A/B/C classification of a finished probability distribution.
 *
 * Categories are a labelling of the engine's own output — never an input that
 * could steer it. The classifier reads the *displayed* (calibrated, or
 * labelled-RAW) probabilities the ticket shows, applies the stored rule rows
 * (`football_category_rules`) when they exist, and falls back to the
 * configured defaults otherwise:
 *
 *   B  when the draw probability is significant (≥ the configured line) —
 *      a draw in play means the match is competitive by definition;
 *   A  when the home winning probability leads the away one by at least the
 *      configured edge margin AND is the strict maximum;
 *   C  when the away winning probability leads the home one by at least the
 *      configured edge margin AND is the strict maximum;
 *   B  otherwise (similar probabilities, draw strictly maximal, or a margin
 *      below the edge line).
 *
 * A prediction whose probabilities are not all stored is UNCLASSIFIED (null
 * key) rather than being forced into a category: an absent number is not 0.
 */
final class CategoryClassifier
{
    public const KEYS = ['A', 'B', 'C'];

    public const DEFAULT_LABELS = [
        'A' => 'HOME ADVANTAGE',
        'B' => 'BALANCED / COMPETITIVE',
        'C' => 'AWAY ADVANTAGE',
    ];

    public const RULE_TYPES = [
        'A' => 'HOME_EDGE',
        'B' => 'DRAW_OR_BALANCED',
        'C' => 'AWAY_EDGE',
    ];

    public const DEFAULT_DESCRIPTIONS = [
        'A' => 'Assigned when the home team carries the strongest winning probability, by at least the configured edge margin.',
        'B' => 'Assigned when both teams have similar probabilities or the draw probability is significant.',
        'C' => 'Assigned when the away team carries the strongest winning probability, by at least the configured edge margin.',
    ];

    /** @var (callable():list<array<string,mixed>>)|null */
    private $ruleSource = null;

    /**
     * @param callable():list<array<string,mixed>> $ruleSource reads the stored
     *        rule rows; returns [] on a fresh database (defaults apply).
     */
    public function __construct(FootballConfiguration $config, $ruleSource = null)
    {
        $this->config = $config;
        $this->ruleSource = $ruleSource;
    }

    /**
     * The effective rule set: stored rows win row-by-row, configured defaults
     * fill whatever is missing, so a half-saved admin form never yields a
     * rule with no edge and no draw line.
     *
     * @return array{edgePct:float, drawSignificantPct:float, labels:array<string,string>,
     *               descriptions:array<string,string>, enabled:array<string,bool>, source:string}
     */
    public function rules(): array
    {
        $edge = $this->config->categoryEdgePct();
        $draw = $this->config->categoryDrawSignificantPct();
        $labels = self::DEFAULT_LABELS;
        $descriptions = self::DEFAULT_DESCRIPTIONS;
        $enabled = ['A' => true, 'B' => true, 'C' => true];
        $source = 'DEFAULTS';
        if ($this->ruleSource !== null) {
            try {
                $rows = (array) ($this->ruleSource)();
            } catch (\Throwable $e) {
                $rows = [];   // a broken rule store must not break classification
            }
            if ($rows !== []) $source = 'STORED';
            foreach ($rows as $row) {
                $key = strtoupper((string) ($row['category_key'] ?? ''));
                if (!in_array($key, self::KEYS, true)) continue;
                if (!empty($row['label'])) $labels[$key] = (string) $row['label'];
                if (!empty($row['description'])) $descriptions[$key] = (string) $row['description'];
                $enabled[$key] = (int) ($row['enabled'] ?? 1) === 1;
                $parameters = is_array($row['parameters'] ?? null) ? $row['parameters'] : json_decode((string) ($row['parameters'] ?? '{}'), true);
                $parameters = is_array($parameters) ? $parameters : [];
                if (is_numeric($parameters['edgePct'] ?? null)) $edge = max(0.0, min(50.0, (float) $parameters['edgePct']));
                if (is_numeric($parameters['drawSignificantPct'] ?? null)) $draw = max(5.0, min(90.0, (float) $parameters['drawSignificantPct']));
            }
        }
        return ['edgePct' => $edge, 'drawSignificantPct' => $draw, 'labels' => $labels, 'descriptions' => $descriptions, 'enabled' => $enabled, 'source' => $source];
    }

    /**
     * Classify a full home/draw/away probability triple (fractions, as stored).
     *
     * @param array{home:?float,draw:?float,away:?float} $probabilities
     * @return array{key:?string, label:string, reason:string, edgePct:float, drawSignificantPct:float}
     *         `key` is null only when the probabilities are incomplete.
     */
    public function classify(array $probabilities): array
    {
        $rules = $this->rules();
        $p = ['home' => $probabilities['home'] ?? null, 'draw' => $probabilities['draw'] ?? null, 'away' => $probabilities['away'] ?? null];
        foreach ($p as $value) {
            if (!is_numeric($value)) {
                return ['key' => null, 'label' => 'UNCLASSIFIED', 'reason' => 'Outcome probabilities are incomplete, so no category is assigned.',
                    'edgePct' => $rules['edgePct'], 'drawSignificantPct' => $rules['drawSignificantPct']];
            }
        }
        $home = 100.0 * (float) $p['home'];
        $draw = 100.0 * (float) $p['draw'];
        $away = 100.0 * (float) $p['away'];
        $edge = $rules['edgePct'];
        $drawLine = $rules['drawSignificantPct'];

        if ($draw >= $drawLine) {
            return $this->verdict('B', $rules, sprintf(
                'Draw probability %s%% reaches the significant-draw line of %s%%: the match is balanced / competitive.',
                number_format($draw, 1), number_format($drawLine, 1)
            ));
        }
        if ($home > $away && $home > $draw && ($home - $away) >= $edge) {
            return $this->verdict('A', $rules, sprintf(
                'Home win probability %s%% leads the away probability %s%% by %s points — at least the %s-point edge margin.',
                number_format($home, 1), number_format($away, 1), number_format($home - $away, 1), number_format($edge, 1)
            ));
        }
        if ($away > $home && $away > $draw && ($away - $home) >= $edge) {
            return $this->verdict('C', $rules, sprintf(
                'Away win probability %s%% leads the home probability %s%% by %s points — at least the %s-point edge margin.',
                number_format($away, 1), number_format($home, 1), number_format($away - $home, 1), number_format($edge, 1)
            ));
        }
        return $this->verdict('B', $rules, sprintf(
            'No side clears the %s-point edge margin and the draw probability %s%% is below the significant line of %s%%: the probabilities are balanced.',
            number_format($edge, 1), number_format($draw, 1), number_format($drawLine, 1)
        ));
    }

    /**
     * Classify from a stored prediction row's columns (fractions), for rows
     * written before the `category` column existed. Same rules, same result —
     * the label carries a `derived` marker so a reader knows it was recomputed
     * at read time rather than written with the prediction.
     *
     * @return array{key:?string, label:string, reason:string, edgePct:float, drawSignificantPct:float, derived:bool}
     */
    public function forStoredRow(array $row): array
    {
        $result = $this->classify([
            'home' => $row['probability_home'] ?? null,
            'draw' => $row['probability_draw'] ?? null,
            'away' => $row['probability_away'] ?? null,
        ]);
        $result['derived'] = true;
        return $result;
    }

    /** Display label for a key, honouring stored rule rows. */
    public function label(?string $key): string
    {
        if ($key === null || $key === '') return 'UNCLASSIFIED';
        return (string) ($this->rules()['labels'][strtoupper($key)] ?? self::DEFAULT_LABELS[strtoupper($key)] ?? 'UNCLASSIFIED');
    }

    /**
     * The default rule rows the admin surface seeds on a fresh database. Each
     * row carries the same parameters JSON the admin save writes, so seeding
     * and saving produce identical shapes.
     *
     * @return list<array<string,mixed>>
     */
    public function defaultRuleRows(): array
    {
        $edge = $this->config->categoryEdgePct();
        $draw = $this->config->categoryDrawSignificantPct();
        $now = gmdate('c');
        $out = [];
        foreach (self::KEYS as $key) {
            $out[] = [
                'category_key' => $key,
                'label' => self::DEFAULT_LABELS[$key],
                'description' => self::DEFAULT_DESCRIPTIONS[$key],
                'rule_type' => self::RULE_TYPES[$key],
                'parameters' => ['edgePct' => $edge, 'drawSignificantPct' => $draw],
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        return $out;
    }

    private function verdict(string $key, array $rules, string $reason): array
    {
        if (empty($rules['enabled'][$key])) {
            // A disabled category cannot be assigned: the match falls to the
            // balanced bucket rather than vanishing from the ticket.
            return ['key' => 'B', 'label' => $rules['labels']['B'], 'reason' => 'Category ' . $key . ' is disabled in the admin rules; the match is held in the balanced bucket.',
                'edgePct' => $rules['edgePct'], 'drawSignificantPct' => $rules['drawSignificantPct']];
        }
        return ['key' => $key, 'label' => $rules['labels'][$key], 'reason' => $reason,
            'edgePct' => $rules['edgePct'], 'drawSignificantPct' => $rules['drawSignificantPct']];
    }
}
