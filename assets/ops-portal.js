/*
 * Analytics & Operations portal
 *   Admin / MIS / Staff : Dashboard (score cards + graphs), Data Gathering,
 *                         Payment Records, System Logs, Reports (CSV/Excel/PDF)
 *   Admin / MIS only    : Modes of Payment, User Accounts, Security & OTP
 *   Cashier             : collection graphs added to the cashier dashboard
 */
(function () {
  'use strict';

  var API = 'backend/';
  var COLORS = {
    brand: '#173b82', blue: '#2563eb', teal: '#0d9488', green: '#16a34a', amber: '#d97706',
    red: '#dc2626', violet: '#6366f1', orange: '#ea580c', slate: '#94a3b8', grid: '#eef2f7', text: '#64748b'
  };
  var MODE_COLORS = { 'HMA': COLORS.blue, 'Walk-in': COLORS.teal, 'Other': COLORS.slate };
  var STATUS_COLORS = { 'Verified': COLORS.green, 'Pending': COLORS.amber, 'Rejected': COLORS.red, 'Awaiting HMA': COLORS.violet };
  var LEVEL_COLORS = { 'College': COLORS.blue, 'SHS': COLORS.orange };
  var ROLE_LABELS = { admin: 'Admin', mis: 'MIS', staff: 'Staff', cashier: 'Cashier', user: 'Student' };
  var YEAR_LEVELS = { College: ['1st Year', '2nd Year', '3rd Year', '4th Year'], SHS: ['Grade 11', 'Grade 12'] };

  var TABS = [
    { id: 'dashboard', label: 'Dashboard', title: 'Analytics Dashboard', roles: ['admin', 'mis', 'staff'],
      desc: 'Score cards and graphs of collections, payment modes, status, and College vs SHS students.' },
    { id: 'data', label: 'Data Gathering', title: 'Data Gathering', roles: ['admin', 'mis', 'staff'],
      desc: 'Collect each student’s assessment, payments, and balance in one dataset. Filter, then export.' },
    { id: 'records', label: 'Payment Records', title: 'Payment Records', roles: ['admin', 'mis', 'staff'],
      desc: 'Every payment transaction with its mode, status, cashier, and receipt.' },
    { id: 'logs', label: 'System Logs', title: 'System Logs', roles: ['admin', 'mis', 'staff'],
      desc: 'Security events (sign-ins, OTP, timeouts) and system activity, with IP address.' },
    { id: 'reports', label: 'Reports', title: 'Reports', roles: ['admin', 'mis', 'staff'],
      desc: 'Download reports as CSV, Excel, or PDF using the filters you choose.' },
    { id: 'modes', label: 'Modes of Payment', title: 'Modes of Payment', roles: ['admin', 'mis'],
      desc: 'Turn Hello Money App (HMA) and Walk-in payments on or off, and track each mode.' },
    { id: 'users', label: 'User Accounts', title: 'User Accounts', roles: ['admin', 'mis'],
      desc: 'Manage Admin, MIS, Staff, Cashier, and Student (College / SHS) accounts and their OTP email.' },
    { id: 'security', label: 'Security & OTP', title: 'Security & OTP', roles: ['admin', 'mis'],
      desc: 'Login OTP, session timeout, and the Gmail sender used for OTP emails.' }
  ];

  var state = {
    role: null, tab: 'dashboard', built: false, charts: {},
    dash: null, modes: null, users: null, usersFilter: { role: '', status: '', search: '' },
    pages: { data: 1, records: 1, logs: 1 }
  };

  // ------------------------------------------------------------------ helpers
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function money(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function shortMoney(n) {
    n = Number(n || 0);
    if (Math.abs(n) >= 1e6) return '₱' + (n / 1e6).toFixed(1) + 'M';
    if (Math.abs(n) >= 1e3) return '₱' + (n / 1e3).toFixed(1) + 'K';
    return '₱' + n.toFixed(0);
  }
  function num(n) { return Number(n || 0).toLocaleString('en-PH'); }
  function pct(n) { return Number(n || 0).toFixed(1) + '%'; }
  function ymd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function qs(obj) {
    return Object.keys(obj).filter(function (k) { return obj[k] !== '' && obj[k] != null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]); }).join('&');
  }
  function allowed(tab) { return tab.roles.indexOf(state.role) !== -1; }

  async function api(path, options) {
    var r = await fetch(API + path, Object.assign({ credentials: 'include', cache: 'no-store' }, options || {}));
    var text = await r.text();
    var d;
    try { d = JSON.parse(text); } catch (e) { throw new Error('Server error (' + r.status + '). Check Apache/MySQL.'); }
    if (!r.ok || d.success === false) throw new Error(d.message || ('Request failed (' + r.status + ').'));
    return d;
  }
  function postJson(path, body) {
    return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  }

  var toastTimer = null;
  function toast(msg, isError) {
    var t = $('#opsToast');
    if (!t) { t = document.createElement('div'); t.id = 'opsToast'; t.className = 'ops-toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.toggle('error', !!isError);
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove('show'); }, 3200);
  }

  function statusPill(group, raw) {
    var cls = { 'Verified': 'green', 'Pending': 'amber', 'Rejected': 'red', 'Awaiting HMA': 'violet' }[group] || '';
    return '<span class="ops-pill ' + cls + '">' + esc(raw || group) + '</span>';
  }
  function modePill(mode) {
    var cls = { 'HMA': 'blue', 'Walk-in': 'teal' }[mode] || '';
    return '<span class="ops-pill ' + cls + '">' + esc(mode === 'HMA' ? 'HMA' : mode) + '</span>';
  }
  function levelPill(level) {
    return '<span class="ops-pill ' + (level === 'SHS' ? 'orange' : 'blue') + '">' + esc(level || 'College') + '</span>';
  }

  function selectOptions(list, current, allLabel) {
    var h = allLabel != null ? '<option value="">' + esc(allLabel) + '</option>' : '';
    list.forEach(function (o) {
      var v = typeof o === 'string' ? o : o.value; var l = typeof o === 'string' ? o : o.label;
      h += '<option value="' + esc(v) + '"' + (String(current) === String(v) ? ' selected' : '') + '>' + esc(l) + '</option>';
    });
    return h;
  }
  function yearOptionsFor(level, current, allLabel) {
    var years = level ? YEAR_LEVELS[level] || [] : YEAR_LEVELS.College.concat(YEAR_LEVELS.SHS);
    return selectOptions(years, current, allLabel);
  }

  function pager(el, pg, onPage) {
    if (!el) return;
    var from = pg.total ? (pg.page - 1) * pg.per_page + 1 : 0;
    var to = Math.min(pg.total, pg.page * pg.per_page);
    el.innerHTML = '<span>Showing ' + num(from) + '–' + num(to) + ' of ' + num(pg.total) + '</span>' +
      '<div class="ops-pager-btns"><button type="button" data-p="' + (pg.page - 1) + '"' + (pg.page <= 1 ? ' disabled' : '') + '>‹ Prev</button>' +
      '<span style="align-self:center">Page ' + pg.page + ' of ' + pg.pages + '</span>' +
      '<button type="button" data-p="' + (pg.page + 1) + '"' + (pg.page >= pg.pages ? ' disabled' : '') + '>Next ›</button></div>';
    $all('button[data-p]', el).forEach(function (b) { b.addEventListener('click', function () { onPage(Number(b.dataset.p)); }); });
  }

  function exportButtons(type) {
    return '<div class="ops-export" data-export-type="' + type + '">' +
      '<button type="button" data-format="csv"><b>CSV</b></button>' +
      '<button type="button" data-format="xlsx"><b>Excel</b></button>' +
      '<button type="button" data-format="pdf"><b>PDF</b></button></div>';
  }

  async function download(type, format, filters, btn) {
    var label = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Preparing…'; }
    try {
      var r = await fetch(API + 'reports.php?' + qs(Object.assign({ type: type, format: format }, filters || {})), { credentials: 'include', cache: 'no-store' });
      var ct = r.headers.get('Content-Type') || '';
      if (!r.ok || ct.indexOf('application/json') === 0) {
        var d = await r.json().catch(function () { return {}; });
        throw new Error(d.message || 'Report could not be generated.');
      }
      var blob = await r.blob();
      var cd = r.headers.get('Content-Disposition') || '';
      var m = /filename="([^"]+)"/.exec(cd);
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = m ? m[1] : (type + '.' + format);
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
      toast((format === 'xlsx' ? 'Excel' : format.toUpperCase()) + ' report downloaded.');
    } catch (err) {
      toast(err.message, true);
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
    }
  }

  function wireExports(root, type, getFilters) {
    $all('.ops-export[data-export-type="' + type + '"] button', root).forEach(function (b) {
      b.addEventListener('click', function () { download(type, b.dataset.format, getFilters(), b); });
    });
  }

  // ------------------------------------------------------------------ charts
  function destroyChart(id) {
    if (state.charts[id]) { try { state.charts[id].destroy(); } catch (e) {} delete state.charts[id]; }
  }
  function chartBox(id) { return document.getElementById(id); }

  function renderChart(id, config, hasData) {
    destroyChart(id);
    var box = chartBox(id);
    if (!box) return;
    if (!hasData) { box.innerHTML = '<div class="ops-chart-empty">No data for the selected filters yet.</div>'; return; }
    if (typeof window.Chart === 'undefined') { box.innerHTML = fallbackBars(config); return; }
    box.innerHTML = '<canvas aria-label="' + esc(config._label || 'Chart') + '" role="img"></canvas>';
    var base = {
      responsive: true, maintainAspectRatio: false, animation: { duration: 350 },
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, color: COLORS.text, font: { size: 11 } } },
        tooltip: { callbacks: {} }
      }
    };
    config.options = deepMerge(base, config.options || {});
    state.charts[id] = new window.Chart(box.querySelector('canvas'), config);
  }

  function deepMerge(a, b) {
    var out = Array.isArray(a) ? a.slice() : Object.assign({}, a);
    Object.keys(b).forEach(function (k) {
      if (b[k] && typeof b[k] === 'object' && !Array.isArray(b[k]) && a[k] && typeof a[k] === 'object') out[k] = deepMerge(a[k], b[k]);
      else out[k] = b[k];
    });
    return out;
  }

  function fallbackBars(config) {
    var labels = config.data.labels || [];
    var ds = config.data.datasets[0] || { data: [] };
    var max = Math.max.apply(null, ds.data.concat([1]));
    return '<div class="ops-fallback-bars">' + labels.map(function (l, i) {
      var v = ds.data[i] || 0;
      return '<div><span>' + esc(l) + '</span><i style="width:' + Math.max(2, (v / max) * 100) + '%"></i><b>' + esc(config._money ? money(v) : num(v)) + '</b></div>';
    }).join('') + '</div>';
  }

  function moneyAxis() {
    return { beginAtZero: true, grid: { color: COLORS.grid }, ticks: { color: COLORS.text, font: { size: 10 }, callback: function (v) { return shortMoney(v); } } };
  }
  function plainAxis(stacked) {
    return { stacked: !!stacked, grid: { display: false }, ticks: { color: COLORS.text, font: { size: 10 }, maxRotation: 0, autoSkip: true } };
  }
  function moneyTooltip() { return { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + money(c.parsed.y != null ? c.parsed.y : c.parsed); } } }; }
  function sum(arr) { return (arr || []).reduce(function (a, b) { return a + Number(b || 0); }, 0); }

  function doughnut(id, labels, values, colors, isMoney, label) {
    renderChart(id, {
      type: 'doughnut', _label: label, _money: isMoney,
      data: { labels: labels, datasets: [{ label: label, data: values, backgroundColor: colors, borderColor: '#fff', borderWidth: 2, hoverOffset: 4 }] },
      options: {
        cutout: '62%',
        plugins: { tooltip: { callbacks: { label: function (c) {
          var total = sum(c.dataset.data) || 1;
          return ' ' + c.label + ': ' + (isMoney ? money(c.parsed) : num(c.parsed)) + ' (' + ((c.parsed / total) * 100).toFixed(1) + '%)';
        } } } }
      }
    }, sum(values) > 0);
  }

  function monthlyChart(id, monthly) {
    var modes = ['HMA', 'Walk-in'];
    if (sum(monthly.Other) > 0) modes.push('Other');
    renderChart(id, {
      type: 'bar', _label: 'Monthly collections by mode of payment', _money: true,
      data: { labels: monthly.labels, datasets: modes.map(function (m) {
        return { label: m === 'HMA' ? 'HMA (Hello Money)' : m, data: monthly[m], backgroundColor: MODE_COLORS[m], borderRadius: 4, maxBarThickness: 34, stack: 'c' };
      }) },
      options: { scales: { x: plainAxis(true), y: Object.assign(moneyAxis(), { stacked: true }) }, plugins: { tooltip: moneyTooltip() } }
    }, modes.some(function (m) { return sum(monthly[m]) > 0; }));
  }

  // ------------------------------------------------------------------ shell
  function build() {
    var root = document.getElementById('opsPortal');
    if (!root || state.built) return;
    state.built = true;
    root.innerHTML =
      '<div class="ops-shell">' +
      '<header class="ops-head"><div><div class="eyebrow" id="opsEyebrow">PORTAL</div><h1 id="opsTitle">Dashboard</h1><p id="opsDesc"></p></div>' +
      '<div class="ops-head-actions"><span class="ops-updated"><i></i>Updated <b id="opsUpdated">—</b></span>' +
      '<button class="btn ghost" type="button" id="opsRefresh">↻ Refresh</button></div></header>' +
      '<nav class="ops-tabs" role="tablist" aria-label="Portal sections">' +
      TABS.map(function (t) { return '<button type="button" class="ops-tab" role="tab" data-tab="' + t.id + '">' + esc(t.label) + '</button>'; }).join('') +
      '</nav>' +
      TABS.map(function (t) { return '<section class="ops-panel" data-panel="' + t.id + '" role="tabpanel"></section>'; }).join('') +
      '</div>' + usersModalHtml();
    $all('.ops-tab', root).forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
    $('#opsRefresh', root).addEventListener('click', function () { load(state.tab, true); });
    buildDashboardPanel(); buildDataPanel(); buildRecordsPanel(); buildLogsPanel(); buildReportsPanel();
    buildModesPanel(); buildUsersPanel(); buildSecurityPanel();
  }

  function applyRoleVisibility() {
    TABS.forEach(function (t) {
      var ok = allowed(t);
      $all('.ops-tab[data-tab="' + t.id + '"]').forEach(function (el) { el.hidden = !ok; });
      $all('.ops-side-link[data-ops-tab="' + t.id + '"]').forEach(function (el) { el.hidden = !ok; });
    });
    var eb = $('#opsEyebrow');
    if (eb) eb.textContent = ({ admin: 'ADMIN • ANALYTICS & REPORTS', mis: 'MIS PORTAL', staff: 'STAFF PORTAL' })[state.role] || 'PORTAL';
    var staffNote = $('#opsStaffNote');
    if (staffNote) staffNote.hidden = state.role !== 'staff';
    $all('.ops-manage-label').forEach(function (el) { el.style.display = state.role === 'staff' ? 'none' : ''; });
  }

  function show(tabId) {
    var tab = TABS.filter(function (t) { return t.id === tabId; })[0];
    if (!tab || !allowed(tab)) tab = TABS[0];
    state.tab = tab.id;
    $all('.ops-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab.id); b.setAttribute('aria-selected', b.dataset.tab === tab.id ? 'true' : 'false'); });
    $all('.ops-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.panel === tab.id); });
    $all('.ops-side-link').forEach(function (a) { a.classList.toggle('active', a.dataset.opsTab === tab.id); });
    var t = $('#opsTitle'); if (t) t.textContent = tab.title;
    var d = $('#opsDesc'); if (d) d.textContent = tab.desc;
    load(tab.id, false);
  }

  function stamp() { var u = $('#opsUpdated'); if (u) u.textContent = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }); }

  function load(tabId, force) {
    var fn = { dashboard: loadDashboard, data: loadData, records: loadRecords, logs: loadLogs, reports: function () {}, modes: loadModes, users: loadUsers, security: loadSecurity }[tabId];
    if (fn) Promise.resolve(fn(force)).then(stamp).catch(function (err) { toast(err.message, true); });
  }

  // ------------------------------------------------------------------ dashboard
  function dashFiltersHtml(prefix) {
    return '<div class="ops-filters">' +
      '<label class="ops-field"><span>From</span><input class="input" type="date" id="' + prefix + 'From"></label>' +
      '<label class="ops-field"><span>To</span><input class="input" type="date" id="' + prefix + 'To"></label>' +
      '<label class="ops-field"><span>Student Level</span><select class="input" id="' + prefix + 'Level">' + selectOptions(['College', 'SHS'], '', 'All levels') + '</select></label>' +
      '<label class="ops-field"><span>Mode of Payment</span><select class="input" id="' + prefix + 'Mode">' + selectOptions([{ value: 'HMA', label: 'HMA (Hello Money)' }, { value: 'Walk-in', label: 'Walk-in' }], '', 'All modes') + '</select></label>' +
      '<button class="btn primary" type="button" id="' + prefix + 'Apply">Apply</button>' +
      '<button class="btn ghost" type="button" id="' + prefix + 'Reset">Reset</button>' +
      '<div class="ops-chips">' +
      ['7|Last 7 days', '30|Last 30 days', 'month|This month', 'year|This year', 'all|All time'].map(function (c) {
        var p = c.split('|'); return '<button type="button" class="ops-chip" data-range="' + p[0] + '">' + p[1] + '</button>';
      }).join('') + '</div></div>';
  }

  function readDashFilters() {
    return { date_from: ($('#dashFrom') || {}).value || '', date_to: ($('#dashTo') || {}).value || '', level: ($('#dashLevel') || {}).value || '', mode: ($('#dashMode') || {}).value || '' };
  }

  function buildDashboardPanel() {
    var p = $('.ops-panel[data-panel="dashboard"]');
    p.innerHTML = '<p class="ops-callout warn" id="opsStaffNote" hidden style="margin:0 0 14px">Staff access is <b>view and report only</b>. Account, payment-mode, and security changes are handled by MIS / Admin.</p>' +
      dashFiltersHtml('dash') +
      '<div class="ops-kpis" id="dashKpis"></div>' +
      '<div class="ops-chart-grid">' +
      chartCard('dashMonthly', 'Monthly Collections by Mode of Payment', 'Verified collections per month — HMA vs Walk-in', true) +
      chartCard('dashModes', 'Mode of Payment Split', 'Share of verified collections') +
      chartCard('dashDaily', 'Daily Transactions & Collections', 'Last 30 days of the selected range', true) +
      chartCard('dashStatus', 'Payment Status', 'All transactions in range') +
      chartCard('dashLevels', 'College vs SHS', 'Verified collections by student level') +
      chartCard('dashFees', 'Collections by Fee', 'Verified amount per fee item') +
      chartCard('dashPeriods', 'Collections by Period', 'Prelim / Midterm / Finals') +
      '</div>' +
      '<div class="ops-card"><div class="ops-card-head"><div><h3>Latest Gathered Transactions</h3><p>The 8 most recent records for the filters above.</p></div>' +
      '<button class="btn ghost" type="button" id="dashOpenRecords">Open Payment Records →</button></div>' +
      '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Date</th><th>Student</th><th>Level</th><th>Fee</th><th>Mode</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody id="dashRecent"></tbody></table></div></div>';
    $('#dashApply').addEventListener('click', function () { loadDashboard(true); });
    $('#dashReset').addEventListener('click', function () { ['dashFrom', 'dashTo', 'dashLevel', 'dashMode'].forEach(function (id) { $('#' + id).value = ''; }); setChip(p, 'all'); loadDashboard(true); });
    $all('.ops-chip', p).forEach(function (c) { c.addEventListener('click', function () { applyRange(c.dataset.range, 'dash'); setChip(p, c.dataset.range); loadDashboard(true); }); });
    $('#dashOpenRecords').addEventListener('click', function () {
      var f = readDashFilters();
      $('#recFrom').value = f.date_from; $('#recTo').value = f.date_to; $('#recLevel').value = f.level; $('#recMode').value = f.mode;
      state.pages.records = 1; show('records');
    });
  }

  function chartCard(id, title, sub, wide) {
    return '<div class="ops-chart-card' + (wide ? ' span-2' : '') + '"><h4>' + esc(title) + '</h4><small>' + esc(sub) + '</small><div class="ops-chart-box" id="' + id + '"></div></div>';
  }
  function setChip(root, range) { $all('.ops-chip', root).forEach(function (c) { c.classList.toggle('active', c.dataset.range === range); }); }
  function applyRange(range, prefix) {
    var to = new Date(), from = null;
    if (range === '7') { from = new Date(); from.setDate(from.getDate() - 6); }
    else if (range === '30') { from = new Date(); from.setDate(from.getDate() - 29); }
    else if (range === 'month') { from = new Date(to.getFullYear(), to.getMonth(), 1); }
    else if (range === 'year') { from = new Date(to.getFullYear(), 0, 1); }
    $('#' + prefix + 'From').value = from ? ymd(from) : '';
    $('#' + prefix + 'To').value = from ? ymd(to) : '';
  }

  function kpi(label, value, sub, color, barPct) {
    return '<div class="ops-kpi" style="--kpi:' + color + '"><div class="ops-kpi-label">' + esc(label) + '</div><div class="ops-kpi-value">' + value + '</div>' +
      (sub ? '<div class="ops-kpi-sub">' + sub + '</div>' : '') +
      (barPct != null ? '<div class="ops-kpi-bar"><span style="width:' + Math.max(0, Math.min(100, barPct)) + '%"></span></div>' : '') + '</div>';
  }

  async function loadDashboard() {
    var d = await api('analytics_data.php?' + qs(Object.assign({ action: 'dashboard' }, readDashFilters())));
    state.dash = d;
    var k = d.scorecards || {};
    var collected = Number(k.total_collected || 0);
    $('#dashKpis').innerHTML =
      kpi('Total Collected', money(collected), num(k.verified_count) + ' verified payment(s) • avg ' + money(k.average_payment), COLORS.green) +
      kpi('Today’s Collection', money(k.today_collected), 'Verified today', COLORS.blue) +
      kpi('Transactions', num(k.transactions), pct(k.verification_rate) + ' verified • ' + num(k.rejected_count) + ' rejected', COLORS.violet, k.verification_rate) +
      kpi('Pending Verification', num(k.pending_count), money(k.pending_amount) + ' waiting for cashier' + (k.awaiting_hma_count ? ' • ' + num(k.awaiting_hma_count) + ' awaiting HMA' : ''), COLORS.amber) +
      kpi('Outstanding Receivables', money(k.outstanding_receivables), pct(k.collection_rate) + ' of ' + money(k.total_assessment) + ' collected', COLORS.red, k.collection_rate) +
      kpi('Students', num(k.students_total), num(k.students_college) + ' College • ' + num(k.students_shs) + ' SHS • ' + num(k.fully_paid_students) + ' fully paid', COLORS.brand) +
      kpi('HMA Collections', money(k.hma_collected), 'Hello Money App (online)', COLORS.blue, collected ? (k.hma_collected / collected) * 100 : 0) +
      kpi('Walk-in Collections', money(k.walkin_collected), 'Paid at the cashier window', COLORS.teal, collected ? (k.walkin_collected / collected) * 100 : 0);

    var c = d.charts || {};
    monthlyChart('dashMonthly', c.monthly);
    doughnut('dashModes', c.modes.labels.map(function (l) { return l === 'HMA' ? 'HMA' : l; }), c.modes.amount, c.modes.labels.map(function (l) { return MODE_COLORS[l] || COLORS.slate; }), true, 'Collections by mode');
    renderChart('dashDaily', {
      type: 'line', _label: 'Daily transactions',
      data: { labels: c.daily.labels, datasets: [
        { type: 'bar', label: 'Collected', data: c.daily.collected, backgroundColor: 'rgba(37,99,235,.22)', borderRadius: 3, yAxisID: 'y', order: 2 },
        { type: 'line', label: 'Transactions', data: c.daily.transactions, borderColor: COLORS.brand, backgroundColor: COLORS.brand, tension: .3, pointRadius: 2, yAxisID: 'y1', order: 1 }
      ] },
      options: {
        scales: { x: plainAxis(), y: Object.assign(moneyAxis(), { position: 'left' }), y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0, color: COLORS.text, font: { size: 10 } } } },
        plugins: { tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + (ctx.dataset.yAxisID === 'y' ? money(ctx.parsed.y) : num(ctx.parsed.y)); } } } }
      }
    }, sum(c.daily.transactions) > 0);
    doughnut('dashStatus', c.status.labels, c.status.count, c.status.labels.map(function (l) { return STATUS_COLORS[l] || COLORS.slate; }), false, 'Transactions by status');
    renderChart('dashLevels', {
      type: 'bar', _label: 'College vs SHS', _money: true,
      data: { labels: c.levels.labels, datasets: [{ label: 'Collected', data: c.levels.amount, backgroundColor: c.levels.labels.map(function (l) { return LEVEL_COLORS[l] || COLORS.slate; }), borderRadius: 6, maxBarThickness: 60 }] },
      options: { plugins: { legend: { display: false }, tooltip: moneyTooltip() }, scales: { x: plainAxis(), y: moneyAxis() } }
    }, sum(c.levels.amount) > 0);
    renderChart('dashFees', {
      type: 'bar', _label: 'Collections by fee', _money: true,
      data: { labels: c.fees.labels, datasets: [{ label: 'Collected', data: c.fees.amount, backgroundColor: COLORS.blue, borderRadius: 5, maxBarThickness: 22 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (ctx) { return ' ' + money(ctx.parsed.x); } } } },
        scales: { x: Object.assign(moneyAxis(), {}), y: { grid: { display: false }, ticks: { color: COLORS.text, font: { size: 10 } } } } }
    }, sum(c.fees.amount) > 0);
    renderChart('dashPeriods', {
      type: 'bar', _label: 'Collections by period', _money: true,
      data: { labels: c.periods.labels, datasets: [{ label: 'Collected', data: c.periods.amount, backgroundColor: COLORS.violet, borderRadius: 6, maxBarThickness: 50 }] },
      options: { plugins: { legend: { display: false }, tooltip: moneyTooltip() }, scales: { x: plainAxis(), y: moneyAxis() } }
    }, sum(c.periods.amount) > 0);

    var rows = d.recent || [];
    $('#dashRecent').innerHTML = rows.length ? rows.map(function (r) {
      return '<tr><td>' + esc(r.payment_date) + '</td><td><b>' + esc(r.student_name) + '</b><small>' + esc(r.student_id || '') + '</small></td><td>' + levelPill(r.student_level) + '</td><td>' + esc(r.fee_name) + '<small>' + esc(r.period) + '</small></td><td>' + modePill(r.mode) + '</td><td class="num">' + money(r.amount) + '</td><td>' + statusPill(r.status_group, r.status) + '</td></tr>';
    }).join('') : '<tr><td colspan="7" class="ops-empty">No transactions for these filters.</td></tr>';
  }

  // ------------------------------------------------------------------ data gathering (students)
  function buildDataPanel() {
    var p = $('.ops-panel[data-panel="data"]');
    p.innerHTML = '<div class="ops-card"><div class="ops-card-head"><div><h3>Student Account Dataset</h3><p>One row per student: assessment, discount, verified payments, pending payments, and remaining balance.</p></div>' + exportButtons('students') + '</div>' +
      '<div class="ops-filters">' +
      '<label class="ops-field"><span>Student Level</span><select class="input" id="dataLevel">' + selectOptions(['College', 'SHS'], '', 'All levels') + '</select></label>' +
      '<label class="ops-field"><span>Year Level</span><select class="input" id="dataYear">' + yearOptionsFor('', '', 'All years') + '</select></label>' +
      '<label class="ops-field"><span>Account</span><select class="input" id="dataAccount">' + selectOptions(['Active', 'Inactive'], '', 'All accounts') + '</select></label>' +
      '<label class="ops-field"><span>Balance</span><select class="input" id="dataBalance">' + selectOptions([{ value: 'with_balance', label: 'With balance' }, { value: 'fully_paid', label: 'Fully paid' }], '', 'All') + '</select></label>' +
      '<label class="ops-field grow"><span>Search</span><input class="input" type="search" id="dataSearch" placeholder="Name, student ID, username, email"></label>' +
      '<button class="btn primary" type="button" id="dataApply">Gather Data</button></div>' +
      '<div class="ops-totals" id="dataTotals"></div>' +
      '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Student</th><th>Level / Year</th><th>Account</th><th class="num">Assessment</th><th class="num">Verified Paid</th><th class="num">Pending</th><th class="num">Balance</th><th>Progress</th><th>Last Payment</th></tr></thead><tbody id="dataBody"></tbody></table></div>' +
      '<div class="ops-pager" id="dataPager"></div></div>';
    $('#dataLevel').addEventListener('change', function () { $('#dataYear').innerHTML = yearOptionsFor(this.value, '', 'All years'); });
    $('#dataApply').addEventListener('click', function () { state.pages.data = 1; loadData(); });
    $('#dataSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') { state.pages.data = 1; loadData(); } });
    wireExports(p, 'students', readDataFilters);
  }
  function readDataFilters() {
    return { level: $('#dataLevel').value, year_level: $('#dataYear').value, account_status: $('#dataAccount').value, balance: $('#dataBalance').value, search: $('#dataSearch').value.trim() };
  }
  async function loadData() {
    var d = await api('analytics_data.php?' + qs(Object.assign({ action: 'students', page: state.pages.data, per_page: 15 }, readDataFilters())));
    var t = d.totals;
    $('#dataTotals').innerHTML = total('Students', num(t.students)) + total('College / SHS', num(t.College) + ' / ' + num(t.SHS)) + total('Assessment', money(t.assessment)) + total('Verified Paid', money(t.paid)) + total('Pending', money(t.pending)) + total('Outstanding', money(t.outstanding)) + total('Fully Paid', num(t.fully_paid));
    $('#dataBody').innerHTML = d.rows.length ? d.rows.map(function (r) {
      return '<tr><td><b>' + esc(r.full_name) + '</b><small>' + esc(r.student_id || 'No student ID') + (r.email ? ' • ' + esc(r.email) : '') + '</small></td>' +
        '<td>' + levelPill(r.student_level) + '<small>' + esc(r.year_level) + '</small></td>' +
        '<td><span class="ops-pill ' + (r.account_status === 'Active' ? 'green' : 'red') + '">' + esc(r.account_status) + '</span></td>' +
        '<td class="num">' + money(r.adjusted_assessment) + (r.discount > 0 ? '<small>less ' + money(r.discount) + '</small>' : '') + '</td>' +
        '<td class="num">' + money(r.paid) + '</td><td class="num">' + money(r.pending) + '</td>' +
        '<td class="num"><b style="color:' + (r.outstanding > 0 ? COLORS.red : COLORS.green) + '">' + money(r.outstanding) + '</b></td>' +
        '<td style="min-width:110px"><div class="ops-kpi-bar" style="--kpi:' + (r.progress >= 100 ? COLORS.green : COLORS.blue) + ';margin:0"><span style="width:' + Math.min(100, r.progress) + '%"></span></div><small>' + pct(r.progress) + '</small></td>' +
        '<td>' + esc(r.last_payment || '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="9" class="ops-empty">No students match these filters.</td></tr>';
    pager($('#dataPager'), d.pagination, function (pg) { state.pages.data = pg; loadData().then(stamp).catch(function (e) { toast(e.message, true); }); });
  }
  function total(label, value) { return '<div class="ops-total"><span>' + esc(label) + '</span><b>' + value + '</b></div>'; }

  // ------------------------------------------------------------------ records
  function buildRecordsPanel() {
    var p = $('.ops-panel[data-panel="records"]');
    p.innerHTML = '<div class="ops-card"><div class="ops-card-head"><div><h3>Payment Transaction Records</h3><p>Filter by date, student level, mode of payment, and status. Exports use the same filters.</p></div>' + exportButtons('payments') + '</div>' +
      '<div class="ops-filters">' +
      '<label class="ops-field"><span>From</span><input class="input" type="date" id="recFrom"></label>' +
      '<label class="ops-field"><span>To</span><input class="input" type="date" id="recTo"></label>' +
      '<label class="ops-field"><span>Level</span><select class="input" id="recLevel">' + selectOptions(['College', 'SHS'], '', 'All levels') + '</select></label>' +
      '<label class="ops-field"><span>Mode</span><select class="input" id="recMode">' + selectOptions([{ value: 'HMA', label: 'HMA (Hello Money)' }, { value: 'Walk-in', label: 'Walk-in' }, { value: 'Other', label: 'Other (legacy)' }], '', 'All modes') + '</select></label>' +
      '<label class="ops-field"><span>Status</span><select class="input" id="recStatus">' + selectOptions(['Verified', 'Pending', 'Rejected', 'Awaiting HMA'], '', 'All statuses') + '</select></label>' +
      '<label class="ops-field grow"><span>Search</span><input class="input" type="search" id="recSearch" placeholder="Student, ID, reference no."></label>' +
      '<button class="btn primary" type="button" id="recApply">Apply</button></div>' +
      '<div class="ops-totals" id="recTotals"></div>' +
      '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Date</th><th>Reference</th><th>Student</th><th>Level</th><th>Fee / Period</th><th>Mode</th><th class="num">Amount</th><th>Status</th><th>Cashier</th><th>Receipt</th></tr></thead><tbody id="recBody"></tbody></table></div>' +
      '<div class="ops-pager" id="recPager"></div></div>';
    $('#recApply').addEventListener('click', function () { state.pages.records = 1; loadRecords(); });
    $('#recSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') { state.pages.records = 1; loadRecords(); } });
    wireExports(p, 'payments', readRecordFilters);
  }
  function readRecordFilters() {
    return { date_from: $('#recFrom').value, date_to: $('#recTo').value, level: $('#recLevel').value, mode: $('#recMode').value, status: $('#recStatus').value, search: $('#recSearch').value.trim() };
  }
  async function loadRecords() {
    var d = await api('analytics_data.php?' + qs(Object.assign({ action: 'records', page: state.pages.records, per_page: 15 }, readRecordFilters())));
    $('#recTotals').innerHTML = total('Records', num(d.totals.records)) + total('Total Amount', money(d.totals.amount)) + total('Verified Collections', money(d.totals.verified_amount));
    $('#recBody').innerHTML = d.rows.length ? d.rows.map(function (r) {
      return '<tr><td>' + esc(r.payment_date) + '</td><td><code style="font-size:11.5px">' + esc(r.reference_number) + '</code></td>' +
        '<td><b>' + esc(r.student_name) + '</b><small>' + esc(r.student_id || '') + '</small></td><td>' + levelPill(r.student_level) + '<small>' + esc(r.year_level || '') + '</small></td>' +
        '<td>' + esc(r.fee_name) + '<small>' + esc(r.period || '') + '</small></td><td>' + modePill(r.mode) + '</td>' +
        '<td class="num">' + money(r.amount) + '</td><td>' + statusPill(r.status_group, r.status) + '</td><td>' + esc(r.cashier_name || '—') + '</td>' +
        '<td>' + (r.receipt_number && (r.status_group === 'Verified') ? '<a class="ops-mini-btn" target="_blank" rel="noopener" href="' + API + 'payment_receipt.php?id=' + encodeURIComponent(r.id) + '">' + esc(r.receipt_number) + '</a>' : '—') + '</td></tr>';
    }).join('') : '<tr><td colspan="10" class="ops-empty">No payment records match these filters.</td></tr>';
    pager($('#recPager'), d.pagination, function (pg) { state.pages.records = pg; loadRecords().then(stamp).catch(function (e) { toast(e.message, true); }); });
  }

  // ------------------------------------------------------------------ logs
  function buildLogsPanel() {
    var p = $('.ops-panel[data-panel="logs"]');
    p.innerHTML = '<div class="ops-card"><div class="ops-card-head"><div><h3>Security & Activity Logs</h3><p>Sign-ins, OTP events, failed logins, inactivity timeouts, and changes made in the system.</p></div>' + exportButtons('logs') + '</div>' +
      '<div class="ops-filters">' +
      '<label class="ops-field"><span>Category</span><select class="input" id="logCategory">' + selectOptions([{ value: 'security', label: 'Security' }, { value: 'activity', label: 'Activity' }], '', 'All categories') + '</select></label>' +
      '<label class="ops-field"><span>From</span><input class="input" type="date" id="logFrom"></label>' +
      '<label class="ops-field"><span>To</span><input class="input" type="date" id="logTo"></label>' +
      '<label class="ops-field grow"><span>Search</span><input class="input" type="search" id="logSearch" placeholder="Activity, username, IP address"></label>' +
      '<button class="btn primary" type="button" id="logApply">Apply</button></div>' +
      '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Date / Time</th><th>Category</th><th>User</th><th>Activity</th><th>IP Address</th></tr></thead><tbody id="logBody"></tbody></table></div>' +
      '<div class="ops-pager" id="logPager"></div></div>';
    $('#logApply').addEventListener('click', function () { state.pages.logs = 1; loadLogs(); });
    $('#logSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') { state.pages.logs = 1; loadLogs(); } });
    wireExports(p, 'logs', readLogFilters);
  }
  function readLogFilters() { return { category: $('#logCategory').value, date_from: $('#logFrom').value, date_to: $('#logTo').value, search: $('#logSearch').value.trim() }; }
  async function loadLogs() {
    var d = await api('analytics_data.php?' + qs(Object.assign({ action: 'logs', page: state.pages.logs, per_page: 20 }, readLogFilters())));
    $('#logBody').innerHTML = d.rows.length ? d.rows.map(function (r) {
      var bad = /fail|wrong|locked|expired|timed out|inactivity/i.test(r.action);
      return '<tr><td style="white-space:nowrap">' + esc(r.created_at) + '</td><td><span class="ops-pill ' + (r.category === 'security' ? 'violet' : 'blue') + '">' + esc(r.category === 'security' ? 'Security' : 'Activity') + '</span></td>' +
        '<td><b>' + esc(r.full_name) + '</b><small>' + esc(r.username ? r.username + ' • ' + (ROLE_LABELS[r.role] || r.role) : '') + '</small></td>' +
        '<td' + (bad ? ' style="color:#b91c1c"' : '') + '>' + esc(r.action) + '</td><td><code style="font-size:11.5px">' + esc(r.ip_address || '—') + '</code></td></tr>';
    }).join('') : '<tr><td colspan="5" class="ops-empty">No log entries match these filters.</td></tr>';
    pager($('#logPager'), d.pagination, function (pg) { state.pages.logs = pg; loadLogs().then(stamp).catch(function (e) { toast(e.message, true); }); });
  }

  // ------------------------------------------------------------------ reports
  function buildReportsPanel() {
    var p = $('.ops-panel[data-panel="reports"]');
    var reports = [
      { type: 'payments', title: 'Payment Transaction Report', desc: 'Every payment with date, reference, student, level, fee, period, mode, amount, status, cashier, and receipt.', items: ['Uses date, level, mode, and status filters', 'Includes totals and verified collections'] },
      { type: 'collections', title: 'Collection Summary (Reconciliation)', desc: 'Daily totals per mode of payment — transactions, verified collections, pending, and rejected amounts.', items: ['HMA vs Walk-in per day', 'Use it to reconcile cashier collections'] },
      { type: 'students', title: 'Student Balances Report', desc: 'Assessment, discounts, verified payments, pending payments, and outstanding balance per student.', items: ['Uses the level filter (College / SHS)', 'Shows paid percentage per student'] },
      { type: 'logs', title: 'System Logs Report', desc: 'Security and activity logs for audits: sign-ins, OTP events, timeouts, and account changes.', items: ['Uses the date range', 'Up to 5,000 most recent entries'] }
    ];
    p.innerHTML = '<div class="ops-card" style="margin-bottom:16px"><div class="ops-card-head"><div><h3>Report Filters</h3><p>Applied to every report below. Leave blank to include everything.</p></div></div>' +
      '<div class="ops-filters" style="margin:0">' +
      '<label class="ops-field"><span>From</span><input class="input" type="date" id="repFrom"></label>' +
      '<label class="ops-field"><span>To</span><input class="input" type="date" id="repTo"></label>' +
      '<label class="ops-field"><span>Student Level</span><select class="input" id="repLevel">' + selectOptions(['College', 'SHS'], '', 'All levels') + '</select></label>' +
      '<label class="ops-field"><span>Mode</span><select class="input" id="repMode">' + selectOptions([{ value: 'HMA', label: 'HMA (Hello Money)' }, { value: 'Walk-in', label: 'Walk-in' }], '', 'All modes') + '</select></label>' +
      '<label class="ops-field"><span>Status</span><select class="input" id="repStatus">' + selectOptions(['Verified', 'Pending', 'Rejected', 'Awaiting HMA'], '', 'All statuses') + '</select></label>' +
      '</div></div>' +
      '<div class="ops-report-grid">' + reports.map(function (r) {
        return '<div class="ops-report"><h3>' + esc(r.title) + '</h3><p>' + esc(r.desc) + '</p><ul>' + r.items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>' + exportButtons(r.type) + '</div>';
      }).join('') + '</div>';
    reports.forEach(function (r) { wireExports(p, r.type, readReportFilters); });
  }
  function readReportFilters() {
    return { date_from: $('#repFrom').value, date_to: $('#repTo').value, level: $('#repLevel').value, mode: $('#repMode').value, status: $('#repStatus').value };
  }

  // ------------------------------------------------------------------ modes of payment
  var ICON_QR = '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3"/></svg>';
  var ICON_DESK = '<svg viewBox="0 0 24 24"><path d="M3 10h18v10H3z"/><path d="M7 10V6a5 5 0 0 1 10 0v4"/><path d="M12 14v2"/></svg>';
  function buildModesPanel() {
    var p = $('.ops-panel[data-panel="modes"]');
    p.innerHTML = '<div class="ops-mode-grid" id="modeCards"></div>' +
      '<div class="ops-chart-grid">' + chartCard('modeMonthly', 'Payment Analytics: Monthly Collections per Mode', 'Verified HMA and Walk-in collections over the last 12 months', true) +
      chartCard('modeCount', 'Transactions per Mode', 'Number of transactions (all statuses)') + '</div>' +
      '<p class="ops-callout warn" style="margin:0">Turning a mode <b>off</b> immediately stops new payments through it (students and cashiers see it as unavailable). Existing records are not changed. At least one mode must stay on.</p>';
  }
  async function loadModes() {
    var both = await Promise.all([api('payment_modes.php'), api('analytics_data.php?action=dashboard')]);
    state.modes = both[0].modes;
    var dash = both[1], k = dash.scorecards || {}, c = dash.charts || {};
    var countOf = function (m) { var i = c.modes.labels.indexOf(m); return i === -1 ? 0 : c.modes.count[i]; };
    var cards = [
      { key: 'hma', cls: '', icon: ICON_QR, collected: k.hma_collected, tx: countOf('HMA') },
      { key: 'walkin', cls: 'walkin', icon: ICON_DESK, collected: k.walkin_collected, tx: countOf('Walk-in') }
    ];
    $('#modeCards').innerHTML = cards.map(function (cd) {
      var m = state.modes[cd.key];
      return '<div class="ops-mode ' + cd.cls + '"><div class="ops-mode-icon">' + cd.icon + '</div><div class="ops-mode-body">' +
        '<h3>' + esc(m.label) + ' <span class="ops-pill ' + (m.enabled ? 'green' : 'red') + '">' + (m.enabled ? 'Enabled' : 'Disabled') + '</span></h3>' +
        '<p>' + esc(m.description) + ' Channel: ' + esc(m.channel === 'Online' ? 'Online' : 'Face-to-face') + '.</p>' +
        '<div class="ops-mode-stats"><span>Collected<b>' + money(cd.collected) + '</b></span><span>Transactions<b>' + num(cd.tx) + '</b></span></div></div>' +
        '<label class="ops-switch" title="' + (m.enabled ? 'Disable' : 'Enable') + ' ' + esc(m.label) + '"><input type="checkbox" data-mode="' + cd.key + '"' + (m.enabled ? ' checked' : '') + ' aria-label="Enable ' + esc(m.label) + '"><span></span></label></div>';
    }).join('');
    $all('#modeCards input[data-mode]').forEach(function (inp) {
      inp.addEventListener('change', async function () {
        var on = inp.checked;
        if (!on && !window.confirm('Disable ' + state.modes[inp.dataset.mode].label + '? Students will not be able to pay through it until it is enabled again.')) { inp.checked = true; return; }
        inp.disabled = true;
        try { var r = await postJson('payment_modes.php', { mode: inp.dataset.mode, enabled: on }); toast(r.message); loadModes().then(stamp); }
        catch (err) { inp.checked = !on; toast(err.message, true); }
        finally { inp.disabled = false; }
      });
    });
    monthlyChart('modeMonthly', c.monthly);
    renderChart('modeCount', {
      type: 'bar', _label: 'Transactions per mode',
      data: { labels: c.modes.labels, datasets: [{ label: 'Transactions', data: c.modes.count, backgroundColor: c.modes.labels.map(function (l) { return MODE_COLORS[l] || COLORS.slate; }), borderRadius: 6, maxBarThickness: 60 }] },
      options: { plugins: { legend: { display: false } }, scales: { x: plainAxis(), y: { beginAtZero: true, ticks: { precision: 0, color: COLORS.text }, grid: { color: COLORS.grid } } } }
    }, sum(c.modes.count) > 0);
  }

  // ------------------------------------------------------------------ users
  function buildUsersPanel() {
    var p = $('.ops-panel[data-panel="users"]');
    p.innerHTML = '<div class="ops-role-counts" id="userCounts"></div>' +
      '<div class="ops-card"><div class="ops-card-head"><div><h3>Accounts</h3><p>Every login is confirmed by an OTP sent to the account email, so each account needs a working email address.</p></div>' +
      '<button class="btn primary" type="button" id="userAdd">＋ Add Account</button></div>' +
      '<div class="ops-filters">' +
      '<label class="ops-field"><span>User Type</span><select class="input" id="userRole"></select></label>' +
      '<label class="ops-field"><span>Status</span><select class="input" id="userStatus">' + selectOptions(['Active', 'Inactive'], '', 'All') + '</select></label>' +
      '<label class="ops-field grow"><span>Search</span><input class="input" type="search" id="userSearch" placeholder="Name, username, email, ID"></label>' +
      '<button class="btn primary" type="button" id="userApply">Apply</button></div>' +
      '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Name</th><th>User Type</th><th>Email (OTP)</th><th>ID / Level</th><th>Status</th><th>Action</th></tr></thead><tbody id="userBody"></tbody></table></div></div>';
    $('#userApply').addEventListener('click', function () { readUserFilters(); loadUsers(); });
    $('#userSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') { readUserFilters(); loadUsers(); } });
    $('#userAdd').addEventListener('click', function () { openUserModal(null); });
  }
  function readUserFilters() { state.usersFilter = { role: $('#userRole').value, status: $('#userStatus').value, search: $('#userSearch').value.trim() }; }
  async function loadUsers() {
    var d = await api('users_manage.php?' + qs(state.usersFilter));
    state.users = d;
    var roles = ['admin', 'mis', 'staff', 'cashier', 'user'];
    $('#userRole').innerHTML = selectOptions(roles.map(function (r) { return { value: r, label: ROLE_LABELS[r] }; }), state.usersFilter.role, 'All user types');
    $('#userCounts').innerHTML = roles.map(function (r) {
      var c = d.counts[r] || { total: 0, active: 0, no_email: 0 };
      return '<button type="button" class="ops-role-count' + (state.usersFilter.role === r ? ' active' : '') + '" data-role="' + r + '"><span>' + ROLE_LABELS[r] + (r === 'user' ? 's' : '') + '</span><b>' + num(c.total) + '</b><small>' + num(c.active) + ' active' + (c.no_email ? ' • <em class="ops-noemail">' + num(c.no_email) + ' without email</em>' : '') + '</small></button>';
    }).join('');
    $all('#userCounts [data-role]').forEach(function (b) {
      b.addEventListener('click', function () { state.usersFilter.role = state.usersFilter.role === b.dataset.role ? '' : b.dataset.role; loadUsers().then(stamp); });
    });
    $('#userBody').innerHTML = d.users.length ? d.users.map(function (u) {
      var idCell = u.role === 'user'
        ? levelPill(u.student_level || 'College') + '<small>' + esc(u.student_id || 'No student ID') + (u.year_level ? ' • ' + esc(u.year_level) : '') + '</small>'
        : esc(u.employee_id || '—');
      var actions = u.can_manage
        ? '<div class="ops-row-actions"><button class="ops-mini-btn" type="button" data-edit="' + u.id + '">Edit</button>' +
          '<button class="ops-mini-btn ' + (u.status === 'Active' ? 'danger' : 'success') + '" type="button" data-toggle="' + u.id + '" data-to="' + (u.status === 'Active' ? 'deactivate' : 'activate') + '">' + (u.status === 'Active' ? 'Deactivate' : 'Activate') + '</button></div>'
        : '<small style="color:#94a3b8">Admin only</small>';
      return '<tr><td><b>' + esc(u.full_name) + '</b><small>@' + esc(u.username) + '</small></td>' +
        '<td><span class="ops-pill ' + ({ admin: 'red', mis: 'violet', staff: 'teal', cashier: 'amber', user: 'blue' }[u.role] || '') + '">' + esc(ROLE_LABELS[u.role] || u.role) + '</span></td>' +
        '<td>' + (u.email ? esc(u.email) : '<span class="ops-pill amber">No email — OTP cannot be sent</span>') + '</td>' +
        '<td>' + idCell + '</td><td><span class="ops-pill ' + (u.status === 'Active' ? 'green' : 'red') + '">' + esc(u.status) + '</span></td><td>' + actions + '</td></tr>';
    }).join('') : '<tr><td colspan="6" class="ops-empty">No accounts match these filters.</td></tr>';
    $all('#userBody [data-edit]').forEach(function (b) {
      b.addEventListener('click', function () { openUserModal(d.users.filter(function (u) { return String(u.id) === b.dataset.edit; })[0]); });
    });
    $all('#userBody [data-toggle]').forEach(function (b) {
      b.addEventListener('click', async function () {
        if (b.dataset.to === 'deactivate' && !window.confirm('Deactivate this account? The user will be signed out and cannot log in.')) return;
        try { var r = await postJson('users_manage.php', { action: b.dataset.to, id: Number(b.dataset.toggle) }); toast(r.message); loadUsers().then(stamp); }
        catch (err) { toast(err.message, true); }
      });
    });
  }

  function usersModalHtml() {
    return '<div class="ops-modal" id="opsUserModal" aria-hidden="true"><div class="ops-modal-card" role="dialog" aria-modal="true" aria-labelledby="opsUserTitle">' +
      '<button class="ops-modal-close" type="button" id="opsUserClose" aria-label="Close">×</button>' +
      '<div class="eyebrow">USER ACCOUNTS</div><h2 id="opsUserTitle">Add Account</h2><p class="subtitle" style="font-size:13px">The email receives the login OTP. Passwords need at least 8 characters.</p>' +
      '<form id="opsUserForm" novalidate><input type="hidden" id="ouId">' +
      '<div class="ops-form-grid">' +
      '<label>User Type<select class="input" id="ouRole"></select></label>' +
      '<label>Status<select class="input" id="ouStatus"><option>Active</option><option>Inactive</option></select></label>' +
      '<label class="full">Full Name<input class="input" id="ouName" maxlength="150" required></label>' +
      '<label>Username<input class="input" id="ouUsername" maxlength="50" required autocomplete="off"></label>' +
      '<label>Email (for OTP)<input class="input" id="ouEmail" type="email" maxlength="190" required autocomplete="off" placeholder="name@gmail.com"></label>' +
      '<label class="ou-staff">Employee ID<input class="input" id="ouEmployee" maxlength="60" placeholder="e.g. MIS-002"></label>' +
      '<label class="ou-student">Student ID<input class="input" id="ouStudentId" maxlength="50" placeholder="e.g. BLCP-2026-0002"></label>' +
      '<label class="ou-student">Student Level<select class="input" id="ouLevel"><option value="College">College</option><option value="SHS">Senior High School (SHS)</option></select></label>' +
      '<label class="ou-student">Year Level<select class="input" id="ouYear"></select></label>' +
      '<label class="full">Password<input class="input" id="ouPassword" type="password" minlength="8" autocomplete="new-password"></label>' +
      '</div><div class="ops-form-msg" id="ouMsg"></div>' +
      '<div class="ops-form-actions"><button class="btn ghost" type="button" id="ouCancel">Cancel</button><button class="btn primary" type="submit" id="ouSave">Save Account</button></div></form></div></div>';
  }

  function wireUserModal() {
    var modal = $('#opsUserModal');
    if (!modal || modal.dataset.wired) return;
    modal.dataset.wired = '1';
    var close = function () { modal.classList.remove('show'); modal.setAttribute('aria-hidden', 'true'); };
    $('#opsUserClose').addEventListener('click', close);
    $('#ouCancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    $('#ouRole').addEventListener('change', syncUserFormRole);
    $('#ouLevel').addEventListener('change', function () { $('#ouYear').innerHTML = yearOptionsFor(this.value, '', 'Not set'); });
    $('#opsUserForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      var msg = $('#ouMsg'); msg.className = 'ops-form-msg';
      var id = $('#ouId').value;
      var payload = {
        action: id ? 'update' : 'create', id: id ? Number(id) : 0, role: $('#ouRole').value, status: $('#ouStatus').value,
        full_name: $('#ouName').value.trim(), username: $('#ouUsername').value.trim(), email: $('#ouEmail').value.trim(),
        employee_id: $('#ouEmployee').value.trim(), student_id: $('#ouStudentId').value.trim(),
        student_level: $('#ouLevel').value, year_level: $('#ouYear').value, password: $('#ouPassword').value
      };
      if (!payload.full_name || !payload.username || !payload.email) { msg.textContent = 'Full name, username, and email are required.'; msg.className = 'ops-form-msg error'; return; }
      if (!id && payload.password.length < 8) { msg.textContent = 'Password must be at least 8 characters.'; msg.className = 'ops-form-msg error'; return; }
      var btn = $('#ouSave'); btn.disabled = true;
      try { var r = await postJson('users_manage.php', payload); toast(r.message); close(); loadUsers().then(stamp); }
      catch (err) { msg.textContent = err.message; msg.className = 'ops-form-msg error'; }
      finally { btn.disabled = false; }
    });
  }

  function syncUserFormRole() {
    var isStudent = $('#ouRole').value === 'user';
    $all('#opsUserForm .ou-student').forEach(function (el) { el.style.display = isStudent ? '' : 'none'; });
    $all('#opsUserForm .ou-staff').forEach(function (el) { el.style.display = isStudent ? 'none' : ''; });
  }

  function openUserModal(u) {
    wireUserModal();
    var manageable = (state.users && state.users.manageable_roles) || ['staff', 'mis', 'cashier', 'user'];
    $('#ouRole').innerHTML = selectOptions(manageable.map(function (r) { return { value: r, label: ROLE_LABELS[r] }; }), u ? u.role : (state.usersFilter.role && manageable.indexOf(state.usersFilter.role) !== -1 ? state.usersFilter.role : 'staff'));
    $('#ouId').value = u ? u.id : '';
    $('#ouStatus').value = u ? u.status : 'Active';
    $('#ouName').value = u ? u.full_name : '';
    $('#ouUsername').value = u ? u.username : '';
    $('#ouEmail').value = u ? u.email : '';
    $('#ouEmployee').value = u ? u.employee_id : '';
    $('#ouStudentId').value = u ? u.student_id : '';
    $('#ouLevel').value = u && u.student_level === 'SHS' ? 'SHS' : 'College';
    $('#ouYear').innerHTML = yearOptionsFor($('#ouLevel').value, u ? u.year_level : '', 'Not set');
    $('#ouPassword').value = '';
    $('#ouPassword').placeholder = u ? 'Leave blank to keep the current password' : 'At least 8 characters';
    $('#opsUserTitle').textContent = u ? 'Edit Account' : 'Add Account';
    $('#ouMsg').className = 'ops-form-msg';
    syncUserFormRole();
    var modal = $('#opsUserModal'); modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
    setTimeout(function () { $('#ouName').focus(); }, 30);
  }

  // ------------------------------------------------------------------ security
  function buildSecurityPanel() {
    var p = $('.ops-panel[data-panel="security"]');
    p.innerHTML = '<div class="ops-policy-grid" id="secPolicy"></div>' +
      '<div class="ops-card"><div class="ops-card-head"><div><h3>OTP Email Sender (Gmail)</h3><p>The Gmail account that emails login OTP codes to users.</p></div><span id="secMailPill"></span></div><div id="secMail"></div></div>' +
      '<div class="ops-card"><div class="ops-card-head"><div><h3>Send a Test Email</h3><p>Confirms that the Gmail sender works before users depend on it.</p></div></div>' +
      '<form class="ops-filters" id="secTestForm" style="margin:0"><label class="ops-field grow"><span>Send test to</span><input class="input" type="email" id="secTestEmail" placeholder="you@gmail.com" required></label><button class="btn primary" type="submit" id="secTestBtn">Send Test Email</button></form></div>' +
      '<div class="ops-card"><div class="ops-card-head"><div><h3>Last 24 Hours</h3><p>Security activity from the system logs.</p></div><button class="btn ghost" type="button" id="secOpenLogs">Open Security Logs →</button></div><div class="ops-totals" id="secStats" style="margin:0"></div></div>';
    $('#secTestForm').addEventListener('submit', async function (e) {
      e.preventDefault();
      var btn = $('#secTestBtn'); btn.disabled = true; btn.textContent = 'Sending…';
      try { var r = await postJson('security_settings.php', { action: 'test_email', email: $('#secTestEmail').value.trim() }); toast(r.message); }
      catch (err) { toast(err.message, true); }
      finally { btn.disabled = false; btn.textContent = 'Send Test Email'; }
    });
    $('#secOpenLogs').addEventListener('click', function () { $('#logCategory').value = 'security'; state.pages.logs = 1; show('logs'); });
  }
  async function loadSecurity() {
    var d = await api('security_settings.php');
    var pol = d.policy, m = d.mail;
    var mins = function (s) { return (s / 60) + ' min'; };
    $('#secPolicy').innerHTML =
      '<div class="ops-policy"><b>' + mins(pol.otp_resend_cooldown_seconds) + '</b><span>Email OTP resend</span><small>A new OTP email can be requested every 2 minutes.</small></div>' +
      '<div class="ops-policy"><b>' + mins(pol.otp_ttl_seconds) + '</b><span>OTP validity</span><small>Codes expire 5 minutes after they are sent. ' + pol.otp_max_attempts + ' wrong tries end the login.</small></div>' +
      '<div class="ops-policy"><b>' + mins(pol.session_timeout_seconds) + '</b><span>Session timeout</span><small>Users are signed out after 3 minutes without activity (warning at 2:30).</small></div>' +
      '<div class="ops-policy"><b>15 min</b><span>Login lockout</span><small>' + esc(pol.login_lockout) + '.</small></div>';
    $('#secMailPill').innerHTML = m.configured ? '<span class="ops-pill green">Configured</span>' : '<span class="ops-pill amber">Not configured — demo mode</span>';
    $('#secMail').innerHTML = m.configured
      ? '<div class="ops-callout ok">OTP emails are sent from <b>' + esc(m.from_email) + '</b> (' + esc(m.from_name) + ') through <code>' + esc(m.host) + ':' + esc(m.port) + '</code> (' + esc(String(m.secure).toUpperCase()) + ').</div>'
      : '<div class="ops-callout warn"><b>The Gmail sender is not set up yet.</b> Logins still require an OTP, but the code is shown on the login screen (demo mode) instead of being emailed.' +
        '<ol><li>Turn on 2-Step Verification on the Gmail account that will send OTPs.</li><li>Create an <b>App Password</b> (Google Account → Security → App passwords).</li>' +
        '<li>Open <code>backend/mail_config.php</code> and set <code>username</code> / <code>from_email</code> to the Gmail address and <code>password</code> to the 16-character App Password.</li>' +
        '<li>Send a test email below, then set <code>dev_fallback</code> to <code>false</code> for production.</li></ol></div>';
    if (d.accounts_without_email) {
      $('#secMail').innerHTML += '<div class="ops-callout warn" style="margin-top:10px"><b>' + num(d.accounts_without_email) + ' active account(s) have no email.</b> They cannot receive an OTP once the Gmail sender is on. <a href="#" id="secFixEmails">Add emails in User Accounts →</a></div>';
      var fix = $('#secFixEmails'); if (fix) fix.addEventListener('click', function (e) { e.preventDefault(); show('users'); });
    }
    var s = d.last_24h;
    $('#secStats').innerHTML = total('Successful sign-ins', num(s.sign_ins)) + total('Failed passwords', num(s.failed_logins)) + total('Wrong OTP entries', num(s.wrong_otps)) + total('Inactivity sign-outs', num(s.timeouts));
  }

  // ------------------------------------------------------------------ cashier graphs
  async function cashierInsights() {
    var shell = document.querySelector('#cashierDashboard .cashier-shell');
    var kpis = shell && shell.querySelector('.cashier-summary');
    if (!shell || !kpis) return;
    var box = document.getElementById('cashierInsights');
    if (!box) {
      box = document.createElement('div');
      box.id = 'cashierInsights'; box.className = 'cashier-insights';
      box.innerHTML = '<div class="ops-chart-grid">' +
        chartCard('cashMonthly', 'My Collections per Month', 'Verified payments assigned to you — HMA vs Walk-in') +
        chartCard('cashStatus', 'My Payment Status', 'All payments assigned to you') + '</div>';
      kpis.parentNode.insertBefore(box, kpis.nextSibling);
    }
    try {
      var d = await api('analytics_data.php?action=dashboard');
      monthlyChart('cashMonthly', d.charts.monthly);
      doughnut('cashStatus', d.charts.status.labels, d.charts.status.count, d.charts.status.labels.map(function (l) { return STATUS_COLORS[l] || COLORS.slate; }), false, 'My payments by status');
    } catch (err) { /* cashier desk keeps working without graphs */ }
  }

  // ------------------------------------------------------------------ public API
  function start(role) {
    state.role = role;
    if (role === 'cashier') { cashierInsights(); return; }
    if (['admin', 'mis', 'staff'].indexOf(role) === -1) return;
    build();
    applyRoleVisibility();
    if (role !== 'admin') show(state.tab || 'dashboard');
  }

  function openFromSidebar(tab) {
    if (state.role === 'admin') document.body.classList.add('ops-view');
    show(tab);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function reset() {
    Object.keys(state.charts).forEach(destroyChart);
    state.role = null; state.tab = 'dashboard';
    document.body.classList.remove('ops-view', 'ops-mode', 'staff-mode', 'mis-mode');
  }

  document.addEventListener('DOMContentLoaded', function () {
    $all('.ops-side-link[data-ops-tab]').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); openFromSidebar(a.dataset.opsTab); });
    });
    // Admin: any other sidebar item leaves the analytics view.
    var nav = document.querySelector('.sidebar .nav');
    if (nav) nav.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (a && !a.classList.contains('ops-side-link')) document.body.classList.remove('ops-view');
    }, true);
  });

  window.OpsPortal = { start: start, show: openFromSidebar, reset: reset, refreshCashier: cashierInsights };
})();
