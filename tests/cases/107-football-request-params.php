<?php
/**
 * Football Intelligence — query-parameter reading (the honesty rule, one layer up).
 *
 * `RequestParams` exists because a coerced parameter is the same mistake as a
 * fabricated number: `?limit=abc` arriving as `limit=1`, or a typo'd `date`
 * silently becoming today, both produce a small plausible payload that the caller
 * cannot tell apart from the request they made. These cases pin the three allowed
 * behaviours — absent takes the default, unusable takes the default *and says so*,
 * out-of-range is clamped *and says so* — and that a mutation refuses rather than
 * substituting a day it will then be billed for.
 */
require_once TESTSPATH . 'football_support.php';


use AIWorkforce\Football\RequestParams;

test('football: an absent parameter takes the documented default without comment', function () {
    $notes = [];
    assert_equals(200, RequestParams::int([], 'limit', 200, 1, 500, $notes), 'absent limit is the default');
    assert_equals(200, RequestParams::int(['limit' => ''], 'limit', 200, 1, 500, $notes), 'an empty value is also absent');
    assert_equals(200, RequestParams::int(['limit' => null], 'limit', 200, 1, 500, $notes), 'as is an explicit null');
    assert_equals([], $notes, 'nothing was reinterpreted, so there is nothing to say');
    $today = gmdate('Y-m-d');
    $notes = [];
    assert_equals($today, RequestParams::date([], 'date', $today, $notes), 'absent date is today');
    assert_equals([], $notes, 'and again, no note');
});

test('football: an unusable parameter falls back and says so out loud', function () {
    $notes = [];
    assert_equals(200, RequestParams::int(['limit' => 'abc'], 'limit', 200, 1, 500, $notes));
    assert_equals(1, count($notes), 'a non-numeric limit is reported, not swallowed');
    assert_contains('not a whole number', $notes[0]);
    assert_contains('"abc"', $notes[0], 'and the note quotes what actually arrived');

    // Scientific and fractional forms are numbers to PHP but not to this endpoint:
    // 1e3 would have arrived as 1, i.e. a different request than the one made.
    $notes = [];
    assert_equals(200, RequestParams::int(['limit' => '1e3'], 'limit', 200, 1, 500, $notes), '1e3 is not accepted as 1000');
    assert_contains('not a whole number', $notes[0] ?? '');
    $notes = [];
    assert_equals(200, RequestParams::int(['limit' => '12.5'], 'limit', 200, 1, 500, $notes));
    assert_contains('not a whole number', $notes[0] ?? '');

    $notes = [];
    assert_equals(200, RequestParams::int(['limit' => ['5']], 'limit', 200, 1, 500, $notes), 'an array parameter is refused');
    assert_contains('[array]', $notes[0] ?? '', 'quoted as a shape, never as Array to string conversion');

    // Signed integers are accepted, so a negative page size is a range problem,
    // not a type problem.
    $notes = [];
    assert_equals(1, RequestParams::int(['limit' => '-25'], 'limit', 200, 1, 500, $notes));
    assert_contains('outside the allowed 1–500', $notes[0] ?? '');
    assert_equals(365, RequestParams::int(['days' => '900'], 'days', 30, 1, 365, $notes),
        'a too-long window clamps to the maximum — it is never quietly replaced by the default');
    assert_contains('; 365 was used', $notes[1] ?? $notes[0], 'and the clamp is stated');
});

test('football: a date is validated as a calendar date, not merely a shape', function () {
    $notes = [];
    assert_equals('2026-09-05', RequestParams::date(['date' => '2026-09-05'], 'date', '1970-01-01', $notes));
    assert_equals([], $notes, 'a real date passes silently');

    $notes = [];
    assert_equals('1970-01-01', RequestParams::date(['date' => '2026-13-01'], 'date', '1970-01-01', $notes), 'month 13 is refused');
    assert_contains('not a real calendar date', $notes[0] ?? '');

    $notes = [];
    assert_equals('1970-01-01', RequestParams::date(['date' => '2025-02-29'], 'date', '1970-01-01', $notes),
        'a non-leap 29 February is refused rather than rolled over to March');
    assert_contains('not a real calendar date', $notes[0] ?? '');
    $notes = [];
    assert_equals('2024-02-29', RequestParams::date(['date' => '2024-02-29'], 'date', '1970-01-01', $notes), 'the leap day that exists is accepted');

    $notes = [];
    assert_equals('1970-01-01', RequestParams::date(['date' => 'yesterday'], 'date', '1970-01-01', $notes), 'a relative word is not a date');
    assert_contains('not a YYYY-MM-DD date', $notes[0] ?? '', 'and the note distinguishes shape from calendar failure');

    // optionalDate is the same rule where "absent" must not become "today".
    $notes = [];
    assert_equals(null, RequestParams::optionalDate([], 'date', $notes), 'absent stays absent');
    assert_equals('2026-09-05', RequestParams::optionalDate(['date' => '2026-09-05'], 'date', $notes));
    assert_equals(null, RequestParams::optionalDate(['date' => '2026-02-30'], 'date', $notes), 'unreadable is dropped…');
    assert_contains('was ignored', $notes[0] ?? '', '…with a note, not silently narrowed');
});

