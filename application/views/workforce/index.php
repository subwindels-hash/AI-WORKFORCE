<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $agents @var int $agentCount @var bool $cloudflareConfigured @var array $llmStatus */
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
?>
<style>
/* ═══ Workforce Grid ═══════════════════════════════════════════════ */
.wf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:24px}
.wf-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;cursor:pointer;transition:all .18s;position:relative;overflow:hidden}
.wf-card:hover{border-color:var(--brand);transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.25)}
.wf-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--agent-color,var(--brand));opacity:0;transition:opacity .18s}
.wf-card:hover::before{opacity:1}
.wf-card .wf-icon{font-size:28px;margin-bottom:10px}
.wf-card .wf-name{font-size:15px;font-weight:700;color:#fff;margin-bottom:4px}
.wf-card .wf-desc{font-size:12px;color:var(--muted);line-height:1.5;margin-bottom:10px}
.wf-card .wf-tools{display:flex;gap:4px;flex-wrap:wrap}
.wf-card .wf-tool{background:var(--panel2);padding:2px 7px;border-radius:10px;font-size:10px;color:var(--dim)}
.wf-status{display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.wf-stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 18px;flex:1;min-width:140px}
.wf-stat .lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.wf-stat .val{font-size:20px;font-weight:700;color:#fff}

/* ═══ Chat Interface ═══════════════════════════════════════════════ */
.wf-chat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;margin-bottom:24px}
.wf-chat-head{background:var(--panel2);padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}
.wf-chat-head .wf-agent-icon{font-size:28px}
.wf-chat-head .wf-agent-name{font-size:15px;font-weight:700;color:#fff}
.wf-chat-head .wf-agent-status{font-size:11px;color:var(--dim)}
.wf-chat-body{padding:18px;min-height:300px;max-height:500px;overflow-y:auto}
.wf-msg{margin-bottom:14px;display:flex;gap:10px}
.wf-msg.user{flex-direction:row-reverse}
.wf-msg .wf-bubble{max-width:80%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.5}
.wf-msg.assistant .wf-bubble{background:var(--panel2);color:var(--text);border-bottom-left-radius:2px}
.wf-msg.user .wf-bubble{background:var(--brand);color:#fff;border-bottom-right-radius:2px}
.wf-msg.system .wf-bubble{background:transparent;border:1px dashed var(--line);color:var(--dim);font-size:11px;max-width:100%}
.wf-chat-foot{padding:14px 18px;border-top:1px solid var(--line);display:flex;gap:8px}
.wf-chat-foot textarea{flex:1;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-sm);padding:10px 12px;color:var(--text);font:inherit;resize:none;min-height:44px}
.wf-chat-foot textarea:focus{outline:none;border-color:var(--brand)}
.wf-chat-foot button{background:var(--brand);color:#fff;border:none;border-radius:var(--radius-sm);padding:10px 18px;font:600 13px/1 inherit;cursor:pointer;transition:background .15s}
.wf-chat-foot button:hover:not(:disabled){background:var(--brand-hover,var(--brand))}
.wf-chat-foot button:disabled{opacity:.5;cursor:not-allowed}
.wf-typing{display:flex;gap:4px;padding:8px 0}
.wf-typing span{width:7px;height:7px;border-radius:50%;background:var(--dim);animation:wf-bounce .6s ease-in-out infinite}
.wf-typing span:nth-child(2){animation-delay:.15s}
.wf-typing span:nth-child(3){animation-delay:.3s}
@keyframes wf-bounce{0%,100%{transform:translateY(0);opacity:.4}50%{transform:translateY(-4px);opacity:1}}

/* ═══ Welcome Banner ═══════════════════════════════════════════════ */
.wf-hero{background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4338ca 100%);border-radius:var(--radius);padding:28px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden}
.wf-hero::after{content:'';position:absolute;top:-50%;right:-20%;width:60%;height:200%;background:radial-gradient(circle,rgba(99,102,241,.15) 0%,transparent 70%)}
.wf-hero h2{font-size:24px;font-weight:800;margin:0 0 8px;position:relative;z-index:1}
.wf-hero p{font-size:14px;opacity:.85;margin:0 0 16px;max-width:600px;position:relative;z-index:1}
.wf-hero-pills{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1}
.wf-hero-pill{background:rgba(255,255,255,.12);backdrop-filter:blur(4px);padding:5px 12px;border-radius:20px;font-size:11px;font-weight:600}

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
    <p>Interact with specialist AI agents — low latency, globally distributed inference.</p>
  </div>
</div>

<?php if (!$cloudflareConfigured): ?>
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
  <p><?= e($agentCount) ?> specialist agents ready to assist with market analysis, trading, language learning, lead discovery, and more. All powered by edge AI for instant responses.</p>
  <div class="wf-hero-pills">
    <span class="wf-hero-pill">⚡ Edge AI</span>
    <span class="wf-hero-pill">🔒 Approved Tools Only</span>
    <span class="wf-hero-pill">📊 Audit Trail</span>
    <span class="wf-hero-pill">🌍 Global Distribution</span>
    <span class="wf-hero-pill">🎯 Specialist Agents</span>
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
    <div class="val" style="font-size:14px"><?= $cloudflareConfigured ? '<span style="color:var(--green)">● Windels AI</span>' : '<span style="color:var(--amber)">● Local Guide</span>' ?></div>
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
<div class="wf-grid">
  <?php foreach ($agents as $i => $a): ?>
    <div class="wf-card" style="--agent-color:<?= e($a['color']) ?>" onclick="selectAgent('<?= e($a['name']) ?>','<?= e($a['label']) ?>','<?= e($a['icon']) ?>')">
      <div class="wf-icon"><?= e($a['icon']) ?></div>
      <div class="wf-name"><?= e($a['label']) ?></div>
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
    </div>
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
    <div style="margin-left:auto;display:flex;gap:6px">
      <button class="btn small" onclick="clearChat()">Clear</button>
      <button class="btn small" onclick="closeChat()">Close</button>
    </div>
  </div>
  <div class="wf-chat-body" id="chat-body"></div>
  <div class="wf-chat-foot">
    <textarea id="chat-input" placeholder="Ask the agent a question..." rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage()}"></textarea>
    <button id="chat-send" onclick="sendMessage()"><?= $ic ?><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg> Send</button>
  </div>
</div>

<!-- Suggestions when no chat active -->
<div id="wf-suggestions" class="panel" style="margin-top:20px">
  <div class="body">
    <h4 style="margin:0 0 12px">💡 Try asking an agent:</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('market','Market Analyst','📈');setTimeout(function(){document.getElementById('chat-input').value='What is the current BTC/USD price?';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">📈 Market</div>
        <div style="font-size:11px;color:var(--dim)">"What is the current BTC/USD price?"</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('trading','Trading Analyst','💹');setTimeout(function(){document.getElementById('chat-input').value='Analyze my portfolio and suggest rebalancing';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">💹 Trading</div>
        <div style="font-size:11px;color:var(--dim)">"Analyze my portfolio and suggest rebalancing"</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('lottery','Lottery Analyst','🎰');setTimeout(function(){document.getElementById('chat-input').value='Show me the most frequent EuroMillions numbers';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">🎰 Lottery</div>
        <div style="font-size:11px;color:var(--dim)">"Show me the most frequent EuroMillions numbers"</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('lead_discovery','Lead Scout','🔍');setTimeout(function(){document.getElementById('chat-input').value='Find top-rated restaurants in London';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">🔍 Lead Discovery</div>
        <div style="font-size:11px;color:var(--dim)">"Find top-rated restaurants in London"</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('language','Language Coach','🗣️');setTimeout(function(){document.getElementById('chat-input').value='Help me practice Dutch conversation';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">🗣️ Language</div>
        <div style="font-size:11px;color:var(--dim)">"Help me practice Dutch conversation"</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px;cursor:pointer" onclick="selectAgent('sports','Sports Intelligence','⚽');setTimeout(function(){document.getElementById('chat-input').value='Show me recent Premier League fixtures';sendMessage()},300)">
        <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px">⚽ Sports</div>
        <div style="font-size:11px;color:var(--dim)">"Show me recent Premier League fixtures"</div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
var currentAgent = null;
var currentLabel = '';
var messages = [];

window.selectAgent = function(name, label, icon) {
  currentAgent = name;
  currentLabel = label;
  document.getElementById('chat-icon').textContent = icon;
  document.getElementById('chat-name').textContent = label;
  document.getElementById('chat-status').textContent = 'Ready to assist';
  document.getElementById('wf-chat').style.display = '';
  document.getElementById('wf-suggestions').style.display = 'none';
  document.getElementById('chat-input').focus();
  if (messages.length === 0) {
    addMessage('assistant', 'Hello! I\'m your ' + label + '. How can I help you today?');
  }
  document.getElementById('wf-chat').scrollIntoView({ behavior: 'smooth', block: 'start' });
};

window.closeChat = function() {
  document.getElementById('wf-chat').style.display = 'none';
  document.getElementById('wf-suggestions').style.display = '';
};

window.clearChat = function() {
  messages = [];
  document.getElementById('chat-body').innerHTML = '';
  addMessage('assistant', 'Chat cleared. How can I help you?');
};

function addMessage(role, text) {
  messages.push({ role: role, text: text });
  var body = document.getElementById('chat-body');
  var div = document.createElement('div');
  div.className = 'wf-msg ' + role;
  div.innerHTML = '<div class="wf-bubble">' + escapeHtml(text).replace(/\n/g, '<br>') + '</div>';
  body.appendChild(div);
  body.scrollTop = body.scrollHeight;
}

function showTyping() {
  var body = document.getElementById('chat-body');
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
  var text = input.value.trim();
  if (!text || !currentAgent) return;

  addMessage('user', text);
  input.value = '';
  input.style.height = 'auto';

  var btn = document.getElementById('chat-send');
  btn.disabled = true;
  document.getElementById('chat-status').textContent = 'Thinking...';
  showTyping();

  fetch('/api/agents/dispatch', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ agent: currentAgent, instruction: text })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    hideTyping();
    btn.disabled = false;
    document.getElementById('chat-status').textContent = 'Ready to assist';
    if (data.ok && data.result) {
      var answer = data.result.answer || data.result.response || JSON.stringify(data.result, null, 2);
      addMessage('assistant', answer);
    } else {
      var error = data.error || 'Agent returned an error';
      var reason = (data.result && data.result.reason) || '';
      addMessage('system', '⚠️ ' + error + (reason ? ' — ' + reason : ''));
    }
  })
  .catch(function(e) {
    hideTyping();
    btn.disabled = false;
    document.getElementById('chat-status').textContent = 'Ready to assist';
    addMessage('system', '⚠️ Network error: ' + e.message);
  });
};

function escapeHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

// Auto-resize textarea
document.getElementById('chat-input').addEventListener('input', function() {
  this.style.height = 'auto';
  this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
})();
</script>
