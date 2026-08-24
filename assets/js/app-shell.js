(() => {
  const toggle = document.querySelector('.sidebar-toggle');
  const sidebar = document.getElementById('app-sidebar');
  if (!toggle || !sidebar) return;
  toggle.addEventListener('click', () => {
    const open = document.body.classList.toggle('sidebar-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
