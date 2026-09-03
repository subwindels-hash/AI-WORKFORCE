/* EuroMillions dashboard enhancements.
 *
 * The page already renders a functional tabbed dashboard inline (see
 * views/lottery/index.php) without this file. This script adds richer async
 * behaviour: tab switching is already wired, but future widgets (full
 * generator UI, system-builder controls, per-line analyzer modals, ticket
 * purchase workflow) can mount on window.__AI_LOTTERY_STATE__ without a
 * full framework dependency. Keeping this file small for now means the
 * feature works even if the JS bundle fails to load.
 */
(function () {
  'use strict';
  if (!window.__AI_LOTTERY_STATE__) return;
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-lottery-generate]');
    if (!t) return;
    ev.preventDefault();
    t.disabled = true;
    var orig = t.textContent;
    t.textContent = 'Generating…';
    fetch('/api/lottery/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ mode: 'balanced', lines: 5 })
    }).then(function (r) { return r.json(); })
      .then(function () { window.location.reload(); })
      .catch(function () { t.disabled = false; t.textContent = orig; });
  });
})();
