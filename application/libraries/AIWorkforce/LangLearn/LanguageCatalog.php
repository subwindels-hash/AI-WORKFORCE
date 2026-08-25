<?php
namespace AIWorkforce\LangLearn;

/**
 * Large-language catalog built from ISO 639 — not a fake list of 7,100
 * invented names. Featured languages (ISO 639-1 + the authored AI banks)
 * ship in this class. The official SIL ISO 639-3 code table can be dropped
 * into application/data/iso639-3.tab to expand the searchable catalog to
 * the full ~7,900 identifiers / ~7,100 living languages.
 *
 * Capability flags are overlaid honestly:
 *   full_ai      — authored assessment / lesson bank exists
 *   translation  — phrasebook / dictionary coverage
 *   tts          — a well-known browser TTS locale exists
 *   stt          — a well-known Web Speech recognition locale exists
 *   text_only    — none of the above
 */
final class LanguageCatalog
{
    private static ?array $index = null;
    private static ?array $byCode = null;

    /** Browser TTS / STT locales that desktop Chrome, Edge and Safari actually ship. */
    public const VOICE_LOCALES = [
        'af' => 'af-ZA', 'am' => 'am-ET', 'ar' => 'ar-SA', 'az' => 'az-AZ', 'bg' => 'bg-BG',
        'bn' => 'bn-IN', 'bs' => 'bs-BA', 'ca' => 'ca-ES', 'cs' => 'cs-CZ', 'cy' => 'cy-GB',
        'da' => 'da-DK', 'de' => 'de-DE', 'el' => 'el-GR', 'en' => 'en-GB', 'es' => 'es-ES',
        'et' => 'et-EE', 'eu' => 'eu-ES', 'fa' => 'fa-IR', 'fi' => 'fi-FI', 'fil' => 'fil-PH',
        'fr' => 'fr-FR', 'ga' => 'ga-IE', 'gl' => 'gl-ES', 'gu' => 'gu-IN', 'he' => 'he-IL',
        'hi' => 'hi-IN', 'hr' => 'hr-HR', 'hu' => 'hu-HU', 'hy' => 'hy-AM', 'id' => 'id-ID',
        'is' => 'is-IS', 'it' => 'it-IT', 'ja' => 'ja-JP', 'jv' => 'jv-ID', 'ka' => 'ka-GE',
        'kk' => 'kk-KZ', 'km' => 'km-KH', 'kn' => 'kn-IN', 'ko' => 'ko-KR', 'lo' => 'lo-LA',
        'lt' => 'lt-LT', 'lv' => 'lv-LV', 'mk' => 'mk-MK', 'ml' => 'ml-IN', 'mn' => 'mn-MN',
        'mr' => 'mr-IN', 'ms' => 'ms-MY', 'mt' => 'mt-MT', 'my' => 'my-MM', 'nb' => 'nb-NO',
        'ne' => 'ne-NP', 'nl' => 'nl-NL', 'no' => 'nb-NO', 'pl' => 'pl-PL', 'ps' => 'ps-AF',
        'pt' => 'pt-PT', 'ro' => 'ro-RO', 'ru' => 'ru-RU', 'si' => 'si-LK', 'sk' => 'sk-SK',
        'sl' => 'sl-SI', 'sq' => 'sq-AL', 'sr' => 'sr-RS', 'su' => 'su-ID', 'sv' => 'sv-SE',
        'sw' => 'sw-KE', 'ta' => 'ta-IN', 'te' => 'te-IN', 'th' => 'th-TH', 'tr' => 'tr-TR',
        'uk' => 'uk-UA', 'ur' => 'ur-PK', 'uz' => 'uz-UZ', 'vi' => 'vi-VN', 'yue' => 'yue-HK',
        'zh' => 'zh-CN', 'zu' => 'zu-ZA', 'tl' => 'fil-PH',
    ];

