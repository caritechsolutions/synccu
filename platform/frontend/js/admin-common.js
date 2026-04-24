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
