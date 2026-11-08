/* BillGuard Phase 2 - browser feedback only. PHP is the source of truth. */
(() => {
  'use strict';
  const form = document.querySelector('#register-form');
  if (!form) return;

  const minUser = Number(form.dataset.usernameMin || 6);
  const maxUser = Number(form.dataset.usernameMax || 20);
  const minPass = Number(form.dataset.passwordMin || 8);
  const maxName = Number(form.dataset.nameMax || 29);
  const el = (name) => form.elements.namedItem(name);
  const input = (name) => String(el(name)?.value || '').trim();
  const errorNode = (name) => document.querySelector('#error-' + name);
  const error = (name, message = '') => {
    const node = errorNode(name);
    if (node) {
      node.textContent = message;
      node.setAttribute('role', message ? 'alert' : 'status');
    }
    const field = el(name);
    if (field?.setAttribute) field.setAttribute('aria-invalid', message ? 'true' : 'false');
  };
  const today = () => {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  };
  const validDate = (v) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
    const [y, m, d] = v.split('-').map(Number);
    const parsed = new Date(y, m - 1, d);
    return parsed.getFullYear() === y && parsed.getMonth() === m - 1 && parsed.getDate() === d && v <= today();
  };

  const passwordStrength = (text) => {
    const n = [/[a-z]/, /[A-Z]/, /\d/, /[^a-zA-Z0-9]/].filter((r) => r.test(text)).length;
    if (text.length < minPass || text.length > 64 || n < 3) return 'Weak';
    return n === 4 && text.length >= Math.max(12, minPass + 4) ? 'Strong' : 'Medium';
  };
  const passField = el('password');
  const passBar = document.querySelector('.strength-meter');
  const passText = document.querySelector('#password-strength-text');
  const updateStrength = () => {
    if (!passBar || !passText) return;
    const password = String(passField?.value || '');
    const strength = passwordStrength(password);
    passBar.dataset.level = password.length ? ({ Weak: '1', Medium: '2', Strong: '3' }[strength]) : '0';
    passText.textContent = password ? `Strength: ${strength}` : 'Strength: enter a password.';
  };
  passField?.addEventListener('input', updateStrength);
  document.querySelector('#password-show')?.addEventListener('change', (event) => {
    passField.type = event.target.checked ? 'text' : 'password';
    const confirm = el('password_confirm');
    confirm.type = passField.type;
  });
  updateStrength();

  const birth = el('birthdate');
  const age = document.querySelector('#age');
  const updateAge = () => {
    const value = input('birthdate');
    if (!age) return;
    if (!validDate(value)) { age.value = ''; return; }
    const dob = new Date(value + 'T00:00:00');
    const now = new Date();
    let years = now.getFullYear() - dob.getFullYear();
    if (now.getMonth() < dob.getMonth() ||
      (now.getMonth() === dob.getMonth() && now.getDate() < dob.getDate())) years -= 1;
    age.value = Math.max(0, years);
  };
  birth?.addEventListener('change', updateAge);
  updateAge();

  const ext = el('name_extension');
  const extOther = document.querySelector('#extension-other-wrap');
  const updateExtension = () => {
    if (!extOther) return;
    const visible = ext.value === 'Other';
    extOther.classList.toggle('is-hidden', !visible);
    el('extension_other').required = visible;
    if (!visible) error('extension_other');
  };
  ext?.addEventListener('change', updateExtension);
  updateExtension();

  const nameRule = (name, label) => {
    const value = input(name);
    if (name === 'middle_name' && !value) return '';
    if (value.length < 2 || value.length > maxName) return `${label} must contain between 2 and ${maxName} characters.`;
    if (/\d/.test(value)) return `Numbers are not allowed in ${label}.`;
    if (/[^A-Za-z ]/.test(value)) return `Special characters are not allowed in ${label}.`;
    if (/  /.test(value)) return `Double space is not allowed in ${label}.`;
    if (/(.)\1\1/i.test(value)) return `Three consecutive identical letters are not allowed in ${label}.`;
    if (value.toUpperCase() === value) return `Do not use all capital letters in ${label}.`;
    if (!/^[A-Z]/.test(value)) return `First letter of your ${label} must be capital.`;
    if (!/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/.test(value)) return `${label} must use Title Case (for example: Juan Carlo).`;
    return '';
  };
  const addressRule = (name, label) => {
    const value = input(name);
    if (name === 'street') {
      if (value.length < 3 || value.length > 50) return 'Purok / Street must contain between 3 and 50 characters.';
      if (!/^[A-Za-z0-9 ]+$/.test(value)) return 'Purok / Street may contain letters, digits and spaces only.';
      if (value.includes('  ')) return 'Purok / Street must not contain double spaces.';
      return '';
    }
    if (value.length < 2 || value.length > 50) return `${label} must contain between 2 and 50 characters.`;
    if (value.includes('  ')) return `${label} must not contain double spaces.`;
    if (!/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/.test(value)) return `${label} must contain letters and spaces in Title Case.`;
    return '';
  };
  const validate = (name) => {
    const v = input(name);
    if (name === 'id_number') return /^\d{4}-\d{4}$/.test(v) ? '' : 'ID Number must follow the format xxxx-xxxx.';
    if (name === 'first_name') return nameRule(name, 'First Name');
    if (name === 'middle_name') return nameRule(name, 'Middle Name');
    if (name === 'family_name') return nameRule(name, 'Family Name');
    if (name === 'name_extension') return ['', 'Jr.', 'Sr.', 'Other'].includes(v) ? '' : 'Please select a valid Name Extension.';
    if (name === 'extension_other') return input('name_extension') !== 'Other' || ['I', 'II', 'III', 'IV', 'IX', 'X'].includes(v)
      ? '' : 'Other Name Extension must be I, II, III, IV, IX or X.';
    if (name === 'birthdate') {
      if (!validDate(v)) return 'Please enter a valid Birthdate that is not in the future.';
      return Number(age?.value || 0) >= 18 ? '' : 'You must be at least 18 years old to register.';
    }
    const addr = { street: 'Purok / Street', barangay: 'Barangay', city: 'Municipality / City', province: 'Province', country: 'Country' };
    if (Object.hasOwn(addr, name)) return addressRule(name, addr[name]);
    if (name === 'zip_code') return /^\d{4}$/.test(v) ? '' : 'ZIP Code must contain exactly 4 digits.';
    if (name === 'email') return v.length <= 100 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)
      ? '' : 'Please enter a valid email address (maximum 100 characters).';
    if (name === 'username') return v.length >= minUser && v.length <= maxUser && /^[A-Za-z][A-Za-z0-9]*$/.test(v)
      ? '' : `Username must start with a letter and contain ${minUser}–${maxUser} letters or digits.`;
    if (name === 'password') {
      const raw = String(passField?.value || '');
      if (raw.length < minPass || raw.length > 64) return `Password must be between ${minPass} and 64 characters.`;
      return passwordStrength(raw) === 'Weak'
        ? 'Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols.' : '';
    }
    if (name === 'password_confirm') return String(el('password_confirm').value) === String(passField.value)
      ? '' : 'Passwords do not match.';
    if (name === 'utility_account_number') return /^\d{8,12}$/.test(v) ? '' : 'Utility Account Number must contain 8 to 12 digits.';
    if (name === 'primary_utility_type') return ['Electricity', 'Water', 'Internet'].includes(v) ? '' : 'Please select a valid utility type.';
    if (name === 'service_start_date') return validDate(v) ? '' : 'Service Start Date must be a valid date that is not in the future.';
    if (name === 'monthly_utility_budget') return /^\d{1,6}(?:\.\d{1,2})?$/.test(v) && Number(v) > 0 && Number(v) <= 999999.99
      ? '' : 'Monthly Utility Budget must be greater than ₱0.00 and no more than ₱999,999.99.';
    if (/^answer_[123]$/.test(name)) return v.length >= 2 && v.length <= 50 && /^[A-Za-z]+(?: [A-Za-z]+)*$/.test(v)
      ? '' : `Security Answer ${name.at(-1)} must contain 2–50 letters and spaces without double spaces.`;
    if (name === 'captcha') return v.length === 6 ? '' : 'Enter the six characters shown in the CAPTCHA.';
    if (name === 'privacy_consent') return el('privacy_consent').checked
      ? '' : 'You must agree to the Privacy Notice to register.';
    return '';
  };
  const fields = [
    'id_number', 'birthdate', 'first_name', 'middle_name', 'family_name',
    'name_extension', 'extension_other', 'street', 'barangay', 'city', 'province',
    'country', 'zip_code', 'utility_account_number', 'primary_utility_type',
    'service_start_date', 'monthly_utility_budget', 'email', 'username',
    'password', 'password_confirm', 'answer_1', 'answer_2', 'answer_3',
    'captcha', 'privacy_consent',
  ];
  for (const name of fields) {
    const field = el(name);
    field?.addEventListener('blur', () => error(name, validate(name)));
    field?.addEventListener('input', () => {
      if (errorNode(name)?.textContent) error(name, validate(name));
    });
  }
  const captchaImage = document.querySelector('#captcha-image');
  document.querySelector('#captcha-refresh')?.addEventListener('click', () => {
    captchaImage.src = 'captcha.php?refresh=' + Date.now();
    el('captcha').value = '';
    error('captcha');
  });

  const userInput = el('username');
  const availability = document.querySelector('#username-availability');
  let lookupTimer;
  let lookupId = 0;
  userInput?.addEventListener('input', () => {
    const id = ++lookupId;
    clearTimeout(lookupTimer);
    const v = input('username');
    availability.textContent = validate('username') || 'Checking availability…';
    if (validate('username')) return;
    lookupTimer = setTimeout(async () => {
      try {
        const response = await fetch('check-username.php?username=' + encodeURIComponent(v), {
          headers: { Accept: 'application/json' }, cache: 'no-store'
        });
        const data = await response.json();
        if (id !== lookupId) return;
        availability.textContent = data.message;
        availability.dataset.status = data.available ? 'available' : 'unavailable';
      } catch {
        if (id === lookupId) availability.textContent = 'Availability check unavailable; the server checks on submission.';
      }
    }, 400);
  });

  form.addEventListener('submit', (event) => {
    updateAge();
    let firstInvalid = null;
    for (const name of fields) {
      const message = validate(name);
      error(name, message);
      if (message && !firstInvalid) firstInvalid = el(name);
    }
    if (firstInvalid) {
      event.preventDefault();
      firstInvalid.focus();
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