    /** Web Speech recognition is a slightly smaller set than TTS. */
    public const STT_LOCALES = [
        'af' => 'af-ZA', 'am' => 'am-ET', 'ar' => 'ar-SA', 'az' => 'az-AZ', 'bg' => 'bg-BG',
        'bn' => 'bn-IN', 'ca' => 'ca-ES', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'de' => 'de-DE',
        'el' => 'el-GR', 'en' => 'en-GB', 'es' => 'es-ES', 'et' => 'et-EE', 'eu' => 'eu-ES',
        'fa' => 'fa-IR', 'fi' => 'fi-FI', 'fil' => 'fil-PH', 'fr' => 'fr-FR', 'gl' => 'gl-ES',
        'gu' => 'gu-IN', 'he' => 'he-IL', 'hi' => 'hi-IN', 'hr' => 'hr-HR', 'hu' => 'hu-HU',
        'hy' => 'hy-AM', 'id' => 'id-ID', 'is' => 'is-IS', 'it' => 'it-IT', 'ja' => 'ja-JP',
        'jv' => 'jv-ID', 'ka' => 'ka-GE', 'km' => 'km-KH', 'kn' => 'kn-IN', 'ko' => 'ko-KR',
        'lo' => 'lo-LA', 'lt' => 'lt-LT', 'lv' => 'lv-LV', 'mk' => 'mk-MK', 'ml' => 'ml-IN',
        'mn' => 'mn-MN', 'mr' => 'mr-IN', 'ms' => 'ms-MY', 'my' => 'my-MM', 'nb' => 'nb-NO',
        'ne' => 'ne-NP', 'nl' => 'nl-NL', 'no' => 'nb-NO', 'pl' => 'pl-PL', 'pt' => 'pt-PT',
        'ro' => 'ro-RO', 'ru' => 'ru-RU', 'si' => 'si-LK', 'sk' => 'sk-SK', 'sl' => 'sl-SI',
        'sq' => 'sq-AL', 'sr' => 'sr-RS', 'su' => 'su-ID', 'sv' => 'sv-SE', 'sw' => 'sw-KE',
        'ta' => 'ta-IN', 'te' => 'te-IN', 'th' => 'th-TH', 'tr' => 'tr-TR', 'uk' => 'uk-UA',
        'ur' => 'ur-PK', 'uz' => 'uz-UZ', 'vi' => 'vi-VN', 'zh' => 'zh-CN', 'zu' => 'zu-ZA',
        'tl' => 'fil-PH',
    ];

