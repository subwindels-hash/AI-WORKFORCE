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
You explain how the signed-in platform works. Be specific: name the page and path.

Product map (member sidebar):
- Dashboard (/dashboard) — home widgets: lottery jackpot, trading, language, sports, agents. Empty modules stay empty.
- AI Command Center (/command-center) — health of all AI modules in one place.
- AI Workforce (/analysis) — multi-agent market analysis (technical, structure, forex, crypto, sentiment, consensus, debate). Chart LIVE badge is earned from provenance, never assumed.
- Windels AI Agents (/app/workforce) — chat with specialist agents (market, sports, lottery, language, trading, leads, video). Observability at /app/agent-platform.
- AI Teacher (/app/languages/teacher) and My Languages (/app/languages) — 20+ languages, real profiles, assessment from authored banks, SRS vocab, listening via browser TTS, speaking via SpeechRecognition. Pronunciation scores are never faked.
- Lead Discovery (/leads) and Pipeline (/lead-pipeline) — search city/category; configured Places (or similar) required; empty results are never filled with fake businesses. Duplicates: provider + stable source ID; human merge.
- Paper Trading (/paper), Strategy Lab (/strategy), Analytics (/journal) — paper orders run kill switch → mode → risk engine. Paper is simulation.
- My Trading (/app/trading), Execution (/execution), Brokers (/brokers), Risk Center (/risk) — 15-step execution supervisor; ANALYSIS_ONLY + kill switch on by default; agents never call brokers.
- Sports Intel (/sports) — api-football / TheSportsDB / SportMonks when configured; no fixtures invented.
- EuroMillions (/lottery) — historical observations only, not predictions; official draws need a configured feed. Tickets, statistics, system builder, backtests stay on that hub.
- Multiplier AI (/multiplier) — live crash-history analysis (Bustabit by default). No demo multipliers silently substituted. Crash games are RNG; analysis is educational.
- Alerts (/notifications), Messages (/messages), My Account (/account), Help (/faq).

