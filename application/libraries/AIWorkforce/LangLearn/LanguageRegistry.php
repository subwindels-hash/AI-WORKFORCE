<?php
namespace AIWorkforce\LangLearn;

/**
 * LANGUAGE REGISTRY — the single source of truth for the language catalog.
 * Nothing else in the application hard-codes languages: controllers, views,
 * services and the DB seed all read from here. New languages are added by
 * registering them (or seeding a row synced from this catalog).
 *
 * Honesty rules (module-wide): `assessmentBank` reflects the real number of
 * authored assessment items for that language; features list what is
 * actually implemented for it. No language may claim capabilities it does
 * not have, and no level may be reported beyond the bank's ceiling.
 */
final class LanguageRegistry
{
    public const LEVELS = ['Beginner', 'A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

    /** @var array<string, array> keyed by ISO code */
    private static ?array $catalog = null;

    public static function all(): array
    {
        if (self::$catalog === null) self::$catalog = self::build();
        return self::$catalog;
    }

    public static function get(string $code): ?array
    {
        return self::all()[strtolower(trim($code))] ?? null;
    }

    /** Runtime extension point — new languages without a code change elsewhere. */
    public static function register(array $language): void
    {
        $code = strtolower(trim((string) ($language['code'] ?? '')));
        if (!preg_match('/^[a-z]{2,3}$/', $code)) {
            throw new \InvalidArgumentException('language code must be 2-3 letters');
        }
        $language['code'] = $code;
        $language['direction'] = in_array($language['direction'] ?? 'ltr', ['ltr', 'rtl'], true) ? $language['direction'] : 'ltr';
        $language['assessment_bank'] = ItemBanks::count($code);
        $language['features'] = self::featuresFor($code);
        $language['active'] = (bool) ($language['active'] ?? true);
        self::$catalog ??= self::build();
        self::$catalog[$code] = $language;
    }

    public static function isLevel(string $level): bool
    {
        return in_array($level, self::LEVELS, true);
    }

    public static function levelIndex(string $level): int
    {
        $i = array_search($level, self::LEVELS, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * Honest capability table for one language — every flag is derived from
     * authored content that actually exists. Pronunciation scores are never
     * claimed: no pronunciation-assessment provider is configured.
     */
    public static function featuresFor(string $code): array
    {
        $code = strtolower(trim($code));
        $bank = ItemBanks::count($code);
        $hasReading = false;
        $hasGrammar = false;
        if ($bank > 0) {
            foreach (ItemBanks::items($code) as $item) {
                if ($item['skill'] === 'reading') $hasReading = true;
                if ($item['skill'] === 'grammar') $hasGrammar = true;
            }
        }
        $hasVocab = VocabularyBank::count($code) > 0;
        $hasTeacher = TeacherContent::writingTasks($code) !== [];
        return [
            'registry' => true,
            'adaptive_assessment' => $bank > 0,
            'assessment_ceiling' => ItemBanks::ceiling($code),
            'lessons' => $bank > 0,
            'conversation' => $hasTeacher,
            'writing_correction' => $hasTeacher,
            'vocabulary_srs' => $hasVocab,
            'listening' => $hasReading,
            'speaking' => $hasReading,
            'grammar' => $hasGrammar,
            'pronunciation_scores' => false,
        ];
    }

    private static function build(): array
    {
        $mk = fn(string $code, string $name, string $native, string $script, string $dir = 'ltr'): array => [
            'code' => $code, 'name' => $name, 'native_name' => $native, 'iso_code' => $code,
            'writing_system' => $script, 'direction' => $dir,
        ];
        $langs = [
            $mk('nl', 'Dutch', 'Nederlands', 'latin'),
            $mk('es', 'Spanish', 'Español', 'latin'),
            $mk('it', 'Italian', 'Italiano', 'latin'),
            $mk('fr', 'French', 'Français', 'latin'),
            $mk('de', 'German', 'Deutsch', 'latin'),
            $mk('en', 'English', 'English', 'latin'),
            $mk('pt', 'Portuguese', 'Português', 'latin'),
            $mk('ar', 'Arabic', 'العربية', 'arabic', 'rtl'),
            $mk('zh', 'Chinese (Mandarin)', '中文', 'han'),
            $mk('ja', 'Japanese', '日本語', 'kana'),
            $mk('ko', 'Korean', '한국어', 'hangul'),
            $mk('ru', 'Russian', 'Русский', 'cyrillic'),
            $mk('hi', 'Hindi', 'हिन्दी', 'devanagari'),
            $mk('tr', 'Turkish', 'Türkçe', 'latin'),
            $mk('sw', 'Swahili', 'Kiswahili', 'latin'),
            $mk('yo', 'Yoruba', 'Yorùbá', 'latin'),
            $mk('ig', 'Igbo', 'Igbo', 'latin'),
            $mk('ha', 'Hausa', 'Hausa', 'latin'),
            $mk('af', 'Afrikaans', 'Afrikaans', 'latin'),
            $mk('zu', 'Zulu', 'isiZulu', 'latin'),
        ];
        $catalog = [];
        foreach ($langs as $l) {
            $l['assessment_bank'] = ItemBanks::count($l['code']);
            $l['features'] = self::featuresFor($l['code']);
            $l['active'] = true;
            $catalog[$l['code']] = $l;
        }
        return $catalog;
    }
}
