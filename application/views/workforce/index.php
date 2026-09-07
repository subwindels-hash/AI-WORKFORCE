<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $agents @var int $agentCount @var bool $windelsAIConfigured @var array $llmStatus */
$ic = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
?>
<style>
/* ═══ Workforce Grid ═══════════════════════════════════════════════ */
.wf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:24px}
.wf-card{display:block;width:100%;text-align:left;font:inherit;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;cursor:pointer;transition:all .18s ease;position:relative;overflow:hidden;user-select:none;color:inherit;-webkit-appearance:none;appearance:none}
.wf-card:hover{border-color:var(--brand);transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.25)}
.wf-card.active{border-color:var(--agent-color,var(--brand));background:var(--surface);box-shadow:0 0 0 1px var(--agent-color,var(--brand)),0 8px 24px rgba(0,0,0,.3)}
.wf-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--agent-color,var(--brand));opacity:0;transition:opacity .18s}
.wf-card:hover::before,.wf-card.active::before{opacity:1}
.wf-card .wf-icon{font-size:28px;margin-bottom:10px;line-height:1}
.wf-card .wf-name{font-size:15px;font-weight:700;color:#fff;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between}
.wf-card .wf-name .wf-action-hint{font-size:11px;font-weight:500;color:var(--dim);opacity:0;transition:opacity .15s}
.wf-card:hover .wf-action-hint,.wf-card.active .wf-action-hint{opacity:1;color:var(--brand)}
.wf-card .wf-desc{font-size:12px;color:var(--muted);line-height:1.5;margin-bottom:10px}
.wf-card .wf-tools{display:flex;gap:4px;flex-wrap:wrap}
.wf-card .wf-tool{background:var(--panel2);padding:2px 7px;border-radius:10px;font-size:10px;color:var(--dim)}
.wf-status{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.wf-stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 18px;flex:1;min-width:140px}
.wf-stat .lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.wf-stat .val{font-size:20px;font-weight:700;color:#fff}

/* ═══ Chat Interface ═══════════════════════════════════════════════ */
.wf-chat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:24px;box-shadow:0 8px 30px rgba(0,0,0,.2)}
.wf-chat-head{background:var(--panel2);padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}
.wf-chat-head .wf-agent-icon{font-size:28px;line-height:1}
.wf-chat-head .wf-agent-name{font-size:15px;font-weight:700;color:#fff}
.wf-chat-head .wf-agent-status{font-size:11px;color:var(--dim)}
.wf-chat-body{padding:18px;min-height:300px;max-height:500px;overflow-y:auto;display:flex;flex-direction:column}
.wf-msg{margin-bottom:14px;display:flex;gap:10px;animation:wf-fade-in .2s ease}
.wf-msg.user{flex-direction:row-reverse;align-self:flex-end}
.wf-msg.assistant{align-self:flex-start}
.wf-msg.system{align-self:center;width:100%}
.wf-msg .wf-bubble{max-width:85%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.5;word-break:break-word}
.wf-msg.assistant .wf-bubble{background:var(--panel2);color:var(--text);border-bottom-left-radius:2px;border:1px solid var(--line)}
.wf-msg.user .wf-bubble{background:var(--brand);color:#fff;border-bottom-right-radius:2px}
.wf-msg.system .wf-bubble{background:transparent;border:1px dashed var(--line);color:var(--dim);font-size:11px;max-width:100%;text-align:center}
.wf-chat-foot{padding:14px 18px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:10px;background:var(--surface)}
.wf-chat-typing-session{display:flex;flex-direction:column;gap:6px;width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:var(--radius-sm);background:var(--panel)}
.wf-chat-typing-label{font-size:10px;font-weight:700;letter-spacing:.02em;color:var(--dim)}
.wf-chat-foot textarea{width:100%;box-sizing:border-box;background:transparent;border:none;padding:4px 0;color:var(--text);font:inherit;resize:none;min-height:44px;max-height:160px}
.wf-chat-foot textarea:focus{outline:none}
.wf-chat-controls{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;padding-top:8px;border-top:1px dashed var(--line)}
.wf-chat-voice-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wf-chat-send{display:inline-flex;align-items:center;gap:6px;background:var(--brand);color:#fff;border:none;border-radius:var(--radius-sm);padding:9px 18px;font:600 13px/1 inherit;cursor:pointer;transition:all .15s ease}
.wf-chat-send:hover:not(:disabled){background:var(--brand-hover,var(--brand));transform:translateY(-1px)}
.wf-chat-send:disabled{opacity:.5;cursor:not-allowed}
.wf-chat-draft-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wf-chat-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:6px 10px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);color:var(--text);font-size:11px;line-height:1.2}
.wf-chat-chip .name{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wf-chat-chip button{border:none;background:transparent;color:var(--dim);cursor:pointer;padding:0;font:inherit}
.wf-chat-chip button:hover{color:#fff}
.wf-chat-hint{font-size:11px;color:var(--dim)}
.wf-msg .wf-bubble .wf-attachment{display:flex;align-items:center;gap:6px;margin-top:8px;padding-top:8px;border-top:1px dashed rgba(255,255,255,.14);font-size:11px;opacity:.95}
.wf-msg.user .wf-bubble .wf-attachment{border-top-color:rgba(255,255,255,.25)}
.wf-msg .wf-bubble .wf-msg-actions{display:flex;justify-content:flex-end;margin-top:8px}
.wf-msg .wf-bubble .wf-msg-edit{border:none;background:transparent;color:inherit;opacity:.75;cursor:pointer;font-size:11px;padding:0}
.wf-msg .wf-bubble .wf-msg-edit:hover{opacity:1;text-decoration:underline}
.wf-typing{display:flex;gap:4px;padding:8px 0}
.wf-typing span{width:7px;height:7px;border-radius:50%;background:var(--dim);animation:wf-bounce .6s ease-in-out infinite}
.wf-typing span:nth-child(2){animation-delay:.15s}
.wf-typing span:nth-child(3){animation-delay:.3s}
@keyframes wf-bounce{0%,100%{transform:translateY(0);opacity:.4}50%{transform:translateY(-4px);opacity:1}}
@keyframes wf-fade-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

/* ═══ Welcome Banner ═══════════════════════════════════════════════ */
.wf-hero{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4338ca 100%);border-radius:var(--radius);padding:28px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden}
.wf-hero::after{content:'';position:absolute;top:-50%;right:-20%;width:60%;height:200%;background:radial-gradient(circle,rgba(99,102,241,.15) 0%,transparent 70%)}
.wf-hero h2{font-size:24px;font-weight:800;margin:0 0 8px;position:relative;z-index:1}
.wf-hero p{font-size:14px;opacity:.85;margin:0 0 16px;max-width:600px;position:relative;z-index:1}
.wf-hero-pills{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1}
.wf-hero-pill{background:rgba(255,255,255,.12);backdrop-filter:blur(4px);padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600}

/* ═══ Suggestions ═════════════════════════════════════════════════ */
.wf-suggest-card{display:block;width:100%;text-align:left;font:inherit;background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px;cursor:pointer;transition:all .18s ease;user-select:none;color:inherit;-webkit-appearance:none;appearance:none}
.wf-suggest-card:hover{border-color:var(--brand);transform:translateY(-2px);background:var(--panel);box-shadow:0 4px 16px rgba(0,0,0,.2)}
.wf-suggest-card .title{font-size:13px;font-weight:700;color:#fff;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between}
.wf-suggest-card .title .arrow{font-size:12px;color:var(--brand);opacity:0;transition:opacity .15s}
.wf-suggest-card:hover .title .arrow{opacity:1}
.wf-suggest-card .query{font-size:12px;color:var(--muted);line-height:1.4}

/* ═══ Config Banner ════════════════════════════════════════════════ */
.wf-config-banner{background:#f59e0b15;border:1px solid #f59e0b44;border-radius:var(--radius);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px}
.wf-config-banner .icon{font-size:24px}
.wf-config-banner .text{flex:1}
.wf-config-banner .text b{color:#f59e0b;font-size:13px}
.wf-config-banner .text p{margin:2px 0 0;font-size:12px;color:var(--muted)}

@media(max-width:768px){
  .wf-grid{grid-template-columns:1fr 1fr}
  .wf-hero{padding:20px}
  .wf-hero h2{font-size:20px}
}
@media(max-width:480px){
  .wf-grid{grid-template-columns:1fr}
}
</style>

<div class="page-head">
  <div>
    <h2>🧠 AI Workforce Console</h2>
    <p>Interact with specialist AI agents — low latency, evidence-grounded intelligence.</p>
  </div>
</div>

<?php if (!$windelsAIConfigured): ?>
<div class="wf-config-banner">
  <span class="icon">⚡</span>
  <div class="text">
    <b>AI provider not configured</b>
    <p>Agents will use the local guide until a compatible LLM provider is configured by an administrator.</p>
  </div>
  <?php if (!empty($admin)): ?>
    <a class="btn small" href="/admin/api">Configure</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="wf-hero">
  <h2>Meet Your AI Workforce</h2>
  <p><?= e((string)$agentCount) ?> specialist agents ready to assist with market analysis, trading, language learning, lead discovery, and more. Select any agent below to start a conversation.</p>
  <div class="wf-hero-pills">
    <span class="wf-hero-pill">⚡ Multi-Model AI</span>
    <span class="wf-hero-pill">🔒 Approved Tools Only</span>
    <span class="wf-hero-pill">📊 Audit Trail</span>
    <span class="wf-hero-pill">🎯 Specialist Intelligence</span>
  </div>
</div>

<!-- Status -->
<div class="wf-status">
  <div class="wf-stat">
    <div class="lbl">Active Agents</div>
    <div class="val"><?= e((string)$agentCount) ?></div>
  </div>
  <div class="wf-stat">
    <div class="lbl">AI Provider</div>
    <div class="val" style="font-size:14px"><?= $windelsAIConfigured ? '<span style="color:var(--green)">● Active (' . e((string)($llmStatus['driver'] ?? 'AI')) . ')</span>' : '<span style="color:var(--amber)">● Local Guide</span>' ?></div>
  </div>
  <div class="wf-stat">
    <div class="lbl">Tool Policy</div>
    <div class="val" style="font-size:14px">Approval Required</div>
  </div>
  <div class="wf-stat">
    <div class="lbl">Session</div>
    <div class="val" style="font-size:14px" id="wf-session-status">Ready</div>
  </div>
</div>

<!-- Agent Grid -->
<h3 style="margin-bottom:12px">Specialist Agents</h3>
<div class="wf-grid" id="wf-agent-grid">
  <?php foreach ($agents as $i => $a): ?>
    <button type="button"
            class="wf-card" 
            data-agent="<?= e($a['name']) ?>"
            data-label="<?= e($a['label']) ?>"
            data-icon="<?= e($a['icon']) ?>"
            style="--agent-color:<?= e($a['color']) ?>" 
            onclick="selectAgent('<?= e($a['name']) ?>','<?= e($a['label']) ?>','<?= e($a['icon']) ?>')">
      <div class="wf-icon"><?= e($a['icon']) ?></div>
      <div class="wf-name">
        <span><?= e($a['label']) ?></span>
        <span class="wf-action-hint">Chat →</span>
      </div>
      <div class="wf-desc"><?= e($a['description']) ?></div>
      <?php if (!empty($a['tools'])): ?>
        <div class="wf-tools">
          <?php foreach (array_slice($a['tools'], 0, 3) as $t): ?>
            <span class="wf-tool"><?= e($t) ?></span>
          <?php endforeach; ?>
          <?php if (count($a['tools']) > 3): ?>
            <span class="wf-tool">+<?= count($a['tools']) - 3 ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </button>
  <?php endforeach; ?>
</div>

<!-- Chat Interface -->
<div class="wf-chat" id="wf-chat" style="display:none">
  <div class="wf-chat-head">
    <span class="wf-agent-icon" id="chat-icon">🤖</span>
    <div>
      <div class="wf-agent-name" id="chat-name">Agent</div>
      <div class="wf-agent-status" id="chat-status">Ready to assist</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button type="button" class="btn small" id="chat-clear" onclick="clearChat()">Clear</button>
      <button type="button" class="btn small" id="chat-close" onclick="closeChat()">Close</button>
    </div>
  </div>
  <div class="wf-chat-body" id="chat-body"></div>
  <div class="wf-chat-foot">
    <div class="wf-chat-typing-session">
      <label class="wf-chat-typing-label" for="chat-input">WINDELS Assistant typing session</label>
      <textarea id="chat-input" placeholder="Ask the agent a question or attach a file for analysis..." rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage();}"></textarea>
    </div>
    <div class="wf-chat-draft-meta" id="wf-chat-draft-meta" hidden></div>
    <div class="wf-chat-hint">Upload TXT, MD, CSV, JSON, DOCX, PDF, MP3, WAV, M4A, OGG, MP4, MOV, AVI, MKV or WEBM up to 25 MB.</div>
    <div class="wf-chat-controls">
      <div class="wf-chat-voice-controls">
        <button type="button" id="wf-chat-attach" class="btn small" aria-label="Attach file">📎 Upload file</button>
        <input type="file" id="wf-file-input" hidden accept=".txt,.md,.markdown,.csv,.tsv,.json,.xml,.html,.htm,.log,.yaml,.yml,.ini,.sql,.srt,.vtt,.rtf,.docx,.pdf,.mp3,.wav,.m4a,.aac,.ogg,.oga,.flac,.opus,.webm,.mp4,.mov,.m4v,.avi,.mkv">
        <button type="button" id="wf-chat-edit-cancel" class="btn small" hidden>Cancel edit</button>
        <button type="button" id="wf-chat-mic" class="btn small" aria-label="Speak">🎤 Speak</button>
        <button type="button" id="wf-chat-mic-stop" class="btn small" aria-label="Stop" disabled style="display:none">⏹ Stop</button>
      </div>
      <button type="button" id="chat-send" class="wf-chat-send" onclick="sendMessage()"><?= $ic ?><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg> <span id="chat-send-label">Send</span></button>
    </div>
  </div>
</div>

<!-- Suggestions when no chat active -->
<div id="wf-suggestions" class="panel" style="margin-top:20px">
  <div class="body">
    <h4 style="margin:0 0 12px">💡 Quick Start — Click any prompt to ask:</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
      <button type="button" class="wf-suggest-card" data-agent="market" data-label="Market Analyst" data-icon="📈" data-prompt="What is the current BTC/USD price?" onclick="askAgent('market','Market Analyst','📈','What is the current BTC/USD price?')">
        <div class="title"><span>📈 Market Analyst</span><span class="arrow">Ask →</span></div>
        <div class="query">"What is the current BTC/USD price?"</div>
      </button>
      <button type="button" class="wf-suggest-card" data-agent="trading" data-label="Trading Analyst" data-icon="💹" data-prompt="Analyze market conditions and provide key support/resistance levels" onclick="askAgent('trading','Trading Analyst','💹','Analyze market conditions and provide key support/resistance levels')">
        <div class="title"><span>💹 Trading Analyst</span><span class="arrow">Ask →</span></div>
        <div class="query">"Analyze market conditions and provide key support/resistance levels"</div>
      </button>
      <button type="button" class="wf-suggest-card" data-agent="lottery" data-label="Lottery Analyst" data-icon="🎰" data-prompt="Show me historical EuroMillions statistical frequency" onclick="askAgent('lottery','Lottery Analyst','🎰','Show me historical EuroMillions statistical frequency')">
        <div class="title"><span>🎰 Lottery Analyst</span><span class="arrow">Ask →</span></div>
        <div class="query">"Show me historical EuroMillions statistical frequency"</div>
      </button>
      <button type="button" class="wf-suggest-card" data-agent="lead_discovery" data-label="Lead Scout" data-icon="🔍" data-prompt="Find top-rated marketing businesses in London" onclick="askAgent('lead_discovery','Lead Scout','🔍','Find top-rated marketing businesses in London')">
        <div class="title"><span>🔍 Lead Scout</span><span class="arrow">Ask →</span></div>
        <div class="query">"Find top-rated marketing businesses in London"</div>
      </button>
      <button type="button" class="wf-suggest-card" data-agent="language" data-label="Language Coach" data-icon="🗣️" data-prompt="Help me practice conversational Dutch vocabulary" onclick="askAgent('language','Language Coach','🗣️','Help me practice conversational Dutch vocabulary')">
        <div class="title"><span>🗣️ Language Coach</span><span class="arrow">Ask →</span></div>
        <div class="query">"Help me practice conversational Dutch vocabulary"</div>
      </button>
      <button type="button" class="wf-suggest-card" data-agent="sports" data-label="Sports Intelligence" data-icon="⚽" data-prompt="Show me upcoming football match fixtures and analysis" onclick="askAgent('sports','Sports Intelligence','⚽','Show me upcoming football match fixtures and analysis')">
        <div class="title"><span>⚽ Sports Intelligence</span><span class="arrow">Ask →</span></div>
        <div class="query">"Show me upcoming football match fixtures and analysis"</div>
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';
  var STORAGE_KEY = 'windels_workforce_chats_v3';
  var MAX_MESSAGES_PER_AGENT = 80;
  var MAX_ATTACHMENT_FACTS_CHARS = 20000;
  var currentAgent = null;
  var currentLabel = '';
  var currentIcon = '';
  var chatState = loadChatState();
  var draftFiles = {};
  var editState = null;

  function defaultState() {
    return { activeAgent: '', chatOpen: false, conversations: {} };
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var inp = document.querySelector('input[name="csrf_token"]');
    if (inp && inp.value) return inp.value;
    return '';
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function cloneJson(value, maxChars) {
    try {
      var raw = JSON.stringify(value);
      if (!raw) return null;
      if (maxChars && raw.length > maxChars) return null;
      return JSON.parse(raw);
    } catch (_) {
      return null;
    }
  }

  function sanitizeAttachment(raw) {
    if (!raw || typeof raw !== 'object') return null;
    var name = typeof raw.name === 'string' ? raw.name : '';
    if (!name) return null;
    return {
      name: name,
      size: Number(raw.size || 0) || 0,
      mime: typeof raw.mime === 'string' ? raw.mime : '',
      extension: typeof raw.extension === 'string' ? raw.extension : '',
      kind: typeof raw.kind === 'string' ? raw.kind : ''
    };
  }

  function sanitizeMessage(raw) {
    if (!raw || typeof raw !== 'object') return null;
    var role = typeof raw.role === 'string' ? raw.role : '';
    if (['assistant', 'user', 'system'].indexOf(role) === -1) return null;
    var text = typeof raw.text === 'string' ? raw.text : '';
    if (text.trim() === '') return null;
    var message = { role: role, text: text };
    var attachment = sanitizeAttachment(raw.attachment);
    if (attachment) message.attachment = attachment;
    if (typeof raw.attachmentContext === 'string' && raw.attachmentContext.trim() !== '') {
      message.attachmentContext = raw.attachmentContext.slice(0, MAX_ATTACHMENT_FACTS_CHARS);
    }
    var facts = cloneJson(raw.attachmentFacts, MAX_ATTACHMENT_FACTS_CHARS);
    if (facts) message.attachmentFacts = facts;
    return message;
  }

  function loadChatState() {
    var fallback = defaultState();
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return fallback;
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') return fallback;
      var state = {
        activeAgent: typeof parsed.activeAgent === 'string' ? parsed.activeAgent : '',
        chatOpen: parsed.chatOpen === true,
        conversations: {}
      };
      var source = parsed.conversations && typeof parsed.conversations === 'object' ? parsed.conversations : {};
      Object.keys(source).forEach(function(agentName) {
        var convo = source[agentName];
        if (!convo || typeof convo !== 'object') return;
        var messages = [];
        if (Array.isArray(convo.messages)) {
          convo.messages.forEach(function(item) {
            var clean = sanitizeMessage(item);
            if (clean) messages.push(clean);
          });
        }
        state.conversations[agentName] = {
          label: typeof convo.label === 'string' ? convo.label : '',
          icon: typeof convo.icon === 'string' ? convo.icon : '',
          draftText: typeof convo.draftText === 'string' ? convo.draftText : '',
          messages: messages.slice(-MAX_MESSAGES_PER_AGENT),
          pendingCount: 0
        };
      });
      return state;
    } catch (_) {
      return fallback;
    }
  }

  function saveChatState() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(chatState));
    } catch (_) {}
  }

  function findAgentCard(agentName) {
    var cards = document.querySelectorAll('.wf-card');
    for (var i = 0; i < cards.length; i += 1) {
      if (cards[i].getAttribute('data-agent') === agentName) return cards[i];
    }
    return null;
  }

  function getAgentMeta(agentName) {
    var card = findAgentCard(agentName);
    if (card) {
      return {
        name: agentName,
        label: card.getAttribute('data-label') || agentName,
        icon: card.getAttribute('data-icon') || '🤖'
      };
    }
    var convo = chatState.conversations[agentName];
    if (convo) {
      return { name: agentName, label: convo.label || agentName, icon: convo.icon || '🤖' };
    }
    return null;
  }

  function ensureConversation(agentName, label, icon) {
    if (!agentName) return null;
    if (!chatState.conversations[agentName] || typeof chatState.conversations[agentName] !== 'object') {
      chatState.conversations[agentName] = {
        label: '',
        icon: '',
        draftText: '',
        messages: [],
        pendingCount: 0
      };
    }
    var convo = chatState.conversations[agentName];
    if (typeof label === 'string' && label) convo.label = label;
    if (typeof icon === 'string' && icon) convo.icon = icon;
    if (typeof convo.draftText !== 'string') convo.draftText = '';
    if (!Array.isArray(convo.messages)) convo.messages = [];
    convo.messages = convo.messages.map(sanitizeMessage).filter(Boolean).slice(-MAX_MESSAGES_PER_AGENT);
    if (typeof convo.pendingCount !== 'number' || convo.pendingCount < 0) convo.pendingCount = 0;
    return convo;
  }

  function getCurrentConversation() {
    return currentAgent ? ensureConversation(currentAgent, currentLabel, currentIcon) : null;
  }

  function getDraftFile(agentName) {
    return agentName && draftFiles[agentName] ? draftFiles[agentName] : null;
  }

  function setDraftFile(agentName, file) {
    if (!agentName) return;
    if (file) draftFiles[agentName] = file;
    else delete draftFiles[agentName];
    renderDraftMeta();
  }

  function formatBytes(bytes) {
    bytes = Number(bytes || 0) || 0;
    if (bytes < 1024) return bytes + ' B';
    var units = ['KB', 'MB', 'GB'];
    var value = bytes / 1024;
    for (var i = 0; i < units.length; i += 1) {
      if (value < 1024 || i === units.length - 1) {
        return (value >= 100 ? Math.round(value) : Math.round(value * 10) / 10) + ' ' + units[i];
      }
      value = value / 1024;
    }
    return bytes + ' B';
  }

  function updateActiveCard(agentName) {
    document.querySelectorAll('.wf-card').forEach(function(card) {
      if (card.getAttribute('data-agent') === agentName) card.classList.add('active');
      else card.classList.remove('active');
    });
  }

  function defaultGreeting(label) {
    return 'Hello! I am your ' + (label || 'assistant') + '. How can I help you today?';
  }

  function latestUserMessageIndex(convo) {
    if (!convo || !Array.isArray(convo.messages)) return -1;
    for (var i = convo.messages.length - 1; i >= 0; i -= 1) {
      if (convo.messages[i] && convo.messages[i].role === 'user') return i;
    }
    return -1;
  }

  function isEditingCurrentAgent() {
    return !!(editState && currentAgent && editState.agent === currentAgent);
  }

  function currentEditMessage() {
    var convo = getCurrentConversation();
    if (!convo || !isEditingCurrentAgent()) return null;
    return convo.messages[editState.index] || null;
  }

  function resizeInput(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  function buildHistoryContent(message) {
    var text = message && typeof message.text === 'string' ? message.text : '';
    if (message && typeof message.attachmentContext === 'string' && message.attachmentContext.trim() !== '') {
      text += '\n\nFILE CONTEXT:\n' + message.attachmentContext;
    }
    return text.trim();
  }

  function conversationHistoryFromMessages(messages) {
    return (messages || [])
      .filter(function(message) {
        return message && ['assistant', 'user', 'system'].indexOf(message.role) !== -1;
      })
      .slice(-10)
      .map(function(message) {
        return { role: message.role, content: buildHistoryContent(message) };
      })
      .filter(function(message) { return !!message.content; });
  }

  function updateMessageAt(agentName, index, patch) {
    var convo = ensureConversation(agentName);
    if (!convo || index < 0 || index >= convo.messages.length) return;
    var next = Object.assign({}, convo.messages[index], patch || {});
    var clean = sanitizeMessage(next);
    if (!clean) return;
    convo.messages[index] = clean;
    persistConversation(agentName);
  }

  function persistConversation(agentName) {
    var convo = ensureConversation(agentName);
    if (convo && convo.messages.length > MAX_MESSAGES_PER_AGENT) {
      convo.messages = convo.messages.slice(-MAX_MESSAGES_PER_AGENT);
    }
    saveChatState();
    if (agentName && currentAgent === agentName) {
      renderCurrentConversation();
      renderDraftMeta();
      updateComposerState();
    }
  }

  function addMessageToAgent(agentName, message) {
    var convo = ensureConversation(agentName);
    if (!convo) return -1;
    var clean = sanitizeMessage(message);
    if (!clean) return -1;
    convo.messages.push(clean);
    persistConversation(agentName);
    return convo.messages.length - 1;
  }

  function setPending(agentName, delta) {
    var convo = ensureConversation(agentName);
    if (!convo) return;
    convo.pendingCount = Math.max(0, (convo.pendingCount || 0) + delta);
    saveChatState();
    if (currentAgent === agentName) {
      renderCurrentConversation();
      renderDraftMeta();
      updateComposerState();
    }
  }

  function openChatPanel() {
    var chatEl = document.getElementById('wf-chat');
    var suggEl = document.getElementById('wf-suggestions');
    if (chatEl) chatEl.style.display = 'block';
    if (suggEl) suggEl.style.display = 'none';
    chatState.chatOpen = true;
    saveChatState();
  }

  function closeChatPanel() {
    var chatEl = document.getElementById('wf-chat');
    var suggEl = document.getElementById('wf-suggestions');
    if (chatEl) chatEl.style.display = 'none';
    if (suggEl) suggEl.style.display = '';
    chatState.chatOpen = false;
    saveChatState();
  }

  function syncComposerFromState() {
    var inputEl = document.getElementById('chat-input');
    if (!inputEl) return;
    var convo = getCurrentConversation();
    inputEl.value = convo ? (convo.draftText || '') : '';
    resizeInput(inputEl);
    renderDraftMeta();
    updateComposerState();
  }

  function createMessageElement(message, index, editableIndex) {
    var div = document.createElement('div');
    div.className = 'wf-msg ' + message.role;

    var bubble = document.createElement('div');
    bubble.className = 'wf-bubble';
    bubble.innerHTML = escapeHtml(message.text).replace(/\n/g, '<br>');

    if (message.attachment) {
      var attach = document.createElement('div');
      attach.className = 'wf-attachment';
      attach.innerHTML = '<span>📎</span><span>'
        + escapeHtml(message.attachment.name)
        + (message.attachment.kind ? ' · ' + escapeHtml(message.attachment.kind) : '')
        + (message.attachment.size ? ' · ' + escapeHtml(formatBytes(message.attachment.size)) : '')
        + '</span>';
      bubble.appendChild(attach);
    }

    if (message.role === 'assistant') {
      addListenButton(bubble, message.text);
    }

    if (message.role === 'user' && index === editableIndex) {
      var actions = document.createElement('div');
      actions.className = 'wf-msg-actions';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wf-msg-edit';
      btn.textContent = 'Edit & resend';
      btn.addEventListener('click', function() {
        window.editLastUserMessage();
      });
      actions.appendChild(btn);
      bubble.appendChild(actions);
    }

    div.appendChild(bubble);
    return div;
  }

  function createTypingElement() {
    var div = document.createElement('div');
    div.className = 'wf-msg assistant';
    div.id = 'wf-typing-indicator';
    div.innerHTML = '<div class="wf-bubble"><div class="wf-typing"><span></span><span></span><span></span></div></div>';
    return div;
  }

  function renderCurrentConversation() {
    var body = document.getElementById('chat-body');
    if (!body) return;
    body.innerHTML = '';
    var convo = getCurrentConversation();
    if (!convo) return;
    var editableIndex = (convo.pendingCount || 0) > 0 ? -1 : latestUserMessageIndex(convo);
    if (isEditingCurrentAgent() && editableIndex !== editState.index) {
      editState = null;
    }
    convo.messages.forEach(function(message, index) {
      body.appendChild(createMessageElement(message, index, editableIndex));
    });
    if ((convo.pendingCount || 0) > 0) {
      body.appendChild(createTypingElement());
    }
    body.scrollTop = body.scrollHeight;
  }

  function renderDraftMeta() {
    var metaEl = document.getElementById('wf-chat-draft-meta');
    if (!metaEl) return;
    metaEl.innerHTML = '';
    var bits = 0;

    function chip(label, removable, action) {
      var span = document.createElement('span');
      span.className = 'wf-chat-chip';
      var name = document.createElement('span');
      name.className = 'name';
      name.textContent = label;
      span.appendChild(name);
      if (removable) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '✕';
        btn.setAttribute('data-chip-action', action);
        span.appendChild(btn);
      }
      metaEl.appendChild(span);
      bits += 1;
    }

    if (isEditingCurrentAgent()) {
      chip('Editing your last message', false, '');
    }

    var file = getDraftFile(currentAgent);
    if (file) {
      chip('New file: ' + file.name + ' · ' + formatBytes(file.size), true, 'remove-file');
    } else {
      var editingMessage = currentEditMessage();
      if (editingMessage && editingMessage.attachment && editingMessage.attachmentContext) {
        chip('Using saved file context: ' + editingMessage.attachment.name, true, 'clear-saved-file-context');
      }
    }

    metaEl.hidden = bits === 0;
  }

  function updateComposerState() {
    var convo = getCurrentConversation();
    var busy = !!(convo && convo.pendingCount > 0);
    var btn = document.getElementById('chat-send');
    var sendLabel = document.getElementById('chat-send-label');
    var statusEl = document.getElementById('chat-status');
    var cancelEdit = document.getElementById('wf-chat-edit-cancel');
    var file = getDraftFile(currentAgent);
    var editingMessage = currentEditMessage();
    var usingSavedFileContext = !!(editingMessage && editingMessage.attachmentContext && !file);

    if (btn) btn.disabled = busy;
    if (cancelEdit) cancelEdit.hidden = !isEditingCurrentAgent();

    if (sendLabel) {
      if (busy) sendLabel.textContent = 'Working…';
      else if (isEditingCurrentAgent()) sendLabel.textContent = file ? 'Save & analyze' : (usingSavedFileContext ? 'Save & resend' : 'Save & send');
      else sendLabel.textContent = file ? 'Analyze file' : 'Send';
    }

    if (statusEl) {
      if (busy) statusEl.textContent = 'Thinking...';
      else if (isEditingCurrentAgent()) statusEl.textContent = 'Editing your last message';
      else statusEl.textContent = 'Ready to assist';
    }
  }

  function activateAgent(name, label, icon, options) {
    if (!name) return;
    options = options || {};
    var meta = getAgentMeta(name) || {};
    currentAgent = name;
    currentLabel = label || meta.label || name;
    currentIcon = icon || meta.icon || '🤖';
    chatState.activeAgent = name;

    updateActiveCard(name);

    var iconEl = document.getElementById('chat-icon');
    var nameEl = document.getElementById('chat-name');
    var statusEl = document.getElementById('chat-status');
    var sessionStatusEl = document.getElementById('wf-session-status');

    if (iconEl) iconEl.textContent = currentIcon;
    if (nameEl) nameEl.textContent = currentLabel;
    if (statusEl) statusEl.textContent = 'Ready to assist';
    if (sessionStatusEl) sessionStatusEl.textContent = currentLabel;

    openChatPanel();

    var convo = ensureConversation(name, currentLabel, currentIcon);
    if (convo.messages.length === 0) {
      convo.messages.push({ role: 'assistant', text: defaultGreeting(currentLabel) });
    }
    saveChatState();
    renderCurrentConversation();
    syncComposerFromState();

    var inputEl = document.getElementById('chat-input');
    if (inputEl && !options.skipFocus) inputEl.focus();

    var chatEl = document.getElementById('wf-chat');
    if (chatEl && !options.skipScroll) {
      chatEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  window.selectAgent = function(name, label, icon) {
    activateAgent(name, label, icon);
  };

  window.askAgent = function(name, label, icon, prompt) {
    activateAgent(name, label, icon);
    var convo = getCurrentConversation();
    if (convo) convo.draftText = prompt || '';
    saveChatState();
    syncComposerFromState();
    setTimeout(function() {
      window.sendMessage();
    }, 150);
  };

  window.closeChat = function() {
    var sessionStatusEl = document.getElementById('wf-session-status');
    closeChatPanel();
    if (sessionStatusEl) sessionStatusEl.textContent = 'Ready';
    updateActiveCard(null);
  };

  window.clearChat = function() {
    if (!currentAgent) return;
    var convo = ensureConversation(currentAgent, currentLabel, currentIcon);
    convo.messages = [{ role: 'assistant', text: 'Chat cleared. How can I assist you with ' + (currentLabel || 'your request') + '?' }];
    convo.pendingCount = 0;
    convo.draftText = '';
    setDraftFile(currentAgent, null);
    if (isEditingCurrentAgent()) editState = null;
    saveChatState();
    renderCurrentConversation();
    syncComposerFromState();
  };

  window.editLastUserMessage = function() {
    var convo = getCurrentConversation();
    if (!convo || (convo.pendingCount || 0) > 0) return;
    var idx = latestUserMessageIndex(convo);
    if (idx < 0) return;
    editState = { agent: currentAgent, index: idx };
    convo.draftText = convo.messages[idx].text || '';
    saveChatState();
    syncComposerFromState();
    var inputEl = document.getElementById('chat-input');
    if (inputEl) inputEl.focus();
  };

  window.cancelEditMessage = function() {
    if (!isEditingCurrentAgent()) return;
    editState = null;
    saveChatState();
    renderCurrentConversation();
    renderDraftMeta();
    updateComposerState();
  };

  function buildUserMessage(text, file, inheritedMessage) {
    var message = { role: 'user', text: text };
    var previous = inheritedMessage || null;
    if (file) {
      var ext = '';
      if (file.name && file.name.indexOf('.') !== -1) ext = file.name.split('.').pop().toLowerCase();
      message.attachment = {
        name: file.name,
        size: file.size || 0,
        mime: file.type || '',
        extension: ext,
        kind: file.type && file.type.indexOf('video/') === 0 ? 'video' : (file.type && file.type.indexOf('audio/') === 0 ? 'audio' : 'file')
      };
    } else if (previous && previous.attachment) {
      message.attachment = cloneJson(previous.attachment) || previous.attachment;
    }
    if (previous && previous.attachmentContext) {
      message.attachmentContext = previous.attachmentContext;
    }
    if (previous && previous.attachmentFacts) {
      var carriedFacts = cloneJson(previous.attachmentFacts, MAX_ATTACHMENT_FACTS_CHARS);
      if (carriedFacts) message.attachmentFacts = carriedFacts;
    }
    return message;
  }

  function requestJson(url, payload) {
    var csrf = getCsrfToken();
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    }).then(function(r) {
      return r.json().then(function(data) {
        return { ok: r.ok, status: r.status, data: data };
      }).catch(function() {
        return { ok: r.ok, status: r.status, data: null };
      });
    });
  }

  function requestUpload(agent, instruction, history, file) {
    var csrf = getCsrfToken();
    var formData = new FormData();
    formData.append('agent', agent);
    formData.append('instruction', instruction);
    formData.append('conversation', JSON.stringify(history));
    formData.append('file', file);
    return fetch('/api/agents/analyze-upload', {
      method: 'POST',
      headers: {
        'X-CSRF-Token': csrf,
        'Accept': 'application/json'
      },
      body: formData
    }).then(function(r) {
      return r.json().then(function(data) {
        return { ok: r.ok, status: r.status, data: data };
      }).catch(function() {
        return { ok: r.ok, status: r.status, data: null };
      });
    });
  }

  window.sendMessage = function() {
    var input = document.getElementById('chat-input');
    if (!input) return;

    if (!currentAgent) {
      activateAgent('general', 'General Assistant', '🤖', { skipScroll: true });
    }

    var agentAtSend = currentAgent;
    var convo = ensureConversation(agentAtSend, currentLabel, currentIcon);
    if (!convo || (convo.pendingCount || 0) > 0) return;

    var rawText = (input.value || '').trim();
    var file = getDraftFile(agentAtSend);
    var editing = editState && editState.agent === agentAtSend ? { agent: editState.agent, index: editState.index } : null;
    var inheritedMessage = editing && convo.messages[editing.index] ? convo.messages[editing.index] : null;
    var text = rawText;

    if (!text && !file && !(inheritedMessage && inheritedMessage.attachmentContext)) return;
    if (!text && (file || (inheritedMessage && inheritedMessage.attachmentContext))) {
      text = 'Analyze this uploaded file. Explain what it contains, the key points, and anything unclear.';
    }

    var beforeMessages = convo.messages.slice(0, editing ? editing.index : convo.messages.length);
    var history = conversationHistoryFromMessages(beforeMessages);
    var nextUserMessage = buildUserMessage(text, file, inheritedMessage);
    convo.messages = beforeMessages.concat([nextUserMessage]);
    var userMessageIndex = convo.messages.length - 1;
    convo.draftText = '';
    if (editing) editState = null;
    saveChatState();
    renderCurrentConversation();
    renderDraftMeta();
    updateComposerState();

    input.value = '';
    resizeInput(input);
    setPending(agentAtSend, 1);

    var request;
    if (file) {
      request = requestUpload(agentAtSend, text, history, file);
    } else {
      var facts = inheritedMessage && inheritedMessage.attachmentFacts
        ? (cloneJson(inheritedMessage.attachmentFacts, MAX_ATTACHMENT_FACTS_CHARS) || inheritedMessage.attachmentFacts)
        : [];
      request = requestJson('/api/agents/dispatch', {
        agent: agentAtSend,
        instruction: text,
        conversation: history,
        facts: facts
      });
    }

    request
      .then(function(res) {
        setPending(agentAtSend, -1);
        var data = res.data;
        if (file && data && (data.attachment || data.contextMessage || data.fileFacts)) {
          var uploadPatch = {};
          if (data.attachment) uploadPatch.attachment = data.attachment;
          if (data.contextMessage) uploadPatch.attachmentContext = data.contextMessage;
          if (data.fileFacts) uploadPatch.attachmentFacts = data.fileFacts;
          updateMessageAt(agentAtSend, userMessageIndex, uploadPatch);
          setDraftFile(agentAtSend, null);
          var fileInput = document.getElementById('wf-file-input');
          if (fileInput) fileInput.value = '';
        }
        if (res.ok && data && (data.ok || data.result)) {
          var result = data.result || data;
          var answer = result.answer || result.response || (typeof result === 'string' ? result : JSON.stringify(result, null, 2));
          addMessageToAgent(agentAtSend, { role: 'assistant', text: answer });
          var warnings = data && Array.isArray(data.warnings) ? data.warnings.filter(Boolean) : [];
          if (warnings.length) {
            addMessageToAgent(agentAtSend, { role: 'system', text: 'ℹ️ File extraction note: ' + warnings.join(' ') });
          }
        } else {
          var error = (data && data.error) || (data && data.result && data.result.reason) || ('Request failed (' + res.status + ')');
          addMessageToAgent(agentAtSend, { role: 'system', text: '⚠️ ' + error });
        }
      })
      .catch(function(e) {
        setPending(agentAtSend, -1);
        addMessageToAgent(agentAtSend, { role: 'system', text: '⚠️ Network error: ' + (e.message || 'Unable to contact agent service') });
      });
  };

  function initDirectListeners() {
    var chatInputEl = document.getElementById('chat-input');
    if (chatInputEl && !chatInputEl.dataset.bound) {
      chatInputEl.dataset.bound = '1';
      chatInputEl.addEventListener('input', function() {
        resizeInput(this);
        var convo = getCurrentConversation();
        if (!convo) return;
        convo.draftText = this.value || '';
        saveChatState();
      });
    }

    var attachBtn = document.getElementById('wf-chat-attach');
    var fileInput = document.getElementById('wf-file-input');
    if (attachBtn && fileInput && !attachBtn.dataset.bound) {
      attachBtn.dataset.bound = '1';
      attachBtn.addEventListener('click', function() {
        if (!currentAgent) activateAgent('general', 'General Assistant', '🤖', { skipScroll: true });
        fileInput.click();
      });
      fileInput.addEventListener('change', function() {
        if (!currentAgent) activateAgent('general', 'General Assistant', '🤖', { skipScroll: true });
        var file = this.files && this.files[0] ? this.files[0] : null;
        setDraftFile(currentAgent, file);
        updateComposerState();
      });
    }

    var metaEl = document.getElementById('wf-chat-draft-meta');
    if (metaEl && !metaEl.dataset.bound) {
      metaEl.dataset.bound = '1';
      metaEl.addEventListener('click', function(ev) {
        var btn = ev.target.closest('[data-chip-action]');
        if (!btn || !currentAgent) return;
        var action = btn.getAttribute('data-chip-action');
        if (action === 'remove-file') {
          setDraftFile(currentAgent, null);
          var fileInput = document.getElementById('wf-file-input');
          if (fileInput) fileInput.value = '';
          updateComposerState();
        }
        if (action === 'clear-saved-file-context') {
          var message = currentEditMessage();
          if (!message) return;
          updateMessageAt(currentAgent, editState.index, {
            attachment: null,
            attachmentContext: '',
            attachmentFacts: null
          });
          renderDraftMeta();
          updateComposerState();
        }
      });
    }

    var cancelEdit = document.getElementById('wf-chat-edit-cancel');
    if (cancelEdit && !cancelEdit.dataset.bound) {
      cancelEdit.dataset.bound = '1';
      cancelEdit.addEventListener('click', function() {
        window.cancelEditMessage();
      });
    }
  }

  // Text-To-Speech Listen button handler
  function addListenButton(bubble, text) {
    if (!text) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn small';
    btn.textContent = '🔊 Listen';
    btn.setAttribute('data-wf-listen', text);
    btn.style.cssText = 'margin-top:6px;font-size:11px;padding:3px 8px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;';
    btn.addEventListener('click', function(ev) {
      ev.preventDefault();
      var speech = window.windelsSpeech || (window.SpeechProvider ? new window.SpeechProvider() : null);
      if (!speech) {
        if ('speechSynthesis' in window) {
          window.speechSynthesis.cancel();
          var utter = new SpeechSynthesisUtterance(text);
          utter.lang = 'en-GB';
          window.speechSynthesis.speak(utter);
        }
        return;
      }
      if (speech.isSpeaking() && btn.classList.contains('is-playing')) {
        speech.stop();
        btn.classList.remove('is-playing');
        btn.textContent = '🔊 Listen';
        return;
      }
      btn.classList.add('is-playing');
      btn.textContent = '⏹ Stop';
      speech.textToSpeech(text, {
        locale: 'en-GB',
        onEnd: function() { btn.classList.remove('is-playing'); btn.textContent = '🔊 Listen'; },
        onError: function() { btn.classList.remove('is-playing'); btn.textContent = '🔊 Listen'; }
      });
    });
    bubble.appendChild(btn);
  }

  // Initialize SpeechProvider and Mic
  function initSpeechControls() {
    var micBtn = document.getElementById('wf-chat-mic');
    var micStop = document.getElementById('wf-chat-mic-stop');
    var inputEl = document.getElementById('chat-input');
    var statusEl = document.getElementById('chat-status');
    if (!micBtn || !inputEl) return;

    var speech = window.windelsSpeech || (window.SpeechProvider ? new window.SpeechProvider() : null);
    if (speech && typeof speech.bindMic === 'function') {
      if (micStop) micStop.style.display = '';
      speech.bindMic(micBtn, inputEl, {
        locale: 'en-GB',
        idleLabel: '🎤 Speak',
        recordingLabel: '🎤 Listening…',
        stopButton: micStop,
        minListenMs: 30000,
        onStatus: function(msg) {
          if (statusEl) statusEl.textContent = msg || 'Ready to assist';
        }
      });
    } else {
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
      if (SR) {
        var recognizer = new SR();
        recognizer.continuous = false;
        recognizer.interimResults = false;
        recognizer.lang = 'en-GB';

        recognizer.onstart = function() {
          micBtn.textContent = '🎤 Listening…';
          if (micStop) { micStop.style.display = ''; micStop.disabled = false; }
          if (statusEl) statusEl.textContent = 'Listening for speech…';
        };
        recognizer.onresult = function(e) {
          var transcript = (e.results && e.results[0] && e.results[0][0]) ? e.results[0][0].transcript : '';
          if (transcript) {
            inputEl.value = inputEl.value ? (inputEl.value + ' ' + transcript) : transcript;
            inputEl.dispatchEvent(new Event('input'));
          }
        };
        recognizer.onerror = function() {
          micBtn.textContent = '🎤 Speak';
          if (micStop) micStop.disabled = true;
          if (statusEl) statusEl.textContent = 'Speech capture error';
        };
        recognizer.onend = function() {
          micBtn.textContent = '🎤 Speak';
          if (micStop) micStop.disabled = true;
          updateComposerState();
        };

        micBtn.addEventListener('click', function() {
          try { recognizer.start(); } catch (_) {}
        });
        if (micStop) {
          micStop.addEventListener('click', function() {
            try { recognizer.stop(); } catch (_) {}
          });
        }
      }
    }
  }

  function restoreSavedAgent() {
    if (!chatState.chatOpen || !chatState.activeAgent) return;
    var meta = getAgentMeta(chatState.activeAgent);
    if (!meta) return;
    activateAgent(
      chatState.activeAgent,
      meta.label,
      meta.icon,
      { skipFocus: true, skipScroll: true }
    );
  }

  initDirectListeners();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initDirectListeners();
      initSpeechControls();
      restoreSavedAgent();
    });
  } else {
    setTimeout(function() {
      initDirectListeners();
      initSpeechControls();
      restoreSavedAgent();
    }, 200);
  }
})();
</script>
