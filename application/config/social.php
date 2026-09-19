<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public social channels rendered in the site footer.
 *
 * Precedence, highest first:
 *   1. the VP_SOCIAL_* environment variable (cPanel .env / hosting panel)
 *   2. the default below
 *
 * Every value is still validated by the footer before it is rendered: only a
 * syntactically valid https:// URL ever becomes a button, so a blank or broken
 * entry hides that channel instead of producing a dead link.
 *
 * ── EDIT ME ────────────────────────────────────────────────────────────────
 * The defaults use the `windelsaiworkforce` handle as a starting point. Replace
 * each line with the official destination for this deployment (or clear it to
 * hide that channel). Setting the matching VP_SOCIAL_* variable overrides the
 * value here without touching this file.
 */
$social = [
    'facebook'  => 'https://www.facebook.com/windelsaiworkforce',
    'instagram' => 'https://www.instagram.com/windelsaiworkforce',
    'x'         => 'https://x.com/windelsaiwork',
    'linkedin'  => 'https://www.linkedin.com/company/windelsaiworkforce',
    'telegram'  => 'https://t.me/windelsaiworkforce',
    'whatsapp'  => '',
    'youtube'   => 'https://www.youtube.com/@windelsaiworkforce',
];

// WhatsApp has no handle: derive the click-to-chat link from the published
// contact phone number when one is configured, so the button follows whatever
// number the deployment already advertises on /contact.
if ($social['whatsapp'] === '') {
    $contactPhone = preg_replace('/\D+/', '', (string) getenv('VP_CONTACT_PHONE'));
    if (is_string($contactPhone) && strlen($contactPhone) >= 8) {
        $social['whatsapp'] = 'https://wa.me/' . $contactPhone;
    }
}

$config['channels'] = $social;
