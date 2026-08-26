<?php
/**
 * AI LANGUAGE TEACHER — natural-language routing over stored learner state.
 */
use AIWorkforce\LangLearn\TeacherCoach;

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
if (!function_exists('ll_run_assessment')) {
    function ll_run_assessment(\AIWorkforce\LangLearn\LangLearnService $svc, int $userId, int $profileId, callable $pickOption): array
    {
        $res = $svc->startAssessment($userId, $profileId);
        $guard = 0;
        while (($res['status'] ?? '') === 'IN_PROGRESS' && $guard++ < 60) {
            $item = $res['item'];
            $res = $svc->answerAssessment($res['assessmentId'], $userId, $pickOption($item));
        }
        assert_equals('COMPLETED', $res['status'], 'assessment must terminate');
        return $res['result'];
    }
}

test('coach parse: teach / assess / converse / correct / test phrases', function () {
    $teach = TeacherCoach::parse('Teach me Dutch from the beginning.');
    assert_equals('learn', $teach['intent']);
    assert_equals('nl', $teach['languageCode']);
    assert_equals(TeacherCoach::GOALS['beginner'], $teach['goal']);

    $learn = TeacherCoach::parse('I want to learn Spanish.');
    assert_equals('learn', $learn['intent']);
    assert_equals('es', $learn['languageCode']);

    $conv = TeacherCoach::parse('Practice Italian conversation with me.');
    assert_equals('converse', $conv['intent']);
    assert_equals('it', $conv['languageCode']);

    $fix = TeacherCoach::parse('Correct my German.');
    assert_equals('correct', $fix['intent']);
    assert_equals('de', $fix['languageCode']);

    $test = TeacherCoach::parse('Test my French level.');
    assert_equals('assess', $test['intent']);
    assert_equals('fr', $test['languageCode']);

    assert_equals('ja', TeacherCoach::parse('I want to learn Japanese')['languageCode']);
    assert_equals('ar', TeacherCoach::parse('Teach me Arabic')['languageCode']);
    assert_null(TeacherCoach::parse('hello there')['languageCode']);
});

test('coach interpret: teach me Dutch creates a profile and asks for a goal or assessment', function () {
    $t = platform();
    $u = ll_user('coach-nl');
    $out = $t->langcoach->interpret($u['id'], 'Teach me Dutch from the beginning.');
    assert_equals('learn', $out['intent']);
    assert_equals('nl', $out['languageCode']);
    assert_equals('Dutch', $out['languageName']);
    assert_not_null($out['profile']);
    assert_equals('From the beginning', $out['profile']['goal']);
    assert_true(in_array($out['nextStep'], ['assess', 'set_goal'], true));
    assert_contains('Dutch', $out['reply']);
    assert_true(count($out['actions']) >= 1);

    $again = $t->langcoach->interpret($u['id'], 'I want to learn Dutch.');
    assert_equals($out['profile']['id'], $again['profile']['id'], 'one profile per language');
});

test('coach interpret: test my French level routes to assessment', function () {
    $t = platform();
    $u = ll_user('coach-fr');
    $out = $t->langcoach->interpret($u['id'], 'Test my French level.');
    assert_equals('assess', $out['intent']);
    assert_equals('fr', $out['languageCode']);
    assert_equals('assess', $out['nextStep']);
    assert_contains('level', mb_strtolower($out['reply']));
    assert_contains('assessment/start', $out['actions'][0]['href']);
});

test('coach interpret: conversation / writing / grammar / vocabulary', function () {
    $t = platform();
    $u = ll_user('coach-modes');
    $conv = $t->langcoach->interpret($u['id'], 'Practice Italian conversation with me.');
    assert_equals('converse', $conv['intent']);
    assert_equals('conversation', $conv['nextStep']);
    assert_contains('/app/languages/conv/', $conv['actions'][0]['href']);

    $write = $t->langcoach->interpret($u['id'], 'Correct my German.');
    assert_equals('correct', $write['intent']);
    assert_equals('writing', $write['nextStep']);

    $gram = $t->langcoach->interpret($u['id'], 'Explain Portuguese grammar.');
    assert_equals('grammar', $gram['intent']);
    assert_equals('grammar', $gram['nextStep']);

    $vocab = $t->langcoach->interpret($u['id'], 'Learn Swahili vocabulary.');
    assert_equals('vocabulary', $vocab['intent']);
    assert_equals('vocabulary', $vocab['nextStep']);
});

test('coach interpret: unknown language is refused honestly', function () {
    $t = platform();
    $u = ll_user('coach-unk');
    $out = $t->langcoach->interpret($u['id'], 'Teach me Klingon.');
    assert_equals('unknown', $out['intent']);
    assert_equals('choose_language', $out['nextStep']);
    assert_null($out['languageCode']);
    assert_contains('catalog', mb_strtolower($out['reply']));
    assert_equals(0, count($t->langlearn->profiles($u['id'])), 'no fake language profile created');
});

test('coach interpret: isolation — cannot attach another user profile', function () {
    $t = platform();
    $a = ll_user('coach-iso-a');
    $b = ll_user('coach-iso-b');
    $pa = $t->langlearn->startLanguage($a['id'], 'nl', 'Travel');
    assert_throws(RuntimeException::class, fn() => $t->langcoach->interpret($b['id'], 'Test my Dutch level.', (int) $pa['id']));
});

test('coach then assessment then path is the real learning cycle', function () {
    $t = platform();
    $u = ll_user('coach-cycle');
    $ask = $t->langcoach->interpret($u['id'], 'Teach me Spanish from the beginning.');
    assert_equals('es', $ask['languageCode']);
    $pid = (int) $ask['profile']['id'];
    assert_equals('From the beginning', $ask['profile']['goal']);
    $result = ll_run_assessment($t->langlearn, $u['id'], $pid, fn($item) => \AIWorkforce\LangLearn\ItemBanks::find('es', $item['id'])['answer']);
    assert_true(\AIWorkforce\LangLearn\LanguageRegistry::levelIndex($result['overallLevel']) >= 1);
    $path = $t->langlearn->pathFor($u['id'], $pid);
    assert_true($path['path'] !== null);
    $cont = $t->langcoach->interpret($u['id'], 'Teach me Spanish.', $pid);
    assert_equals('learn', $cont['nextStep']);
    $progress = $t->langlearn->progressFor($t->langlearn->profileOwned($pid, $u['id']));
    assert_equals('assessment', $progress['levelSource']);
    assert_true($progress['onboarding']['hasPath']);
});