test('football: a window bound is either readable or dropped with a note', function () {
    // Passing an unreadable `from` through to SQL would return no rows, which the
    // panel renders as an empty feed — indistinguishable from "nothing scheduled".
    $notes = [];
    assert_equals('2026-09-01', RequestParams::timestamp(['from' => '2026-09-01'], 'from', $notes));
    assert_equals([], $notes);
    $notes = [];
    assert_equals('2026-09-01T12:00:00+00:00', RequestParams::timestamp(['from' => '2026-09-01T12:00:00+00:00'], 'from', $notes),
        'a full ISO stamp is a legitimate bound');
    $notes = [];
    assert_equals(null, RequestParams::timestamp(['from' => 'last tuesday'], 'from', $notes), 'prose is refused');
    assert_contains('was ignored', $notes[0] ?? '');
    $notes = [];
    assert_equals(null, RequestParams::timestamp(['from' => '2026-99-99'], 'from', $notes), 'an impossible date is refused too');
    assert_contains('was ignored', $notes[0] ?? '');
    $notes = [];
    assert_equals(null, RequestParams::timestamp([], 'from', $notes), 'and no note is invented for a caller who sent nothing');
    assert_equals([], $notes);
});

test('football: a mutation refuses an unreadable date instead of picking another day', function () {
    // The two places that either spend provider quota (sync) or write prediction
    // rows (board rebuild) must not substitute today for a typo.
    assert_true(RequestParams::suppliedButInvalidDate(['date' => '2026-02-30']), 'an impossible date is flagged');
    assert_true(RequestParams::suppliedButInvalidDate(['date' => 'tomorrow']), 'as is a non-date');
    assert_false(RequestParams::suppliedButInvalidDate(['date' => '2026-09-05']), 'a real date is not');
    assert_false(RequestParams::suppliedButInvalidDate([]), 'an omitted date means today, which is a documented default');
    assert_false(RequestParams::suppliedButInvalidDate(['date' => '']), 'and an empty field counts as omitted');

    foreach ([
        'application/controllers/Football.php' => ['suppliedButInvalidDate', 'No provider request was made', 'no other day will be predicted in its place'],
        'application/controllers/Api_football.php' => ['suppliedButInvalidDate', 'no provider request was made'],
    ] as $file => $needles) {
        $source = fx_fb_read($file);
        foreach ($needles as $needle) {
            assert_true(str_contains((string) $source, $needle), $file . ' refuses with "' . $needle . '"');
        }
    }
});

test('football: the read endpoints route their parameters through the one rule', function () {
    // One place decides what a valid `limit`/`days`/`date` is; an endpoint that
    // hand-rolls `(int) $value` drifts back to silent coercion within a commit.
    $source = fx_fb_read('application/controllers/Api_football.php');
    foreach (['limit', 'days', 'modelVersionId'] as $param) {
        assert_true(str_contains($source, "RequestParams::int(\$g, '" . $param . "'"), $param . ' is read through RequestParams');
    }
    assert_true(str_contains($source, "RequestParams::date(\$g, 'date'"), 'the dashboard and board dates too');
    assert_true(str_contains($source, "RequestParams::optionalDate(\$g, 'date'"), 'and the fixtures window keeps absent distinct from today');
    assert_equals(0, preg_match_all('/\(\s*int\s*\)\s*\(\s*\$g\[/', $source), 'no endpoint casts a query value by hand any more');
});

test('football: the notes reach the response, not just the logs', function () {
    // A fallback the caller cannot see is the exact behaviour this class exists to
    // remove, so every parameterised endpoint has to echo what it did.
    $source = fx_fb_read('application/controllers/Api_football.php');
    $echoed = substr_count($source, "'notes' => array_values(\$notes)");
    assert_true($echoed >= 6, 'every parameterised endpoint echoes its notes (found ' . $echoed . ' of 7)');
    assert_contains("'filter' => \$filter", $source, 'and the fixtures endpoint states which window it actually queried');

    $console = fx_fb_read('application/controllers/Football.php');
    assert_contains('RequestParams::date($get', $console, 'the console reads its date the same way');
    assert_contains("if (\$notes !== []) \$data['notice']", $console, 'and renders the note on the page');
    assert_contains("\$data['date'] = \$date", $console, 'while still showing the day it settled on');
});
