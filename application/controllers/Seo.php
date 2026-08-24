<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Public SEO documents generated from the environment-driven SEO settings. */
class Seo extends CI_Controller
{
    private function settings(): array
    {
        $this->config->load('seo', true);
        return $this->config->item('settings', 'seo') ?: [];
    }

    public function robots()
    {
        $seo = $this->settings(); $base = rtrim((string) ($seo['canonical'] ?? ''), '/');
        $body = "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin\nDisallow: /account\n" . ($base !== '' ? "Sitemap: {$base}/sitemap.xml\n" : '');
        $this->output->set_content_type('text/plain')->set_output($body);
    }

    public function sitemap()
    {
        $seo = $this->settings(); $base = rtrim((string) ($seo['canonical'] ?? ''), '/');
        $paths = ['/', '/strategy', '/execution', '/brokers', '/risk', '/paper', '/journal', '/sports', '/sports/tickets', '/leads', '/lead-pipeline', '/app/languages'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($paths as $path) $xml .= '<url><loc>' . htmlspecialchars($base . $path, ENT_XML1, 'UTF-8') . '</loc></url>';
        $xml .= '</urlset>';
        $this->output->set_content_type('application/xml')->set_output($xml);
    }
}
