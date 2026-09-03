<?php
namespace AIWorkforce;

/**
 * Organized admin email templates. Each template has a code, subject, HTML and
 * text body, and a list of substitution variables. Rendering replaces
 * {{name}} markers in subject/body with the supplied context array. System
 * templates (is_system=1) are seeded on first boot and cannot be deleted
 * from the UI — they can, however, be cloned to custom templates.
 */
final class EmailTemplates
{
    public const DEFAULT_TEMPLATES = [
        [
            'code' => 'contact_autoreply',
            'name' => 'Contact form auto-reply',
            'category' => 'contact',
            'description' => 'Sent automatically to the visitor when their contact form is received.',
            'subject' => 'We received your message — {{site_name}}',
            'variables' => ['site_name', 'name', 'email', 'phone', 'address', 'message', 'contact_email'],
            'html' => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="padding:24px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:8px 8px 0 0">
    <h1 style="margin:0;font-size:22px">{{site_name}}</h1>
    <p style="margin:6px 0 0;opacity:.9">We received your message</p>
  </div>
  <div style="padding:28px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
    <p style="font-size:16px;margin-top:0">Hi {{name}},</p>
    <p>Thank you for contacting <strong>{{site_name}}</strong>. Your message has been received and a member of the team will reply, usually within one business day.</p>
    <table style="width:100%;border-collapse:collapse;margin:18px 0;background:#f8fafc;border-radius:6px;overflow:hidden">
      <tr><td style="padding:8px 14px;color:#64748b;width:120px">Phone</td><td style="padding:8px 14px">{{phone}}</td></tr>
      <tr><td style="padding:8px 14px;color:#64748b;border-top:1px solid #e2e8f0">Address</td><td style="padding:8px 14px;border-top:1px solid #e2e8f0">{{address}}</td></tr>
    </table>
    <p style="color:#475569;font-size:13px;border-left:3px solid #2563eb;padding-left:12px"><strong>Your message:</strong></p>
    <p style="white-space:pre-wrap;background:#f8fafc;padding:12px;border-radius:6px;color:#1e293b">{{message}}</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">
    <p style="font-size:12px;color:#64748b;margin:0">This is an automated confirmation. If your enquiry is urgent, please call the published phone number or email <a href="mailto:{{contact_email}}" style="color:#2563eb">{{contact_email}}</a>.</p>
  </div>
</div>',
            'text' => "Hi {{name}},\n\nThank you for contacting {{site_name}}. Your message has been received and a member of the team will reply, usually within one business day.\n\nPhone: {{phone}}\nAddress: {{address}}\n\nYour message:\n{{message}}\n\n---\nThis is an automated confirmation. If your enquiry is urgent, email {{contact_email}}.",
        ],
        [
            'code' => 'admin_reply',
            'name' => 'Admin reply to contact enquiry',
            'category' => 'contact',
            'description' => 'Personal reply the administrator sends from the inbox to a contact-form submission.',
            'subject' => 'Re: {{subject}} — {{site_name}}',
            'variables' => ['site_name', 'name', 'subject', 'reply_body', 'original_message', 'signature_name'],
            'html' => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="padding:20px 28px;background:#f8fafc;border-bottom:2px solid #2563eb">
    <h2 style="margin:0;color:#0f172a;font-size:20px">{{site_name}}</h2>
    <p style="margin:4px 0 0;color:#64748b;font-size:13px">Customer support reply</p>
  </div>
  <div style="padding:28px">
    <p style="font-size:15px;margin-top:0">Hi {{name}},</p>
    <div style="font-size:15px;line-height:1.6">{{reply_body}}</div>
    <p style="margin-top:24px">Best regards,<br><strong>{{signature_name}}</strong><br>{{site_name}}</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0">
    <p style="font-size:12px;color:#64748b;margin:0"><strong>On {{original_message_date}} you wrote:</strong></p>
    <blockquote style="margin:8px 0 0;padding:10px 14px;border-left:3px solid #cbd5e1;color:#475569;white-space:pre-wrap;font-size:13px;background:#f8fafc;border-radius:0 6px 6px 0">{{original_message}}</blockquote>
  </div>
</div>',
            'text' => "Hi {{name}},\n\n{{reply_body}}\n\nBest regards,\n{{signature_name}}\n{{site_name}}\n\n---\nOn {{original_message_date}} you wrote:\n\n{{original_message}}",
        ],
        [
            'code' => 'message_received_notice',
            'name' => 'New message notification (internal)',
            'category' => 'internal',
            'description' => 'Sent to configured site operator email when a new contact message arrives.',
            'subject' => 'New contact message — {{site_name}}',
            'variables' => ['site_name', 'name', 'email', 'phone', 'address', 'message', 'inbox_url'],
            'html' => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="padding:16px 24px;background:#dc2626;color:#fff;border-radius:8px 8px 0 0">
    <h2 style="margin:0;font-size:18px">New contact message</h2>
  </div>
  <div style="padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
    <p>A new message has been submitted via the public contact form on {{site_name}}.</p>
    <table style="width:100%;border-collapse:collapse;margin:12px 0">
      <tr><td style="padding:6px 10px;color:#64748b;width:100px">From</td><td style="padding:6px 10px"><strong>{{name}}</strong> &lt;<a href="mailto:{{email}}">{{email}}</a>&gt;</td></tr>
      <tr><td style="padding:6px 10px;color:#64748b">Phone</td><td style="padding:6px 10px">{{phone}}</td></tr>
      <tr><td style="padding:6px 10px;color:#64748b">Address</td><td style="padding:6px 10px">{{address}}</td></tr>
    </table>
    <p style="background:#f8fafc;padding:12px;border-radius:6px;white-space:pre-wrap">{{message}}</p>
    <p style="margin-top:18px"><a href="{{inbox_url}}" style="background:#2563eb;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">Open Inbox &amp; Reply</a></p>
  </div>
</div>',
            'text' => "New contact message from {{name}} <{{email}}>\n\nPhone: {{phone}}\nAddress: {{address}}\n\n{{message}}\n\nOpen inbox to reply: {{inbox_url}}",
        ],
        [
            'code' => 'welcome_user',
            'name' => 'Welcome / account created',
            'category' => 'account',
            'description' => 'Sent to a user when an administrator creates their account.',
            'subject' => 'Welcome to {{site_name}}, {{name}}!',
            'variables' => ['site_name', 'name', 'email', 'temporary_password', 'login_url'],
            'html' => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="padding:24px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border-radius:8px 8px 0 0">
    <h1 style="margin:0;font-size:22px">Welcome to {{site_name}}</h1>
  </div>
  <div style="padding:28px;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
    <p>Hi {{name}},</p>
    <p>An account has been created for you on <strong>{{site_name}}</strong>. Sign in using the email <strong>{{email}}</strong> and the temporary password below. You will be asked to set your own password on first login.</p>
    <div style="background:#f8fafc;border:1px dashed #94a3b8;border-radius:8px;padding:14px;margin:18px 0;font-family:ui-monospace,monospace;font-size:15px;text-align:center">{{temporary_password}}</div>
    <p><a href="{{login_url}}" style="background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block">Sign in to your account</a></p>
    <p style="font-size:12px;color:#64748b;margin-top:20px">If you did not expect this email, please contact support.</p>
  </div>
</div>',
            'text' => "Hi {{name}},\n\nAn account has been created for you on {{site_name}}.\n\nSign in at: {{login_url}}\nEmail: {{email}}\nTemporary password: {{temporary_password}}\n\nYou will be asked to choose your own password on first login.",
        ],
        [
            'code' => 'password_reset',
            'name' => 'Password reset',
            'category' => 'account',
            'description' => 'Password-reset confirmation after an admin triggers a reset.',
            'subject' => 'Your password has been reset — {{site_name}}',
            'variables' => ['site_name', 'name', 'temporary_password', 'login_url'],
            'html' => '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:0 auto;color:#0f172a">
  <div style="padding:24px;background:#0f766e;color:#fff;border-radius:8px 8px 0 0"><h1 style="margin:0;font-size:20px">Password reset</h1></div>
  <div style="padding:28px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px">
    <p>Hi {{name}},</p>
    <p>Your password on <strong>{{site_name}}</strong> has been reset. Sign in with the temporary password below:</p>
    <div style="background:#f8fafc;border:1px dashed #94a3b8;border-radius:8px;padding:14px;margin:18px 0;font-family:ui-monospace,monospace;font-size:15px;text-align:center">{{temporary_password}}</div>
    <p><a href="{{login_url}}" style="background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;display:inline-block">Sign in</a></p>
  </div>
</div>',
            'text' => "Hi {{name}},\n\nYour password on {{site_name}} has been reset.\n\nSign in at: {{login_url}}\nTemporary password: {{temporary_password}}",
        ],
    ];

