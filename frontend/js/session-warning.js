/* BillGuard Phase 4.5 — No background renewal; only deliberate "Stay Signed In". */
(() => {
  'use strict';
  const timer = document.getElementById('bg-session-timer');
  const dialog = document.getElementById('bg-session-warning');
  const remaining = document.getElementById('bg-session-remaining');
  const error = document.getElementById('bg-session-error');
  const renewal = document.getElementById('bg-session-renew-form');
  const stay = document.getElementById('bg-stay-signed-in');
  const card = dialog?.querySelector('.session-warning-card');
  if (!timer || !dialog || !remaining || !renewal || !stay || !card) return;

  let checking = false;
  let submitting = false;
  let shown = false;
  let focusBeforeWarning = null;
  let deadline = 0;
  let hadClock = false;

  function setDeadline(data) {
    const serverTime = Number(data.server_time);
    const expiry = Number(data.expires_at);
    if (!Number.isFinite(serverTime) || !Number.isFinite(expiry) ||
        serverTime <= 0 || expiry <= serverTime) {
      return false;
    }
    // The server is authoritative; monotonic performance.now() avoids the
    // user's computer-clock offset from affecting idle expiry.
    deadline = performance.now() + (expiry - serverTime) * 1000;
    hadClock = true;
    return true;
  }

  function signedOut() {
    // An expired session must never keep private dashboard content visible.
    window.location.replace('login.php?session=expired');
  }

  function hideWarning() {
    if (!shown) return;
    shown = false;
    dialog.hidden = true;
    document.body.classList.remove('session-warning-open');
    error.hidden = true;
    error.textContent = '';
    focusBeforeWarning?.focus?.();
  }

  function showWarning() {
    if (shown) return;
    shown = true;
    focusBeforeWarning = document.activeElement;
    dialog.hidden = false;
    document.body.classList.add('session-warning-open');
    stay.focus();
  }

  function formatRemaining(ms) {
    const seconds = Math.max(0, Math.ceil(ms / 1000));
    return String(Math.floor(seconds / 60)).padStart(2, '0') +
      ':' + String(seconds % 60).padStart(2, '0');
  }

  async function statusCheck() {
    if (checking || submitting) return;
    checking = true;
    try {
      const response = await fetch('session-status.php', {
        method: 'GET', cache: 'no-store', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (response.status === 401 || response.status === 403) {
        signedOut();
        return;
      }
      if (!response.ok) {
        signedOut();
        return;
      }
      const data = await response.json();
      if (!data.ok || !setDeadline(data)) {
        signedOut();
        return;
      }
      tick();
    } catch {
      // If session state can't be verified, require a fresh authenticated page.
      signedOut();
    } finally {
      checking = false;
    }
  }

  function tick() {
    if (!hadClock || submitting || checking) return;
    const ms = deadline - performance.now();
    if (ms <= 0) {
      showWarning();
      remaining.textContent = '00:00';
      // Status check is read-only and NEVER renews the PHP session.
      void statusCheck();
    } else if (ms <= 60000) {
      showWarning();
      remaining.textContent = formatRemaining(ms);
    } else {
      hideWarning();
    }
  }

  function trapFocus(event) {
    if (!shown || event.key !== 'Tab') return;
    const focusable = [...dialog.querySelectorAll('button:not(:disabled), input:not([type="hidden"])')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault(); first.focus();
    }
  }

  renewal.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    submitting = true;
    stay.disabled = true;
    error.hidden = true;
    error.textContent = '';
    try {
      const response = await fetch(renewal.action, {
        method: 'POST', cache: 'no-store', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: new FormData(renewal)
      });
      if (response.status === 401 || response.status === 403) {
        signedOut();
        return;
      }
      if (!response.ok) throw new Error('Renewal failed');
      const data = await response.json();
      if (!data.ok || !setDeadline(data)) throw new Error('Renewal failed');
      hideWarning();
    } catch {
      // Never claim that a session was extended if the server did not confirm.
      error.textContent = 'Session renewal was not confirmed. Please sign in again.';
      error.hidden = false;
      signedOut();
    } finally {
      stay.disabled = false;
      submitting = false;
    }
  });

  document.addEventListener('keydown', trapFocus);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) void statusCheck();
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) void statusCheck();
  });

  if (!setDeadline({
    server_time: timer.dataset.serverTime,
    expires_at: timer.dataset.expiresAt
  })) {
    signedOut();
    return;
  }
  tick();
  setInterval(tick, 500);
})();
