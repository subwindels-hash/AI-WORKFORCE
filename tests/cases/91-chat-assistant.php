<?php
/**
 * WINDELS Assistant local guide must answer from the product map,
 * not repeat a single module-list sentence.
 */

test('chat assistant never repeats the old generic module list', function () {
    $a = new AIWorkforce\ChatAssistant();
    $banned = 'I can guide you through the AI Language Teacher, Languages, Dashboard, Lead Discovery, Pipeline, Sports, EuroMillions, Trading and Account. Ask about any area.';
    foreach ([
        'hello',
        'what is this platform',
        'how does trading work',
        'tell me about euromillions',
        'where is multiplier ai',
        'asdfzxcv unknown',
    ] as $q) {
        $out = $a->respond($q);
        assert_equals('local-guide', $out['provider']);
        assert_false($out['message'] === $banned, 'generic list for: ' . $q);
        assert_false(str_contains($out['message'], 'Ask about any area.'), $q);
    }
});

test('chat assistant maps modules to real paths and honesty rules', function () {
    $a = new AIWorkforce\ChatAssistant();
    $cases = [
        ['How do I start language learning?', '/app/languages', 'Pronunciation'],
        ['Where is Windels AI Agents?', '/app/workforce', 'brokers'],
        ['Explain EuroMillions', '/lottery', 'historical observations'],
        ['Multiplier live data', '/multiplier', 'NO_DATA'],
        ['Lead discovery search', '/leads', 'fake businesses'],
        ['paper trading', '/paper', 'simulation'],
        ['sports fixtures', '/sports', 'no invented matches'],
        ['what is windels ai workforce', '/dashboard', 'kill switch'],
        ['admin login please', 'member sign-in', 'URL'],
    ];
    foreach ($cases as [$q, $must, $also]) {
        $msg = $a->localAnswer($q);
        assert_contains($must, $msg, $q);
        assert_contains($also, $msg, $q . ' extra');
    }
});