Honesty: never invent prices, draws, businesses, fixtures or crash results. Say when a provider or administrator must configure something. Keep replies concise and practical. Do not reveal or link administrator login URLs.
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
            'dashboard' => 'Open Dashboard at /dashboard after you sign in. It shows real widgets only — lottery jackpot, paper equity, language profiles, sports providers and Windels AI Agents. If a provider is not configured the widget stays empty instead of inventing numbers.',
            'command' => 'AI Command Center (/command-center) is the single status board for Windels AI Agents, Multiplier AI, Lottery, Trading, Sports, Language and Lead Discovery. Status is healthy / degraded / error from live checks, not placeholders.',
            'agents' => 'Windels AI Agents live at /app/workforce. Pick a specialist (Market, Sports, Lottery, Language, Trading, Lead Scout, Video) and send an instruction. Tools need approval; the audit trail records dispatches. Platform health is at /app/agent-platform. Agents never call brokers.',
            'analysis' => 'AI Workforce analysis is /analysis. Technical, market-structure, forex, crypto and sentiment agents vote, then consensus and an adversarial debate can only reduce conviction — they never manufacture a trade. The chart LIVE badge requires non-synthetic, fresh, undelayed provenance.',
            'language' => 'Open AI Teacher at /app/languages/teacher or My Languages at /app/languages. Create a profile for a catalog language (Dutch, Spanish, French, German, English, and more). Assessment, lessons and SRS vocabulary use authored banks. Listening uses the browser voice; speaking scores word accuracy from a real transcript. Pronunciation scores are never invented.',
            'leads' => 'Lead Discovery is /leads. Search a city, category or business type. Google Places (or another configured provider) must be enabled in Admin → API; empty provider results are never replaced with fake businesses. Identity is provider + stable source ID. Review duplicates in Intelligence; merges need a human. Pipeline at /lead-pipeline is the Kanban for those real leads.',
            'pipeline' => 'Pipeline (/lead-pipeline) is the Kanban/table for leads you already discovered. Change status, assign a member, add a note. It does not invent businesses — those come from Lead Discovery after a provider search.',
            'export' => 'Export leads from Lead Discovery or Intelligence as formula-safe CSV/JSON. Every export is written to the audit history. Nothing is exported that was not stored from a real provider.',
            'duplicate' => 'Open Lead Discovery Intelligence to review duplicate candidates. Primary identity is provider plus stable source ID. Secondary signals never auto-merge; a human must confirm.',
            'trading' => 'Trading defaults to ANALYSIS_ONLY with the kill switch on. Paper Trading (/paper) simulates orders through the full risk chain (kill switch → mode → stop-loss → Risk Engine). Strategy Lab (/strategy) backtests and requires paper evidence before live approval. My Trading is /app/trading. Agents analyse; they never place broker orders.',
            'paper' => 'Paper Trading (/paper) is simulation: accounts, orders, fills and strategy deployments. Every order still hits the kill switch, trading mode and Risk Engine. Nothing leaves the process. Synthetic prices require an explicit audited flag and stay labelled SIMULATION.',
            'execution' => 'Execution (/execution) runs the 15-step Trade Execution Supervisor: kill switch, mode, strategy lifecycle, broker health, freshness, duplicates, risk, then human approval or a configured automation envelope. Routing only happens through a verified connector. Brokers are at /brokers; limits at /risk.',
            'brokers' => 'Brokers (/brokers) lists your connections (MT5, OANDA, Alpaca, IBKR, exchanges). Order routing needs a healthy, demo-gated connector unless live is explicitly allowed. A missing bridge is ROUTING_BLOCKED — the platform does not pretend a terminal exists.',
            'risk' => 'Risk Center (/risk) holds limits, kill switch and the Portfolio Risk Monitor (exposure, leverage, correlated positions, drawdown, daily loss, broker disconnect). The Risk Engine can veto any paper or live order. Default boot: ANALYSIS_ONLY + kill switch active.',
            'sports' => 'Sports Intel is /sports. Fixtures and odds come from configured api-football, TheSportsDB or SportMonks. With no provider the module is DISABLED_NO_PROVIDER and shows no invented matches. Predictions, when enabled, are model-stamped and never mixed with demo data.',
            'lottery' => 'EuroMillions is /lottery. Frequency, hot/cold and gaps are historical observations, not predictions — the engine states that every valid line has the same chance. Official draws appear only after a configured feed is ingested and verified. Build tickets, statistics, systems and backtests on that hub. Actual ticket results stay separate from backtests and sandbox data.',
            'multiplier' => 'Multiplier AI is /multiplier. It analyses live crash-game history (Bustabit public feed by default, or WINDELS_CRASH_HISTORY_URL). Nine specialist agents produce an ensemble estimate with confidence and risk. If the live feed is down you see NO_DATA — simulated rounds are not substituted. Crash games use RNG; this is educational analysis, not a guaranteed next crash.',
            'account' => 'My Account is /account (profile, security, password). Messages with support are /messages. Alerts are /notifications. Sign in from the homepage; register if you do not have an account. Public pages never link an administrator login.',
            'admin' => 'WINDELS AI WORKFORCE only exposes the normal member sign-in on the public site. Administrator tools (API providers, users, notifications) are behind a private entry after a privileged session. I will not publish that URL. Members use /account.',
            'help' => 'Help/FAQ is /faq. For how a module works, name it (agents, languages, leads, trading, sports, EuroMillions, multiplier) and I will give the path and the honesty rules for that area.',
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
            'greeting' => ['hello', 'hi ', 'hi,', "hi'", 'hey', 'good morning', 'good afternoon', 'good evening', 'thanks', 'thank you'],
            'platform' => ['what is windels', 'what is this', 'about the platform', 'ai workforce', 'windels ai', 'what can you do', 'what do you do', 'who are you', 'tell me about', 'overview', 'full details', 'this product'],
            'command' => ['command center', 'command-center'],
            'agents' => ['windels ai agent', 'ai agent', 'workforce console', 'specialist agent', '/app/workforce', 'agent platform'],
            'analysis' => ['analysis', 'consensus', 'candlestick', 'market data', '/analysis'],
            'language' => ['language', 'teacher', 'dutch', 'spanish', 'vocabulary', 'cefr', 'pronunciation', 'lesson'],
            'leads' => ['lead', 'discover', 'google places', 'business search', 'places'],
            'pipeline' => ['pipeline', 'kanban'],
            'export' => ['export', 'csv', 'json download'],
            'duplicate' => ['duplicate', 'merge lead'],
            'paper' => ['paper trad', 'paper account', 'simulate order'],
            'execution' => ['execution', 'supervisor', 'approve trade', 'kill switch'],
            'brokers' => ['broker', 'mt5', 'metatrader', 'oanda', 'alpaca', 'ibkr'],
            'risk' => ['risk center', 'risk engine', 'drawdown', 'exposure'],
            'trading' => ['trad', 'strategy lab', 'journal', 'kill switch', 'analysis_only', 'forex', 'crypto'],
            'sports' => ['sport', 'fixture', 'football', 'premier league', 'api-football'],
            'lottery' => ['lottery', 'euromillions', 'euro millions', 'lucky star', 'ticket'],
            'multiplier' => ['multiplier', 'crash game', 'aviator', 'bustabit'],
            'admin' => ['admin', 'administrator', 'super admin'],
            'account' => ['account', 'password', 'sign in', 'login', 'register', 'message', 'notification', 'alert'],
            'dashboard' => ['dashboard', 'home widget', '/dashboard'],
            'help' => ['help', 'faq', 'how do i', 'where do i'],
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
