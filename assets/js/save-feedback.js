/**
 * Professional save notifications. Existing .notice banners stay in place;
 * this also raises a toast so a successful save is obvious after redirect.
 */
(function () {
  'use strict';
  function ensureHost() {
    var host = document.getElementById('save-toasts');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'save-toasts';
    host.className = 'save-toasts';
    host.setAttribute('aria-live', 'polite');
    document.body.appendChild(host);
    return host;
  }

  function toast(kind, message) {
    if (!message) return;
    var host = ensureHost();
    var item = document.createElement('div');
    item.className = 'save-toast save-toast--' + (kind === 'error' ? 'err' : 'ok');
    item.textContent = message;
    host.appendChild(item);
    setTimeout(function () { item.classList.add('is-in'); }, 10);
    setTimeout(function () {
      item.classList.remove('is-in');
      setTimeout(function () { item.remove(); }, 280);
    }, 4200);
  }

  window.WindelsSaveFeedback = { toast: toast };

  function boot() {
    document.querySelectorAll('.notice.ok, .notice.err').forEach(function (n) {
      if (n.dataset.toasted === '1') return;
      n.dataset.toasted = '1';
      var text = (n.textContent || '').trim();
      if (!text) return;
      toast(n.classList.contains('err') ? 'error' : 'ok', text);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  document.addEventListener('DOMContentLoaded', boot);
})();
