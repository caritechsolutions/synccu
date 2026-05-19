const fmt = n => parseFloat(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
let locationsCache = [];

const DEFAULT_DENOMS = [
  {key:'100',label:'$100',face:100},{key:'50',label:'$50',face:50},{key:'20',label:'$20',face:20},
  {key:'10',label:'$10',face:10},{key:'5',label:'$5',face:5},{key:'2',label:'$2',face:2},
  {key:'1',label:'$1',face:1},{key:'0.50',label:'$0.50',face:0.50},{key:'0.25',label:'$0.25',face:0.25},
  {key:'0.10',label:'$0.10',face:0.10},{key:'0.05',label:'$0.05',face:0.05},{key:'0.01',label:'$0.01',face:0.01}
];
let DENOMS = DEFAULT_DENOMS;

const TYPE_LABELS = {
  vault_to_drawer:'Vault → Drawer', drawer_to_vault:'Drawer → Vault',
  deposit_to_drawer:'Deposit', withdrawal_from_drawer:'Withdrawal',
  bank_transfer_in:'Bank In', bank_transfer_out:'Bank Out', adjustment:'Adjustment',
  make_change_out:'Change (Out)', make_change_in:'Change (In)',
  loan_disbursement:'Loan Disbursement'
};

// Tab switching
document.querySelectorAll('.tab-sub').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.tab-sub').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.sub-content').forEach(c => c.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('sub-' + t.dataset.sub).classList.add('active');
  });
});

function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// Denomination grid builder
function createDenomGrid(containerId) {
  const el = document.getElementById(containerId);
  const totalId = containerId + '_total';
  let html = '<table class="denom-grid"><thead><tr><th>Denomination</th><th>Count</th><th>Value</th></tr></thead><tbody>';
  DENOMS.forEach(d => {
    html += '<tr><td>' + d.label + '</td><td><input type="number" min="0" value="0" data-denom="' + d.key + '" data-face="' + d.face + '" class="dg-input" oninput="updateDenomGrid(\'' + containerId + '\')"></td><td class="dg-val" data-denom="' + d.key + '">$0.00</td></tr>';
  });
  html += '</tbody><tfoot><tr class="total-row"><td colspan="2">TOTAL</td><td id="' + totalId + '">$0.00</td></tr></tfoot></table>';
  el.innerHTML = html;
  return {
    getValues() {
      const vals = [];
      el.querySelectorAll('.dg-input').forEach(inp => {
        const c = parseInt(inp.value) || 0;
        if (c > 0) vals.push({denomination: inp.dataset.denom, count: c});
      });
      return vals;
    },
    getTotal() {
      let t = 0;
      el.querySelectorAll('.dg-input').forEach(inp => { t += (parseInt(inp.value)||0) * parseFloat(inp.dataset.face); });
      return Math.round(t * 100) / 100;
    },
    reset() { el.querySelectorAll('.dg-input').forEach(inp => { inp.value = 0; }); updateDenomGrid(containerId); },
    populate(denomData) {
      this.reset();
      (denomData || []).forEach(d => {
        const inp = el.querySelector('.dg-input[data-denom="' + d.denomination + '"]');
        if (inp) { inp.value = parseInt(d.net_count || d.count) || 0; }
      });
      updateDenomGrid(containerId);
    }
  };
}

async function loadLocationDenoms(locationId, grid) {
  try {
    const r = await apiFetch('/api/v1/cash/locations/' + locationId + '/denominations');
    const json = await r.json();
    if (r.ok && json.data) { grid.populate(json.data); }
  } catch(e) {}
}

function updateDenomGrid(containerId) {
  const el = document.getElementById(containerId);
  let total = 0;
  el.querySelectorAll('.dg-input').forEach(inp => {
    const c = parseInt(inp.value) || 0;
    const v = c * parseFloat(inp.dataset.face);
    total += v;
    el.querySelector('.dg-val[data-denom="' + inp.dataset.denom + '"]').textContent = '$' + fmt(v);
  });
  const totalEl = document.getElementById(containerId + '_total');
  if (totalEl) totalEl.textContent = '$' + fmt(total);
  if (containerId === 'mcOutGrid' || containerId === 'mcInGrid') updateMakeChangeStatus();
}

