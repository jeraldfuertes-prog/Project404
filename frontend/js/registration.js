/* BillGuard simplified registration interface.
 * Browser validation is additional; the PHP server validates every input again.
 */
(() => {
  'use strict';
  const form = document.querySelector('#register-form');
  if (!form) return;
  const field = (name) => form.elements.namedItem(name);
  const value = (name) => String(field(name)?.value ?? '').trim();
  const minUser = Number(form.dataset.usernameMin || 6);
  const maxUser = Number(form.dataset.usernameMax || 20);
  const minPass = Number(form.dataset.passwordMin || 8);
  const maxName = Number(form.dataset.nameMax || 29);
  const errorBox = (name) => document.querySelector('#error-' + name);

  function setError(name, message = '') {
    const node = errorBox(name);
    if (node) {
      node.textContent = message;
      node.setAttribute('role', message ? 'alert' : 'status');
    }
    const input = field(name);
    if (input?.setAttribute) input.setAttribute('aria-invalid', message ? 'true' : 'false');
  }
  function strength(text) {
    const classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/]
      .filter((test) => test.test(text)).length;
    if (text.length < minPass || text.length > 64 || classes < 3) return 'Weak';
    return classes === 4 && text.length >= Math.max(12, minPass + 4)
      ? 'Strong' : 'Medium';
  }
  const pass = field('password');
  const confirm = field('password_confirm');
  const meter = document.querySelector('.strength-meter');
  const hint = document.querySelector('#password-strength-text');
  function updateStrength() {
    const content = String(pass?.value || '');
    const level = content.length ? strength(content) : '';
    if (meter) meter.dataset.level = level ? ({ Weak: '1', Medium: '2', Strong: '3' })[level] : '0';
    if (hint) hint.textContent = level ? 'Strength: ' + level : 'Strength: enter a password.';
  }
  pass?.addEventListener('input', updateStrength);
  document.querySelector('#password-show')?.addEventListener('change', (event) => {
    const type = event.target.checked ? 'text' : 'password';
    if (pass) pass.type = type;
    if (confirm) confirm.type = type;
  });
  updateStrength();

  const containsControl = (text) => /\p{Cc}|\p{Cf}|\p{Cs}/u.test(text);
  const nameRule = (name, label) => {
    const text = value(name);
    if (text.length < 2 || Array.from(text).length > maxName) {
      return label + ' must contain between 2 and ' + maxName + ' characters.';
    }
    if (containsControl(text)) return label + ' contains unsupported control characters.';
    return '';
  };
  function validate(name) {
    const text = value(name);
    if (name === 'first_name') return nameRule(name, 'First Name');
    if (name === 'family_name') return nameRule(name, 'Family Name');
    if (name === 'home_address') {
      if (containsControl(text)) return 'Home Address contains unsupported control characters.';
      return Array.from(text).length >= 5 && Array.from(text).length <= 180
        ? '' : 'Home Address must contain between 5 and 180 characters.';
    }
    if (name === 'email') {
      return text.length <= 100 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)
        ? '' : 'Please enter a valid email address (maximum 100 characters).';
    }
    if (name === 'username') {
      return text.length >= minUser && text.length <= maxUser && /^[A-Za-z][A-Za-z0-9]*$/.test(text)
        ? '' : 'Username must start with a letter and contain ' + minUser + '–' + maxUser + ' letters or digits.';
    }
    if (name === 'password') {
      const raw = String(pass?.value || '');
      if (raw.length < minPass || raw.length > 64) return 'Password must be between ' + minPass + ' and 64 characters.';
      return strength(raw) === 'Weak'
        ? 'Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols.'
        : '';
    }
    if (name === 'password_confirm') {
      return String(pass?.value || '') === String(confirm?.value || '') ? '' : 'Passwords do not match.';
    }
    if (/^answer_[123]$/.test(name)) {
      return text.length >= 2 && text.length <= 50 && /^[A-Za-z]+(?: [A-Za-z]+)*$/.test(text)
        ? '' : 'Security Answer ' + name.slice(-1) + ' must contain 2–50 letters and spaces without double spaces.';
    }
    if (name === 'privacy_consent') {
      return field(name)?.checked ? '' : 'You must agree to the Privacy Notice to register.';
    }
    return '';
  }

  const fieldNames = [
    'first_name', 'family_name', 'home_address', 'email', 'username',
    'password', 'password_confirm', 'answer_1', 'answer_2', 'answer_3', 'privacy_consent',
  ];
  for (const name of fieldNames) {
    field(name)?.addEventListener('blur', () => setError(name, validate(name)));
    field(name)?.addEventListener('input', () => {
      if (errorBox(name)?.textContent) setError(name, validate(name));
    });
  }
  const usernameBox = field('username');
  const availability = document.querySelector('#username-availability');
  let lastRequest = 0;
  let pending = null;
  usernameBox?.addEventListener('input', () => {
    const id = ++lastRequest;
    clearTimeout(pending);
    const name = value('username');
    const issue = validate('username');
    if (availability) {
      availability.textContent = issue || 'Checking availability…';
      availability.dataset.status = '';
    }
    if (issue) return;
    pending = setTimeout(async () => {
      try {
        const response = await fetch('check-username.php?username=' + encodeURIComponent(name), {
          headers: { Accept: 'application/json' }, cache: 'no-store',
        });
        const result = await response.json();
        if (id !== lastRequest || !availability) return;
        availability.textContent = result.message;
        availability.dataset.status = result.available ? 'available' : 'unavailable';
      } catch {
        if (id === lastRequest && availability) {
          availability.textContent = 'Availability will be checked when you submit.';
        }
      }
    }, 450);
  });

  const recovery = form.querySelector('.recovery-details');
  if (recovery?.querySelector('.form-error:not(:empty)')) recovery.open = true;
  form.addEventListener('submit', (event) => {
    let invalid = null;
    for (const name of fieldNames) {
      const issue = validate(name);
      setError(name, issue);
      if (issue && !invalid) invalid = field(name);
    }
    if (invalid) {
      event.preventDefault();
      if (recovery?.contains(invalid)) recovery.open = true;
      invalid.focus();
      invalid.scrollIntoView({ behavior: 'auto', block: 'center' });
    }
  });
})();
