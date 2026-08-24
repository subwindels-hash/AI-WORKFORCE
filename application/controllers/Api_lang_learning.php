<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AI Language Learning API (Phase 1) — /api/v1/language-learning.
 * Authenticated; every profile/assessment/module is isolated to its owner:
 * callers can only touch rows whose user_id matches their session identity.
 * Mutating endpoints require the X-CSRF-Token header (like the trading API).
 */
class Api_lang_learning extends Api_controller
{
    private ?array $user;

    private function guard(bool $csrf = true): ?array
    {
        return $this->requirePermission('system.authenticated', $csrf);
    }

    private function fail(Throwable $e): void
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
        $this->jsonError($e->getMessage(), $status);
    }

    // ---------------------------------------------------------- languages

    public function languages()
    {
        if (!$this->guard(false)) return;
        $this->json(['languages' => $this->platform->langlearn->languages()]);
    }

    public function show_language(string $code)
    {
        if (!$this->guard(false)) return;
        try { $this->json(['language' => $this->platform->langlearn->language($code)]); }
        catch (Throwable $e) { $this->fail($e); }
    }

    // ---------------------------------------------------------- profiles

    public function profiles()
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        if (strtoupper($this->input->method(true)) === 'POST') {
            $body = $this->jsonBody();
            try {
                $profile = $this->platform->langlearn->startLanguage(
                    (int) $user['id'],
                    (string) ($body['languageCode'] ?? ''),
                    $body['goal'] ?? null,
                    (string) ($body['explanationLanguage'] ?? 'en')
                );
                $this->json(['profile' => $profile], 201);
            } catch (Throwable $e) { $this->fail($e); }
            return;
        }
        $this->json(['profiles' => $this->platform->langlearn->profiles((int) $user['id'])]);
    }

    public function show_profile(int $id)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json(['profile' => $this->platform->langlearn->profileOwned($id, (int) $user['id']) + ['progress' => $this->platform->langlearn->progressFor($this->platform->langlearn->profileOwned($id, (int) $user['id']))]]); }
        catch (Throwable $e) { $this->fail($e); }
    }

    // -------------------------------------------------------- assessment

    public function start_assessment(int $profileId)
    {
        $user = $this->guard();
        if (!$user) return;
        try { $this->json($this->platform->langlearn->startAssessment((int) $user['id'], $profileId), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function show_assessment(string $id)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try {
            $a = $this->platform->langlearn->assessmentOwned($id, (int) $user['id']);
            $this->json(['assessment' => [
                'id' => $a['id'], 'status' => $a['status'], 'languageCode' => $a['language_code'],
                'startedAt' => $a['started_at'], 'completedAt' => $a['completed_at'],
                'pendingItem' => $a['state']['pendingItem'] ?? null, 'result' => $a['result'],
            ]]);
        } catch (Throwable $e) { $this->fail($e); }
    }

    public function answer_assessment(string $id)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        if (!isset($body['answerIndex']) || !is_numeric($body['answerIndex'])) {
            return $this->jsonError('body must be {answerIndex: 0-3}');
        }
        try { $this->json($this->platform->langlearn->answerAssessment($id, (int) $user['id'], (int) $body['answerIndex'])); }
        catch (Throwable $e) { $this->fail($e); }
    }

    // ------------------------------------------------------ learning path

    public function generate_path(int $profileId)
    {
        $user = $this->guard();
        if (!$user) return;
        try { $this->json($this->platform->langlearn->generatePath((int) $user['id'], $profileId), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function show_path(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json($this->platform->langlearn->pathFor((int) $user['id'], $profileId)); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function start_checkpoint(string $moduleId)
    {
        $user = $this->guard();
        if (!$user) return;
        try { $this->json($this->platform->langlearn->startCheckpoint($moduleId, (int) $user['id']), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function answer_checkpoint(string $moduleId)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        if (!is_array($body['answers'] ?? null)) return $this->jsonError('body must be {answers: {itemId: index}}');
        try { $this->json($this->platform->langlearn->submitCheckpoint($moduleId, (int) $user['id'], $body['answers'])); }
        catch (Throwable $e) { $this->fail($e); }
    }

    // ----------------------------------------------------------- progress

    public function progress(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try {
            $profile = $this->platform->langlearn->profileOwned($profileId, (int) $user['id']);
            $this->json(['languageCode' => $profile['language_code'], 'progress' => $this->platform->langlearn->progressFor($profile)]);
        } catch (Throwable $e) { $this->fail($e); }
    }
    // ================= PHASE 2: AI TEACHER =================

    public function start_lesson(string $moduleId)
    {
        $user = $this->guard();
        if (!$user) return;
        try { $this->json($this->platform->langteacher->startLesson($moduleId, (int) $user['id']), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function answer_lesson(string $moduleId)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        if (!is_array($body['answers'] ?? null)) return $this->jsonError('body must be {answers: {itemId: index}}');
        try { $this->json($this->platform->langteacher->submitLesson($moduleId, (int) $user['id'], $body['answers'])); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function conversations(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json(['scenarios' => $this->platform->langteacher->conversations((int) $user['id'], $profileId)]); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function start_conversation(int $profileId)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        try { $this->json($this->platform->langteacher->startConversation((int) $user['id'], $profileId, (string) ($body['scenario'] ?? ''), (string) ($body['correction'] ?? 'important')), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function conversation_turn(string $sessionId)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        if (!isset($body['text']) || !is_string($body['text'])) return $this->jsonError('body must be {text: string}');
        try { $this->json($this->platform->langteacher->conversationTurn($sessionId, (int) $user['id'], $body['text'])); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function writing_tasks(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json(['tasks' => $this->platform->langteacher->writingTasks((int) $user['id'], $profileId)]); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function submit_writing(int $profileId)
    {
        $user = $this->guard();
        if (!$user) return;
        $body = $this->jsonBody();
        if (!isset($body['taskCode'], $body['text'])) return $this->jsonError('body must be {taskCode, text}');
        try { $this->json($this->platform->langteacher->submitWriting((int) $user['id'], $profileId, (string) $body['taskCode'], (string) $body['text']), 201); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function grammar(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json(['rules' => $this->platform->langteacher->grammarRules((int) $user['id'], $profileId)]); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function grammar_simple(int $profileId, string $ruleId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json($this->platform->langteacher->explainSimply((int) $user['id'], $profileId, $ruleId)); }
        catch (Throwable $e) { $this->fail($e); }
    }

    public function history(int $profileId)
    {
        if (!$this->guard(false)) return;
        $user = $this->session->userdata('identity');
        try { $this->json($this->platform->langteacher->history((int) $user['id'], $profileId)); }
        catch (Throwable $e) { $this->fail($e); }
    }

}
