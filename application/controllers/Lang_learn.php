<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AI Language Learning console (server-rendered pages, PRG flows).
 * Per-user data: everything requires a signed-in session identity.
 */
class Lang_learn extends MY_Controller
{
    public function index()
    {
        $data = $this->base('Languages');
        $user = $this->sessionUser();
        $data['user'] = $user;
        $data['languages'] = $this->platform->langlearn->languages();
        $data['myProfiles'] = $user ? $this->platform->langlearn->profiles((int) $user['id']) : [];
        $this->render($data, 'langlearn/index');
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

    public function start()
    {
        $user = $this->requireUser();
        try {
            $profile = $this->platform->langlearn->startLanguage((int) $user['id'], (string) $this->input->post('code'), (string) $this->input->post('goal'));
            $this->session->set_flashdata('llNotice', 'Added to My Languages. Take the AI level assessment to set your starting level.');
            redirect('/app/languages/p/' . $profile['id']);
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
            redirect('/app/languages');
        }
    }

    public function profile(int $id)
    {
        $user = $this->requireUser();
        $data = $this->base('Language');
        $profile = $this->platform->langlearn->profileOwned($id, (int) $user['id']);
        $data['profile'] = $profile;
        $data['language'] = \Aegis\LangLearn\LanguageRegistry::get($profile['language_code']);
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
        $data['language'] = \Aegis\LangLearn\LanguageRegistry::get($a['language_code']);
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
        try {
            $result = $this->platform->langlearn->submitCheckpoint($moduleId, (int) $user['id'], is_array($answers) ? $answers : []);
            $this->session->set_flashdata('llNotice', sprintf('Checkpoint %s — %d%% (%d/%d correct).',
                $result['passed'] ? 'PASSED' : 'not passed', $result['scorePct'], $result['correct'], $result['total']));
        } catch (Throwable $e) {
            $this->session->set_flashdata('llError', $e->getMessage());
        }
        $module = $this->platform->model->langlearn->findModule($moduleId);
        redirect('/app/languages/p/' . ($module['profile_id'] ?? 0));
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
            redirect('/app/languages');
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
