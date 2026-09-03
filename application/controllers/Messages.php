<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

use AIWorkforce\Messaging\DirectMessages;

/**
 * Member ⇄ administrator direct messages (server-rendered). One thread per
 * member: replies written from the admin console (/admin/messages) appear
 * here, and unread replies raise the sidebar Messages badge. Opening the
 * page acknowledges every admin message in the thread.
 */
class Messages extends App_Controller
{
    private const SEND_WINDOW = 600;   // seconds
    private const SEND_MAX = 30;       // messages per window

    public function index()
    {
        $user = $this->identity;
        $userId = (int) $user['id'];
        $this->AIWorkforce_model->messages->markReadByUser($userId);
        $state = $this->platform->state();
        $data = [
            'title' => 'Messages',
            'active' => 'messages',
            'status' => [
                'tradingMode' => $state['tradingMode'],
                'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth(),
            ],
            'thread' => $this->AIWorkforce_model->messages->threadFor($userId),
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
        ];
        $this->load->view('layout/header', $data);
        $this->load->view('messages/index', $data);
        $this->load->view('layout/footer');
    }

    public function send()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/messages'); return; }
        if (!$this->validCsrf()) {
            $this->session->set_flashdata('error', 'Invalid security token. Please try again.');
            redirect('/messages'); return;
        }
        $this->guardRate();
        $user = $this->identity;
        $body = DirectMessages::cleanBody((string) $this->input->post('body'));
        if (!DirectMessages::validBody($body)) {
            $this->session->set_flashdata('error', 'Write a message of 1–' . DirectMessages::MAX_BODY . ' characters.');
            redirect('/messages'); return;
        }
        $this->AIWorkforce_model->messages->append([
            'user_id' => (int) $user['id'],
            'sender_id' => (int) $user['id'],
            'sender_role' => 'user',
            'sender_label' => (string) ($user['display_name'] ?? $user['username'] ?? $user['email'] ?? 'Member'),
            'body' => $body,
        ]);
        $this->session->set_flashdata('notice', 'Message sent — the support team will reply here.');
        redirect('/messages');
    }

    /** Same sliding window guard the public chat endpoint uses. */
    private function guardRate(): void
    {
        $key = 'ai_workforce_dm_window';
        $now = time();
        $window = array_values(array_filter(
            array_map('intval', (array) $this->session->userdata($key)),
            fn($at) => $at > $now - self::SEND_WINDOW
        ));
        if (count($window) >= self::SEND_MAX) {
            $this->session->set_flashdata('error', 'You are sending messages too quickly — please wait a moment.');
            redirect('/messages');
            exit;
        }
        $window[] = $now;
        $this->session->set_userdata($key, $window);
    }

    private function validCsrf(): bool
    {
        $sent = (string) $this->input->post('csrf_token');
        $known = (string) $this->session->userdata('csrf_token');
        return $sent !== '' && $known !== '' && hash_equals($known, $sent);
    }
}