    /** Seed default templates idempotently (no clobber of custom templates). */
    public static function ensure(object $db): void
    {
        try {
            $now = gmdate('c');
            foreach (self::DEFAULT_TEMPLATES as $t) {
                $existing = $db->get_where('email_templates', ['code' => $t['code']], 1);
                $row = $existing ? $existing->row_array() : null;
                $payload = [
                    'code' => $t['code'],
                    'name' => $t['name'],
                    'category' => $t['category'],
                    'description' => $t['description'],
                    'subject' => $t['subject'],
                    'body_html' => $t['html'],
                    'body_text' => $t['text'],
                    'variables_json' => json_encode($t['variables']),
                    'is_system' => 1,
                    'is_active' => 1,
                    'updated_at' => $now,
                ];
                if ($row) {
                    $db->where('id', (int) $row['id'])->update('email_templates', $payload);
                } else {
                    $payload['created_at'] = $now;
                    $payload['created_by'] = null;
                    $payload['updated_by'] = null;
                    $db->insert('email_templates', $payload);
                }
            }
        } catch (\Throwable $e) {
            // tables may not exist yet on a fresh boot; SchemaInstaller will call us again after migration
        }
    }

    /** Render {{var}} markers in subject/body with context. Missing vars are replaced with empty string. */
    public static function render(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $m) use ($context) {
            $key = $m[1];
            $val = $context[$key] ?? '';
            return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
        }, $template);
    }

    /** Text-only rendering (no HTML escaping). */
    public static function renderText(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $m) use ($context) {
            return (string) ($context[$m[1]] ?? '');
        }, $template);
    }
}
