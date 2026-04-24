/* Dashboard - Charts & Live Data */

const COLORS = {
  blue: '#3b82f6', green: '#10b981', orange: '#f59e0b', purple: '#8b5cf6',
  red: '#ef4444', cyan: '#06b6d4', pink: '#ec4899', indigo: '#6366f1',
  blueLight: 'rgba(59,130,246,0.1)', greenLight: 'rgba(16,185,129,0.1)',
  orangeLight: 'rgba(245,158,11,0.1)', purpleLight: 'rgba(139,92,246,0.1)',
};

function fmt$(v) {
  if (v == null) return '$0';
  const n = Number(v);
  if (n >= 1e6) return '$' + (n / 1e6).toFixed(1) + 'M';
  if (n >= 1e3) return '$' + (n / 1e3).toFixed(1) + 'K';
  return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtN(v) { return Number(v || 0).toLocaleString(); }

function animateValue(el, end, prefix, duration) {
  prefix = prefix || '';
  duration = duration || 1200;
  const start = 0;
  const startTime = performance.now();
  function tick(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    const current = Math.floor(start + (end - start) * eased);
    el.textContent = prefix + current.toLocaleString();
    if (progress < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

function animateDollar(el, end, duration) {
  duration = duration || 1200;
  const startTime = performance.now();
  function tick(now) {
    const elapsed = now - startTime;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    const current = end * eased;
    el.textContent = fmt$(current);
    if (progress < 1) requestAnimationFrame(tick);
  }
  requestAnimationFrame(tick);
}

let trendChart, acctChart, loanChart, depWithChart;

function renderTrendChart(data) {
  const ctx = document.getElementById('trendChart');
  if (!ctx) return;
  const labels = data.map(d => {
    const dt = new Date(d.date + 'T00:00:00');
    return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  });
  const counts = data.map(d => Number(d.count));
  const totals = data.map(d => Number(d.total));

  if (trendChart) trendChart.destroy();
  trendChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Volume ($)',
          data: totals,
          borderColor: COLORS.blue,
          backgroundColor: 'rgba(59,130,246,0.08)',
          fill: true,
          tension: 0.4,
          pointRadius: 2,
          pointHoverRadius: 5,
          yAxisID: 'y',
        },
        {
          label: 'Count',
          data: counts,
          borderColor: COLORS.purple,
          backgroundColor: 'transparent',
          borderDash: [5, 5],
          tension: 0.4,
          pointRadius: 2,
          pointHoverRadius: 5,
          yAxisID: 'y1',
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', labels: { usePointStyle: true, padding: 20 } },
        tooltip: {
          backgroundColor: 'rgba(0,0,0,0.8)',
          padding: 12,
          callbacks: {
            label: function(c) {
              if (c.datasetIndex === 0) return ' Volume: $' + Number(c.raw).toLocaleString(undefined, {minimumFractionDigits:2});
              return ' Count: ' + c.raw;
            }
          }
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: {
          position: 'left',
          grid: { color: 'rgba(0,0,0,0.05)' },
          ticks: { callback: v => fmt$(v) }
        },
        y1: {
          position: 'right',
          grid: { drawOnChartArea: false },
          ticks: { precision: 0 }
        }
      }
    }
  });
}

function renderAccountChart(data) {
  const ctx = document.getElementById('acctChart');
  if (!ctx) return;
  const labels = data.map(d => (d.account_type || 'other').replace(/_/g, ' '));
  const values = data.map(d => Number(d.total_balance));
  const colors = [COLORS.blue, COLORS.green, COLORS.orange, COLORS.purple, COLORS.cyan, COLORS.pink];

  if (acctChart) acctChart.destroy();
  acctChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 0, hoverOffset: 8 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
        tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + fmt$(c.raw) } }
      }
    }
  });
}

function renderLoanChart(data) {
  const ctx = document.getElementById('loanChart');
  if (!ctx) return;
  const labels = data.map(d => (d.status || 'unknown'));
  const values = data.map(d => Number(d.count));
  const statusColors = {
    active: COLORS.green, approved: COLORS.blue, application: COLORS.orange,
    denied: COLORS.red, delinquent: '#dc2626', closed: '#6b7280', paid_off: COLORS.cyan
  };
  const colors = labels.map(l => statusColors[l] || COLORS.purple);

  if (loanChart) loanChart.destroy();
  loanChart = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labels.map(l => l.replace(/_/g, ' ')),
      datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 8 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16 } },
        tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.raw + ' loans' } }
      }
    }
  });
}

