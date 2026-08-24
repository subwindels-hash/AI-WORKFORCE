<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Environment-driven SEO settings; no server-specific values are committed. */
$seoBaseUrl = rtrim((string) (getenv('VP_BASE_URL') ?: ''), '/');
$seo['site_name'] = (string) (getenv('VP_SITE_NAME') ?: 'AEGIS AI Intelligence Platform');
$seo['title_suffix'] = (string) (getenv('VP_SITE_TITLE_SUFFIX') ?: ' · AEGIS');
$seo['description'] = (string) (getenv('VP_SITE_DESCRIPTION') ?: 'Evidence-first AI intelligence for trading, sports, language learning, lottery research and lead discovery.');
$seo['keywords'] = (string) (getenv('VP_SITE_KEYWORDS') ?: 'AI intelligence, trading intelligence, lead discovery, sports intelligence, language learning, EuroMillions');
$seo['robots'] = (string) (getenv('VP_ROBOTS') ?: 'index,follow');
$seo['canonical'] = $seoBaseUrl !== '' ? $seoBaseUrl . '/' : '';
$seo['og_image'] = (string) (getenv('VP_OG_IMAGE') ?: (($seoBaseUrl !== '' ? $seoBaseUrl : '') . '/assets/images/aegis-mark.png'));
$seo['theme_color'] = (string) (getenv('VP_THEME_COLOR') ?: '#07090e');
$config['settings'] = $seo;
