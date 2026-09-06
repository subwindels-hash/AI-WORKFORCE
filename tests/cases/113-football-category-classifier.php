<?php
/**
 * Football Intelligence — A/B/C classification and the admin-configurable
 * rules behind it (spec §3 + §10).
 *
 * Categories are a labelling of the engine's output: the classifier reads the
 * displayed probabilities and the stored rule rows, and the admin panel's
 * persisted values reach the engine through the same configuration precedence
 * as the environment (saved > environment > default). Every rule here is
 * deterministic arithmetic on a stored probability triple — there is nothing
 * to randomise and nothing to fabricate.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CategoryClassifier;
use AIWorkforce\Football\FootballConfiguration;

function fx_fb_classifier(array $config = [], ?array $storedRows = null): CategoryClassifier
{
    $source = $storedRows === null ? null : static function () use ($storedRows): array {
        return $storedRows;
    };
    return new CategoryClassifier(new FootballConfiguration($config), $source);
}

test('football: a clear home edge is A, a clear away edge is C, close probabilities are B', function () {
    $c = fx_fb_classifier();
    $a = $c->classify(['home' => 0.62, 'draw' => 0.22, 'away' => 0.16]);
    assert_equals('A', $a['key'], 'home leads the away probability by 46 points (edge line 5)');
    assert_contains('HOME ADVANTAGE', strtoupper($a['label']), 'A carries the home-advantage label');

    $away = $c->classify(['home' => 0.16, 'draw' => 0.22, 'away' => 0.62]);
    assert_equals('C', $away['key'], 'away leads by 46 points');
    assert_contains('AWAY ADVANTAGE', strtoupper($away['label']), 'C carries the away-advantage label');

    $balanced = $c->classify(['home' => 0.38, 'draw' => 0.26, 'away' => 0.36]);
    assert_equals('B', $balanced['key'], 'no side clears the 5-point edge margin (38 vs 36)');
});

test('football: a significant draw forces B even when one side leads on raw points', function () {
    $c = fx_fb_classifier();
    $verdict = $c->classify(['home' => 0.45, 'draw' => 0.35, 'away' => 0.20]);
    assert_equals('B', $verdict['key'], 'draw at 35% is at or above the 30% significant-draw line');
    assert_contains('draw', strtolower($verdict['reason']), 'the reason names the draw line');

    // Just under the line the same margin is classified by the edge rule:
    $under = $c->classify(['home' => 0.45, 'draw' => 0.29, 'away' => 0.26]);
    assert_equals('A', $under['key'], 'draw at 29% is below the line; home leads away by 19 points');
});

test('football: incomplete probabilities are UNCLASSIFIED, never guessed', function () {
    $c = fx_fb_classifier();
    $missing = $c->classify(['home' => 0.5, 'draw' => null, 'away' => 0.5]);
    assert_null($missing['key'], 'an absent probability is not zero and not a guess');
    assert_equals('UNCLASSIFIED', $missing['label']);
    $none = $c->classify([]);
    assert_null($none['key'], 'an empty triple is unclassified');
});

test('football: a disabled category never labels a match; the match falls to B', function () {
    $rows = fx_fb_classifier()->defaultRuleRows();
    foreach ($rows as &$row) if ($row['category_key'] === 'A') $row['enabled'] = 0;
    unset($row);
    $c = fx_fb_classifier([], $rows);
    $verdict = $c->classify(['home' => 0.62, 'draw' => 0.22, 'away' => 0.16]);
    assert_equals('B', $verdict['key'], 'A is disabled in the admin rules, so the home-edge match is held in the balanced bucket');
    assert_contains('disabled', strtolower($verdict['reason']), 'the reason explains why it is B');
});

test('football: stored rule rows win over the configured defaults, row by row', function () {
    // Stored edge of 8: a 5-point margin that the default (5) would call A is B.
    $rows = fx_fb_classifier()->defaultRuleRows();
    foreach ($rows as &$row) {
        $row['parameters'] = ['edgePct' => 8.0, 'drawSignificantPct' => 30.0];
        if ($row['category_key'] === 'A') $row['label'] = 'HOME SIDE FAVOUR';
    }
    unset($row);
    $c = fx_fb_classifier([], $rows);

    $rules = $c->rules();
    assert_equals('STORED', $rules['source'], 'the rule set came from the stored rows');
    assert_equals(8.0, $rules['edgePct'], 'the stored edge replaces the configured default');
    assert_equals('HOME SIDE FAVOUR', $rules['labels']['A'], 'the stored label replaces the default label');

    assert_equals('B', $c->classify(['home' => 0.40, 'draw' => 0.25, 'away' => 0.35])['key'],
        'a 5-point margin is below the stored 8-point edge, so the match is balanced');
    assert_contains('HOME SIDE FAVOUR', strtoupper($c->label('A')), 'label() honours the stored rows too');
});

test('football: a broken or empty rule source falls back to the configured defaults', function () {
    $source = static function (): array { throw new RuntimeException('rule store down'); };
    $c = new CategoryClassifier(new FootballConfiguration(['WINDELS_FOOTBALL_CATEGORY_EDGE_PCT' => 12.0]), $source);
    $rules = $c->rules();
    assert_equals('DEFAULTS', $rules['source'], 'a throwing rule store degrades to the defaults, not to an error');
    assert_equals(12.0, $rules['edgePct'], 'the configured (admin-saved) edge still applies');
    assert_equals('A', $c->classify(['home' => 0.55, 'draw' => 0.25, 'away' => 0.20])['key'],
        'the engine still classifies with the fallback rules (35-point edge, draw below the line)');
});

test('football: configuration precedence is test-override > admin-saved > default', function () {
    assert_equals(5.0, (new FootballConfiguration([]))->categoryEdgePct(), 'built-in default edge is 5.0');
    assert_equals(30.0, (new FootballConfiguration([]))->categoryDrawSignificantPct(), 'built-in default draw line is 30.0');
    assert_true((new FootballConfiguration([]))->enabled(), 'the module is enabled by default');
    assert_true((new FootballConfiguration([]))->autoPredictEnabled(), 'automatic predictions are on by default');

    $saved = static function (string $key): ?string {
        return match ($key) {
            'WINDELS_FOOTBALL_CATEGORY_EDGE_PCT' => '7.5',
            'WINDELS_FOOTBALL_CATEGORY_DRAW_PCT' => '35',
            'WINDELS_FOOTBALL_ENABLED' => '0',
            'WINDELS_FOOTBALL_AUTO_PREDICT' => '0',
            default => null,
        };
    };
    $fromSaved = new FootballConfiguration([], $saved);
    assert_equals(7.5, $fromSaved->categoryEdgePct(), 'an admin-saved value beats the default');
    assert_equals(35.0, $fromSaved->categoryDrawSignificantPct(), 'an admin-saved draw line beats the default');
    assert_false($fromSaved->enabled(), 'an admin-saved 0 disables the module');
    assert_false($fromSaved->autoPredictEnabled(), 'an admin-saved 0 disables automatic predictions');

    $overrideWins = new FootballConfiguration(
        ['WINDELS_FOOTBALL_CATEGORY_EDGE_PCT' => 2.0, 'WINDELS_FOOTBALL_ENABLED' => true],
        $saved
    );
    assert_equals(2.0, $overrideWins->categoryEdgePct(), 'a per-case override beats the saved value');
    assert_true($overrideWins->enabled(), 'a per-case override beats the saved value for flags too');
});

test('football: the league scope is empty by default and matches provider|id, id, or name', function () {
    $config = new FootballConfiguration([]);
    assert_equals([], $config->leagueScope(), 'no scope configured means every stored league');
    assert_true($config->inLeagueScope('any', 'anything', 'any league'), 'an empty scope admits everything');

    $scoped = new FootballConfiguration(['WINDELS_FOOTBALL_LEAGUE_SCOPE' => json_encode(['fxprov|39', 'Premier League'])]);
    assert_equals(['fxprov|39', 'Premier League'], $scoped->leagueScope());
    assert_true($scoped->inLeagueScope('fxprov', '39', 'Premier League'), 'provider|externalId identity matches');
    assert_true($scoped->inLeagueScope('other', '39', 'Premier League'), 'the bare externalId matches regardless of provider');
    assert_true($scoped->inLeagueScope(null, '999', 'premier league'), 'the competition name matches case-insensitively');
    assert_false($scoped->inLeagueScope('fxprov', '77', 'Championship'), 'an out-of-scope league is refused');

    $invalid = new FootballConfiguration(['WINDELS_FOOTBALL_LEAGUE_SCOPE' => 'not json']);
    assert_equals([], $invalid->leagueScope(), 'an unparsable scope degrades to "all leagues", never to a crash');
});