function renderDepWithChart(data) {
  const ctx = document.getElementById('depWithChart');
  if (!ctx) return;
  let depTotal = 0, withTotal = 0, depCount = 0, withCount = 0;
  (data || []).forEach(d => {
    if (d.type === 'deposit') { depTotal = Number(d.total); depCount = Number(d.count); }
    if (d.type === 'withdrawal') { withTotal = Number(d.total); withCount = Number(d.count); }
  });

  if (depWithChart) depWithChart.destroy();
  depWithChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['Deposits', 'Withdrawals'],
      datasets: [{
        label: 'Amount ($)',
        data: [depTotal, withTotal],
        backgroundColor: [COLORS.green, COLORS.red],
        borderRadius: 8,
        barPercentage: 0.5,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: c => ' ' + fmt$(c.raw) + ' (' + (c.dataIndex === 0 ? depCount : withCount) + ' txns)' } }
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { callback: v => fmt$(v) } }
      }
    }
  });
}

function populateStats(d) {
  const el = id => document.getElementById(id);

  const memEl = el('statMembers');
  const depEl = el('statDeposits');
  const loanEl = el('statLoans');
  const loanBalEl = el('statLoanBal');
  const todayTxnEl = el('statTodayTxn');
  const todayDepEl = el('statTodayDep');

  if (memEl) animateValue(memEl, d.members || 0, '', 1000);
  if (depEl) animateDollar(depEl, d.total_deposits || 0, 1200);
  if (loanEl) animateValue(loanEl, d.active_loans || 0, '', 800);
  if (loanBalEl) animateDollar(loanBalEl, d.total_loan_balance || 0, 1200);

  const today = d.today || {};
  if (todayTxnEl) animateValue(todayTxnEl, today.today_transactions || 0, '', 800);
  if (todayDepEl) animateDollar(todayDepEl, today.today_deposits || 0, 1000);

  const pendEl = el('pendingLoans');
  if (pendEl) pendEl.textContent = fmtN(d.pending_loan_apps);
  const delEl = el('delinquentLoans');
  if (delEl) delEl.textContent = fmtN(d.delinquent_loans);
  const acctEl = el('activeAccounts');
  if (acctEl) acctEl.textContent = fmtN(d.active_accounts);
  const newMemEl = el('newMembersToday');
  if (newMemEl) newMemEl.textContent = fmtN(today.new_members_today);
  const todayWithEl = el('todayWithdrawals');
  if (todayWithEl) todayWithEl.textContent = fmt$(today.today_withdrawals);

  const alertDel = el('alertDelinquent');
  if (alertDel) alertDel.textContent = fmtN(d.delinquent_loans);
  const alertPend = el('alertPending');
  if (alertPend) alertPend.textContent = fmtN(d.pending_loan_apps);
  const alertAcct = el('alertAccounts');
  if (alertAcct) alertAcct.textContent = fmtN(d.active_accounts);
}

function populateCashPosition(data) {
  const container = document.getElementById('cashPositionCards');
  if (!container || !data || !data.length) {
    if (container) container.innerHTML = '<p style="color:var(--text-muted);text-align:center;padding:24px">No cash position data</p>';
    return;
  }
  const icons = { vault: '#icon-key', drawer: '#icon-wallet', bank_account: '#icon-credit-card' };
  const colors = { vault: 'blue', drawer: 'green', bank_account: 'purple' };
  container.innerHTML = data.map(d => {
    const type = d.type || 'vault';
    return '<div class="cash-pos-card cash-pos-' + colors[type] + '">' +
      '<div class="cash-pos-icon"><svg><use href="' + (icons[type] || '#icon-dollar') + '"/></svg></div>' +
      '<div class="cash-pos-info"><span class="cash-pos-label">' + type.replace(/_/g, ' ') + '</span>' +
      '<span class="cash-pos-value">' + fmt$(d.total) + '</span></div></div>';
  }).join('');
}

