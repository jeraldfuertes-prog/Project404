/* Node.js VM regression tests: no real browser, session cookie, or network. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(
  path.join(__dirname, '../../frontend/js/session-warning.js'), 'utf8'
);

function harness(idleSeconds = 90, warningSeconds = 60) {
  let now = 0;
  let posts = 0;
  let tick = null;
  let serverDeadline = idleSeconds * 1000;
  let redirect = '';
  const handlers = new Map();
  const elements = new Map();

  const classes = {
    add() {},
    remove() {}
  };
  const dialog = {
    hidden: true,
    querySelector() { return { focus() {} }; },
    querySelectorAll() { return []; }
  };
  const renewal = {
    action: 'session-renew.php',
    addEventListener(name, callback) { handlers.set('renewal:' + name, callback); }
  };
  const stay = { disabled: false, focus() {} };
  const error = { hidden: true, textContent: '' };
  const remaining = { textContent: '' };
  const timer = {
    dataset: {
      idleSeconds: String(idleSeconds),
      warningSeconds: String(warningSeconds),
      serverTime: '1000',
      expiresAt: String(1000 + idleSeconds)
    }
  };
  elements.set('bg-session-timer', timer);
  elements.set('bg-session-warning', dialog);
  elements.set('bg-session-remaining', remaining);
  elements.set('bg-session-error', error);
  elements.set('bg-session-renew-form', renewal);
  elements.set('bg-stay-signed-in', stay);

  const document = {
    hidden: false,
    activeElement: { focus() {} },
    body: { classList: classes },
    getElementById(id) { return elements.get(id); },
    addEventListener(name, callback) {
      if (!handlers.has(name)) handlers.set(name, []);
      handlers.get(name).push(callback);
    }
  };
  const window = {
    location: { replace(value) { redirect = value; } },
    addEventListener() {}
  };
  const fetch = async (url, options) => {
    if (now >= serverDeadline) return { status: 401, ok: false };
    if (url === 'session-renew.php') {
      assert.equal(options.method, 'POST');
      posts++;
      serverDeadline = now + idleSeconds * 1000;
    } else {
      assert.equal(url, 'session-status.php');
      assert.equal(options.method, 'GET');
    }
    return {
      status: 200,
      ok: true,
      async json() {
        return {
          ok: true,
          server_time: 1000 + Math.floor(now / 1000),
          expires_at: 1000 + Math.floor(serverDeadline / 1000)
        };
      }
    };
  };
  const context = {
    document, window, fetch,
    performance: { now: () => now },
    FormData: class {},
    setInterval(callback) { tick = callback; }
  };
  vm.runInNewContext(source, context, { filename: 'session-warning.js' });
  assert.equal(typeof tick, 'function', 'UI countdown was attached');
  return {
    get visible() { return !dialog.hidden; },
    get posts() { return posts; },
    get remaining() { return remaining.textContent; },
    get redirect() { return redirect; },
    advance(milliseconds) { now += milliseconds; tick(); },
    activity(type = 'pointermove') {
      for (const handler of handlers.get(type) || []) {
        handler({ isTrusted: true });
      }
    },
    stay() {
      handlers.get('renewal:submit')({ preventDefault() {} });
    }
  };
}
async function flush() {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

async function main() {
  const fifteen = harness(900, 60);
  assert.equal(fifteen.visible, false, '15-minute session starts without a warning');
  fifteen.advance(839000);
  assert.equal(fifteen.visible, false, 'Warning hidden before 14 minutes');
  fifteen.advance(1000);
  assert.equal(fifteen.visible, true, 'Warning appears after 14 minutes inactivity');
  assert.equal(fifteen.remaining, '01:00', '15-minute session has one-minute warning');
  assert.equal(fifteen.posts, 0, 'Passive countdown never renews session');
  fifteen.advance(60000);
  await flush();
  assert.equal(fifteen.redirect, 'login.php?session=expired',
    '15-minute inactivity expires session and redirects');

  const idle = harness();
  assert.equal(idle.visible, false, 'Warning hidden when authenticated');
  idle.advance(29000);
  assert.equal(idle.visible, false, 'No warning before 30 seconds inactivity');
  idle.advance(1000);
  assert.equal(idle.visible, true, 'Warning appears after 30 seconds inactivity');
  assert.equal(idle.remaining, '01:00', 'One-minute countdown starts at 01:00');
  assert.equal(idle.posts, 0, 'No automatic server renewal while idle');

  const active = harness();
  active.advance(20000);
  active.activity('pointermove');
  await flush();
  active.advance(20000);
  active.activity('keydown');
  await flush();
  active.advance(11000);
  assert.equal(active.visible, false, 'Genuine user activity prevents warning');
  assert.equal(active.posts, 2, 'Active session renewals use throttled POST');

  const short = harness(30, 15);
  assert.equal(short.visible, false, '30-second TOTAL timeout no longer warns immediately');
  short.advance(16000);
  assert.equal(short.visible, true, 'Short total timeout warns only after inactivity');

  const responding = harness();
  responding.advance(30000);
  assert.equal(responding.visible, true);
  responding.stay();
  await flush();
  assert.equal(responding.posts, 1, 'Stay Signed In performs an authenticated POST');
  assert.equal(responding.visible, false, 'Confirmed renewal hides warning');
  responding.advance(31000);
  assert.equal(responding.visible, true, 'Warning returns near renewed deadline');

  const expired = harness();
  expired.advance(90000);
  await flush();
  assert.equal(expired.posts, 0, 'Idle expiry performs no renewal request');
  assert.equal(expired.redirect, 'login.php?session=expired',
    'Expired session redirects to login');

  console.log('[OK] Session countdown, user activity, short demo, explicit renewal and expiry.');
}
main().catch(err => {
  console.error(err);
  process.exitCode = 1;
});