    public static function reset(): void
    {
        self::$index = null;
        self::$byCode = null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        self::boot();
        return self::$index;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    public static function get(string $code): ?array
    {
        self::boot();
        $code = strtolower(trim($code));
        if ($code === '') return null;
        if (isset(self::$byCode[$code])) return self::$byCode[$code];
        foreach (self::$byCode as $row) {
            if (($row['iso6393'] ?? '') === $code || ($row['iso6391'] ?? '') === $code) return $row;
        }
        return null;
    }

    public static function has(string $code): bool
    {
        return self::get($code) !== null;
    }

    public static function localeFor(string $code): string
    {
        $row = self::get($code);
        if ($row && !empty($row['bcp47'])) return (string) $row['bcp47'];
        $code = strtolower(trim($code));
        return self::VOICE_LOCALES[$code] ?? ($code !== '' ? $code : 'en-GB');
    }

    /**
     * Search by English name, native name or ISO / BCP-47 code.
     * @return array<int, array<string,mixed>>
     */
    public static function search(string $query, int $limit = 20): array
    {
        self::boot();
        $q = mb_strtolower(trim($query), 'UTF-8');
        $limit = max(1, min(80, $limit));
        if ($q === '') {
            $featured = array_values(array_filter(self::$index, fn($r) => !empty($r['featured'])));
            usort($featured, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return array_slice($featured, 0, $limit);
        }
        $scored = [];
        foreach (self::$index as $row) {
            $hay = [
                (string) ($row['code'] ?? ''),
                (string) ($row['iso6391'] ?? ''),
                (string) ($row['iso6393'] ?? ''),
                (string) ($row['iso_code'] ?? ''),
                mb_strtolower((string) ($row['name'] ?? ''), 'UTF-8'),
                mb_strtolower((string) ($row['native_name'] ?? ''), 'UTF-8'),
                mb_strtolower((string) ($row['bcp47'] ?? ''), 'UTF-8'),
            ];
            $score = 0;
            foreach ($hay as $h) {
                if ($h === '') continue;
                if ($h === $q) { $score = max($score, 100); continue; }
                if (str_starts_with($h, $q)) { $score = max($score, 80); continue; }
                if (str_contains($h, $q)) $score = max($score, 50);
            }
            if ($score === 0) continue;
            if (!empty($row['full_ai'])) $score += 8;
            if (!empty($row['featured'])) $score += 4;
            $scored[] = [$score, $row];
        }
        usort($scored, function ($a, $b) {
            if ($a[0] === $b[0]) return strcasecmp($a[1]['name'], $b[1]['name']);
            return $b[0] <=> $a[0];
        });
        return array_map(fn($x) => $x[1], array_slice($scored, 0, $limit));
    }

    public static function capabilities(string $code): array
    {
        $row = self::get($code);
        if (!$row) {
            return [
                'full_ai' => false, 'translation' => false, 'tts' => false, 'stt' => false,
                'text_only' => true, 'label' => 'Unknown language',
            ];
        }
        return [
            'full_ai' => !empty($row['full_ai']),
            'translation' => !empty($row['translation']),
            'tts' => !empty($row['tts']),
            'stt' => !empty($row['stt']),
            'text_only' => !empty($row['text_only']),
            'label' => (string) ($row['support_label'] ?? 'Text only'),
        ];
    }

    public static function officialTablePath(): string
    {
        $candidates = [
            (defined('APPPATH') ? APPPATH : __DIR__ . '/../../../') . 'data/iso639-3.tab',
            (defined('FCPATH') ? FCPATH : dirname(__DIR__, 4) . '/') . 'application/data/iso639-3.tab',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }
        return $candidates[0];
    }

    /** Parse an official SIL ISO 639-3 .tab file into catalog rows. */
    public static function parseOfficialTable(string $path): array
    {
        if (!is_file($path)) return [];
        $fh = fopen($path, 'r');
        if (!$fh) return [];
        $out = [];
        $header = fgetcsv($fh, 0, "\t");
        if (!is_array($header)) { fclose($fh); return []; }
        $map = [];
        foreach ($header as $i => $col) $map[strtolower(trim((string) $col))] = $i;
        $idIdx = $map['id'] ?? 0;
        $p1Idx = $map['part1'] ?? 3;
        $scopeIdx = $map['scope'] ?? 4;
        $typeIdx = $map['language_type'] ?? 5;
        $nameIdx = $map['ref_name'] ?? 6;
        while (($cols = fgetcsv($fh, 0, "\t")) !== false) {
            if (!isset($cols[$idIdx], $cols[$nameIdx])) continue;
            $id = strtolower(trim((string) $cols[$idIdx]));
            $name = trim((string) $cols[$nameIdx]);
            if ($id === '' || $name === '' || !preg_match('/^[a-z]{3}$/', $id)) continue;
            $part1 = strtolower(trim((string) ($cols[$p1Idx] ?? '')));
            $scope = strtoupper(trim((string) ($cols[$scopeIdx] ?? 'I')));
            $type = strtoupper(trim((string) ($cols[$typeIdx] ?? 'L')));
            if ($scope === 'S') continue; // special non-language codes
            $out[] = [
                'iso6393' => $id,
                'iso6391' => preg_match('/^[a-z]{2}$/', $part1) ? $part1 : '',
                'name' => $name,
                'scope' => $scope,
                'type' => $type,
            ];
        }
        fclose($fh);
        return $out;
    }

    private static function boot(): void
    {
        if (self::$index !== null) return;
        $rows = [];
        foreach (self::seed() as $row) {
            $rows[self::key($row)] = $row;
        }
        $official = self::officialTablePath();
        if (is_file($official)) {
            foreach (self::parseOfficialTable($official) as $raw) {
                $key = $raw['iso6391'] !== '' ? $raw['iso6391'] : $raw['iso6393'];
                if (isset($rows[$key])) {
                    $rows[$key]['iso6393'] = $rows[$key]['iso6393'] ?: $raw['iso6393'];
                    continue;
                }
                if (isset($rows[$raw['iso6393']])) continue;
                $rows[$raw['iso6393']] = self::decorate([
                    'code' => $raw['iso6391'] !== '' ? $raw['iso6391'] : $raw['iso6393'],
                    'iso6391' => $raw['iso6391'],
                    'iso6393' => $raw['iso6393'],
                    'name' => $raw['name'],
                    'native_name' => $raw['name'],
                    'writing_system' => 'unspecified',
                    'direction' => 'ltr',
                    'featured' => false,
                    'type' => $raw['type'],
                ]);
            }
        }
        self::$index = array_values($rows);
        self::$byCode = [];
        foreach (self::$index as $row) {
            self::$byCode[$row['code']] = $row;
            if (!empty($row['iso6391'])) self::$byCode[$row['iso6391']] = $row;
            if (!empty($row['iso6393'])) self::$byCode[$row['iso6393']] = $row;
        }
    }

    private static function key(array $row): string
    {
        return (string) ($row['code'] ?? $row['iso6393'] ?? $row['iso6391']);
    }

    private static function decorate(array $row): array
    {
        $code = strtolower(trim((string) ($row['code'] ?? $row['iso6391'] ?? $row['iso6393'] ?? '')));
        $iso1 = strtolower(trim((string) ($row['iso6391'] ?? (strlen($code) === 2 ? $code : ''))));
        $iso3 = strtolower(trim((string) ($row['iso6393'] ?? (strlen($code) === 3 ? $code : ''))));
        $reg = $iso1 !== '' ? LanguageRegistry::get($iso1) : LanguageRegistry::get($code);
        $bank = 0;
        if ($iso1 !== '') $bank = ItemBanks::count($iso1);
        if ($bank === 0 && $code !== '') $bank = ItemBanks::count($code);
        $fullAi = $bank > 0;
        $translation = self::hasTranslation($iso1 !== '' ? $iso1 : $code);
        $ttsKey = $iso1 !== '' ? $iso1 : $code;
        $tts = isset(self::VOICE_LOCALES[$ttsKey]) || isset(self::VOICE_LOCALES[$code]);
        $stt = isset(self::STT_LOCALES[$ttsKey]) || isset(self::STT_LOCALES[$code]);
        $bcp47 = $row['bcp47'] ?? (self::VOICE_LOCALES[$ttsKey] ?? self::VOICE_LOCALES[$code] ?? ($iso1 !== '' ? $iso1 : $iso3));
        $dir = $row['direction'] ?? ($reg['direction'] ?? self::defaultDirection($iso1 !== '' ? $iso1 : $code));
        $script = $row['writing_system'] ?? ($reg['writing_system'] ?? 'latin');
        $native = $row['native_name'] ?? ($reg['native_name'] ?? $row['name']);
        $label = $fullAi ? 'Supported for full AI learning'
            : ($translation && $tts ? 'Translation and voice available'
            : ($translation ? 'Translation available'
            : ($tts ? 'Voice available'
            : ($stt ? 'Speech recognition available' : 'Text only'))));
        return [
            'code' => $iso1 !== '' ? $iso1 : $code,
            'iso_code' => $iso1 !== '' ? $iso1 : $iso3,
            'iso6391' => $iso1,
            'iso6393' => $iso3,
            'name' => (string) $row['name'],
            'native_name' => (string) $native,
            'bcp47' => (string) $bcp47,
            'locale' => (string) $bcp47,
            'writing_system' => (string) $script,
            'direction' => in_array($dir, ['ltr', 'rtl'], true) ? $dir : 'ltr',
            'featured' => (bool) ($row['featured'] ?? ($fullAi || $iso1 !== '')),
            'type' => (string) ($row['type'] ?? 'L'),
            'full_ai' => $fullAi,
            'translation' => $translation,
            'tts' => $tts,
            'stt' => $stt,
            'text_only' => !$fullAi && !$translation && !$tts && !$stt,
            'support_label' => $label,
            'assessment_bank' => $bank,
        ];
    }

    private static function hasTranslation(string $code): bool
    {
        static $codes = null;
        if ($codes === null) {
            $codes = ['en' => true, 'fr' => true, 'es' => true, 'de' => true, 'it' => true, 'pt' => true, 'nl' => true, 'tr' => true, 'sw' => true];
        }
        return isset($codes[$code]);
    }

    private static function defaultDirection(string $code): string
    {
        return in_array($code, ['ar', 'he', 'fa', 'ur', 'ps', 'yi', 'dv', 'ku'], true) ? 'rtl' : 'ltr';
    }

    /**
     * Featured / ISO 639-1 seed. Every row is a real language with a real
     * ISO code. Native names are the commonly used endonym; when a widely
     * accepted endonym is not recorded we keep the English reference name.
     */
    private static function seed(): array
    {
        $mk = function (
            string $iso1,
            string $iso3,
            string $name,
            string $native,
            string $script = 'latin',
            string $dir = 'ltr',
            ?string $bcp47 = null
        ): array {
            return self::decorate([
                'code' => $iso1, 'iso6391' => $iso1, 'iso6393' => $iso3,
                'name' => $name, 'native_name' => $native, 'writing_system' => $script,
                'direction' => $dir, 'bcp47' => $bcp47, 'featured' => true, 'type' => 'L',
            ]);
        };

        $rows = [
            $mk('aa', 'aar', 'Afar', 'Qafaraf'),
            $mk('ab', 'abk', 'Abkhazian', 'Аҧсуа', 'cyrillic'),
            $mk('ae', 'ave', 'Avestan', 'Avesta', 'avestan'),
            $mk('af', 'afr', 'Afrikaans', 'Afrikaans', 'latin', 'ltr', 'af-ZA'),
            $mk('ak', 'aka', 'Akan', 'Akan'),
            $mk('am', 'amh', 'Amharic', 'አማርኛ', 'ethiopic', 'ltr', 'am-ET'),
            $mk('an', 'arg', 'Aragonese', 'Aragonés'),
            $mk('ar', 'ara', 'Arabic', 'العربية', 'arabic', 'rtl', 'ar-SA'),
            $mk('as', 'asm', 'Assamese', 'অসমীয়া', 'bengali'),
            $mk('av', 'ava', 'Avaric', 'Авар', 'cyrillic'),
            $mk('ay', 'aym', 'Aymara', 'Aymar aru'),
            $mk('az', 'aze', 'Azerbaijani', 'Azərbaycan', 'latin', 'ltr', 'az-AZ'),
            $mk('ba', 'bak', 'Bashkir', 'Башҡорт', 'cyrillic'),
            $mk('be', 'bel', 'Belarusian', 'Беларуская', 'cyrillic'),
            $mk('bg', 'bul', 'Bulgarian', 'Български', 'cyrillic', 'ltr', 'bg-BG'),
            $mk('bi', 'bis', 'Bislama', 'Bislama'),
            $mk('bm', 'bam', 'Bambara', 'Bamanankan'),
            $mk('bn', 'ben', 'Bengali', 'বাংলা', 'bengali', 'ltr', 'bn-IN'),
            $mk('bo', 'bod', 'Tibetan', 'བོད་སྐད་', 'tibetan'),
            $mk('br', 'bre', 'Breton', 'Brezhoneg'),
            $mk('bs', 'bos', 'Bosnian', 'Bosanski', 'latin', 'ltr', 'bs-BA'),
            $mk('ca', 'cat', 'Catalan', 'Català', 'latin', 'ltr', 'ca-ES'),
            $mk('ce', 'che', 'Chechen', 'Нохчийн', 'cyrillic'),
            $mk('ch', 'cha', 'Chamorro', 'Chamoru'),
            $mk('co', 'cos', 'Corsican', 'Corsu'),
            $mk('cr', 'cre', 'Cree', 'ᓀᐦᐃᔭᐍᐏᐣ', 'canadian-aboriginal'),
            $mk('cs', 'ces', 'Czech', 'Čeština', 'latin', 'ltr', 'cs-CZ'),
            $mk('cu', 'chu', 'Church Slavic', 'Словѣньскъ', 'cyrillic'),
            $mk('cv', 'chv', 'Chuvash', 'Чӑваш', 'cyrillic'),
            $mk('cy', 'cym', 'Welsh', 'Cymraeg', 'latin', 'ltr', 'cy-GB'),
            $mk('da', 'dan', 'Danish', 'Dansk', 'latin', 'ltr', 'da-DK'),
            $mk('de', 'deu', 'German', 'Deutsch', 'latin', 'ltr', 'de-DE'),
            $mk('dv', 'div', 'Dhivehi', 'ދިވެހި', 'thaana', 'rtl'),
            $mk('dz', 'dzo', 'Dzongkha', 'རྫོང་ཁ', 'tibetan'),
            $mk('ee', 'ewe', 'Ewe', 'Eʋegbe'),
            $mk('el', 'ell', 'Greek', 'Ελληνικά', 'greek', 'ltr', 'el-GR'),
            $mk('en', 'eng', 'English', 'English', 'latin', 'ltr', 'en-GB'),
            $mk('eo', 'epo', 'Esperanto', 'Esperanto'),
            $mk('es', 'spa', 'Spanish', 'Español', 'latin', 'ltr', 'es-ES'),
            $mk('et', 'est', 'Estonian', 'Eesti', 'latin', 'ltr', 'et-EE'),
            $mk('eu', 'eus', 'Basque', 'Euskara', 'latin', 'ltr', 'eu-ES'),
            $mk('fa', 'fas', 'Persian', 'فارسی', 'arabic', 'rtl', 'fa-IR'),
            $mk('ff', 'ful', 'Fulah', 'Fulfulde'),
            $mk('fi', 'fin', 'Finnish', 'Suomi', 'latin', 'ltr', 'fi-FI'),
            $mk('fj', 'fij', 'Fijian', 'Na Vosa Vakaviti'),
            $mk('fo', 'fao', 'Faroese', 'Føroyskt'),
            $mk('fr', 'fra', 'French', 'Français', 'latin', 'ltr', 'fr-FR'),
            $mk('fy', 'fry', 'Western Frisian', 'Frysk'),
            $mk('ga', 'gle', 'Irish', 'Gaeilge', 'latin', 'ltr', 'ga-IE'),
            $mk('gd', 'gla', 'Scottish Gaelic', 'Gàidhlig'),
            $mk('gl', 'glg', 'Galician', 'Galego', 'latin', 'ltr', 'gl-ES'),
            $mk('gn', 'grn', 'Guarani', 'Avañeʼẽ'),
            $mk('gu', 'guj', 'Gujarati', 'ગુજરાતી', 'gujarati', 'ltr', 'gu-IN'),
            $mk('gv', 'glv', 'Manx', 'Gaelg'),
            $mk('ha', 'hau', 'Hausa', 'Hausa', 'latin', 'ltr', 'ha-NG'),
            $mk('he', 'heb', 'Hebrew', 'עברית', 'hebrew', 'rtl', 'he-IL'),
            $mk('hi', 'hin', 'Hindi', 'हिन्दी', 'devanagari', 'ltr', 'hi-IN'),
            $mk('ho', 'hmo', 'Hiri Motu', 'Hiri Motu'),
            $mk('hr', 'hrv', 'Croatian', 'Hrvatski', 'latin', 'ltr', 'hr-HR'),
            $mk('ht', 'hat', 'Haitian Creole', 'Kreyòl ayisyen'),
            $mk('hu', 'hun', 'Hungarian', 'Magyar', 'latin', 'ltr', 'hu-HU'),
            $mk('hy', 'hye', 'Armenian', 'Հայերեն', 'armenian', 'ltr', 'hy-AM'),
            $mk('hz', 'her', 'Herero', 'Otjiherero'),
            $mk('ia', 'ina', 'Interlingua', 'Interlingua'),
            $mk('id', 'ind', 'Indonesian', 'Bahasa Indonesia', 'latin', 'ltr', 'id-ID'),
            $mk('ie', 'ile', 'Interlingue', 'Interlingue'),
            $mk('ig', 'ibo', 'Igbo', 'Igbo', 'latin', 'ltr', 'ig-NG'),
            $mk('ii', 'iii', 'Sichuan Yi', 'ꆈꌠꉙ', 'yi'),
            $mk('ik', 'ipk', 'Inupiaq', 'Iñupiaq'),
            $mk('io', 'ido', 'Ido', 'Ido'),
            $mk('is', 'isl', 'Icelandic', 'Íslenska', 'latin', 'ltr', 'is-IS'),
            $mk('it', 'ita', 'Italian', 'Italiano', 'latin', 'ltr', 'it-IT'),
            $mk('iu', 'iku', 'Inuktitut', 'ᐃᓄᒃᑎᑐᑦ', 'canadian-aboriginal'),
            $mk('ja', 'jpn', 'Japanese', '日本語', 'kana', 'ltr', 'ja-JP'),
            $mk('jv', 'jav', 'Javanese', 'Basa Jawa', 'latin', 'ltr', 'jv-ID'),
            $mk('ka', 'kat', 'Georgian', 'ქართული', 'georgian', 'ltr', 'ka-GE'),
            $mk('kg', 'kon', 'Kongo', 'Kikongo'),
            $mk('ki', 'kik', 'Kikuyu', 'Gĩkũyũ'),
            $mk('kj', 'kua', 'Kuanyama', 'Oshikwanyama'),
            $mk('kk', 'kaz', 'Kazakh', 'Қазақ', 'cyrillic', 'ltr', 'kk-KZ'),
            $mk('kl', 'kal', 'Kalaallisut', 'Kalaallisut'),
            $mk('km', 'khm', 'Khmer', 'ខ្មែរ', 'khmer', 'ltr', 'km-KH'),
            $mk('kn', 'kan', 'Kannada', 'ಕನ್ನಡ', 'kannada', 'ltr', 'kn-IN'),
            $mk('ko', 'kor', 'Korean', '한국어', 'hangul', 'ltr', 'ko-KR'),
            $mk('kr', 'kau', 'Kanuri', 'Kanuri'),
            $mk('ks', 'kas', 'Kashmiri', 'کٲشُر', 'arabic', 'rtl'),
            $mk('ku', 'kur', 'Kurdish', 'Kurdî'),
            $mk('kv', 'kom', 'Komi', 'Коми', 'cyrillic'),
            $mk('kw', 'cor', 'Cornish', 'Kernewek'),
            $mk('ky', 'kir', 'Kyrgyz', 'Кыргызча', 'cyrillic'),
            $mk('la', 'lat', 'Latin', 'Latina'),
            $mk('lb', 'ltz', 'Luxembourgish', 'Lëtzebuergesch'),
            $mk('lg', 'lug', 'Ganda', 'Luganda'),
            $mk('li', 'lim', 'Limburgan', 'Limburgs'),
            $mk('ln', 'lin', 'Lingala', 'Lingála'),
            $mk('lo', 'lao', 'Lao', 'ລາວ', 'lao', 'ltr', 'lo-LA'),
            $mk('lt', 'lit', 'Lithuanian', 'Lietuvių', 'latin', 'ltr', 'lt-LT'),
            $mk('lu', 'lub', 'Luba-Katanga', 'Tshiluba'),
            $mk('lv', 'lav', 'Latvian', 'Latviešu', 'latin', 'ltr', 'lv-LV'),
            $mk('mg', 'mlg', 'Malagasy', 'Malagasy'),
            $mk('mh', 'mah', 'Marshallese', 'Kajin M̧ajeļ'),
            $mk('mi', 'mri', 'Māori', 'Māori'),
            $mk('mk', 'mkd', 'Macedonian', 'Македонски', 'cyrillic', 'ltr', 'mk-MK'),
            $mk('ml', 'mal', 'Malayalam', 'മലയാളം', 'malayalam', 'ltr', 'ml-IN'),
            $mk('mn', 'mon', 'Mongolian', 'Монгол', 'cyrillic', 'ltr', 'mn-MN'),
            $mk('mr', 'mar', 'Marathi', 'मराठी', 'devanagari', 'ltr', 'mr-IN'),
            $mk('ms', 'msa', 'Malay', 'Bahasa Melayu', 'latin', 'ltr', 'ms-MY'),
            $mk('mt', 'mlt', 'Maltese', 'Malti', 'latin', 'ltr', 'mt-MT'),
            $mk('my', 'mya', 'Burmese', 'မြန်မာ', 'myanmar', 'ltr', 'my-MM'),
            $mk('na', 'nau', 'Nauru', 'Dorerin Naoero'),
            $mk('nb', 'nob', 'Norwegian Bokmål', 'Norsk bokmål', 'latin', 'ltr', 'nb-NO'),
            $mk('nd', 'nde', 'North Ndebele', 'isiNdebele'),
            $mk('ne', 'nep', 'Nepali', 'नेपाली', 'devanagari', 'ltr', 'ne-NP'),
            $mk('ng', 'ndo', 'Ndonga', 'Owambo'),
            $mk('nl', 'nld', 'Dutch', 'Nederlands', 'latin', 'ltr', 'nl-NL'),
            $mk('nn', 'nno', 'Norwegian Nynorsk', 'Norsk nynorsk'),
            $mk('no', 'nor', 'Norwegian', 'Norsk', 'latin', 'ltr', 'nb-NO'),
            $mk('nr', 'nbl', 'South Ndebele', 'isiNdebele'),
            $mk('nv', 'nav', 'Navajo', 'Diné bizaad'),
            $mk('ny', 'nya', 'Chichewa', 'Chichewa'),
            $mk('oc', 'oci', 'Occitan', 'Occitan'),
            $mk('oj', 'oji', 'Ojibwa', 'Anishinaabemowin'),
            $mk('om', 'orm', 'Oromo', 'Afaan Oromoo'),
            $mk('or', 'ori', 'Odia', 'ଓଡ଼ିଆ', 'oriya'),
            $mk('os', 'oss', 'Ossetian', 'Ирон', 'cyrillic'),
            $mk('pa', 'pan', 'Punjabi', 'ਪੰਜਾਬੀ', 'gurmukhi'),
            $mk('pi', 'pli', 'Pali', 'पालि', 'devanagari'),
            $mk('pl', 'pol', 'Polish', 'Polski', 'latin', 'ltr', 'pl-PL'),
            $mk('ps', 'pus', 'Pashto', 'پښتو', 'arabic', 'rtl', 'ps-AF'),
            $mk('pt', 'por', 'Portuguese', 'Português', 'latin', 'ltr', 'pt-PT'),
            $mk('qu', 'que', 'Quechua', 'Runa Simi'),
            $mk('rm', 'roh', 'Romansh', 'Rumantsch'),
            $mk('rn', 'run', 'Rundi', 'Ikirundi'),
            $mk('ro', 'ron', 'Romanian', 'Română', 'latin', 'ltr', 'ro-RO'),
            $mk('ru', 'rus', 'Russian', 'Русский', 'cyrillic', 'ltr', 'ru-RU'),
            $mk('rw', 'kin', 'Kinyarwanda', 'Ikinyarwanda'),
            $mk('sa', 'san', 'Sanskrit', 'संस्कृतम्', 'devanagari'),
            $mk('sc', 'srd', 'Sardinian', 'Sardu'),
            $mk('sd', 'snd', 'Sindhi', 'سنڌي', 'arabic', 'rtl'),
            $mk('se', 'sme', 'Northern Sami', 'Davvisámegiella'),
            $mk('sg', 'sag', 'Sango', 'Sängö'),
            $mk('si', 'sin', 'Sinhala', 'සිංහල', 'sinhala', 'ltr', 'si-LK'),
            $mk('sk', 'slk', 'Slovak', 'Slovenčina', 'latin', 'ltr', 'sk-SK'),
            $mk('sl', 'slv', 'Slovenian', 'Slovenščina', 'latin', 'ltr', 'sl-SI'),
            $mk('sm', 'smo', 'Samoan', 'Gagana Samoa'),
            $mk('sn', 'sna', 'Shona', 'chiShona'),
            $mk('so', 'som', 'Somali', 'Soomaali'),
            $mk('sq', 'sqi', 'Albanian', 'Shqip', 'latin', 'ltr', 'sq-AL'),
            $mk('sr', 'srp', 'Serbian', 'Српски', 'cyrillic', 'ltr', 'sr-RS'),
            $mk('ss', 'ssw', 'Swati', 'siSwati'),
            $mk('st', 'sot', 'Southern Sotho', 'Sesotho'),
            $mk('su', 'sun', 'Sundanese', 'Basa Sunda', 'latin', 'ltr', 'su-ID'),
            $mk('sv', 'swe', 'Swedish', 'Svenska', 'latin', 'ltr', 'sv-SE'),
            $mk('sw', 'swa', 'Swahili', 'Kiswahili', 'latin', 'ltr', 'sw-KE'),
            $mk('ta', 'tam', 'Tamil', 'தமிழ்', 'tamil', 'ltr', 'ta-IN'),
            $mk('te', 'tel', 'Telugu', 'తెలుగు', 'telugu', 'ltr', 'te-IN'),
            $mk('tg', 'tgk', 'Tajik', 'Тоҷикӣ', 'cyrillic'),
            $mk('th', 'tha', 'Thai', 'ไทย', 'thai', 'ltr', 'th-TH'),
            $mk('ti', 'tir', 'Tigrinya', 'ትግርኛ', 'ethiopic'),
            $mk('tk', 'tuk', 'Turkmen', 'Türkmençe'),
            $mk('tl', 'tgl', 'Tagalog', 'Tagalog', 'latin', 'ltr', 'fil-PH'),
            $mk('tn', 'tsn', 'Tswana', 'Setswana'),
            $mk('to', 'ton', 'Tongan', 'Lea faka-Tonga'),
            $mk('tr', 'tur', 'Turkish', 'Türkçe', 'latin', 'ltr', 'tr-TR'),
            $mk('ts', 'tso', 'Tsonga', 'Xitsonga'),
            $mk('tt', 'tat', 'Tatar', 'Татар', 'cyrillic'),
            $mk('tw', 'twi', 'Twi', 'Twi'),
            $mk('ty', 'tah', 'Tahitian', 'Reo Tahiti'),
            $mk('ug', 'uig', 'Uyghur', 'ئۇيغۇرچە', 'arabic', 'rtl'),
            $mk('uk', 'ukr', 'Ukrainian', 'Українська', 'cyrillic', 'ltr', 'uk-UA'),
            $mk('ur', 'urd', 'Urdu', 'اردو', 'arabic', 'rtl', 'ur-PK'),
            $mk('uz', 'uzb', 'Uzbek', 'Oʻzbekcha', 'latin', 'ltr', 'uz-UZ'),
            $mk('ve', 'ven', 'Venda', 'Tshivenḓa'),
            $mk('vi', 'vie', 'Vietnamese', 'Tiếng Việt', 'latin', 'ltr', 'vi-VN'),
            $mk('vo', 'vol', 'Volapük', 'Volapük'),
            $mk('wa', 'wln', 'Walloon', 'Walon'),
            $mk('wo', 'wol', 'Wolof', 'Wolof'),
            $mk('xh', 'xho', 'Xhosa', 'isiXhosa'),
            $mk('yi', 'yid', 'Yiddish', 'ייִדיש', 'hebrew', 'rtl'),
            $mk('yo', 'yor', 'Yoruba', 'Yorùbá', 'latin', 'ltr', 'yo-NG'),
            $mk('za', 'zha', 'Zhuang', 'Vahcuengh'),
            $mk('zh', 'zho', 'Chinese (Mandarin)', '中文', 'han', 'ltr', 'zh-CN'),
            $mk('zu', 'zul', 'Zulu', 'isiZulu', 'latin', 'ltr', 'zu-ZA'),
        ];

        // Additional widely used ISO 639-3 individual languages (real names).
        $extra = [
            ['yue', '', 'yue', 'Cantonese', '廣東話', 'han', 'ltr', 'yue-HK'],
            ['cmn', '', 'cmn', 'Mandarin Chinese', '普通话', 'han', 'ltr', 'zh-CN'],
            ['fil', '', 'fil', 'Filipino', 'Filipino', 'latin', 'ltr', 'fil-PH'],
            ['haw', '', 'haw', 'Hawaiian', 'ʻŌlelo Hawaiʻi'],
            ['ceb', '', 'ceb', 'Cebuano', 'Binisaya'],
            ['hmn', '', 'hmn', 'Hmong', 'Hmoob'],
            ['lus', '', 'lus', 'Mizo', 'Mizo ṭawng'],
            ['pcm', '', 'pcm', 'Nigerian Pidgin', 'Naijá'],
            ['ary', '', 'ary', 'Moroccan Arabic', 'الدارجة', 'arabic', 'rtl'],
            ['arz', '', 'arz', 'Egyptian Arabic', 'مصرى', 'arabic', 'rtl'],
            ['apc', '', 'apc', 'Levantine Arabic', 'شامي', 'arabic', 'rtl'],
            ['ckb', '', 'ckb', 'Central Kurdish', 'سۆرانی', 'arabic', 'rtl'],
            ['kmr', '', 'kmr', 'Northern Kurdish', 'Kurmancî'],
            ['nan', '', 'nan', 'Southern Min', '閩南語', 'han'],
            ['hak', '', 'hak', 'Hakka Chinese', '客家話', 'han'],
            ['wuu', '', 'wuu', 'Wu Chinese', '吴语', 'han'],
            ['swh', '', 'swh', 'Swahili (individual)', 'Kiswahili'],
            ['arb', '', 'arb', 'Standard Arabic', 'العربية الفصحى', 'arabic', 'rtl', 'ar-SA'],
        ];
        foreach ($extra as $e) {
            $rows[] = self::decorate([
                'code' => $e[0], 'iso6391' => $e[1], 'iso6393' => $e[2],
                'name' => $e[3], 'native_name' => $e[4],
                'writing_system' => $e[5] ?? 'latin', 'direction' => $e[6] ?? 'ltr',
                'bcp47' => $e[7] ?? null, 'featured' => true, 'type' => 'L',
            ]);
        }
        return $rows;
    }
}
