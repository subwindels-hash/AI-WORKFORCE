<?php
namespace AIWorkforce;

/**
 * Public-site SEO settings.
 *
 * Defaults live in application/config/seo.php (environment-driven). Super
 * admins may override any value from System Settings → SEO; overrides are
 * stored in platform_settings (category 'seo'). An empty override means
 * "use the server default", so clearing a field restores env behavior.
 */
class SeoSettings
{
    /** config/seo.php key => platform_settings key */
    public const KEYS = [
        'site_name' => 'seo_site_name',
        'title_suffix' => 'seo_title_suffix',
        'description' => 'seo_description',
        'keywords' => 'seo_keywords',
        'robots' => 'seo_robots',
        'canonical' => 'seo_canonical',
        'og_image' => 'seo_og_image',
        'theme_color' => 'seo_theme_color',
    ];

    public const ROBOTS_OPTIONS = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];

    /**
     * Merge DB overrides over the config defaults. Never throws: any
     * database problem returns the config values untouched.
     */
    public static function effective(array $config, mixed $db = null): array
    {
        if ($db === null) return $config;
        try {
            $rows = $db->get_where('platform_settings', ['category' => 'seo'])->result_array();
        } catch (\Throwable $e) {
            return $config;
        }
        if (!is_array($rows)) return $config;
        $byKey = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['k'])) continue;
            $byKey[(string) $row['k']] = (string) ($row['v'] ?? '');
        }
        foreach (self::KEYS as $short => $full) {
            if (isset($byKey[$full]) && $byKey[$full] !== '') $config[$short] = $byKey[$full];
        }
        return $config;
    }
}
