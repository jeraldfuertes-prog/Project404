(() => {
  'use strict';
  const button = document.querySelector('#otp-resend');
  const timer = document.querySelector('#resend-timer');
  const otp = document.querySelector('#otp');
  if (otp) otp.addEventListener('input', () => { otp.value = otp.value.replace(/[^0-9]/g, '').slice(0, 6); });
  if (!button || !timer) return;

  const initialWait = Math.max(0, Number(button.dataset.wait || 0));
  if (!initialWait) return;
  const target = Date.now() + initialWait * 1000;
  const update = () => {
    const seconds = Math.max(0, Math.ceil((target - Date.now()) / 1000));
    button.disabled = seconds > 0;
    timer.textContent = seconds > 0
      ? `Resend available in ${seconds} seconds.` : 'You may resend now.';
    if (seconds === 0) window.clearInterval(interval);
  };
  const interval = window.setInterval(update, 250);
  update();
})();
