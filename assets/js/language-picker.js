/**
 * Searchable one-language picker. Talks to /api/v1/language-learning/catalog
 * and writes the chosen ISO code into a hidden <select> or <input>.
 */
(function () {
  'use strict';

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function capBadge(lang) {
    if (!lang) return 'Text only';
    if (lang.full_ai) return 'Full AI learning';
    if (lang.translation && lang.tts) return 'Translation + voice';
    if (lang.translation) return 'Translation available';
    if (lang.tts) return 'Voice available';
    if (lang.stt) return 'Speech recognition';
    return lang.support_label || 'Text only';
  }

  function labelOf(lang) {
    if (!lang) return '';
    var name = lang.name || lang.code;
    var native = lang.native_name && lang.native_name !== name ? ' — ' + lang.native_name : '';
    return name + native;
  }

  window.WindelsLanguagePicker = function (opts) {
    var mount = typeof opts.mount === 'string' ? document.querySelector(opts.mount) : opts.mount;
    var target = typeof opts.target === 'string' ? document.querySelector(opts.target) : opts.target;
    if (!mount || !target) return null;
    var endpoint = opts.endpoint || '/api/v1/language-learning/catalog';
    var selected = opts.value || target.value || '';
    var timer = null;
    var open = false;

    mount.classList.add('lang-picker');
    mount.innerHTML = '';
    var display = el('button', 'lang-picker-display');
    display.type = 'button';
    display.setAttribute('aria-haspopup', 'listbox');
    var panel = el('div', 'lang-picker-panel');
    panel.hidden = true;
    var search = el('input', 'lang-picker-search');
    search.type = 'search';
    search.placeholder = opts.placeholder || 'Search language…';
    search.setAttribute('aria-label', opts.placeholder || 'Search language…');
    var list = el('div', 'lang-picker-list');
    list.setAttribute('role', 'listbox');
    var note = el('p', 'lang-picker-note');
    panel.appendChild(search);
    panel.appendChild(list);
    panel.appendChild(note);
    mount.appendChild(display);
    mount.appendChild(panel);

    function setDisplay(lang) {
      display.textContent = lang ? labelOf(lang) : (opts.emptyLabel || 'Choose a language');
      display.dataset.code = lang ? lang.code : '';
    }

    function choose(lang) {
      if (!lang) return;
      selected = lang.code;
      if (target.tagName === 'SELECT') {
        var opt = target.querySelector('option[value="' + lang.code + '"]');
        if (!opt) {
          opt = document.createElement('option');
          opt.value = lang.code;
          opt.textContent = labelOf(lang);
          target.appendChild(opt);
        }
        target.value = lang.code;
      } else {
        target.value = lang.code;
      }
      target.dispatchEvent(new Event('change', { bubbles: true }));
      setDisplay(lang);
      close();
      if (opts.onChange) opts.onChange(lang);
    }

    function render(rows, total) {
      list.innerHTML = '';
      if (!rows || !rows.length) {
        list.appendChild(el('div', 'lang-picker-empty', 'No matching language.'));
        note.textContent = '';
        return;
      }
      rows.forEach(function (lang) {
        var row = el('button', 'lang-picker-item');
        row.type = 'button';
        row.setAttribute('role', 'option');
        var title = el('span', 'lang-picker-name', labelOf(lang));
        var meta = el('span', 'lang-picker-meta', (lang.iso_code || lang.code || '') + ' · ' + capBadge(lang));
        row.appendChild(title);
        row.appendChild(meta);
        if (lang.code === selected) row.classList.add('is-selected');
        row.addEventListener('click', function () { choose(lang); });
        list.appendChild(row);
      });
      note.textContent = total ? ('Showing ' + rows.length + ' of ' + total + ' languages') : '';
    }

    function fetchRows(q) {
      var url = endpoint + '?limit=20&q=' + encodeURIComponent(q || '');
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (body) { render(body.languages || [], body.total); })
        .catch(function () { list.innerHTML = ''; list.appendChild(el('div', 'lang-picker-empty', 'Language search is unavailable.')); });
    }

    function openPanel() {
      open = true;
      panel.hidden = false;
      display.setAttribute('aria-expanded', 'true');
      search.focus();
      fetchRows(search.value);
    }
    function close() {
      open = false;
      panel.hidden = true;
      display.setAttribute('aria-expanded', 'false');
    }

    display.addEventListener('click', function () { open ? close() : openPanel(); });
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { fetchRows(search.value); }, 160);
    });
    document.addEventListener('click', function (ev) {
      if (open && !mount.contains(ev.target)) close();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && open) close();
    });

    var initial = null;
    if (opts.initial) initial = opts.initial;
    setDisplay(initial || (selected ? { code: selected, name: selected } : null));
    if (selected && !opts.initial) {
      fetch(endpoint + '?limit=1&q=' + encodeURIComponent(selected), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (body) {
          var hit = (body.languages || []).find(function (l) { return l.code === selected || l.iso6391 === selected || l.iso6393 === selected; });
          if (hit) setDisplay(hit);
        })
        .catch(function () {});
    }
    return { choose: choose, getValue: function () { return selected; }, refresh: function () { fetchRows(search.value); } };
  };
})();
