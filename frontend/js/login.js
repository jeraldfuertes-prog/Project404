(() => {
  'use strict';
  const checkbox = document.querySelector('#show-login-password');
  const password = document.querySelector('#password');
  checkbox?.addEventListener('change', () => {
    if (password) password.type = checkbox.checked ? 'text' : 'password';
  });
  const image = document.querySelector('#captcha-image');
  const refresh = document.querySelector('#captcha-refresh');
  refresh?.addEventListener('click', () => {
    if (image) image.src = 'captcha.php?refresh=' + Date.now();
    const input = document.querySelector('#captcha');
    if (input) input.value = '';
  });

  // The PHP server owns the actual lockout; this is only the UI countdown.
  const countdown = document.querySelector('#login-lockout-countdown');
  const submit = document.querySelector('#login-submit');
  if (countdown && submit) {
    const seconds = Math.max(0, Number(countdown.dataset.wait) || 0);
    const end = Date.now() + seconds * 1000;
    const interval = window.setInterval(() => {
      const remaining = Math.max(0, Math.ceil((end - Date.now()) / 1000));
      if (remaining > 0) {
        countdown.textContent = 'Account login temporarily locked. Retry in ' + remaining + ' seconds.';
        return;
      }
      window.clearInterval(interval);
      submit.disabled = false;
      countdown.textContent = 'Lockout time elapsed. Complete a fresh CAPTCHA before signing in.';
      refresh?.click();
    }, 250);
  }
})();
