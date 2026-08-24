<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div id="aegis-chat" class="aegis-chat" data-endpoint="/api/chat/respond">
  <button class="aegis-chat-launch" type="button" aria-expanded="false" aria-controls="aegis-chat-panel">
    <img src="/assets/images/ai-agent-avatar.png" alt=""> <span>Ask AEGIS</span>
  </button>
  <section id="aegis-chat-panel" class="aegis-chat-panel" hidden aria-label="AEGIS website assistant">
    <header><img src="/assets/images/ai-agent-avatar.png" alt="AEGIS assistant"><div><strong>AEGIS Guide</strong><small>Product help · grounded responses</small></div><button type="button" class="aegis-chat-close" aria-label="Close assistant">×</button></header>
    <div class="aegis-chat-messages" aria-live="polite"><div class="aegis-chat-message agent">Hi, I’m AEGIS Guide. Ask me how to use the website.</div></div>
    <form class="aegis-chat-form"><input name="message" maxlength="1000" required placeholder="Ask about a page or feature…" autocomplete="off"><button type="submit">Send</button></form>
    <p class="aegis-chat-note">Guidance only. No private account, market, sports, lottery or lead records are exposed to this assistant.</p>
  </section>
</div>
<script src="/assets/js/aegis-chat.js" defer></script>
