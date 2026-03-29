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
})();