function populateRecentTxns(txns) {
  const tbody = document.getElementById('recentTransactions');
  if (!tbody) return;
  if (!txns || !txns.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No recent transactions</td></tr>';
    return;
  }
  tbody.innerHTML = txns.map(t => {
    const date = new Date(t.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const isCredit = ['deposit', 'interest', 'loan_payment', 'fee_reversal'].includes(t.type);
    const amtClass = isCredit ? 'text-green' : 'text-red';
    const sign = isCredit ? '+' : '-';
    const statusCls = t.status === 'completed' ? 'badge-success' : t.status === 'pending' ? 'badge-warning' : 'badge-neutral';
    return '<tr>' +
      '<td>' + date + '</td>' +
      '<td><span class="txn-type-badge">' + (t.type || '').replace(/_/g, ' ') + '</span></td>' +
      '<td>' + (t.reference_number || '-') + '</td>' +
      '<td class="' + amtClass + ' font-semibold">' + sign + '$' + Number(t.amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) + '</td>' +
      '<td><span class="badge ' + statusCls + '">' + t.status + '</span></td></tr>';
  }).join('');
}

async function loadDashboardData() {
  try {
    const resp = await apiFetch('/api/v1/admin/dashboard');
    if (!resp.ok) return;
    const json = await resp.json();
    const d = json.data || json;

    populateStats(d);
    populateRecentTxns(d.recent_transactions);
    populateCashPosition(d.cash_position);

    if (d.transaction_trend && d.transaction_trend.length) renderTrendChart(d.transaction_trend);
    if (d.account_types && d.account_types.length) renderAccountChart(d.account_types);
    if (d.loan_status && d.loan_status.length) renderLoanChart(d.loan_status);
    renderDepWithChart(d.deposit_vs_withdrawal);

    document.querySelectorAll('.dash-card').forEach((card, i) => {
      card.style.animationDelay = (i * 0.05) + 's';
      card.classList.add('dash-animate-in');
    });
  } catch (e) {
    console.error('Dashboard load error:', e);
  }
}

async function runReconcile() {
  const btn = document.getElementById('reconcileBtn');
  if (!btn) return;
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Syncing...';

  try {
    const resp = await apiFetch('/api/v1/admin/reconcile', { method: 'POST' });
    const json = await resp.json();
    const d = json.data || json;
    const fixes = d.total_fixes || 0;

    let msg = fixes === 0
      ? 'All balances are in sync. No corrections needed.'
      : fixes + ' balance(s) corrected:\n';

    if (d.accounts && d.accounts.details && d.accounts.details.length) {
      msg += '\nAccounts:\n';
      d.accounts.details.forEach(a => {
        msg += '  ' + a.account_number + ' (' + a.type + '): $' + a.was + ' -> $' + a.should_be + '\n';
      });
    }
    if (d.loans && d.loans.details && d.loans.details.length) {
      msg += '\nLoans:\n';
      d.loans.details.forEach(l => {
        msg += '  ' + l.loan_number + ': $' + l.balance_was + ' -> $' + l.balance_should_be + '\n';
      });
    }
    if (d.fund_locations && d.fund_locations.details && d.fund_locations.details.length) {
      msg += '\nFund Locations:\n';
      d.fund_locations.details.forEach(f => {
        msg += '  ' + f.name + ' (' + f.type + '): $' + f.was + ' -> $' + f.should_be + '\n';
      });
    }

    alert(msg);
    if (fixes > 0) loadDashboardData();
  } catch (e) {
    alert('Reconciliation failed: ' + e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  try {
    const user = JSON.parse(localStorage.getItem('synccu-user'));
    if (user) {
      const subtitle = document.querySelector('.page-subtitle');
      if (subtitle) subtitle.textContent = 'Welcome back, ' + (user.first_name || 'Admin') + '. Here is your credit union overview.';
      const avatarEl = document.querySelector('.avatar');
      if (avatarEl && user.first_name && user.last_name) {
        avatarEl.textContent = user.first_name[0] + user.last_name[0];
      }
    }
  } catch (e) {}
  loadDashboardData();
});
