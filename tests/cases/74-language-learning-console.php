<?php
/**
 * AI Language Learning console: pages, routes and the full CHOOSE → ASSESS
 * → PATH → LESSON → PRACTICE cycle. No placeholder controllers.
 */

if (!function_exists('ll_user')) {
    function ll_user(string $tag): array
    {
        $p = platform();
        $email = "ll-{$tag}-" . uniqid() . '@example.com';
        $now = gmdate('c');
        $user = $p->model->identity->createUser(['email' => $email, 'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT), 'display_name' => "LL {$tag}", 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        return ['id' => (int) $user['id'], 'email' => $email];
    }
}

test('language console routes cover the learning cycle', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    foreach ([
        "\$route['app/languages'] = 'lang_learn';",
        "\$route['app/languages/teacher'] = 'lang_learn/teacher';",
        "\$route['app/languages/teacher/ask'] = 'lang_learn/teacher_ask';",
        "\$route['app/languages/begin'] = 'lang_learn/begin';",
        "\$route['api/v1/language-learning/languages'] = 'api_lang_learning/languages';",
        "\$route['api/v1/language-learning/profiles'] = 'api_lang_learning/profiles';",
        "\$route['app/languages/w/(:num)'] = 'lang_learn/writing/\$1';",
        "\$route['app/languages/g/(:num)'] = 'lang_learn/grammar/\$1';",
        "\$route['app/languages/c/(:any)'] = 'lang_learn/conversation_show/\$1';",
        "\$route['app/languages/c/(:any)/say'] = 'lang_learn/conversation_say/\$1';",
    ] as $need) {
        assert_contains($need, $routes);
    }
});

test('language console controller implements writing, grammar and conversation pages', function () {
    $src = file_get_contents(FCPATH . 'application/controllers/Lang_learn.php');
    foreach (['function writing(', 'function writing_submit(', 'function grammar(', 'function teacher_ask(', 'function conversation_show(', 'function conversation_say(', 'function lesson(', 'function daily_plan('] as $fn) {
        assert_contains($fn, $src, $fn);
    }
    assert_false(str_contains($src, 'langteacher->moduleOwned'), 'lessons use langlearn->moduleOwned, not a missing teacher method');
    assert_contains("redirect('/app/languages/c/' . \$result['sessionId'])", $src);
    assert_contains("langlearn/conversation_run", $src);
    assert_contains("langlearn/writing", $src);
    assert_contains("langlearn/grammar", $src);
});

test('language console views are real pages, not placeholders', function () {
    $index = file_get_contents(FCPATH . 'application/views/langlearn/index.php');
    assert_contains('My languages', $index);
    assert_contains('/app/languages/teacher/ask', $index);
    assert_contains('/app/languages/begin?code=', $index);
    assert_false(str_contains($index, 'action="/app/languages/start"'), 'catalog Learn goes through the goal form');

    $profile = file_get_contents(FCPATH . 'application/views/langlearn/profile.php');
    foreach (['Continue Learning', 'Start AI Lesson', 'AI Conversation', 'Speaking Practice', 'Listening Practice', 'Vocabulary', 'Grammar', 'Writing Practice', 'Daily Plan'] as $label) {
        assert_contains($label, $profile);
    }

    $lesson = file_get_contents(FCPATH . 'application/views/langlearn/lesson.php');
    assert_contains('$lesson ?? $lessonView', $lesson);
    assert_contains('Practice (pass', $lesson);

    $writing = file_get_contents(FCPATH . 'application/views/langlearn/writing.php');
    assert_contains('<b>Original:</b>', $writing);
    assert_contains('correctedVersion', $writing);
    assert_contains('nativeVersion', $writing);
    assert_contains('original_text', $writing);
    assert_contains('/app/languages/w/', $writing);

    $grammar = file_get_contents(FCPATH . 'application/views/langlearn/grammar.php');
    assert_contains('explain it more simply', $grammar);

    $conv = file_get_contents(FCPATH . 'application/views/langlearn/conversation.php');
    assert_contains('Correct only important mistakes', $conv);
    assert_contains('Conversation only', $conv);
});

test('conversation page state keeps last feedback after a turn', function () {
    $t = platform();
    $u = ll_user('console-conv');
    $profile = $t->langlearn->startLanguage($u['id'], 'es');
    $s = $t->langteacher->startConversation($u['id'], (int) $profile['id'], 'first-meeting', 'immediate');
    $t->langteacher->conversationTurn($s['sessionId'], $u['id'], 'bonjour je suis ici');
    $view = $t->langteacher->conversationStateForPage($s['sessionId'], $u['id']);
    assert_equals('ACTIVE', $view['status']);
    assert_false($view['lastFeedback']['ok']);
    assert_not_null($view['lastFeedback']['expected']);
    assert_true(count($view['history']) >= 1);
});

test('full learning cycle: choose language, assess, path, lesson, writing, daily plan', function () {
    $t = platform();
    $u = ll_user('console-cycle');
    $coach = $t->langcoach->interpret($u['id'], 'Teach me Dutch from the beginning.');
    assert_equals('nl', $coach['languageCode']);
    $pid = (int) $coach['profile']['id'];

    $res = $t->langlearn->startAssessment($u['id'], $pid);
    $guard = 0;
    while (($res['status'] ?? '') === 'IN_PROGRESS' && $guard++ < 60) {
        $item = $res['item'];
        $res = $t->langlearn->answerAssessment($res['assessmentId'], $u['id'], \AIWorkforce\LangLearn\ItemBanks::find('nl', $item['id'])['answer']);
    }
    assert_equals('COMPLETED', $res['status']);
    assert_true(\AIWorkforce\LangLearn\LanguageRegistry::levelIndex($res['result']['overallLevel']) >= 1);

    $path = $t->langlearn->pathFor($u['id'], $pid);
    assert_not_null($path['path']);
    $moduleId = $path['modules'][0]['id'];
    $lesson = $t->langteacher->startLesson($moduleId, $u['id']);
    assert_true(count($lesson['lesson']['practiceItems']) >= 2);
    $good = [];
    foreach ($lesson['lesson']['practiceItems'] as $item) {
        $good[$item['id']] = \AIWorkforce\LangLearn\ItemBanks::find('nl', $item['id'])['answer'];
    }
    $graded = $t->langteacher->submitLesson($moduleId, $u['id'], $good);
    assert_true($graded['passed']);

    $write = $t->langteacher->submitWriting($u['id'], $pid, 'self-introduction', 'Hallo! Ik heet Nora.');
    assert_equals('Hallo! Ik heet Nora.', $write['attempt']['feedback']['originalText']);
    assert_true($write['attempt']['feedback']['correctedVersion'] !== '');

    $plan = $t->adaptive->dailyPlan($u['id'], $pid);
    assert_true(count($plan['blocks']) >= 1);
    $progress = $t->langlearn->progressFor($t->langlearn->profileOwned($pid, $u['id']));
    assert_equals('assessment', $progress['levelSource']);
    assert_true($progress['pathCompletionPct'] > 0);
    assert_equals(1, $progress['studyStreakDays']);
});
