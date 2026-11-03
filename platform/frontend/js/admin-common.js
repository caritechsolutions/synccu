// SyncCU Admin - Common UI interactions (sidebar, theme, dropdown, logout, API)

/**
 * Wrapper around fetch that auto-attaches the auth token and
 * redirects to login on 401 responses.
 */
async function apiFetch(url, options = {}) {
  const token = localStorage.getItem('synccu-token');
  if (!token) {
    window.location.href = '../index.html';
    throw new Error('No auth token');
  }
  const headers = Object.assign({ 'Authorization': 'Bearer ' + token }, options.headers || {});
  if (options.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }
  const resp = await fetch(url, Object.assign({}, options, { headers }));
  if (resp.status === 401) {
    localStorage.removeItem('synccu-token');
    localStorage.removeItem('synccu-refresh');
    localStorage.removeItem('synccu-user');
    window.location.href = '../index.html';
    throw new Error('Session expired');
  }
  return resp;
}

/** Parish codes (stored in users.state) → display names. */
window.PARISHES = {
  CC: 'Christ Church', SA: 'St. Andrew', SG: 'St. George', SJ: 'St. James', SN: 'St. John',
  SO: 'St. Joseph', SL: 'St. Lucy', SM: 'St. Michael', SP: 'St. Peter', SH: 'St. Philip', ST: 'St. Thomas',
};
window.parishName = function (code) { return (code && window.PARISHES[code]) || code || ''; };

/**
 * Supervisor (user-of-authority) override.
 *
 * Protected fields (e.g. backdated transaction dates) stay locked until an
 * admin/manager enters their credentials. Credentials are verified with the
 * API, cached in memory for a short window so a clerk can key in a run of
 * historical entries, and attached to each protected request so the server
 * can re-verify them on the actual write.
 */
window.SupervisorAuth = (function () {
  const TTL_MS = 15 * 60 * 1000;
  let cache = null;
  let modal = null;

  function today() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function get() { return (cache && (Date.now() - cache.at) < TTL_MS) ? cache : null; }
  function clear() { cache = null; }

  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'supervisorAuthModal';
    modal.style.zIndex = '3000';
    modal.innerHTML =
      '<div class="modal" style="max-width:420px">' +
        '<div class="modal-header"><h3>Supervisor Authorization</h3>' +
          '<button type="button" class="icon-btn modal-close" id="supAuthClose">&times;</button></div>' +
        '<form id="supAuthForm"><div class="modal-body">' +
          '<p id="supAuthReason" style="font-size:.85rem;color:var(--text-muted);margin:0 0 .75rem 0"></p>' +
          '<div class="form-group"><label for="supAuthEmail">Supervisor Email</label>' +
            '<input type="email" id="supAuthEmail" class="form-input" autocomplete="off" required></div>' +
          '<div class="form-group"><label for="supAuthPassword">Password</label>' +
            '<input type="password" id="supAuthPassword" class="form-input" autocomplete="new-password" required></div>' +
          '<div id="supAuthError" style="display:none;color:#dc2626;font-size:.85rem;margin-top:.25rem"></div>' +
        '</div><div class="modal-footer">' +
          '<button type="button" class="btn btn-secondary" id="supAuthCancel">Cancel</button>' +
          '<button type="submit" class="btn btn-primary" id="supAuthSubmit">Authorize</button>' +
        '</div></form></div>';
    document.body.appendChild(modal);
    return modal;
  }

  /** Prompt for supervisor credentials. Resolves with the cached auth, or null if cancelled. */
  function request(reason) {
    const existing = get();
    if (existing) return Promise.resolve(existing);
    const m = ensureModal();
    const form = m.querySelector('#supAuthForm');
    const err = m.querySelector('#supAuthError');
    const emailEl = m.querySelector('#supAuthEmail');
    const passEl = m.querySelector('#supAuthPassword');
    m.querySelector('#supAuthReason').textContent = reason || 'This action requires approval from a manager or administrator.';
    err.style.display = 'none';
    form.reset();
    m.classList.add('active');
    setTimeout(() => emailEl.focus(), 50);

    return new Promise((resolve) => {
      const done = (val) => { cleanup(); m.classList.remove('active'); resolve(val); };
      const onCancel = () => done(null);
      const onSubmit = async (e) => {
        e.preventDefault();
        const btn = m.querySelector('#supAuthSubmit');
        btn.disabled = true; btn.textContent = 'Checking...';
        err.style.display = 'none';
        try {
          const resp = await apiFetch('/api/v1/auth/verify-supervisor', {
            method: 'POST',
            body: JSON.stringify({ email: emailEl.value.trim(), password: passEl.value }),
          });
          const json = await resp.json().catch(() => ({}));
          if (!resp.ok) {
            err.textContent = json.error || json.message || 'Authorization failed';
            err.style.display = '';
            return;
          }
          cache = { email: emailEl.value.trim(), password: passEl.value, name: (json.data && json.data.name) || 'Supervisor', at: Date.now() };
          done(cache);
        } catch (ex) {
          err.textContent = 'Network error: ' + ex.message;
          err.style.display = '';
        } finally {
          btn.disabled = false; btn.textContent = 'Authorize';
        }
      };
      const onOverlay = (e) => { if (e.target === m) onCancel(); };
      function cleanup() {
        form.removeEventListener('submit', onSubmit);
        m.querySelector('#supAuthCancel').removeEventListener('click', onCancel);
        m.querySelector('#supAuthClose').removeEventListener('click', onCancel);
        m.removeEventListener('click', onOverlay);
      }
      form.addEventListener('submit', onSubmit);
      m.querySelector('#supAuthCancel').addEventListener('click', onCancel);
      m.querySelector('#supAuthClose').addEventListener('click', onCancel);
      m.addEventListener('click', onOverlay);
    });
  }

  /** Add override credentials to a payload when a supervisor has authorized this session. */
  function attach(payload) {
    const c = get();
    if (c) { payload.override_email = c.email; payload.override_password = c.password; }
    return payload;
  }

  /**
   * Wire a protected date input: locked at today until a supervisor unlocks it.
   * Returns helpers: isBackdated(), value(), reset(), attachTo(payload, fieldName).
   */
  function bindDateField(inputId, buttonId, badgeId, reason) {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);
    const badge = badgeId ? document.getElementById(badgeId) : null;
    if (!input || !button) return null;
    const t = today();
    input.value = t; input.max = t; input.disabled = true;

    function setUnlocked(auth) {
      input.disabled = false;
      button.textContent = 'Unlocked';
      button.classList.add('btn-success');
      if (badge) { badge.textContent = 'Authorized by ' + auth.name; badge.style.display = ''; }
    }
    function setLocked() {
      input.disabled = true; input.value = t;
      button.textContent = 'Unlock';
      button.classList.remove('btn-success');
      if (badge) { badge.style.display = 'none'; badge.textContent = ''; }
    }
    button.addEventListener('click', async () => {
      if (!input.disabled) { setLocked(); return; }
      const auth = await request(reason || 'Backdating requires supervisor authorization.');
      if (auth) setUnlocked(auth);
    });
    return {
      input,
      isBackdated: () => !input.disabled && input.value && input.value !== today(),
      value: () => input.value,
      reset: setLocked,
      attachTo: (payload, field) => {
        if (!input.disabled && input.value && input.value !== today()) {
          payload[field || 'transaction_date'] = input.value;
          attach(payload);
        }
        return payload;
      },
    };
  }

  return { get, clear, request, attach, bindDateField, today };
})();

