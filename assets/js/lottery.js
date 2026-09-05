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
    /* HISTORICAL mode is driven by the verified historical dataset stored in
       the database. If that dataset is empty the API answers 400 with a
       DATA UNAVAILABLE message — we surface it instead of silently falling
       back to random numbers. `count` is the API's field name for the number
       of lines. */
    fetch('/api/lottery/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ mode: 'HISTORICAL', count: 5 })
    }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (!res.ok || res.body.error) {
          t.disabled = false;
          t.textContent = orig;
          window.alert(res.body.error || 'DATA UNAVAILABLE — the verified historical dataset could not be read.');
          return;
        }
        window.location.reload();
      })
      .catch(function () { t.disabled = false; t.textContent = orig; });
  });
})();
