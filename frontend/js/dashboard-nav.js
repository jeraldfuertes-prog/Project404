/* BillGuard authenticated dashboard: desktop collapsible / mobile sliding sidebar. */
(() => {
  'use strict';
  const shell = document.getElementById('app-shell');
  const sidebar = document.getElementById('app-sidebar');
  if (!shell || !sidebar) return;
  const toggles = [...shell.querySelectorAll('[data-sidebar-toggle]')];
  const scrim = shell.querySelector('[data-close-sidebar]');
  const mobile = window.matchMedia('(max-width: 860px)');
  let lastFocus = null;
  let collapsed = false;
  try { collapsed = localStorage.getItem('billguard_sidebar_collapsed') === 'yes'; } catch { /* storage optional */ }

  function sync() {
    const isMobile = mobile.matches;
    shell.classList.toggle('is-collapsed', collapsed && !isMobile);
    if (!isMobile) shell.classList.remove('is-mobile-open');
    const expanded = isMobile ? shell.classList.contains('is-mobile-open') : !collapsed;
    for (const button of toggles) {
      button.setAttribute('aria-expanded', String(expanded));
      const inside = sidebar.contains(button);
      button.setAttribute('aria-label', isMobile
        ? (expanded ? 'Close navigation menu' : 'Open navigation menu')
        : (collapsed ? 'Expand sidebar' : 'Collapse sidebar'));
      if (inside) button.title = isMobile ? 'Close menu' : (collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
    if (scrim) scrim.tabIndex = isMobile && expanded ? 0 : -1;
  }

  function closeMobile() {
    if (!shell.classList.contains('is-mobile-open')) return;
    shell.classList.remove('is-mobile-open');
    sync();
    lastFocus?.focus();
  }

  function toggle(event) {
    event.preventDefault();
    if (mobile.matches) {
      lastFocus = document.activeElement;
      if (shell.classList.contains('is-mobile-open')) {
        closeMobile();
      } else {
        shell.classList.add('is-mobile-open');
        sync();
        sidebar.querySelector('.app-sidebar__link')?.focus();
      }
    } else {
      collapsed = !collapsed;
      try { localStorage.setItem('billguard_sidebar_collapsed', collapsed ? 'yes' : 'no'); } catch { /* storage optional */ }
      sync();
    }
  }

  for (const button of toggles) button.addEventListener('click', toggle);
  scrim?.addEventListener('click', closeMobile);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMobile();
  });
  if (mobile.addEventListener) mobile.addEventListener('change', sync);
  else mobile.addListener(sync);
  sync();

  // Conditional "Other" input on the create/edit bill form.
  const type = document.querySelector('#utility_type');
  const other = document.querySelector('#other-utility-field');
  const custom = document.querySelector('#custom_utility_name');
  if (type && other && custom) {
    const setOther = () => {
      const enabled = type.value === 'Other';
      other.hidden = !enabled;
      custom.required = enabled;
      if (!enabled) custom.value = '';
    };
    type.addEventListener('change', setOther);
    setOther();
  }

  // Show an explicit confirmation before deleting a bill.
  for (const form of document.querySelectorAll('form[data-confirm-delete]')) {
    form.addEventListener('submit', (event) => {
      if (!window.confirm('Delete this unpaid bill permanently? This cannot be undone.')) {
        event.preventDefault();
      }
    });
  }
})();