(function() {
  // ── Sidebar toggle (mobile) ──
  const hamburger = document.getElementById('hamburgerBtn');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (hamburger && sidebar) {
    hamburger.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      if (overlay) overlay.classList.toggle('active');
    });
  }
  if (overlay) {
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
    });
  }

  // ── Theme toggle ──
  const themeToggle = document.getElementById('themeToggle');
  if (themeToggle) {
    const saved = localStorage.getItem('synccu-theme');
    if (saved === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
      const sun = themeToggle.querySelector('.icon-sun');
      const moon = themeToggle.querySelector('.icon-moon');
      if (sun) sun.style.display = 'none';
      if (moon) moon.style.display = '';
    }
    themeToggle.addEventListener('click', () => {
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
      localStorage.setItem('synccu-theme', isDark ? 'light' : 'dark');
      const sun = themeToggle.querySelector('.icon-sun');
      const moon = themeToggle.querySelector('.icon-moon');
      if (sun) sun.style.display = isDark ? '' : 'none';
      if (moon) moon.style.display = isDark ? 'none' : '';
    });
  }

  // ── User dropdown ──
  const avatarBtn = document.getElementById('userAvatarBtn');
  const userDropdown = document.getElementById('userDropdown');
  if (avatarBtn && userDropdown) {
    avatarBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      userDropdown.classList.toggle('show');
    });
    document.addEventListener('click', () => userDropdown.classList.remove('show'));
  }

  // ── Populate user info ──
  try {
    const user = JSON.parse(localStorage.getItem('synccu-user'));
    if (user) {
      const initials = (user.first_name?.[0] || '') + (user.last_name?.[0] || '');
      const fullName = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
      const avatar = document.querySelector('.avatar');
      if (avatar && initials) avatar.textContent = initials.toUpperCase();
      const header = document.querySelector('.dropdown-header');
      if (header) {
        const strong = header.querySelector('strong');
        const span = header.querySelector('span');
        if (strong) strong.textContent = fullName || 'User';
        if (span) span.textContent = user.email || '';
      }
    }
  } catch(e) {}

  // ── Logout ──
  function doLogout() {
    localStorage.removeItem('synccu-token');
    localStorage.removeItem('synccu-refresh');
    localStorage.removeItem('synccu-user');
    window.location.href = '../index.html';
  }
  document.getElementById('logoutBtn')?.addEventListener('click', (e) => { e.preventDefault(); doLogout(); });
  document.getElementById('logoutBtn2')?.addEventListener('click', (e) => { e.preventDefault(); doLogout(); });

  // ── Role-based access control ──
  (function enforceRoleAccess() {
    try {
      var user = JSON.parse(localStorage.getItem('synccu-user'));
      if (!user) return;
      var role = user.role || 'member';
      var page = location.pathname.split('/').pop();

      // Pages each role can access
      var allowed = {
        admin:   null, // null = all pages
        manager: null,
        teller:  ['dashboard.html', 'members.html', 'transactions.html', 'cash-management.html'],
        member:  ['dashboard.html'],
      };

      var roleAllowed = allowed[role];
      if (roleAllowed && !roleAllowed.includes(page)) {
        window.location.href = 'dashboard.html';
        return;
      }

      // Hide sidebar nav items the role shouldn't see
      var sidebarHide = {
        teller: ['accounts.html', 'loans.html', 'reports.html', 'network.html', 'settings.html'],
        member: ['members.html', 'accounts.html', 'transactions.html', 'loans.html', 'reports.html', 'cash-management.html', 'network.html', 'settings.html'],
      };

      var hidePages = sidebarHide[role];
      if (hidePages) {
        document.querySelectorAll('.sidebar-nav .nav-item').forEach(function(link) {
          var href = link.getAttribute('href') || '';
          if (hidePages.some(function(p) { return href.indexOf(p) !== -1; })) {
            link.style.display = 'none';
          }
        });
      }

      // Hide settings link in user dropdown for non-admin/manager
      if (role !== 'admin' && role !== 'manager') {
        document.querySelectorAll('.dropdown-item').forEach(function(item) {
          if ((item.getAttribute('href') || '').indexOf('settings.html') !== -1) {
            item.style.display = 'none';
          }
        });
      }

      // Expose role for page-specific JS
      window.__synccu_role = role;
    } catch(e) {}
  })();

  // ── Apply branding from tenant settings ──
  async function applyBranding() {
    try {
      const resp = await fetch('/api/v1/tenant/settings', {
        headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('synccu-token') || '') }
      });
      if (!resp.ok) return;
      const json = await resp.json();
      const d = json.data || {};
      const tenant = d.tenant || {};
      const s = d.settings || {};

      const cuName = tenant.name || 'SyncCU';
      const brandEl = document.querySelector('.sidebar-brand h2');
      if (brandEl) brandEl.textContent = cuName;

      const primary = s.branding_primary_color;
      const secondary = s.branding_secondary_color;
      if (primary) {
        document.documentElement.style.setProperty('--primary', primary);
        document.documentElement.style.setProperty('--primary-hover', secondary || primary);
        document.documentElement.style.setProperty('--text-link', primary);
      }
      if (secondary) {
        document.documentElement.style.setProperty('--primary-dark', secondary);
        document.documentElement.style.setProperty('--accent', secondary);
        document.documentElement.style.setProperty('--bg-sidebar', secondary);
      }

      const logoUrl = s.branding_logo_url;
      if (logoUrl && brandEl) {
        brandEl.innerHTML = '<img src="' + logoUrl + '" alt="' + cuName + '" style="max-height:32px;max-width:140px">';
      }

      const favicon = s.branding_favicon_url;
      if (favicon) {
        let link = document.querySelector('link[rel="icon"]');
        if (!link) {
          link = document.createElement('link');
          link.rel = 'icon';
          document.head.appendChild(link);
        }
        link.href = favicon;
      }

      localStorage.setItem('synccu-branding', JSON.stringify({
        name: cuName, primary, secondary, logoUrl, favicon
      }));
    } catch(e) {}
  }

  // Apply cached branding instantly, then refresh from API
  try {
    const cached = JSON.parse(localStorage.getItem('synccu-branding') || '{}');
    if (cached.name) {
      const brandEl = document.querySelector('.sidebar-brand h2');
      if (brandEl) {
        if (cached.logoUrl) brandEl.innerHTML = '<img src="' + cached.logoUrl + '" alt="' + cached.name + '" style="max-height:32px;max-width:140px">';
        else brandEl.textContent = cached.name;
      }
    }
    if (cached.primary) {
      document.documentElement.style.setProperty('--primary', cached.primary);
      document.documentElement.style.setProperty('--text-link', cached.primary);
      if (cached.secondary) {
        document.documentElement.style.setProperty('--primary-hover', cached.secondary);
        document.documentElement.style.setProperty('--primary-dark', cached.secondary);
        document.documentElement.style.setProperty('--bg-sidebar', cached.secondary);
      }
    }
    if (cached.favicon) {
      let link = document.querySelector('link[rel="icon"]');
      if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link); }
      link.href = cached.favicon;
    }
  } catch(e) {}
  applyBranding();
})();
