(function () {
  'use strict';
  var root = document.getElementById('aegis-chat');
  if (!root) return;
  var launch = root.querySelector('.aegis-chat-launch');
  var panel = root.querySelector('.aegis-chat-panel');
  var close = root.querySelector('.aegis-chat-close');
  var form = root.querySelector('.aegis-chat-form');
  var input = form && form.querySelector('input[name="message"]');
  var messages = root.querySelector('.aegis-chat-messages');
  var endpoint = root.getAttribute('data-endpoint') || '/api/chat/respond';
  function setOpen(open) { panel.hidden = !open; launch.setAttribute('aria-expanded', open ? 'true' : 'false'); if (open && input) input.focus(); }
  function addMessage(text, kind) { var item = document.createElement('div'); item.className = 'aegis-chat-message ' + kind; item.textContent = text; messages.appendChild(item); messages.scrollTop = messages.scrollHeight; return item; }
  launch.addEventListener('click', function () { setOpen(panel.hidden); });
  close.addEventListener('click', function () { setOpen(false); });
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var message = input.value.trim();
    if (!message) return;
    input.value = ''; addMessage(message, 'user');
    var pending = addMessage('Thinking…', 'agent pending');
    var button = form.querySelector('button'); button.disabled = true;
    fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: message }) })
      .then(function (response) { return response.json().then(function (body) { if (!response.ok) throw new Error(body.error || 'The assistant is unavailable.'); return body; }); })
      .then(function (body) { pending.className = 'aegis-chat-message agent'; pending.textContent = body.message || 'No response was returned.'; })
      .catch(function (error) { pending.className = 'aegis-chat-message error'; pending.textContent = error.message || 'The assistant is unavailable.'; })
      .finally(function () { button.disabled = false; });
  });
}());
