<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Public marketing website. No dashboard chrome and no login required. */
class Site extends MY_Controller
{
    public function index() { $this->page('home', 'Home', 'site/home'); }
    public function about() { $this->page('about', 'About', 'site/about'); }
    public function services() { $this->page('services', 'Services', 'site/services'); }
    public function how_it_works() { $this->page('how', 'How it works', 'site/how'); }
    public function locations() { $this->page('locations', 'Coverage', 'site/locations'); }
    public function safety() { $this->page('safety', 'Safety & trust', 'site/safety'); }
    public function faq() { $this->page('faq', 'FAQ', 'site/faq'); }
    public function contact() { $this->page('contact', 'Contact', 'site/contact'); }

    public function contact_submit()
    {
        $name = trim((string) $this->input->post('name'));
        $email = strtolower(trim((string) $this->input->post('email')));
        $phone = \AIWorkforce\IdentitySchema::normalizePhone((string) $this->input->post('phone'));
        $address = \AIWorkforce\IdentitySchema::normalizeAddress((string) $this->input->post('address'));
        $message = trim((string) $this->input->post('message'));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($message) < 10) {
            $this->session->set_flashdata('error', 'Enter your name, a valid email, and a message of at least 10 characters.');
            redirect('/contact');
            return;
        }
        if (!\AIWorkforce\IdentitySchema::validPhone($phone) || !\AIWorkforce\IdentitySchema::validAddress($address)) {
            $this->session->set_flashdata('error', 'Enter a valid phone number and a street address (at least 5 characters).');
            redirect('/contact');
            return;
        }
        $actor = 'visitor';
        $signedIn = $this->currentUser();
        if ($signedIn) {
            $actor = (string) $signedIn['id'];
            try {
                \AIWorkforce\IdentitySchema::ensure($this->db);
                $this->AIWorkforce_model->identity->updateUser((int) $signedIn['id'], ['phone' => $phone, 'address' => $address]);
                $fresh = $this->AIWorkforce_model->identity->findUserById((int) $signedIn['id']);
                if ($fresh) {
                    $fresh['permissions'] = $signedIn['permissions'] ?? $this->AIWorkforce_model->identity->permissionsForUser((int) $signedIn['id']);
                    $fresh = \AIWorkforce\IdentitySchema::stripSecrets($fresh);
                    $this->session->set_userdata(['identity' => $fresh]);
                }
            } catch (\Throwable $e) {
                log_message('error', 'contact_submit profile sync failed: ' . $e->getMessage());
            }
        }
        // Persist in the admin inbox before notifying.
        try { \AIWorkforce\EmailTemplates::ensure($this->db); } catch (\Throwable $_) {}
        $inboxId = null;
        try {
            $inboxId = $this->AIWorkforce_model->inbox->create([
                'sender_name' => $name,
                'sender_email' => $email,
                'sender_phone' => $phone,
                'sender_address' => $address,
                'subject' => 'Contact form inquiry from ' . $name,
                'body' => mb_substr($message, 0, 8000),
                'source' => 'contact_form',
                'ip' => $this->input->ip_address(),
                'user_agent' => substr((string) $this->input->user_agent(), 0, 250),
                'user_id' => $signedIn ? (int) $signedIn['id'] : null,
                'status' => 'new',
                'is_read' => 0,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'contact_submit inbox persist failed: ' . $e->getMessage());
        }

        $this->platform->model->audit->emit('CONTACT_INQUIRY', 'Public contact form received from ' . $name, [
            'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address,
            'message' => mb_substr($message, 0, 2000), 'inbox_id' => $inboxId,
        ], $actor);
        $notified = $this->notifyOperator($name, $email, $phone, $address, $message, $inboxId);
        $autoreplied = $this->sendAutoReply($name, $email, $phone, $address, $message);

        if ($inboxId && $autoreplied) {
            try {
                $this->AIWorkforce_model->inbox->addReply([
                    'message_id' => $inboxId,
                    'template_id' => null,
                    'author_id' => null,
                    'author_label' => 'Auto-reply',
                    'direction' => 'outbound',
                    'to_email' => $email,
                    'subject' => 'We received your message — ' . (string) (getenv('VP_SITE_NAME') ?: 'WINDELS AI WORKFORCE'),
                    'body' => '[Auto-reply sent via ' . (getenv('VP_MAIL_FROM') ?: 'system') . ']',
                    'body_text' => '',
                    'delivery_status' => 'sent',
                ]);
            } catch (\Throwable $_) {}
        }

        if ($notified && $autoreplied) {
            $this->session->set_flashdata('notice', 'Thank you. Your message was received and a copy sent to the site operator. A confirmation email is on its way to ' . $email . '.');
        } elseif ($notified) {
            $this->session->set_flashdata('notice', 'Thank you. Your message was received and a copy sent to the site operator.');
        } else {
            $this->session->set_flashdata('notice', 'Thank you. Your message was recorded. Outbound email is not configured yet, so no email copy was sent.');
        }
        redirect('/contact');
    }

