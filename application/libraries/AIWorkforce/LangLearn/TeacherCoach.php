<?php
namespace AIWorkforce\LangLearn;

/**
 * AI LANGUAGE TEACHER — natural-language routing over stored learner state.
 *
 * Understands requests such as:
 *   "Teach me Dutch from the beginning."
 *   "I want to learn Spanish."
 *   "Practice Italian conversation with me."
 *   "Correct my German."
 *   "Test my French level."
 *
 * The coach never invents a level, a score or a next lesson. It reads the
 * user's real profile (goal, assessment, path, progress) and returns the
 * honest next step plus a reply in the user's explanation language (English
 * in this build — explanations stay in the stored explanation_language).
 */
class TeacherCoach
{
    public const GOALS = [
        'conversation' => 'Daily conversation',
        'travel'       => 'Travel',
        'work'         => 'Work / business',
        'exam'         => 'Exam / certification',
        'family'       => 'Family / community',
        'relocation'   => 'Living in the country',
        'culture'      => 'Culture and media',
        'beginner'     => 'From the beginning',
    ];

    public function __construct(
        private LangLearnService $core,
        private TeacherService $teacher,
    ) {}

    /**
     * Parse a free-form request, ensure a language profile exists, and return
     * the next honest action for this learner.
     *
     * @return array{
     *   intent:string, languageCode:?string, languageName:?string,
     *   profile:?array, nextStep:string, reply:string,
     *   actions:array<int, array{label:string, href:string, method?:string}>,
     *   parsed:array
     * }
     */
    public function interpret(int $userId, string $message, ?int $profileId = null): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 400) {
            throw new \InvalidArgumentException('message must be 1–400 characters');
        }
        $parsed = self::parse($message);
        $code = $parsed['languageCode'];

        if ($profileId) {
            $profile = $this->core->profileOwned($profileId, $userId);
            $code = $code ?: $profile['language_code'];
        } elseif ($code) {
            $profile = $this->core->startLanguage(
                $userId,
                $code,
                $parsed['goal'] ?? null,
                'en'
            );
        } else {
            $mine = $this->core->profiles($userId);
            if (count($mine) === 1) {
                $profile = $this->core->profileOwned((int) $mine[0]['id'], $userId);
                $code = $profile['language_code'];
            } else {
                return $this->pack('unknown', null, null, null, 'choose_language',
                    'Which language would you like to learn? Pick one from the catalog — I will not guess.',
                    [['label' => 'Open language catalog', 'href' => '/app/languages']],
                    $parsed);
            }
        }

        $lang = $this->core->language($code);
        $name = (string) ($lang['name'] ?? $code);
        $onboarding = $this->core->onboardingFor($profile);
        $intent = $parsed['intent'];

        if ($intent === 'assess') {
            if (ItemBanks::count($code) === 0) {
                return $this->pack('assess', $code, $name, $profile, 'unavailable',
                    "{$name} is in the catalog, but its assessment bank is not authored yet. No level will be invented.",
                    [['label' => "Open {$name} profile", 'href' => '/app/languages/p/' . (int) $profile['id']]],
                    $parsed);
            }
            return $this->pack('assess', $code, $name, $profile, 'assess',
                "Great. Let us first determine your current {$name} level. The result comes only from your answers.",
                [['label' => "Start {$name} assessment", 'href' => '/app/languages/p/' . (int) $profile['id'] . '/assessment/start', 'method' => 'POST']],
                $parsed);
        }

        if ($intent === 'converse') {
            $scenarios = $this->teacher->conversations($userId, (int) $profile['id']);
            if (!$scenarios) {
                return $this->pack('converse', $code, $name, $profile, 'unavailable',
                    "Conversation drills for {$name} are not authored yet — they will not be simulated.",
                    [['label' => "Open {$name} profile", 'href' => '/app/languages/p/' . (int) $profile['id']]],
                    $parsed);
            }
            return $this->pack('converse', $code, $name, $profile, 'conversation',
                "Let us practise {$name} conversation. Choose a scenario and a correction preference — I will not interrupt every word unless you ask.",
                [['label' => "{$name} conversations", 'href' => '/app/languages/conv/' . (int) $profile['id']]],
                $parsed);
        }

        if ($intent === 'correct' || $intent === 'writing') {
            $tasks = $this->teacher->writingTasks($userId, (int) $profile['id']);
            if (!$tasks) {
                return $this->pack('correct', $code, $name, $profile, 'unavailable',
                    "Writing tasks for {$name} are not authored yet.",
                    [['label' => "Open {$name} profile", 'href' => '/app/languages/p/' . (int) $profile['id']]],
                    $parsed);
            }
            return $this->pack('correct', $code, $name, $profile, 'writing',
                "Write in {$name}. I will keep your original text, show a reconstructed version from the required elements, and a more natural model sentence — I will not pretend to fully copy-edit free-form grammar.",
                [['label' => "{$name} writing practice", 'href' => '/app/languages/w/' . (int) $profile['id']]],
                $parsed);
        }

        if ($intent === 'grammar') {
            return $this->pack('grammar', $code, $name, $profile, 'grammar',
                "Here are the verified {$name} grammar rules I can teach. Ask me to explain one more simply if it is unclear.",
                [['label' => "{$name} grammar", 'href' => '/app/languages/g/' . (int) $profile['id']]],
                $parsed);
        }

        if ($intent === 'vocabulary') {
            return $this->pack('vocabulary', $code, $name, $profile, 'vocabulary',
                "We will learn {$name} words with a real spaced-repetition schedule. Reviews move 1 → 3 → 7 → 14 → 30 → 90 days only when you remember them.",
                [['label' => "{$name} vocabulary", 'href' => '/app/languages/v/' . (int) $profile['id']]],
                $parsed);
        }

        // Default: learn / teach — follow the real onboarding cycle.
        return $this->learnReply($profile, $lang, $onboarding, $parsed);
    }

    /** Goal catalog for the onboarding form. */
    public static function goalOptions(): array
    {
        return self::GOALS;
    }

    /**
     * Deterministic intent + language extraction. Unknown languages stay null
     * rather than being guessed.
     *
     * @return array{intent:string, languageCode:?string, goal:?string, raw:string}
     */
    public static function parse(string $message): array
    {
        $raw = trim($message);
        $hay = mb_strtolower($raw);
        $intent = 'learn';
        if (preg_match('/\b(test|assess|assessment|level|placement)\b/u', $hay)) $intent = 'assess';
        elseif (preg_match('/\b(convers|talk|chat|speak with|practice .+ conversation)\b/u', $hay)) $intent = 'converse';
        elseif (preg_match('/\b(correct|correction|check my writing|proofread)\b/u', $hay)) $intent = 'correct';
        elseif (preg_match('/\b(writ(e|ing))\b/u', $hay)) $intent = 'writing';
        elseif (preg_match('/\bgrammar\b/u', $hay)) $intent = 'grammar';
        elseif (preg_match('/\b(vocab\w*|flashcards?|words?)\b/u', $hay)) $intent = 'vocabulary';
        elseif (preg_match('/\b(teach|learn|study|start)\b/u', $hay)) $intent = 'learn';

        $goal = null;
        if (preg_match('/from the beginning|absolute beginner|start from (zero|scratch)/u', $hay)) {
            $goal = self::GOALS['beginner'];
        } elseif (preg_match('/\btravel\b/u', $hay)) {
            $goal = self::GOALS['travel'];
        } elseif (preg_match('/\b(work|business|job)\b/u', $hay)) {
            $goal = self::GOALS['work'];
        } elseif (preg_match('/\bexam\b/u', $hay)) {
            $goal = self::GOALS['exam'];
        } elseif (preg_match('/\b(conversation|speak|talk)\b/u', $hay) && $intent === 'learn') {
            $goal = self::GOALS['conversation'];
        }

        return [
            'intent' => $intent,
            'languageCode' => self::matchLanguage($hay),
            'goal' => $goal,
            'raw' => $raw,
        ];
    }

    /** Resolve an ISO code from a free-text mention, or null if none matches. */
    public static function matchLanguage(string $hay): ?string
    {
        $hay = mb_strtolower(trim($hay));
        if ($hay === '') return null;

        $candidates = [];
        foreach (LanguageRegistry::all() as $code => $lang) {
            $names = array_filter([
                $code,
                mb_strtolower((string) ($lang['name'] ?? '')),
                mb_strtolower((string) ($lang['native_name'] ?? '')),
            ]);
            foreach ($names as $name) {
                if ($name === '') continue;
                $candidates[] = ['code' => $code, 'name' => $name, 'len' => mb_strlen($name)];
            }
        }
        // Extra well-known aliases that are not the registry English name.
        foreach ([
            'mandarin' => 'zh', 'putonghua' => 'zh', 'castilian' => 'es',
            'flemish' => 'nl', 'brazilian' => 'pt', 'portuguese' => 'pt',
            'nederlands' => 'nl', 'deutsch' => 'de', 'francais' => 'fr', 'français' => 'fr',
        ] as $alias => $code) {
            $candidates[] = ['code' => $code, 'name' => $alias, 'len' => mb_strlen($alias)];
        }
        usort($candidates, fn($a, $b) => $b['len'] <=> $a['len']);
        foreach ($candidates as $c) {
            if ($c['len'] <= 2) {
                if (preg_match('/\b' . preg_quote($c['name'], '/') . '\b/u', $hay)) return $c['code'];
                continue;
            }
            if (mb_strpos($hay, $c['name']) !== false) return $c['code'];
        }
        return null;
    }

    /** Word that looks like a language name but is not in the catalog. */
    public static function unmatchedLanguageName(string $message): ?string
    {
        $hay = mb_strtolower(trim($message));
        if (!preg_match('/(?:teach me|learn|test my|correct my|practice)\s+([a-z\p{L}-]+)/u', $hay, $m)) {
            return null;
        }
        $token = $m[1];
        if (preg_match('/^(level|conversation|writing|grammar|vocabulary|words|lesson|it|this)$/u', $token)) {
            return null;
        }
        return self::matchLanguage($token) === null ? $token : null;
    }

    private function learnReply(array $profile, array $lang, array $onboarding, array $parsed): array
    {
        $code = $profile['language_code'];
        $name = (string) ($lang['name'] ?? $code);
        $pid = (int) $profile['id'];
        $next = $onboarding['next'];

        if ($next === 'set_goal') {
            return $this->pack('learn', $code, $name, $profile, 'set_goal',
                "I can teach you {$name}. First, what is your goal — daily conversation, travel, work, an exam, or starting from the beginning?",
                [['label' => "Set my {$name} goal", 'href' => '/app/languages/begin?code=' . rawurlencode($code)]],
                $parsed);
        }
        if ($next === 'assess') {
            return $this->pack('learn', $code, $name, $profile, 'assess',
                "Great. Let us first determine your current {$name} level. Questions get harder when you answer well and easier when you struggle — the level is computed from your answers, never assigned at random.",
                [['label' => "Start {$name} assessment", 'href' => '/app/languages/p/' . $pid . '/assessment/start', 'method' => 'POST']],
                $parsed);
        }
        if ($next === 'path') {
            return $this->pack('learn', $code, $name, $profile, 'path',
                "Your {$name} level is {$profile['level']}. I will build a personalized path from that level — modules unlock only when you pass real checkpoints.",
                [['label' => 'Generate learning path', 'href' => '/app/languages/p/' . $pid . '/path/generate', 'method' => 'POST']],
                $parsed);
        }
        $level = $profile['level'];
        return $this->pack('learn', $code, $name, $profile, 'learn',
            "Continue learning {$name} at {$level}. Your next lesson, conversation and reviews are on your profile — progress is stored from real activity only.",
            [
                ['label' => "Continue {$name}", 'href' => '/app/languages/p/' . $pid],
                ['label' => "Today's plan", 'href' => '/app/languages/d/' . $pid],
                ['label' => 'AI conversation', 'href' => '/app/languages/conv/' . $pid],
            ],
            $parsed);
    }

    private function pack(
        string $intent,
        ?string $code,
        ?string $name,
        ?array $profile,
        string $nextStep,
        string $reply,
        array $actions,
        array $parsed
    ): array {
        return [
            'intent' => $intent,
            'languageCode' => $code,
            'languageName' => $name,
            'profile' => $profile,
            'nextStep' => $nextStep,
            'reply' => $reply,
            'actions' => $actions,
            'parsed' => $parsed,
        ];
    }
}
