(() => {
  'use strict';
  const toggle = document.querySelector('#show-reset-passwords');
  if (!toggle) return;
  const fields = [
    document.querySelector('#new_password'),
    document.querySelector('#confirm_password')
  ];
  toggle.addEventListener('change', () => {
    for (const input of fields) {
      if (input) input.type = toggle.checked ? 'text' : 'password';
    }
  });
})();
