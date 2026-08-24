(() => {
  // The dashboard is a normal multi-page app: every page is server-rendered
  // with the same sidebar/header/footer shell, so the browser's native Back /
  // Forward history works unchanged. This script only improves the mobile
  // sidebar affordance (toggle, close-on-navigate, outside-click) — it never
  // touches window.history or replaces state.
  const toggle = document.querySelector('.sidebar-toggle');
  const sidebar = document.getElementById('app-sidebar');
  if (!toggle || !sidebar) return;

  const setOpen = (open) => {
    document.body.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  toggle.addEventListener('click', (ev) => {
    ev.stopPropagation();
    setOpen(!document.body.classList.contains('sidebar-open'));
  });

  // Close the drawer when a link inside it is chosen (navigation follows).
  sidebar.addEventListener('click', (ev) => {
    const a = ev.target.closest('a[href]');
    if (a) setOpen(false);
  });

  // Close when tapping outside the drawer on small screens.
  document.addEventListener('click', (ev) => {
    if (!document.body.classList.contains('sidebar-open')) return;
    if (sidebar.contains(ev.target) || toggle.contains(ev.target)) return;
    setOpen(false);
  });

  // Close on Escape for keyboard users.
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') setOpen(false);
  });

  // Profile dropdown (header).
  const profileBtn = document.getElementById('profile-btn');
  const profileMenu = document.getElementById('profile-menu');
  if (profileBtn && profileMenu) {
    const setMenu = (open) => { profileMenu.classList.toggle('open', open); profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
    profileBtn.addEventListener('click', (ev) => { ev.stopPropagation(); setMenu(!profileMenu.classList.contains('open')); });
    document.addEventListener('click', (ev) => { if (!profileMenu.contains(ev.target) && !profileBtn.contains(ev.target)) setMenu(false); });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') setMenu(false); });
  }
})();
