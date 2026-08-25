<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * WINDELS Assistant — floating AI chat widget.
 * Ships its own stylesheet (fixed positioning, z-index, responsive sizing)
 * so it floats independently of whatever page shell, layout, overflow or
 * footer it is embedded in. Deliberately NOT loaded on auth pages.
 */ ?>
<link rel="stylesheet" href="/assets/css/chat-widget.css">
<div id="ai_workforce-chat" class="ai_workforce-chat" data-endpoint="/api/chat/respond">
  <section id="ai_workforce-chat-panel" class="ai_workforce-chat-panel" hidden role="dialog" aria-label="WINDELS Assistant">
    <header>
      <span class="ai_workforce-chat-avatar">
        <img src="/assets/images/ai-agent-avatar.png" alt="" width="34" height="34" loading="lazy"
             onerror="this.style.display='none';this.parentElement.classList.add('is-fallback');">
        <span class="ai_workforce-chat-fallback" aria-hidden="true">W</span>
      </span>
      <span class="ai_workforce-chat-id">
        <strong>WINDELS Assistant</strong>
        <small>Product help · grounded responses</small>
      </span>
      <button type="button" class="ai_workforce-chat-close" aria-label="Close assistant" title="Close">×</button>
    </header>
    <div class="ai_workforce-chat-messages" aria-live="polite"><div class="ai_workforce-chat-message agent">Hi, I'm the WINDELS Assistant. Ask me how to use the platform. <button type="button" class="ai_workforce-chat-listen" data-listen="Hi, I'm the WINDELS Assistant. Ask me how to use the platform.">🔊 Listen</button></div></div>
    <p class="ai_workforce-chat-voice-status" id="ai_workforce-chat-voice-status" hidden></p>
    <form class="ai_workforce-chat-form">
      <button type="button" class="ai_workforce-chat-mic" id="ai_workforce-chat-mic" aria-label="Tap to speak">🎤 Speak</button>
      <input name="message" maxlength="1000" required placeholder="Ask about a page or feature…" autocomplete="off" aria-label="Message">
      <button type="submit" aria-label="Send message">Send</button>
    </form>
    <p class="ai_workforce-chat-note">Guidance only. No private account, market, sports, lottery or lead records are exposed to this assistant.</p>
  </section>
  <button class="ai_workforce-chat-launch" type="button" aria-expanded="false" aria-controls="ai_workforce-chat-panel" aria-label="Open WINDELS Assistant" title="Ask WINDELS Assistant">
    <span class="ai_workforce-chat-avatar">
      <img src="/assets/images/ai-agent-avatar.png" alt="" width="40" height="40" loading="lazy"
           onerror="this.style.display='none';this.parentElement.classList.add('is-fallback');">
      <span class="ai_workforce-chat-fallback" aria-hidden="true">W</span>
    </span>
    <span class="ai_workforce-chat-launch-label" aria-hidden="true">Ask WINDELS</span>
  </button>
</div>
<script src="/assets/js/ai_workforce-chat.js" defer></script>
