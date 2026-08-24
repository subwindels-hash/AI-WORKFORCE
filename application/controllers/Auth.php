<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Browser authentication pages for the portable PHP/cPanel application. */
class Auth extends MY_Controller
{
    public function index()
    {
        if ($this->sessionUser()) { redirect('/account'); return; }
        $this->renderAuth(false);
    }

    public function admin_login()
    {
        if ($this->sessionUser() && $this->platform->identity->can($this->sessionUser(), 'system.super_admin')) { redirect('/admin'); return; }
        $this->renderAuth(true);
    }

    public function login()
    {
        $admin = $this->input->post('admin') === '1';
        $email = strtolower(trim((string) $this->input->post('email')));
        $password = (string) $this->input->post('password');
        $attempts = (int) $this->session->userdata('login_attempts');
        $until = (int) $this->session->userdata('login_locked_until');
        if ($until > time()) { $this->flash('error', 'Too many attempts. Try again later.'); $this->redirectLogin($admin); return; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') { $this->flash('error', 'Enter a valid email and password.'); $this->redirectLogin($admin); return; }
        $user = $this->platform->identity->authenticate($email, $password);
        if (!$user || ($admin && !$this->platform->identity->can($user, 'system.super_admin'))) {
            $attempts++;
            $this->session->set_userdata('login_attempts', $attempts);
            if ($attempts >= 5) $this->session->set_userdata('login_locked_until', time() + 900);
            $this->flash('error', $admin ? 'Administrator access was not granted.' : 'Invalid email or password.');
            $this->redirectLogin($admin); return;
        }
        $this->session->sess_regenerate(true);
        $this->session->set_userdata(['identity' => $user, 'csrf_token' => bin2hex(random_bytes(32)), 'login_attempts' => 0, 'login_locked_until' => 0]);
        redirect($admin ? '/admin' : '/account');
    }

    public function logout()
    {
        $identity = $this->sessionUser();
        if ($identity) {
            $token = (string) $this->input->post('csrf_token');
            $known = (string) $this->session->userdata('csrf_token');
            if ($token === '' || $known === '' || !hash_equals($known, $token)) { $this->flash('error', 'Invalid security token.'); redirect('/account'); return; }
        }
        $this->session->sess_destroy(); redirect('/login');
    }

    public function account()
    {
        $user = $this->requireUser();
        $this->renderPage('Account', 'account', ['user' => $user]);
    }

    private function renderAuth(bool $admin): void
    {
        $this->load->view('auth/login', [
            'title' => $admin ? 'Administrator sign in' : 'User sign in',
            'admin' => $admin,
            'error' => $this->consumeFlash('error'),
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
        ]);
    }

    private function renderPage(string $title, string $active, array $data = []): void
    {
        $state = $this->platform->state();
        $data = array_merge($data, [
            'title' => $title, 'active' => $active,
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'], 'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->consumeFlash('notice'), 'error' => $this->consumeFlash('error'),
        ]);
        $this->load->view('layout/header', $data); $this->load->view('auth/account', $data); $this->load->view('layout/footer');
    }

    private function requireUser(): array
    {
        $user = $this->sessionUser();
        if (!$user) { redirect('/login'); exit; }
        return $user;
    }

    private function sessionUser(): ?array
    {
        $user = $this->session->userdata('identity');
        return is_array($user) && !empty($user['id']) ? $user : null;
    }

    private function redirectLogin(bool $admin): void { redirect($admin ? '/admin/login' : '/login'); }
    private function flash(string $key, string $value): void { $this->session->set_flashdata($key, $value); }
    private function consumeFlash(string $key): ?string { $value = $this->session->flashdata($key); return is_string($value) && $value !== '' ? $value : null; }
}