    /** cPanel / SMTP configuration for the public contact page and outbound mail. */
    private function contactConfig(): array
    {
        $lat = (float) (getenv('VP_CONTACT_LAT') ?: 9.05785);
        $lon = (float) (getenv('VP_CONTACT_LON') ?: 7.49508);
        $zoom = (int) (getenv('VP_CONTACT_MAP_ZOOM') ?: 12);
        if ($zoom < 3) $zoom = 3;
        if ($zoom > 18) $zoom = 18;
        $span = 0.6 / $zoom;
        $pad = max(0.0004, $span);
        $bbox = [
            number_format($lon - $pad, 6, '.', ''),
            number_format($lat - $pad, 6, '.', ''),
            number_format($lon + $pad, 6, '.', ''),
            number_format($lat + $pad, 6, '.', ''),
        ];
        return [
            'email' => (string) (getenv('VP_CONTACT_EMAIL') ?: getenv('VP_MAIL_FROM') ?: getenv('MAIL_FROM_ADDRESS') ?: 'noreply@yourdomain.com'),
            'phone' => (string) (getenv('VP_CONTACT_PHONE') ?: '+234 800 000 0000'),
            'address' => (string) (getenv('VP_CONTACT_ADDRESS') ?: 'Suite 10, Example Business Plaza'),
            'city' => (string) (getenv('VP_CONTACT_CITY') ?: 'Abuja, Nigeria'),
            'mapSrc' => 'https://www.openstreetmap.org/export/embed.html?bbox='
                . implode('%2C', $bbox)
                . '&layer=mapnik&marker=' . number_format($lat, 6, '.', '') . '%2C' . number_format($lon, 6, '.', ''),
            'mapLink' => 'https://www.openstreetmap.org/?mlat=' . number_format($lat, 6, '.', '')
                . '&mlon=' . number_format($lon, 6, '.', '') . '#map=' . $zoom . '/' . number_format($lat, 6, '.', '') . '/' . number_format($lon, 6, '.', ''),
            'mailEnabled' => \AIWorkforce\Mailer::enabled(),
        ];
    }

    /** Email the site operator that a contact form was submitted. */
    private function notifyOperator(string $name, string $email, string $phone, string $address, string $message, ?int $inboxId = null): bool
    {
        if (!\AIWorkforce\Mailer::enabled()) return false;
        $to = (string) (getenv('VP_CONTACT_EMAIL') ?: getenv('VP_CONTACT_TO') ?: getenv('VP_MAIL_FROM') ?: getenv('MAIL_FROM_ADDRESS') ?: '');
        if ($to === '') return false;
        $site = (string) (getenv('VP_SITE_NAME') ?: 'WINDELS AI WORKFORCE');
        $baseUrl = rtrim((string) (getenv('AI_WORKFORCE_BASE_URL') ?: (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] : '')), '/');
        $inboxUrl = $inboxId ? ($baseUrl . '/admin/inbox') : '';
        $ctx = ['site_name' => $site, 'name' => $name, 'email' => $email, 'phone' => $phone,
                'address' => $address, 'message' => $message, 'inbox_url' => $inboxUrl];