function updateMakeChangeStatus() {
  const status = document.getElementById('mcMatchStatus');
  if (!status || !mcOutGrid || !mcInGrid) return;
  const outT = mcOutGrid.getTotal();
  const inT = mcInGrid.getTotal();
  if (outT === 0 && inT === 0) { status.textContent = '—'; status.style.color = 'var(--text-muted)'; return; }
  if (Math.abs(outT - inT) < 0.01) {
    status.innerHTML = '$' + fmt(outT) + '<br>Balanced';
    status.style.color = '#22c55e';
  } else {
    status.innerHTML = '$' + fmt(outT) + ' / $' + fmt(inT) + '<br>Mismatch';
    status.style.color = '#ef4444';
  }
}

// Grids initialized after loading settings
let dispGrid, retGrid, closeGrid, adjGrid, mcOutGrid, mcInGrid;

function initDenomGrids() {
  dispGrid = createDenomGrid('dispDenomGrid');
  retGrid = createDenomGrid('retDenomGrid');
  closeGrid = createDenomGrid('closeDenomGrid');
  adjGrid = createDenomGrid('adjDenomGrid');
  mcOutGrid = createDenomGrid('mcOutGrid');
  mcInGrid = createDenomGrid('mcInGrid');
}

async function loadDenomSettings() {
  try {
    const r = await apiFetch('/api/v1/tenant/settings');
    const data = (await r.json()).data || {};
    if (data.cash_denominations && Array.isArray(data.cash_denominations)) {
      DENOMS = data.cash_denominations.map(d => ({
        key: String(d.value), label: d.label, face: parseFloat(d.value)
      }));
    }
  } catch(e) {}
  initDenomGrids();
}

// Load summary
async function loadSummary() {
  try {
    const r = await apiFetch('/api/v1/cash/summary');
    const d = (await r.json()).data || {};
    const vaults = (d.locations?.vault || []);
    const drawers = (d.locations?.drawer || []);
    const banks = (d.locations?.bank_account || []);
    document.getElementById('statCards').innerHTML =
      '<div class="stat-card"><div class="label">Vault / Safe</div><div class="value">$' + fmt(d.vault_total) + '</div><div class="sub">' + vaults.length + ' vault(s)</div></div>' +
      '<div class="stat-card"><div class="label">Teller Drawers</div><div class="value">$' + fmt(d.drawer_total) + '</div><div class="sub">' + drawers.length + ' active drawer(s)</div></div>' +
      '<div class="stat-card"><div class="label">Bank Accounts</div><div class="value">$' + fmt(d.bank_total) + '</div><div class="sub">' + banks.length + ' account(s)</div></div>' +
      '<div class="stat-card"><div class="label">Grand Total</div><div class="value">$' + fmt(d.grand_total) + '</div><div class="sub">All fund locations</div></div>';
  } catch(e) { document.getElementById('statCards').innerHTML = ''; }
}

