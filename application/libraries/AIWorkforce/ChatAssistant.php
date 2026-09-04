<?php
namespace AIWorkforce;

/**
 * WINDELS Assistant — product guide for AI WORKFORCE.
 *
 * Uses a configured LLM when available; otherwise a grounded local guide that
 * knows every signed-in module. Never invents market, lottery, sports or
 * business data. Never links the public admin login.
 */
final class ChatAssistant
{
    private const SYSTEM = <<<'TXT'
You are WINDELS Assistant for WINDELS AI WORKFORCE (also called WINDELS AI OS).
Full source: https://github.com/subwindels-hash/AI-WORKFORCE

You explain how the signed-in platform works. Be specific: name the page and path.
NEVER repeat the same information in a conversation. Each reply should be fresh and useful.

PRODUCT MAP (member sidebar after sign-in):
- Dashboard (/dashboard) — home widgets: lottery jackpot, trading equity, paper accounts, language profiles, sports providers, Windels AI Agents. Empty modules stay empty (no fake data).
- AI Command Center (/command-center) — health of ALL AI modules: Windels AI Agents, Multiplier AI, Lottery Intel, Trading Intel, Sports Intel, Language AI, Lead Discovery.
- AI Workforce (/analysis) — multi-agent market analysis: technical, market structure, forex, crypto, sentiment, consensus, adversarial debate. Chart LIVE badge requires non-synthetic, fresh, undelayed provenance.
- Windels AI Agents (/app/workforce) — chat with 9 specialist agents (Market, Sports, Lottery, Language, Trading, Lead Scout, Video). Observability at /app/agent-platform. Tools need approval; audit trail records dispatches. AGENTS NEVER CALL BROKERS.
- AI Language Teacher (/app/languages/teacher), My Languages (/app/languages) — 20+ languages, real profiles, assessment from authored banks, SRS vocabulary, browser TTS listening, SpeechRecognition speaking. Pronunciation scores from real transcripts, NEVER fabricated.
- Lead Discovery (/leads), Pipeline (/lead-pipeline) — search city/category. Configured Places provider required. Empty results NOT filled with fake businesses. Duplicates: provider + stable source ID; human merge required.
- Paper Trading (/paper), Strategy Lab (/strategy), Analytics (/journal) — paper orders run kill switch → mode → risk engine. Paper is SIMULATION. Strategy Lab backtests and requires paper evidence before live approval.
- My Trading (/app/trading), Execution (/execution), Brokers (/brokers), Risk Center (/risk) — 15-step execution supervisor. ANALYSIS_ONLY + kill switch ON by default. Agents analyze; never place broker orders. Brokers: MT5, OANDA, Alpaca, IBKR, exchanges.
- Sports Intel (/sports) — fixtures and odds from connected sports data. No fixtures invented when feed missing. Predictions model-stamped, never mixed with demo.
- EuroMillions (/lottery) — HISTORICAL OBSERVATIONS ONLY, not predictions. Every valid line has same chance. Official draws need configured feed. Tickets, statistics (frequency/hot-cold/gaps), system builder, backtests on this hub. Actual ticket results separate from backtests/sandbox.
- Multiplier AI (/multiplier) — LIVE crash-history analysis (Bustabit by default, or WINDELS_CRASH_HISTORY_URL). 9 specialist agents produce ensemble estimate with confidence + risk. NO demo multipliers silently substituted. If live feed down → NO_DATA. Crash games use RNG; educational analysis, not guaranteed prediction.
- Alerts (/notifications), Messages (/messages), My Account (/account), Help (/faq).

HONESTY RULES (NEVER VIOLATE):
- NEVER invent prices, draws, businesses, fixtures, crash results, or prediction accuracy
- NEVER claim future prediction ability for lottery, crashes, or markets
- Say when a provider/administrator must configure something
- If you don't know, say so — don't make things up
- Keep replies CONCISE but COMPLETE — answer what was asked, don't repeat

MODULE DETAILS (know these cold):

DASHBOARD: Shows real widget values only. Lottery shows jackpot if configured feed has data. Trading shows paper equity. Language shows profile count. Sports shows provider count. Windels AI Agents shows agent/tool counts. If a widget has no data, it stays empty — no placeholders, no fake numbers.

LEAD DISCOVERY: Search by city, category, business type. Requires configured Places provider (Google Places or similar). Results come from real provider data — each lead has provider + stable source ID for identity. Empty results are NOT filled with fake businesses. Duplicate detection: same provider + source ID = potential duplicate, reviewed in Intelligence, human confirms merge.

LANGUAGES: Catalog includes Dutch, Spanish, French, German, English, Italian, Portuguese, and more. Create profile → assessment from authored banks → lessons + SRS vocabulary → listening (browser TTS) + speaking (SpeechRecognition, scores from real transcript). Pronunciation scores never faked.

TRADING: Default boot state is ANALYSIS_ONLY + kill switch active. Paper Trading simulates orders through FULL risk chain (kill switch → mode → stop-loss → Risk Engine). Strategy Lab backtests strategies on paper data. My Trading shows positions. Execution runs 15-step supervisor before any routing. Brokers need healthy connector; missing bridge = ROUTING_BLOCKED.

SPORTS: Fixtures/odds from connected sports data providers. No fixtures invented. When no feed, module shows no matches. Predictions when enabled are stamped with model version, never mixed with demo.

EUROMILLIONS: Frequency/hot-cold/gaps/distribution are HISTORICAL OBSERVATIONS — the engine explicitly states every valid line has equal probability. Official draws only after configured feed ingests and verifies. System builder computes C(N,5)×C(S,2) lines, never hardcoded. Backtests include mandatory random baseline for comparison.

MULTIPLIER: Uses LiveCrashProvider (Bustabit public feed by default). 9 agents: historical, pattern, probability, sequence, anomaly, risk, validation, performance, prediction. Ensemble estimate with confidence + risk level. NO demo data substitution. Educational analysis only — crashes use provably-fair RNG.

ACCOUNT: Profile, security, password management. Messages with support at /messages. Alerts at /notifications. Sign in from homepage; register if new. Public pages never expose admin login.

If asked about anything not listed, say: "That's not a module I have details on. Try asking about Dashboard, Command Center, Windels AI Agents, Languages, Lead Discovery, Trading, Sports, EuroMillions, Multiplier AI, or Account."
TXT;