        $tpl = null;
        try { $tpl = $this->AIWorkforce_model->inbox->findTemplateByCode('message_received_notice'); } catch (\Throwable $_) {}
        if ($tpl) {
            $subject = \AIWorkforce\EmailTemplates::renderText($tpl['subject'], $ctx);
            $html = \AIWorkforce\EmailTemplates::render($tpl['body_html'], $ctx);
            $text = $tpl['body_text'] ? \AIWorkforce\EmailTemplates::renderText($tpl['body_text'], $ctx) : strip_tags($html);
        } else {
            $subject = 'New contact message — ' . $site;
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:auto;color:#0f172a">'
                . '<h2 style="color:#2563eb">' . htmlspecialchars($subject) . '</h2>'
                . '<p><b>From:</b> ' . htmlspecialchars($name) . ' &lt;' . htmlspecialchars($email) . '&gt;</p>'
                . '<p><b>Phone:</b> ' . htmlspecialchars($phone) . '</p>'
                . '<p><b>Address:</b> ' . htmlspecialchars($address) . '</p>'
                . '<hr style="border:0;border-top:1px solid #e2e8f0">'
                . '<p style="white-space:pre-wrap">' . htmlspecialchars($message) . '</p>'
                . ($inboxUrl ? '<p><a href="' . htmlspecialchars($inboxUrl) . '">Open admin inbox</a></p>' : '')
                . '</div>';
            $text = "{$subject}\n\nFrom: {$name} <{$email}>\nPhone: {$phone}\nAddress: {$address}\n\n{$message}" . ($inboxUrl ? "\n\nOpen inbox: {$inboxUrl}" : '');
        }
        return \AIWorkforce\Mailer::send($this, $to, $subject, $html, $text, $email, $name)['ok'];
    }

    /** Email the sender a confirmation that their message was received. */
    private function sendAutoReply(string $name, string $email, string $phone, string $address, string $message): bool
    {
        if (!\AIWorkforce\Mailer::enabled()) return false;
        $config = $this->contactConfig();
        $site = (string) (getenv('VP_SITE_NAME') ?: 'WINDELS AI WORKFORCE');
        $ctx = ['site_name' => $site, 'name' => $name, 'email' => $email, 'phone' => $phone,
                'address' => $address, 'message' => $message, 'contact_email' => $config['email']];
        $tpl = null;
        try { $tpl = $this->AIWorkforce_model->inbox->findTemplateByCode('contact_autoreply'); } catch (\Throwable $_) {}
        if ($tpl) {
            $subject = \AIWorkforce\EmailTemplates::renderText($tpl['subject'], $ctx);
            $html = \AIWorkforce\EmailTemplates::render($tpl['body_html'], $ctx);
            $text = $tpl['body_text'] ? \AIWorkforce\EmailTemplates::renderText($tpl['body_text'], $ctx) : strip_tags($html);
        } else {
            $subject = 'We received your message — ' . $site;
            $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#0f172a">'
                . '<h2 style="color:#2563eb">We received your message</h2>'
                . '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                . '<p>Thank you for contacting ' . htmlspecialchars($site) . '. Your message has been received and a member of the team will reply shortly.</p>'
                . '<p style="color:#64748b;font-size:13px">Phone: ' . htmlspecialchars($phone) . '<br>Address: ' . htmlspecialchars($address) . '</p>'
                . '<hr style="border:0;border-top:1px solid #e2e8f0">'
                . '<p style="color:#64748b;font-size:13px">Your message:</p>'
                . '<p style="white-space:pre-wrap">' . htmlspecialchars(mb_substr($message, 0, 2000)) . '</p>'
                . '<hr style="border:0;border-top:1px solid #e2e8f0">'
                . '<p style="margin-top:16px;color:#64748b;font-size:12px">This is an automated confirmation. Replies to this address may not be monitored — please use the contact page or ' . htmlspecialchars($config['email']) . '.</p>'
                . '</div>';
            $text = "We received your message\n\nHi {$name},\n\nThank you for contacting {$site}. We will reply shortly.\n\nPhone: {$phone}\nAddress: {$address}\n\nYour message:\n{$message}";
        }
        return \AIWorkforce\Mailer::send($this, $email, $subject, $html, $text)['ok'];
    }

    private function page(string $active, string $title, string $view): void
    {
        $data = [
            'title' => $title,
            'active' => $active,
            'user' => $this->currentUser(),
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'languages' => count($this->platform->langlearn->languages()),
            'contact' => $this->contactConfig(),
        ];
        $this->load->view('site/layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('site/layout/footer', $data);
    }
}
