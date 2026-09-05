<?php
namespace AIWorkforce;

/**
 * Moving announcement bar content shown at the top of every page.
 *
 * Priority: super-admin override (System Settings → Announcement) >
 * VP_ANNOUNCEMENT env var > built-in defaults. A saved-but-empty override
 * shows no messages; the toggle hides the bar entirely. An unset override
 * keeps the previous env/default behavior. Never throws.
 */
class AnnouncementBar
{
    public const MESSAGES_KEY = 'announcement_messages';
    public const ENABLED_KEY = 'announcement_enabled';

    public static function defaults(): array
    {
        return [
            'Welcome to WINDELS AI WORKFORCE — your AI-powered workforce platform.',
            'NEW: Open the AI Language Teacher for instant translation, listening and speaking practice.',
            'Enterprise-grade analysis, language learning and lead discovery — evidence-first, audited, fail-closed.',
        ];
    }

    /** @return array{enabled:bool, messages:string[]} */
    public static function content(mixed $db = null): array
    {
        $enabled = true;
        $hasOverride = false;
        $raw = '';
        if ($db !== null) {
            try {
                $rows = $db->get_where('platform_settings', ['category' => 'announcement'])->result_array();
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (!is_array($row) || !isset($row['k'])) continue;
                        if ((string) $row['k'] === self::ENABLED_KEY) $enabled = (string) ($row['v'] ?? '') === '1';
                        if ((string) $row['k'] === self::MESSAGES_KEY) { $hasOverride = true; $raw = (string) ($row['v'] ?? ''); }
                    }
                }
            } catch (\Throwable $e) { /* fail-closed to env/defaults */ }
        }
        if ($hasOverride) {
            $messages = self::split($raw);
        } else {
            $env = (string) (getenv('VP_ANNOUNCEMENT') ?: getenv('ANNOUNCEMENT') ?: '');
            $messages = self::split($env);
            if (!$messages) $messages = self::defaults();
        }
        return ['enabled' => $enabled, 'messages' => $messages];
    }

    /** One message per line; the legacy env "|" separator still works. */
    public static function split(string $raw): array
    {
        $parts = preg_split('/\r\n|\r|\n|\|/', $raw);
        if (!is_array($parts)) return [];
        return array_values(array_filter(array_map('trim', $parts), fn($m) => $m !== ''));
    }
}