    public function respond(string $message, ?array $user = null): array
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 1000) {
            throw new \InvalidArgumentException('message must contain 1–1000 characters');
        }
        $managed = ApiProviders::resolve('llm') ?? ApiProviders::resolve('language_ai');
        if (is_array($managed)) {
            $answer = $this->providerAnswer($message, $managed);
            if ($answer !== null) {
                return $this->pack($answer, 'configured-ai', 'Product guidance only; no private record data was provided to the assistant.');
            }
        }
        $configured = getenv('AI_CHAT_ENABLED') === '1'
            && trim((string) getenv('AI_CHAT_API_URL')) !== ''
            && trim((string) getenv('AI_CHAT_API_KEY')) !== ''
            && trim((string) getenv('AI_CHAT_MODEL')) !== '';
        if ($configured) {
            $answer = $this->providerAnswer($message);
            if ($answer !== null) {
                return $this->pack($answer, 'configured-ai', 'Product guidance only; no private record data was provided to the assistant.');
            }
        }
        return $this->pack(
            $this->localAnswer($message, $user),
            'local-guide',
            'Product guidance only; configure an approved AI provider for generated responses.'
        );
    }

    /** Topic-aware local guide. Public so tests can pin replies. */
    public function localAnswer(string $message, ?array $user = null): string
    {
        $value = $this->normalize($message);
        $topic = $this->matchTopic($value);
        $signedIn = is_array($user) && !empty($user['id']);

        return match ($topic) {
            'greeting' => $this->answerGreeting($signedIn),
            'platform' => $this->answerPlatform(),
            'dashboard' => 'Dashboard (/dashboard) shows real widget values only — lottery jackpot (if feed configured), paper equity, language profiles count, sports providers count, Windels AI Agents count. Empty widgets stay empty, never filled with fake numbers.',
            'command' => 'AI Command Center (/command-center) shows health of ALL AI modules: Windels AI Agents, Multiplier AI, Lottery Intel, Trading Intel, Sports Intel, Language AI, Lead Discovery. Status is healthy/degraded/error from live checks, not placeholders.',
            'agents' => 'Windels AI Agents (/app/workforce) — chat with 9 specialist agents: Market, Sports, Lottery, Language, Trading, Lead Scout, Video. Tools need approval; audit trail records dispatches. AGENTS NEVER CALL BROKERS. Platform health at /app/agent-platform.',
            'analysis' => 'AI Workforce (/analysis) — multi-agent market analysis: technical, market structure, forex, crypto, sentiment agents vote, then consensus + adversarial debate (can only reduce conviction, never manufacture trades). Chart LIVE badge requires non-synthetic, fresh, undelayed provenance.',
            'language' => 'AI Language Teacher (/app/languages/teacher), My Languages (/app/languages) — 20+ languages. Create profile → assessment from authored banks → lessons + SRS vocabulary → browser TTS listening + SpeechRecognition speaking. Pronunciation scores from REAL transcripts, never fabricated.',
            'leads' => 'Lead Discovery (/leads) — search city/category/business type. Requires configured Places provider (Google Places or similar). Results from REAL provider data — each lead has provider + stable source ID. Empty results NOT filled with fake businesses. Duplicates reviewed in Intelligence; human confirms merge.',
            'pipeline' => 'Pipeline (/lead-pipeline) — Kanban/table for leads already discovered. Change status, assign member, add notes. Does NOT invent businesses — those come from Lead Discovery after provider search.',
            'export' => 'Export leads from Lead Discovery or Intelligence as formula-safe CSV/JSON. Every export written to audit history. Nothing exported that was not stored from a real provider.',
            'duplicate' => 'Open Lead Discovery Intelligence to review duplicate candidates. Primary identity: provider + stable source ID. Secondary signals never auto-merge; human must confirm.',
            'trading' => 'Trading defaults to ANALYSIS_ONLY + kill switch ON. Paper Trading (/paper) simulates orders through FULL risk chain. Strategy Lab (/strategy) backtests, requires paper evidence before live approval. My Trading (/app/trading) shows positions. Agents analyze; never place broker orders.',
            'paper' => 'Paper Trading (/paper) — SIMULATION: accounts, orders, fills, strategy deployments. Every order hits kill switch, trading mode, Risk Engine. Nothing leaves the process. Synthetic prices require explicit audited flag, stay labelled SIMULATION.',
            'execution' => 'Execution (/execution) — 15-step Trade Execution Supervisor: kill switch, mode, strategy lifecycle, broker health, freshness, duplicates, risk, then human approval or configured automation envelope. Routing only through verified connector.',
            'brokers' => 'Brokers (/brokers) — connections: MT5, OANDA, Alpaca, IBKR, exchanges. Order routing needs healthy, demo-gated connector unless live explicitly allowed. Missing bridge = ROUTING_BLOCKED.',
            'risk' => 'Risk Center (/risk) — limits, kill switch, Portfolio Risk Monitor (exposure, leverage, correlated positions, drawdown, daily loss, broker disconnect). Risk Engine can veto any paper/live order. Default boot: ANALYSIS_ONLY + kill switch active.',
            'sports' => 'Sports Intel (/sports) — fixtures/odds from connected sports data. No fixtures invented when feed missing. Predictions, when enabled, stamped with model version, never mixed with demo.',
            'lottery' => 'EuroMillions (/lottery) — HISTORICAL OBSERVATIONS ONLY, NOT predictions. Every valid line has equal probability. Official draws only after configured feed ingests + verifies. Statistics (frequency/hot-cold/gaps/distribution), system builder (computes C(N,5)×C(S,2)), backtests with mandatory random baseline. Actual ticket results separate from backtests/sandbox.',
            'multiplier' => 'Multiplier AI (/multiplier) — LIVE crash-history analysis (Bustabit public feed by default, or WINDELS_CRASH_HISTORY_URL). 9 specialist agents produce ensemble estimate with confidence + risk. NO demo multipliers silently substituted — if live feed down → NO_DATA. Crash games use provably-fair RNG; educational analysis, not guaranteed prediction.',
            'account' => 'My Account (/account) — profile, security, password. Messages with support at /messages. Alerts at /notifications. Sign in from homepage; register if new. Public pages never expose admin login.',
            'admin' => 'WINDELS AI WORKFORCE only exposes normal member sign-in on public site. Administrator tools (API providers, users, notifications) behind private entry after privileged session. I will not publish that URL. Members use /account.',
            'help' => 'Help/FAQ is /faq. For how a module works, name it specifically (e.g., "EuroMillions tickets", "paper trading kill switch", "language teacher Dutch", "lead search", "multiplier live feed") and I will give the exact path and honesty rules.',
            default => $this->answerFallback($value),
        };
    }

    private function pack(string $message, string $provider, string $disclaimer): array
    {
        return [
            'message' => $message,
            'provider' => $provider,
            'grounded' => true,
            'disclaimer' => $disclaimer,
        ];
    }

    private function normalize(string $message): string
    {
        $value = strtolower($message);
        $value = str_replace(['’', '`'], "'", $value);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function matchTopic(string $value): string
    {
        $topics = [
            'greeting' => ['hello', 'hi ', 'hi,', "hi'", 'hey', 'good morning', 'good afternoon', 'good evening', 'thanks', 'thank you', 'greetings'],
            'platform' => ['what is windels', 'what is this', 'about the platform', 'ai workforce', 'windels ai', 'what can you do', 'what do you do', 'who are you', 'tell me about', 'overview', 'full details', 'this product', 'explain', 'describe', 'introduction', 'what is', 'your platform'],
            'command' => ['command center', 'command-center', 'all modules', 'module health', 'AI status'],
            'agents' => ['windels ai agent', 'ai agent', 'workforce console', 'specialist agent', '/app/workforce', 'agent platform', 'agent chat', 'talk to agent'],
            'analysis' => ['analysis', 'consensus', 'candlestick', 'market data', '/analysis', 'market analysis', 'technical analysis', 'ai workforce analysis'],
            'language' => ['language', 'teacher', 'dutch', 'spanish', 'vocabulary', 'cefr', 'pronunciation', 'lesson', 'speak', 'learn language', 'language learning', 'my languages'],
            'leads' => ['lead', 'discover', 'google places', 'business search', 'places', 'find business', 'search business', 'lead discovery'],
            'pipeline' => ['pipeline', 'kanban', 'lead pipeline', 'manage leads'],
            'export' => ['export', 'csv', 'json download', 'download leads'],
            'duplicate' => ['duplicate', 'merge lead', 'merge duplicate', 'intelligence'],
            'paper' => ['paper trad', 'paper account', 'simulate order', 'paper trading', 'simulation'],
            'execution' => ['execution', 'supervisor', 'approve trade', 'kill switch', 'trade execution', 'execution supervisor'],
            'brokers' => ['broker', 'mt5', 'metatrader', 'oanda', 'alpaca', 'ibkr', 'connect broker', 'brokers'],
            'risk' => ['risk center', 'risk engine', 'drawdown', 'exposure', 'risk management', 'portfolio risk'],
            'trading' => ['trad', 'strategy lab', 'journal', 'kill switch', 'analysis_only', 'forex', 'crypto', 'trading dashboard', 'paper trading', 'strategy', 'trading account'],
            'sports' => ['sport', 'fixture', 'football', 'premier league', 'sports intel', 'sports intelligence', 'odds', 'sports data'],
            'lottery' => ['lottery', 'euromillions', 'euro millions', 'lucky star', 'ticket', 'lottery intel', 'frequency', 'hot cold', 'gap', 'distribution', 'system builder', 'wheel', 'backtest'],
            'multiplier' => ['multiplier', 'crash game', 'aviator', 'bustabit', 'crash', 'multiplier ai', 'live crash'],
            'admin' => ['admin', 'administrator', 'super admin'],
            'account' => ['account', 'password', 'sign in', 'login', 'register', 'message', 'notification', 'alert', 'profile', 'security', 'my account'],
            'dashboard' => ['dashboard', 'home widget', '/dashboard', 'home', 'widgets', 'summary'],
            'help' => ['help', 'faq', 'how do i', 'where do i', 'guide', 'which module'],
        ];
        foreach ($topics as $topic => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    return $topic;
                }
            }
        }
        return 'unknown';
    }

    private function answerGreeting(bool $signedIn): string
    {
        if ($signedIn) {
            return 'I am WINDELS Assistant for AI WORKFORCE. Ask about a module and I will tell you the path and how it actually behaves — Dashboard, Command Center, Windels AI Agents, Languages, Leads, Trading, Sports, EuroMillions, Multiplier AI, or Account.';
        }
        return 'I am WINDELS Assistant. Sign in to use the workspace. I can explain Dashboard, Windels AI Agents, the AI Language Teacher, Lead Discovery, Trading (analysis-only by default), Sports Intel, EuroMillions and Multiplier AI. I do not invent prices, draws or businesses.';
    }

    private function answerPlatform(): string
    {
        return 'WINDELS AI WORKFORCE is a signed-in AI operating system: specialist agents, language teaching, lead discovery, sports and lottery research, crash-history analysis, and governed trading. Core rule: AI may analyse and recommend inside approved tools, but it never bypasses providers, risk controls, the execution supervisor or the kill switch. Fake data is labelled or refused. Start at /dashboard after sign-in.';
    }

    private function answerFallback(string $value): string
    {
        if (str_contains($value, 'how') || str_contains($value, 'where') || str_contains($value, 'what')) {
            return 'WINDELS AI WORKFORCE is organised by module, not a single chatbot brain. Name the area you mean — for example “EuroMillions tickets”, “paper trading kill switch”, “language teacher Dutch”, “lead search”, or “multiplier live feed” — and I will give the exact page and the honesty rules there.';
        }
        return 'I did not match that to a module. Try asking about Dashboard, Windels AI Agents, Languages, Lead Discovery, Trading, Sports Intel, EuroMillions, Multiplier AI, or Account.';
    }

    private function providerAnswer(string $message, ?array $managed = null): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => $message],
        ];
        if ($managed) {
            $answer = ApiProviders::openaiChat($managed, $messages);
            if ($answer !== null) {
                return $answer;
            }
        }
        $url = trim((string) getenv('AI_CHAT_API_URL'));
        $key = trim((string) getenv('AI_CHAT_API_KEY'));
        $model = trim((string) getenv('AI_CHAT_MODEL'));
        if ($url === '' || $key === '' || $model === '') {
            return null;
        }
        $body = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => 420], JSON_UNESCAPED_SLASHES);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer {$key}\r\n",
                'content' => $body,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }
        $payload = json_decode($raw, true);
        $answer = $payload['choices'][0]['message']['content'] ?? null;
        return is_string($answer) && trim($answer) !== '' ? mb_substr(trim($answer), 0, 2000) : null;
    }
}
