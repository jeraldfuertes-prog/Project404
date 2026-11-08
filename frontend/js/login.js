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
})();
