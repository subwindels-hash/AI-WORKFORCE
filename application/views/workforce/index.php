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
      <textarea id="chat-input" placeholder="Ask the agent a question..." rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage();}"></textarea>
    </div>
    <div class="wf-chat-controls">
      <div class="wf-chat-voice-controls">
        <button type="button" id="wf-chat-mic" class="btn small" aria-label="Speak">🎤 Speak</button>
        <button type="button" id="wf-chat-mic-stop" class="btn small" aria-label="Stop" disabled style="display:none">⏹ Stop</button>
      </div>
      <button type="button" id="chat-send" class="wf-chat-send" onclick="sendMessage()"><?= $ic ?><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg> <span>Send</span></button>
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
  var currentAgent = null;
  var currentLabel = '';
  var messages = [];

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

  function updateActiveCard(agentName) {
    document.querySelectorAll('.wf-card').forEach(function(card) {
      if (card.getAttribute('data-agent') === agentName) {
        card.classList.add('active');
      } else {
        card.classList.remove('active');
      }
    });
  }

  window.selectAgent = function(name, label, icon) {
    currentAgent = name;
    currentLabel = label;
    updateActiveCard(name);

    var iconEl = document.getElementById('chat-icon');
    var nameEl = document.getElementById('chat-name');
    var statusEl = document.getElementById('chat-status');
    var sessionStatusEl = document.getElementById('wf-session-status');

    if (iconEl) iconEl.textContent = icon || '🤖';
    if (nameEl) nameEl.textContent = label || name;
    if (statusEl) statusEl.textContent = 'Ready to assist';
    if (sessionStatusEl) sessionStatusEl.textContent = label || name;

    var chatEl = document.getElementById('wf-chat');
    var suggEl = document.getElementById('wf-suggestions');
    if (chatEl) chatEl.style.display = 'block';
    if (suggEl) suggEl.style.display = 'none';

    var chatBody = document.getElementById('chat-body');
    if (chatBody && chatBody.children.length === 0) {
      addMessage('assistant', "Hello! I am your " + label + ". How can I help you today?");
    }

    var inputEl = document.getElementById('chat-input');
    if (inputEl) {
      inputEl.focus();
    }

    if (chatEl) {
      chatEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  window.askAgent = function(name, label, icon, prompt) {
    window.selectAgent(name, label, icon);
    var input = document.getElementById('chat-input');
    if (input) {
      input.value = prompt;
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }
    setTimeout(function() {
      window.sendMessage();
    }, 150);
  };

  window.closeChat = function() {
    var chatEl = document.getElementById('wf-chat');
    var suggEl = document.getElementById('wf-suggestions');
    var sessionStatusEl = document.getElementById('wf-session-status');
    if (chatEl) chatEl.style.display = 'none';
    if (suggEl) suggEl.style.display = '';
    if (sessionStatusEl) sessionStatusEl.textContent = 'Ready';
    updateActiveCard(null);
  };

  window.clearChat = function() {
    messages = [];
    var body = document.getElementById('chat-body');
    if (body) body.innerHTML = '';
    addMessage('assistant', "Chat cleared. How can I assist you with " + (currentLabel || 'your request') + "?");
  };

  function addMessage(role, text) {
    messages.push({ role: role, text: text });
    var body = document.getElementById('chat-body');
    if (!body) return;

    var div = document.createElement('div');
    div.className = 'wf-msg ' + role;
    
    var formattedText = escapeHtml(text).replace(/\n/g, '<br>');
    div.innerHTML = '<div class="wf-bubble">' + formattedText + '</div>';
    body.appendChild(div);

    if (role === 'assistant') {
      var bubble = div.querySelector('.wf-bubble');
      if (bubble) addListenButton(bubble, text);
    }

    body.scrollTop = body.scrollHeight;
  }

  function showTyping() {
    var body = document.getElementById('chat-body');
    if (!body) return;
    var existing = document.getElementById('wf-typing-indicator');
    if (existing) return;

    var div = document.createElement('div');
    div.className = 'wf-msg assistant';
    div.id = 'wf-typing-indicator';
    div.innerHTML = '<div class="wf-bubble"><div class="wf-typing"><span></span><span></span><span></span></div></div>';
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }

  function hideTyping() {
    var el = document.getElementById('wf-typing-indicator');
    if (el) el.remove();
  }

  window.sendMessage = function() {
    var input = document.getElementById('chat-input');
    if (!input) return;
    var text = input.value.trim();
    if (!text) return;

    if (!currentAgent) {
      window.selectAgent('general', 'General Assistant', '🤖');
    }

    addMessage('user', text);
    input.value = '';
    input.style.height = 'auto';

    var btn = document.getElementById('chat-send');
    var statusEl = document.getElementById('chat-status');
    if (btn) btn.disabled = true;
    if (statusEl) statusEl.textContent = 'Thinking...';
    showTyping();

    var csrf = getCsrfToken();
    var payload = {
      agent: currentAgent,
      instruction: text,
      conversation: messages.slice(-10)
    };

    fetch('/api/agents/dispatch', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    })
    .then(function(r) {
      return r.json().then(function(data) {
        return { ok: r.ok, status: r.status, data: data };
      }).catch(function() {
        return { ok: r.ok, status: r.status, data: null };
      });
    })
    .then(function(res) {
      hideTyping();
      if (btn) btn.disabled = false;
      if (statusEl) statusEl.textContent = 'Ready to assist';

      var data = res.data;
      if (res.ok && data && (data.ok || data.result)) {
        var result = data.result || data;
        var answer = result.answer || result.response || (typeof result === 'string' ? result : JSON.stringify(result, null, 2));
        addMessage('assistant', answer);
      } else {
        var error = (data && data.error) || (data && data.result && data.result.reason) || ('Request failed (' + res.status + ')');
        addMessage('system', '⚠️ ' + error);
      }
    })
    .catch(function(e) {
      hideTyping();
      if (btn) btn.disabled = false;
      if (statusEl) statusEl.textContent = 'Ready to assist';
      addMessage('system', '⚠️ Network error: ' + (e.message || 'Unable to contact agent service'));
    });
  };

  // Direct Event Listeners for Buttons & Interactive Elements
  function initDirectListeners() {
    // Agent Grid delegation
    var grid = document.getElementById('wf-agent-grid');
    if (grid && !grid.dataset.bound) {
      grid.dataset.bound = '1';
      grid.addEventListener('click', function(ev) {
        var card = ev.target.closest('.wf-card');
        if (!card) return;
        var name = card.getAttribute('data-agent');
        var label = card.getAttribute('data-label');
        var icon = card.getAttribute('data-icon');
        if (name) window.selectAgent(name, label, icon);
      });
    }

    // Suggestions delegation
    var sugg = document.getElementById('wf-suggestions');
    if (sugg && !sugg.dataset.bound) {
      sugg.dataset.bound = '1';
      sugg.addEventListener('click', function(ev) {
        var card = ev.target.closest('.wf-suggest-card');
        if (!card) return;
        var name = card.getAttribute('data-agent');
        var label = card.getAttribute('data-label');
        var icon = card.getAttribute('data-icon');
        var prompt = card.getAttribute('data-prompt');
        if (name && prompt) window.askAgent(name, label, icon, prompt);
      });
    }

    // Direct button listeners
    var sendBtn = document.getElementById('chat-send');
    if (sendBtn && !sendBtn.dataset.bound) {
      sendBtn.dataset.bound = '1';
      sendBtn.addEventListener('click', function(e) {
        e.preventDefault();
        window.sendMessage();
      });
    }

    var clearBtn = document.getElementById('chat-clear');
    if (clearBtn && !clearBtn.dataset.bound) {
      clearBtn.dataset.bound = '1';
      clearBtn.addEventListener('click', function(e) {
        e.preventDefault();
        window.clearChat();
      });
    }

    var closeBtn = document.getElementById('chat-close');
    if (closeBtn && !closeBtn.dataset.bound) {
      closeBtn.dataset.bound = '1';
      closeBtn.addEventListener('click', function(e) {
        e.preventDefault();
        window.closeChat();
      });
    }

    // Auto-resize textarea
    var chatInputEl = document.getElementById('chat-input');
    if (chatInputEl && !chatInputEl.dataset.bound) {
      chatInputEl.dataset.bound = '1';
      chatInputEl.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
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
      // Direct Web Speech API fallback
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
          if (statusEl) statusEl.textContent = 'Ready to assist';
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

  initDirectListeners();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initDirectListeners();
      initSpeechControls();
    });
  } else {
    setTimeout(function() {
      initDirectListeners();
      initSpeechControls();
    }, 200);
  }
})();
</script>
