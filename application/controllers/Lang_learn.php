<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * AI Language Learning console (server-rendered pages, PRG flows).
 * Per-user data: everything requires a signed-in session identity.
 */
class Lang_learn extends App_Controller
{
    public function index()
    {
        $data = $this->base('Language Learning');
        $user = $this->sessionUser();
        $data['user'] = $user;
        $data['languages'] = $this->platform->langlearn->languages();
        $data['catalogPreview'] = $this->platform->langlearn->searchCatalog('', 30);
        $data['catalogTotal'] = $this->platform->langlearn->catalogCount();
        $data['myProfiles'] = $user ? $this->platform->langlearn->profiles((int) $user['id']) : [];
        $this->render($data, 'langlearn/index');
    }

    /** AI Teacher — instant translate + listen + speak flow on a single page. */
    public function teacher()
    {
        $user = $this->requireUser();
        $data = $this->base('AI Language Teacher');
        $data['active'] = 'teacher';
        $featured = $this->platform->langlearn->searchCatalog('', 80);
        $must = [];
        foreach (['nl', 'en', 'fr', 'de', 'es', 'it', 'ja'] as $code) {
            $hit = \AIWorkforce\LangLearn\LanguageCatalog::get($code);
            if ($hit) $must[$hit['code']] = $hit;
        }
        foreach ($featured as $row) $must[$row['code']] = $row;
        $data['languages'] = array_values($must);
        $data['catalogTotal'] = $this->platform->langlearn->catalogCount();
        $data['csrfToken'] = (string) $this->session->userdata('csrf_token');
        $data['locales'] = array_merge(\AIWorkforce\LangLearn\Translator::LOCALES, \AIWorkforce\LangLearn\LanguageCatalog::VOICE_LOCALES);
        // The user's real learning profile(s) so the "Continue studying"
        // links point somewhere useful instead of a hard-coded profile id.
        try { $data['myProfiles'] = $this->platform->langlearn->profiles((int) $user['id']); }
        catch (Throwable $e) { $data['myProfiles'] = []; }
        // Determine the user's active learning language and native language
        // from their profile so the teacher has proper context.
        $data['learningLanguage'] = null;
        $data['nativeLanguage'] = 'en';
        $data['proficiencyLevel'] = 'Beginner';
        if (!empty($data['myProfiles'])) {
            foreach ($data['myProfiles'] as $profile) {
                $lc = $profile['language_code'] ?? null;
                if ($lc) {
                    $data['learningLanguage'] = $lc;
                    $data['nativeLanguage'] = $profile['explanation_language'] ?? 'en';
                    $data['proficiencyLevel'] = $profile['level'] ?? 'Beginner';
                    break;
                }
            }
        }
        // Each example pins an explicit source→target pair so learners see
        // both directions working (e.g. Dutch→English and English→Dutch).
        // These are overridden by the user's actual languages when available.
        $data['examplePairs'] = [
            ['text' => 'Good morning, how are you?', 'source' => 'en', 'target' => 'nl'],
            ['text' => 'Goedemorgen, hoe gaat het?', 'source' => 'nl', 'target' => 'en'],
            ['text' => 'Hello', 'source' => 'en', 'target' => 'fr'],
            ['text' => 'Hallo', 'source' => 'de', 'target' => 'en'],
            ['text' => 'Gracias', 'source' => 'es', 'target' => 'en'],
            ['text' => 'Thank you very much', 'source' => 'en', 'target' => 'de'],
        ];
        // If we have a user profile, adapt examplePairs to use their languages
        if (!empty($data['myProfiles']) && $data['learningLanguage']) {
            $learnLang = $data['learningLanguage'];
            $nativeLang = $data['nativeLanguage'];
            // Replace with pairs that involve the user's actual languages
            $data['examplePairs'] = [
                ['text' => 'Good morning, how are you?', 'source' => $nativeLang, 'target' => $learnLang],
                ['text' => ucfirst($learnLang) . ': Goedemorgen, hoe gaat het?', 'source' => $learnLang, 'target' => $nativeLang],
            ];
            // Filter out empty ones
            $data['examplePairs'] = array_filter($data['examplePairs'], fn($p) => !empty($p['text']));
        }
        $this->render($data, 'langlearn/teacher');
    }

