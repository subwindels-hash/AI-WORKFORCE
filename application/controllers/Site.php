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
        $message = trim((string) $this->input->post('message'));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($message) < 10) {
            $this->session->set_flashdata('error', 'Enter your name, a valid email, and a message of at least 10 characters.');
            redirect('/contact');
            return;
        }
        $this->platform->model->audit->emit('CONTACT_INQUIRY', 'Public contact form received from ' . $name, [
            'name' => $name, 'email' => $email, 'message' => mb_substr($message, 0, 2000),
        ], 'visitor');
        $notified = $this->notifyOperator($name, $email, $message);
        $autoreplied = $this->sendAutoReply($name, $email, $message);

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
        // Symmetric degree span keeps the marker centred in the OSM embed.
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
            'phone' => (string) (getenv('VP_CONTACT_PHONE') ?: ''),
            'address' => (string) (getenv('VP_CONTACT_ADDRESS') ?: ''),
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
    private function notifyOperator(string $name, string $email, string $message): bool
    {
        if (!\AIWorkforce\Mailer::enabled()) return false;
        $to = (string) (getenv('VP_CONTACT_EMAIL') ?: getenv('VP_CONTACT_TO') ?: getenv('VP_MAIL_FROM') ?: getenv('MAIL_FROM_ADDRESS') ?: '');
        if ($to === '') return false;
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#0f172a">'
            . '<h2 style="color:#2563eb">New contact message — WINDELS AI WORKFORCE</h2>'
            . '<p><b>From:</b> ' . htmlspecialchars($name) . ' &lt;' . htmlspecialchars($email) . '&gt;</p>'
            . '<hr style="border:0;border-top:1px solid #e2e8f0">'
            . '<p style="white-space:pre-wrap">' . htmlspecialchars($message) . '</p>'
            . '</div>';
        $text = "New contact message — WINDELS AI WORKFORCE\n\nFrom: {$name} <{$email}>\n\n{$message}";
        return \AIWorkforce\Mailer::send($this, $to, 'WINDELS AI WORKFORCE contact form', $html, $text, $email, $name)['ok'];
    }

    /** Email the sender a confirmation that their message was received. */
    private function sendAutoReply(string $name, string $email, string $message): bool
    {
        if (!\AIWorkforce\Mailer::enabled()) return false;
        $config = $this->contactConfig();
        $site = (string) (getenv('VP_SITE_NAME') ?: 'WINDELS AI WORKFORCE');
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;color:#0f172a">'
            . '<h2 style="color:#2563eb">We received your message</h2>'
            . '<p>Hi ' . htmlspecialchars($name) . ',</p>'
            . '<p>Thank you for contacting ' . htmlspecialchars($site) . '. Your message has been received and a member of the team will reply shortly.</p>'
            . '<hr style="border:0;border-top:1px solid #e2e8f0">'
            . '<p style="color:#64748b;font-size:13px">Your message:</p>'
            . '<p style="white-space:pre-wrap">' . htmlspecialchars(mb_substr($message, 0, 2000)) . '</p>'
            . '<hr style="border:0;border-top:1px solid #e2e8f0">'
            . '<p style="margin-top:16px;color:#64748b;font-size:12px">This is an automated confirmation. Replies to this address may not be monitored — please use the contact page or ' . htmlspecialchars($config['email']) . '.</p>'
            . '</div>';
        $text = "We received your message\n\nHi {$name},\n\n"
            . "Thank you for contacting {$site}. Your message has been received and a member of the team will reply shortly.\n\n"
            . "Your message:\n{$message}\n\n"
            . "This is an automated confirmation. Replies to this address may not be monitored — please use the contact page or {$config['email']}.";
        return \AIWorkforce\Mailer::send($this, $email, 'We received your message — ' . $site, $html, $text)['ok'];
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