// Load locations
async function loadLocations() {
  try {
    const r = await apiFetch('/api/v1/cash/locations');
    const data = await r.json();
    locationsCache = data.data || [];
    const tb = document.getElementById('locationsBody');
    if (!locationsCache.length) { tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">No fund locations yet. Click "Add Location" to create one.</td></tr>'; return; }
    tb.innerHTML = locationsCache.map(l => '<tr>' +
      '<td><span class="type-badge ' + l.type + '">' + l.type.replace('_',' ') + '</span></td>' +
      '<td><strong>' + l.name + '</strong></td>' +
      '<td>' + (l.assigned_user_name || (l.type === 'bank_account' ? (l.bank_name || '') : '—')) + '</td>' +
      '<td>$' + fmt(l.balance) + '</td>' +
      '<td><span class="badge badge-' + (l.status==='active'?'success':'warning') + '">' + l.status + '</span></td>' +
      '<td><button class="btn btn-sm btn-secondary" onclick="viewLocation(\'' + l.id + '\')">View</button></td>' +
    '</tr>').join('');
    populateSelectors();
  } catch(e) { document.getElementById('locationsBody').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#ef4444">Failed to load</td></tr>'; }
}

function populateSelectors() {
  const vaults = locationsCache.filter(l => l.type === 'vault' && l.status === 'active');
  const drawers = locationsCache.filter(l => l.type === 'drawer' && l.status === 'active');
  const banks = locationsCache.filter(l => l.type === 'bank_account' && l.status === 'active');

  ['dispVault','retVault','btVault'].forEach(id => {
    const s = document.getElementById(id);
    if (!s) return;
    s.innerHTML = '<option value="">Select vault...</option>' + vaults.map(v => '<option value="' + v.id + '">' + v.name + ' ($' + fmt(v.balance) + ')</option>').join('');
  });
  ['dispDrawer','retDrawer'].forEach(id => {
    const s = document.getElementById(id);
    s.innerHTML = '<option value="">Select drawer...</option>' + drawers.map(d => '<option value="' + d.id + '">' + d.name + (d.assigned_user_name ? ' - ' + d.assigned_user_name : '') + ' ($' + fmt(d.balance) + ')</option>').join('');
  });

  const btSel = document.getElementById('btAccount');
  btSel.innerHTML = '<option value="">Select bank account...</option>' + banks.map(b => '<option value="' + b.id + '">' + b.name + ' ($' + fmt(b.balance) + ')</option>').join('');

  const adjSel = document.getElementById('adjLocation');
  adjSel.innerHTML = '<option value="">Select location...</option>' + locationsCache.filter(l => l.status === 'active').map(l => '<option value="' + l.id + '">' + l.name + ' (' + l.type.replace('_',' ') + ')</option>').join('');

  const mcSel = document.getElementById('mcLocation');
  if (mcSel) {
    const cashLocs = locationsCache.filter(l => (l.type === 'vault' || l.type === 'drawer') && l.status === 'active');
    mcSel.innerHTML = '<option value="">Select location...</option>' + cashLocs.map(l => '<option value="' + l.id + '">' + l.name + ' (' + l.type + ', $' + fmt(l.balance) + ')</option>').join('');
  }
}

function viewLocation(id) {
  const l = locationsCache.find(x => x.id === id);
  if (!l) return;
  let html = '<dl style="display:grid;grid-template-columns:120px 1fr;gap:.5rem .75rem">' +
    '<dt>Type</dt><dd><span class="type-badge ' + l.type + '">' + l.type.replace('_',' ') + '</span></dd>' +
    '<dt>Name</dt><dd><strong>' + l.name + '</strong></dd>' +
    '<dt>Status</dt><dd>' + l.status + '</dd>' +
    '<dt>Balance</dt><dd>$' + fmt(l.balance) + '</dd>';
  if (l.type === 'drawer') html += '<dt>Teller</dt><dd>' + (l.assigned_user_name || 'Unassigned') + '</dd>';
  if (l.type === 'bank_account') {
    html += '<dt>Bank</dt><dd>' + (l.bank_name || '—') + '</dd>';
    html += '<dt>Account #</dt><dd>' + (l.bank_account_number || '—') + '</dd>';
  }
  html += '</dl>';
  document.getElementById('locationDetailBody').innerHTML = html;
  openModal('locationDetailModal');
}

// Load movements
async function loadMovements() {
  try {
    const type = document.getElementById('filterMoveType').value;
    let url = '/api/v1/cash/movements?per_page=50';
    if (type) url += '&type=' + type;
    const r = await apiFetch(url);
    const json = await r.json();
    const items = (json.data?.items || json.data || []);
    const tb = document.getElementById('movementsBody');
    if (!items.length) { tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No cash movements found.</td></tr>'; return; }
    tb.innerHTML = items.map(m => '<tr>' +
      '<td><span class="type-badge">' + (TYPE_LABELS[m.type] || m.type) + '</span></td>' +
      '<td>' + (m.from_name || '—') + '</td>' +
      '<td>' + (m.to_name || m.description || '—') + '</td>' +
      '<td>$' + fmt(m.amount) + '</td>' +
      '<td>' + (m.performed_by_name || '—') + '</td>' +
      '<td style="font-size:.8rem">' + m.reference_number + '</td>' +
      '<td>' + new Date(m.created_at).toLocaleString() + '</td>' +
    '</tr>').join('');
  } catch(e) { document.getElementById('movementsBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444">Failed to load</td></tr>'; }
}
document.getElementById('filterMoveType').addEventListener('change', loadMovements);

// Load sessions
async function loadSessions() {
  try {
    const r = await apiFetch('/api/v1/cash/sessions?per_page=50');
    const json = await r.json();
    const items = (json.data?.items || json.data || []);
    const tb = document.getElementById('sessionsBody');
    if (!items.length) { tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No drawer sessions found.</td></tr>'; return; }
    tb.innerHTML = items.map(s => {
      const badge = s.status==='open'?'success':s.status==='closed'?'info':'danger';
      const disc = s.discrepancy ? (parseFloat(s.discrepancy) !== 0 ? '<span class="discrep-neg">$' + fmt(Math.abs(s.discrepancy)) + '</span>' : '<span class="discrep-ok">$0.00</span>') : '—';
      return '<tr>' +
        '<td>' + (s.teller_name || '—') + '</td>' +
        '<td>' + (s.drawer_name || '—') + '</td>' +
        '<td>' + new Date(s.opened_at).toLocaleString() + '</td>' +
        '<td>$' + fmt(s.opening_balance) + '</td>' +
        '<td><span class="badge badge-' + badge + '">' + s.status.replace(/_/g,' ') + '</span></td>' +
        '<td>' + disc + '</td>' +
        '<td>' + (s.status === 'open' ? '<button class="btn btn-sm btn-warning" onclick="openCloseSession(\'' + s.id + '\',\'' + (s.drawer_name||'') + '\',' + s.opening_balance + ',\'' + (s.drawer_id||'') + '\')">Close</button>' : '') + '</td>' +
      '</tr>';
    }).join('');
  } catch(e) { document.getElementById('sessionsBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#ef4444">Failed to load</td></tr>'; }
}

// Toggle location form fields
function toggleLocFields() {
  const t = document.getElementById('locType').value;
  document.getElementById('locUserGroup').style.display = t === 'drawer' ? '' : 'none';
  document.getElementById('locBankFields').style.display = t === 'bank_account' ? '' : 'none';
}

// Load members for drawer assignment
async function loadMembers() {
  try {
    const r = await apiFetch('/api/v1/members?per_page=200');
    const json = await r.json();
    const users = json.data?.items || json.data || [];
    const sel = document.getElementById('locUserId');
    sel.innerHTML = '<option value="">Select teller...</option>' + users.map(u => '<option value="' + u.id + '">' + (u.first_name||'') + ' ' + (u.last_name||'') + ' (' + (u.role||'') + ')</option>').join('');
  } catch(e) {}
}

// Create location
document.getElementById('addLocationForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = {
    type: document.getElementById('locType').value,
    name: document.getElementById('locName').value,
  };
  if (body.type === 'drawer') body.assigned_user_id = document.getElementById('locUserId').value || null;
  if (body.type === 'bank_account') {
    body.bank_name = document.getElementById('locBankName').value;
    body.bank_account_number = document.getElementById('locBankAcct').value;
  }
  try {
    const r = await apiFetch('/api/v1/cash/locations', {method:'POST', body: JSON.stringify(body)});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    closeModal('addLocationModal');
    document.getElementById('addLocationForm').reset();
    loadLocations(); loadSummary();
  } catch(err) { alert('Failed: ' + err.message); }
});

// Dispense
async function doDispense() {
  const vaultId = document.getElementById('dispVault').value;
  const drawerId = document.getElementById('dispDrawer').value;
  if (!vaultId || !drawerId) { alert('Select vault and drawer.'); return; }
  const denoms = dispGrid.getValues();
  const total = dispGrid.getTotal();
  if (total <= 0) { alert('Enter denomination counts.'); return; }
  if (!confirm('Dispense $' + fmt(total) + ' from vault to drawer?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/dispense', {method:'POST', body: JSON.stringify({
      vault_id: vaultId, drawer_id: drawerId, amount: total, denominations: denoms
    })});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    alert('Dispensed $' + fmt(total) + ' successfully.');
    dispGrid.reset();
    loadLocations(); loadSummary(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
}

// Auto-populate return grid when drawer selected
document.getElementById('retDrawer').addEventListener('change', function() {
  if (this.value) { loadLocationDenoms(this.value, retGrid); } else { retGrid.reset(); }
});

// Return
async function doReturn() {
  const drawerId = document.getElementById('retDrawer').value;
  const vaultId = document.getElementById('retVault').value;
  if (!drawerId || !vaultId) { alert('Select drawer and vault.'); return; }
  const denoms = retGrid.getValues();
  const total = retGrid.getTotal();
  if (total <= 0) { alert('Enter denomination counts.'); return; }
  if (!confirm('Return $' + fmt(total) + ' from drawer to vault?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/return', {method:'POST', body: JSON.stringify({
      drawer_id: drawerId, vault_id: vaultId, amount: total, denominations: denoms
    })});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    alert('Returned $' + fmt(total) + ' successfully.');
    retGrid.reset();
    loadLocations(); loadSummary(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
}

// Close session
let closeSessionId = null;
function openCloseSession(id, drawerName, openBal, drawerId) {
  closeSessionId = id;
  document.getElementById('closeSessionInfo').innerHTML =
    '<p><strong>Drawer:</strong> ' + drawerName + '</p>' +
    '<p><strong>Opening Balance:</strong> $' + fmt(openBal) + '</p>';
  closeGrid.reset();
  document.getElementById('closeNotes').value = '';
  openModal('closeSessionModal');
  if (drawerId) { loadLocationDenoms(drawerId, closeGrid); }
}

document.getElementById('closeSessionForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!closeSessionId) return;
  const denoms = closeGrid.getValues();
  const total = closeGrid.getTotal();
  if (!confirm('Close this session with actual balance of $' + fmt(total) + '?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/sessions/' + closeSessionId + '/close', {method:'POST', body: JSON.stringify({
      actual_balance: total, denominations: denoms, notes: document.getElementById('closeNotes').value
    })});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    closeModal('closeSessionModal');
    const d = json.data;
    if (d && d.discrepancy && Math.abs(parseFloat(d.discrepancy)) >= 0.01) {
      alert('Session closed with discrepancy of $' + fmt(Math.abs(d.discrepancy)) + '. Please investigate.');
    } else {
      alert('Session closed successfully. No discrepancy.');
    }
    loadSessions(); loadLocations(); loadSummary(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
});

// Bank transfer
async function doBankTransfer() {
  const body = {
    location_id: document.getElementById('btAccount').value,
    vault_id: document.getElementById('btVault').value,
    direction: document.getElementById('btDirection').value,
    amount: parseFloat(document.getElementById('btAmount').value),
    description: document.getElementById('btDesc').value
  };
  if (!body.location_id) { alert('Select a bank account.'); return; }
  if (!body.vault_id) { alert('Select a vault.'); return; }
  if (!body.amount || body.amount <= 0) { alert('Enter a valid amount.'); return; }
  const dirLabel = body.direction === 'out' ? 'Vault → Bank' : 'Bank → Vault';
  if (!confirm('Record ' + dirLabel + ' transfer of $' + fmt(body.amount) + '?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/bank-transfer', {method:'POST', body: JSON.stringify(body)});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    alert('Bank transfer recorded.');
    document.getElementById('btAmount').value = '';
    document.getElementById('btDesc').value = '';
    loadLocations(); loadSummary(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
}

// Adjustment
async function doAdjustment() {
  const body = {
    location_id: document.getElementById('adjLocation').value,
    amount: parseFloat(document.getElementById('adjAmount').value),
    description: document.getElementById('adjDesc').value,
    denominations: adjGrid.getValues()
  };
  if (!body.location_id) { alert('Select a location.'); return; }
  if (!body.amount || isNaN(body.amount)) { alert('Enter a valid amount.'); return; }
  if (!body.description) { alert('Enter a reason for the adjustment.'); return; }
  if (!confirm('Adjust balance by $' + fmt(body.amount) + '?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/adjust', {method:'POST', body: JSON.stringify(body)});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    alert('Adjustment recorded.');
    document.getElementById('adjAmount').value = '';
    document.getElementById('adjDesc').value = '';
    adjGrid.reset();
    loadLocations(); loadSummary(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
}

// Make Change
async function doMakeChange() {
  const locationId = document.getElementById('mcLocation').value;
  if (!locationId) { alert('Select a location.'); return; }
  const denomsOut = mcOutGrid.getValues();
  const denomsIn = mcInGrid.getValues();
  const totalOut = mcOutGrid.getTotal();
  const totalIn = mcInGrid.getTotal();
  if (totalOut <= 0) { alert('Enter the denominations you are giving out.'); return; }
  if (totalIn <= 0) { alert('Enter the denominations you are receiving in.'); return; }
  if (Math.abs(totalOut - totalIn) >= 0.01) { alert('Totals must match. Giving out: $' + fmt(totalOut) + ', Receiving in: $' + fmt(totalIn)); return; }
  if (!confirm('Make change of $' + fmt(totalOut) + ' at this location?')) return;
  try {
    const r = await apiFetch('/api/v1/cash/make-change', {method:'POST', body: JSON.stringify({
      location_id: locationId,
      denominations_out: denomsOut,
      denominations_in: denomsIn,
      description: document.getElementById('mcDesc').value
    })});
    const json = await r.json();
    if (!r.ok) { alert(json.error||'Failed'); return; }
    alert('Change made successfully. Ref: ' + (json.data?.reference || ''));
    mcOutGrid.reset(); mcInGrid.reset();
    document.getElementById('mcDesc').value = '';
    loadLocations(); loadMovements();
  } catch(err) { alert('Failed: ' + err.message); }
}

// Init — load denomination settings first, then everything else
loadDenomSettings().then(() => {
  loadSummary(); loadLocations(); loadMovements(); loadSessions(); loadMembers();
});
