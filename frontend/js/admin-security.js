/* Phase 6 read-only monitoring: safe DOM updates, admin-only feed, 60s refresh. */
(() => {
  'use strict';
  const chart = document.getElementById('bg-monitor-chart');
  const events = document.getElementById('bg-monitor-events');
  if (!chart || !events) return;

  const byId = (id) => document.getElementById(id);
  const cell = (value) => {
    const td = document.createElement('td');
    // No innerHTML: content may come from an untrusted username or IP field.
    td.textContent = value == null || value === '' ? '—' : String(value);
    return td;
  };
  const numeric = (value) => {
    const n = Number(value);
    return Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0;
  };
  const replaceTable = (id, records, columns, empty) => {
    const target = byId(id);
    if (!target) return;
    const fragment = document.createDocumentFragment();
    if (!Array.isArray(records) || records.length === 0) {
      const row = document.createElement('tr');
      const item = cell(empty);
      item.colSpan = columns.length;
      row.appendChild(item);
      fragment.appendChild(row);
    } else {
      for (const record of records) {
        const row = document.createElement('tr');
        for (const column of columns) row.appendChild(cell(record[column]));
        fragment.appendChild(row);
      }
    }
    target.replaceChildren(fragment);
  };
  const replaceChart = (hourly) => {
    if (!Array.isArray(hourly) || hourly.length !== 24) return;
    const max = Math.max(1, ...hourly.map((x) => numeric(x.failures)));
    const fragment = document.createDocumentFragment();
    for (const point of hourly) {
      const group = document.createElement('div');
      group.className = 'bg-monitor__bar-group';
      const amount = document.createElement('span');
      amount.className = 'bg-monitor__bar-value';
      amount.textContent = String(numeric(point.failures));
      const bar = document.createElement('span');
      bar.className = 'bg-monitor__bar';
      bar.style.height = (numeric(point.failures) > 0
        ? Math.max(4, Math.round(100 * numeric(point.failures) / max)) : 2) + '%';
      bar.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.className = 'bg-monitor__bar-label';
      label.textContent = String(point.hour ?? '');
      group.append(amount, bar, label);
      fragment.appendChild(group);
    }
    chart.replaceChildren(fragment);
  };
  let requesting = false;
  async function refresh() {
    if (requesting || document.hidden) return;
    requesting = true;
    const status = byId('bg-monitor-status');
    try {
      const response = await fetch('admin-security-data.php' + window.location.search, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      if (response.status === 401 || response.status === 403) {
        if (status) {
          status.textContent = 'Admin access or session expired. Sign in again to view security activity.';
          status.setAttribute('role', 'alert');
        }
        window.clearInterval(refreshHandle);
        return;
      }
      if (!response.ok) throw new Error('Security feed unavailable.');
      const data = await response.json();
      if (data.ok !== true || !data.summary) throw new Error('Unexpected response.');
      const cards = {
        'bg-monitor-failed': data.summary.failed_logins,
        'bg-monitor-success': data.summary.successful_logins,
        'bg-monitor-otp': data.summary.otp_failures,
        'bg-monitor-flags': data.summary.flags,
      };
      for (const [id, amount] of Object.entries(cards)) {
        const element = byId(id);
        if (element) element.textContent = String(numeric(amount));
      }
      replaceChart(data.hourly);
      replaceTable('bg-monitor-ips', data.top_ips, ['ip_address', 'failures'],
        'No failed logins in this period.');
      replaceTable('bg-monitor-flag-table', data.flags,
        ['pattern', 'target', 'severity', 'created_at'],
        'No automated flags yet. Run the CLI security monitor.');
      replaceTable('bg-monitor-events', data.events,
        ['event_type', 'username_typed', 'ip_address', 'created_at'],
        'No activity matches these filters.');
      const updated = byId('bg-monitor-updated');
      if (updated) updated.textContent = String(data.updated_at ?? '');
      if (status) status.textContent = 'Security overview refreshed. Activity is read-only.';
    } catch {
      if (status) status.textContent = 'Live refresh unavailable. The last loaded results remain visible.';
    } finally {
      requesting = false;
    }
  }
  const refreshHandle = window.setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refresh();
  });
})();