    public function login()
    {
        $email = strtolower(trim((string) $this->input->post('email')));
        $password = (string) $this->input->post('password');
        $user = $this->platform->identity->authenticate($email, $password);
        if (!$user) {
            $this->session->set_flashdata('llError', 'Invalid credentials.');
            return redirect('/app/languages');
        }
        $this->session->set_userdata('identity', $user);
        $this->session->set_flashdata('llNotice', 'Welcome back, ' . ($user['display_name'] ?: $user['email']) . '!');
        redirect('/app/languages');
    }

    public function begin()
    {
        $user = $this->requireUser();
        $code = strtolower(trim((string) ($this->input->get('code') ?: $this->input->post('code'))));
        $data = $this->base('Start learning');
        try { $data['language'] = $this->platform->langlearn->language($code); }
        catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            return redirect('/app/languages');
        }
        $data['goals'] = \AIWorkforce\LangLearn\TeacherCoach::goalOptions();
        $data['explanationLanguages'] = $this->platform->langlearn->searchCatalog('', 40);
        $existing = $this->platform->model->langlearn->findProfileByUserLanguage((int) $user['id'], $code);
        $data['existing'] = $existing;
        $this->render($data, 'langlearn/begin');
    }

    public function start()
    {
        $user = $this->requireUser();
        try {
            $minutes = $this->input->post('dailyMinutes');
            $profile = $this->platform->langlearn->startLanguage(
                (int) $user['id'],
                (string) $this->input->post('code'),
                (string) $this->input->post('goal'),
                (string) ($this->input->post('explanationLanguage') ?: 'en'),
                $minutes !== null && $minutes !== '' ? (int) $minutes : null
            );
            $this->session->set_flashdata('llNotice', 'Added to My Languages. Take the AI level assessment so we can set your starting level from real answers.');
            redirect('/app/languages/p/' . $profile['id']);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages');
        }
    }

    public function save_goal(int $profileId)
    {
        $user = $this->requireUser();
        try {
            $this->platform->langlearn->updateProfile((int) $user['id'], $profileId, [
                'goal' => (string) $this->input->post('goal'),
                'explanationLanguage' => (string) ($this->input->post('explanationLanguage') ?: 'en'),
                'dailyMinutes' => (int) ($this->input->post('dailyMinutes') ?: 20),
            ]);
            $this->session->set_flashdata('llNotice', 'Goal saved. Next: take the level assessment.');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/p/' . $profileId);
    }

    public function teacher_ask()
    {
        $user = $this->requireUser();
        $message = trim((string) $this->input->post('message'));
        try {
            $coach = $this->platform->langcoach->interpret((int) $user['id'], $message);
            $this->session->set_flashdata('llNotice', $coach['reply']);
            $href = $coach['actions'][0]['href'] ?? '/app/languages';
            $method = strtoupper($coach['actions'][0]['method'] ?? 'GET');
            if ($method === 'POST' && !empty($coach['profile']['id'])) {
                // POST actions (start assessment / generate path) stay on a GET page
                // that already has the form — send the learner to the profile.
                redirect('/app/languages/p/' . (int) $coach['profile']['id']);
                return;
            }
            redirect($href);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/teacher');
        }
    }

    public function profile(int $id)
    {
        $user = $this->requireUser();
        $data = $this->base('Language');
        $profile = $this->platform->langlearn->profileOwned($id, (int) $user['id']);
        $data['profile'] = $profile;
        $data['language'] = \AIWorkforce\LangLearn\LanguageRegistry::get($profile['language_code']);
        $data['progress'] = $this->platform->langlearn->progressFor($profile);
        $data['path'] = $this->platform->langlearn->pathFor((int) $user['id'], $id);
        $data['latest'] = $this->platform->model->langlearn->latestCompletedAssessment($id);
        $this->render($data, 'langlearn/profile');
    }

    public function start_assessment(int $profileId)
    {
        $user = $this->requireUser();
        try {
            $res = $this->platform->langlearn->startAssessment((int) $user['id'], $profileId);
            redirect('/app/languages/a/' . $res['assessmentId']);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/p/' . $profileId);
        }
    }

    public function assessment(string $id)
    {
        $user = $this->requireUser();
        $data = $this->base('Assessment');
        $a = $this->platform->langlearn->assessmentOwned($id, (int) $user['id']);
        $data['assessment'] = $a;
        $data['language'] = \AIWorkforce\LangLearn\LanguageRegistry::get($a['language_code']);
        $this->render($data, 'langlearn/assessment');
    }

    public function answer(string $assessmentId)
    {
        $user = $this->requireUser();
        try {
            $this->platform->langlearn->answerAssessment($assessmentId, (int) $user['id'], (int) $this->input->post('answerIndex'));
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/a/' . $assessmentId);
    }

    public function generate_path(int $profileId)
    {
        $user = $this->requireUser();
        try {
            $this->platform->langlearn->generatePath((int) $user['id'], $profileId);
            $this->session->set_flashdata('llNotice', 'Personalized learning path generated from your level.');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/p/' . $profileId);
    }

    public function checkpoint(string $moduleId)
    {
        $user = $this->requireUser();
        $data = $this->base('Module checkpoint');
        try {
            $data['checkpoint'] = $this->platform->langlearn->startCheckpoint($moduleId, (int) $user['id']);
            $data['error'] = null;
        } catch (Throwable $e) {
            $data['checkpoint'] = null;
            $data['error'] = $e->getMessage();
        }
        $this->render($data, 'langlearn/checkpoint');
    }

    public function answer_checkpoint(string $moduleId)
    {
        $user = $this->requireUser();
        $answers = $this->input->post('answers');

        // Normalise answers to an associative array keyed on item id
        $normalised = [];
        if (is_array($answers)) {
            foreach ($answers as $itemId => $index) {
                $normalised[(string) $itemId] = (int) $index;
            }
        }

        try {
            $this->platform->langlearn->submitCheckpoint($moduleId, (int) $user['id'], $normalised);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/checkpoint/' . $moduleId);
    }

    public function save_goal_legacy(int $profileId)
    {
        $user = $this->requireUser();
        try {
            $this->platform->langlearn->updateProfile((int) $user['id'], $profileId, [
                'goal' => (string) $this->input->post('goal'),
                'explanationLanguage' => (string) ($this->input->post('explanationLanguage') ?: 'en'),
                'dailyMinutes' => (int) ($this->input->post('dailyMinutes') ?: 20),
            ]);
            $this->session->set_flashdata('llNotice', 'Goal saved. Next: take the level assessment.');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/p/' . $profileId);
    }

    public function assessment_start(string $assessmentId)
    {
        // Alias for start_assessment
        $user = $this->requireUser();
        try {
            $res = $this->platform->langlearn->startAssessment((int) $user['id'], (int) strval($assessmentId));
            redirect('/app/languages/a/' . $res['assessmentId']);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/p/' . $profileId);
        }
    }

    public function checkpoint_go(string $moduleId)
    {
        // Alias for checkpoint
        $user = $this->requireUser();
        $data = $this->base('Module checkpoint');
        try {
            $data['checkpoint'] = $this->platform->langlearn->startCheckpoint($moduleId, (int) $user['id']);
            $data['error'] = null;
        } catch (Throwable $e) {
            $data['checkpoint'] = null;
            $data['error'] = $e->getMessage();
        }
        $this->render($data, 'langlearn/checkpoint');
    }

    public function checkpoint_answer(string $moduleId)
    {
        // Alias for answer_checkpoint
        $user = $this->requireUser();
        $answers = $this->input->post('answers');
        $normalised = [];
        if (is_array($answers)) {
            foreach ($answers as $itemId => $index) {
                $normalised[(string) $itemId] = (int) $index;
            }
        }
        try {
            $this->platform->langlearn->submitCheckpoint($moduleId, (int) $user['id'], $normalised);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/checkpoint/' . $moduleId);
    }

    public function path_generate(int $profileId)
    {
        // Alias for generate_path
        $user = $this->requireUser();
        try {
            $this->platform->langlearn->generatePath((int) $user['id'], $profileId);
            $this->session->set_flashdata('llNotice', 'Personalized learning path generated from your level.');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/p/' . $profileId);
    }

    public function grammar_simple(int $profileId, string $ruleId)
    {
        $user = $this->requireUser();
        try {
            $s = $this->platform->langteacher->explainSimply((int) $user['id'], $profileId, $ruleId);
            $this->session->set_flashdata('llNotice', 'Simpler: ' . $s['simple']['rule'] . ' — ' . $s['simple']['correctExample']);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/g/' . $profileId);
    }

    public function history(int $profileId)
    {
        $user = $this->requireUser();
        $data = $this->base('History');
        try { $data['history'] = $this->platform->langteacher->history((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['history'] = ['attempts' => [], 'conversations' => [], 'writing' => []]; }
$this->render($data, 'langlearn/history');
    }

    // ================= PHASE 2: STRUCTURED LESSON PAGES =================

    public function lesson(string $moduleId)
    {
        $user = $this->requireUser();
        try {
            $module = $this->platform->langteacher->moduleOwned($moduleId, (int) $user['id']);
            $lesson = $this->platform->langteacher->startLesson($moduleId, (int) $user['id']);
            $data = $this->base('Lesson');
            $data['module'] = $module;
            $data['lesson'] = $lesson;
            $this->render($data, 'langlearn/lesson');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/p/' . $user['id']);
        }
    }

    public function lesson_answer(string $moduleId)
    {
        $user = $this->requireUser();
        $answers = $this->input->post('answers');
        try {
            $result = $this->platform->langteacher->submitLesson($moduleId, (int) $user['id'], $answers ?? []);
            $this->session->set_flashdata('llNotice', sprintf('Practice graded — %s %d%%', $result['passed'] ? 'passed' : 'not passed', $result['scorePct']));
            // Module completion and next module unlock are handled by submitCheckpoint()
            // $result['moduleStatus'] will be 'COMPLETED' when passed >= 75%
            if ($result['passed']) {
                // Module is now COMPLETED; redirect to daily plan or profile
                // The next module will be available in the learning path
                redirect('/app/languages/p/' . $user['id']);
            }
            redirect('/app/languages/m/' . $moduleId . '/lesson');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/m/' . $moduleId . '/lesson');
        }
    }

    // ================= CONVERSATION PAGES =================

    public function conversation(int $profileId)
    {
        $user = $this->requireUser();
        // Verify the profile belongs to the user
        $profile = $this->platform->langlearn->profileOwned($profileId, (int) $user['id']);
        $data = $this->base('Conversation');
        $data['profileId'] = $profileId;
        $data['language'] = \AIWorkforce\LangLearn\LanguageRegistry::get($profile['language_code']);
        // Load conversation scenarios appropriate for the user's level
        try {
            $scenarios = $this->platform->langteacher->conversations((int) $user['id'], $profileId);
            $data['scenarios'] = $scenarios;
        } catch (Throwable $e) {
            $data['scenarios'] = ['code' => 'greeting', 'title' => 'Greetings', 'turns' => 3];
        }
        $this->render($data, 'langlearn/conversation');
    }

    public function conversation_start(int $profileId)
    {
        $user = $this->requireUser();
        $scenario = (string) $this->input->post('scenario');
        $correction = (string) ($this->input->post('correction') ?: 'important');
        try {
            $result = $this->platform->langteacher->startConversation((int) $user['id'], $profileId, $scenario, $correction);
            $this->session->set_flashdata('llNotice', 'Conversation started: ' . ($result['scenario'] ?? $scenario));
            // Redirect to the conversation page to continue
            redirect('/app/languages/conv/' . $profileId);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/conv/' . $profileId);
        }
    }

    public function conversation_turn(string $sessionId)
    {
        $user = $this->requireUser();
        $body = $this->jsonBody();
        if (!isset($body['text']) || !is_string($body['text'])) {
            $this->jsonError('body must be {text: string}');
            return;
        }
        try {
            $result = $this->platform->langteacher->conversationTurn($sessionId, (int) $user['id'], $body['text']);
            $this->json($result);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    public function conversation_show(string $sessionId)
    {
        $user = $this->requireUser();
        try {
            $session = $this->platform->langteacher->findConversation($sessionId);
            if (!$session || (int) $session['user_id'] !== (int) $user['id']) {
                throw new \RuntimeException('conversation not found', 404);
            }
            $data = $this->base('Conversation session');
            $data['session'] = $session;
            $this->render($data, 'langlearn/conversation_show');
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages/conv/' . (int) $user['id']);
        }
    }

    public function conversation_say(string $sessionId)
    {
        $user = $this->requireUser();
        $body = $this->jsonBody();
        if (!isset($body['text']) || !is_string($body['text'])) {
            return $this->jsonError('body must be {text: string}');
        }
        try {
            $result = $this->platform->langteacher->conversationTurn($sessionId, (int) $user['id'], $body['text']);
            $this->json($result);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage());
        }
    }

    public function conversation_go(int $profileId)
    {
        // Alias for conversation_start
        $this->conversation_start($profileId);
    }

    // ================= PHASE 3: VOCABULARY PAGES =================

    public function vocabulary(int $profileId)
    {
        $user = $this->requireUser();
        $data = $this->base('Vocabulary');
        try { $data['catalog'] = $this->platform->vocabulary->catalog((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['catalog'] = []; }
        try { $data['progress'] = $this->platform->vocabulary->progress((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['progress'] = null; }
        try { $data['dueCount'] = count($this->platform->vocabulary->due((int) $user['id'], $profileId)); }
        catch (Throwable $e) { $data['dueCount'] = 0; }
        $data['profileId'] = $profileId;
        $profile = $this->platform->model->langlearn->findProfile($profileId);
        $data['langCode'] = $profile['language_code'] ?? 'en';
        $data['locale'] = \AIWorkforce\LangLearn\Translator::LOCALES[$data['langCode']] ?? 'en-GB';
        $this->render($data, 'langlearn/vocabulary');
    }

    public function vocabulary_add(int $profileId)
    {
        $user = $this->requireUser();
        $ids = $this->input->post('vocabularyIds');
        try {
            $res = $this->platform->vocabulary->addWords((int) $user['id'], $profileId, is_array($ids) ? $ids : [], $this->input->post('starter') === '1');
            $this->session->set_flashdata('llNotice', "Added {$res['added']} word(s) — {$res['totalInList']} in your list.");
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/v/' . $profileId);
    }

    public function vocab_review(int $profileId, string $mode)
    {
        $user = $this->requireUser();
        $data = $this->base('Review');
        try { $data['review'] = $this->platform->vocabulary->startReview((int) $user['id'], $profileId, $mode); }
        catch (Throwable $e) { $data['review'] = ['mode' => $mode, 'cards' => [], 'note' => $e->getMessage()]; }
        $data['profileId'] = $profileId;
        $this->render($data, 'langlearn/vocab_review');
    }

    public function vocab_submit(int $profileId, string $mode)
    {
        $user = $this->requireUser();
        $answers = $this->input->post('answers');
        try {
            $res = $this->platform->vocabulary->submitReview((int) $user['id'], $profileId, $mode, is_array($answers) ? $answers : []);
            $this->session->set_flashdata('llNotice', sprintf('Review done — %d/%d correct. Next review per the spaced schedule.', $res['correct'], $res['total']));
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/v/' . $profileId);
    }

    // ================= PHASE 4: LISTENING + SPEAKING PAGES =================

    public function listening(int $profileId)
    {
        $user = $this->requireUser();
        $data = $this->base('Listening practice');
        try { $data['listening'] = $this->platform->audiopractice->listeningExercises((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['listening'] = ['available' => false, 'exercises' => [], 'note' => $e->getMessage()]; }
        try { $data['history'] = $this->platform->audiopractice->listeningHistory((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['history'] = []; }
        $data['profileId'] = $profileId;
        $data['langCode'] = $this->platform->model->langlearn->findProfile($profileId)['language_code'] ?? 'en';
        $this->render($data, 'langlearn/listening');
    }

    public function listening_attempt(int $profileId)
    {
        $user = $this->requireUser();
        $mode = (string) $this->input->post('mode');
        try {
            $answer = $mode === 'comprehension' ? (int) $this->input->post('answer') : (string) $this->input->post('transcript');
            $res = $this->platform->audiopractice->submitListening((int) $user['id'], $profileId, (string) $this->input->post('itemId'), $mode, $answer);
            $this->session->set_flashdata('llNotice', sprintf('Listening %s — %s%% · %s', $res['passed'] ? 'passed' : 'not passed', $res['scorePct'], $mode === 'comprehension' ? ('correct: ' . $res['detail']['expected']) : ('you wrote: ' . mb_substr((string) $res['detail']['given'], 0, 60))));
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/l/' . $profileId);
    }

    public function speaking(int $profileId)
    {
        $user = $this->requireUser();
        $data = $this->base('Speaking practice');
        try { $data['speaking'] = $this->platform->audiopractice->speakingPrompts((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['speaking'] = ['available' => false, 'prompts' => [], 'note' => $e->getMessage()]; }
        try { $data['history'] = $this->platform->audiopractice->speakingHistory((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['history'] = []; }
        $data['profileId'] = $profileId;
        $data['langCode'] = $this->platform->model->langlearn->findProfile($profileId)['language_code'] ?? 'en';
        $this->render($data, 'langlearn/speaking');
    }

    public function speaking_attempt(int $profileId)
    {
        $user = $this->requireUser();
        $transcript = $this->input->post('transcript');
        try {
            $res = $this->platform->audiopractice->submitSpeaking((int) $user['id'], $profileId, (string) $this->input->post('promptId'),
                ($transcript === null || trim((string) $transcript) === '') ? null : (string) $transcript,
                (string) ($this->input->post('provider') ?: 'browser_webspeech'));
            $this->session->set_flashdata($res['scored'] ? 'llNotice' : 'llError',
                $res['scored'] ? sprintf('Word accuracy %s%%%s — from your real transcript. Pronunciation scores are not available (no provider).', $res['wordAccuracyPct'], $res['exactMatch'] ? ' · exact match' : '')
                : ($res['note'] ?? 'Attempt recorded without a transcript.'));
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        redirect('/app/languages/s/' . $profileId);
    }

    // ================= PHASE 5: ADAPTIVE LEARNING PAGE =================

    public function daily_plan(int $profileId)
    {
        $user = $this->requireUser();
        $data = $this->base("Today's plan & AI insights");
        try { $data['plan'] = $this->platform->adaptive->dailyPlan((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['plan'] = null; }
        try { $data['weaknesses'] = $this->platform->adaptive->weaknesses((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['weaknesses'] = ['weaknesses' => [], 'strengths' => [], 'note' => $e->getMessage()]; }
        try { $data['recommendations'] = $this->platform->adaptive->recommendations((int) $user['id'], $profileId)['recommendations']; }
        catch (Throwable $e) { $data['recommendations'] = []; }
        try { $data['mastery'] = $this->platform->adaptive->mastery((int) $user['id'], $profileId); }
        catch (Throwable $e) { $data['mastery'] = null; }
        $data['profileId'] = $profileId;
        $this->render($data, 'langlearn/daily_plan');
    }

    public function daily_plan_regenerate(int $profileId)
    {
        $user = $this->requireUser();
        try { $this->platform->adaptive->dailyPlan((int) $user['id'], $profileId, regenerate: true); }
        catch (Throwable $e) { $this->session->set_flashdata('llError', $e->getMessage()); }
        redirect('/app/languages/d/' . $profileId);
    }

    // ------------------------------------------------------------ helpers

    private function sessionUser(): ?array
    {
        $user = $this->session->userdata('identity');
        return is_array($user) && !empty($user['id']) ? $user : null;
    }

    private function requireUser(): array
    {
        $user = $this->sessionUser();
        if (!$user) {
            $this->session->set_flashdata('llError', 'Please sign in first.');
            redirect('/login');
            die; // redirect() exits in CI3; kept for static analysis
        }
        return $user;
    }

    private function base(string $title): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => 'languages',
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->session->flashdata('llNotice'),
            'error' => $this->session->flashdata('llError'),
        ];
    }

    private function render(array $data, string $view): void
    {
        $this->load->view('layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer');
    }
}