// SyncCU Platform - Main Application JavaScript

// ============================================
// API Client
// ============================================
class ApiClient {
    constructor(baseUrl = '/api/v1') {
        this.baseUrl = baseUrl;
        this.accessToken = null;
        this.refreshToken = null;
        this.onUnauthorized = null;
    }

    setTokens(access, refresh) {
        this.accessToken = access;
        this.refreshToken = refresh;
        if (access) sessionStorage.setItem('access_token', access);
        if (refresh) sessionStorage.setItem('refresh_token', refresh);
    }

    loadTokens() {
        this.accessToken = sessionStorage.getItem('access_token');
        this.refreshToken = sessionStorage.getItem('refresh_token');
    }

    clearTokens() {
        this.accessToken = null;
        this.refreshToken = null;
        sessionStorage.removeItem('access_token');
        sessionStorage.removeItem('refresh_token');
    }

    async request(method, endpoint, data = null, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        const headers = {
            'Content-Type': 'application/json',
            ...options.headers
        };

        if (this.accessToken) {
            headers['Authorization'] = `Bearer ${this.accessToken}`;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

        const config = { method, headers };
        if (data && method !== 'GET') config.body = JSON.stringify(data);
        if (method === 'GET' && data) {
            const params = new URLSearchParams(data);
            url += '?' + params.toString();
        }

        try {
            let response = await fetch(url, config);

            if (response.status === 401 && this.refreshToken && !options._isRetry) {
                const refreshed = await this.refreshAccessToken();
                if (refreshed) {
                    headers['Authorization'] = `Bearer ${this.accessToken}`;
                    config.headers = headers;
                    response = await fetch(url, {...config, _isRetry: true});
                } else {
                    this.clearTokens();
                    if (this.onUnauthorized) this.onUnauthorized();
                    throw new Error('Session expired');
                }
            }

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw { status: response.status, message: result.message || result.detail || 'Request failed', data: result };
            }

            return result;
        } catch (error) {
            if (error.status) throw error;
            throw { status: 0, message: 'Network error. Please check your connection.' };
        }
    }

    async refreshAccessToken() {
        try {
            const response = await fetch(`${this.baseUrl}/auth/refresh`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ refresh_token: this.refreshToken })
            });
            if (response.ok) {
                const data = await response.json();
                this.setTokens(data.access_token, data.refresh_token || this.refreshToken);
                return true;
            }
            return false;
        } catch { return false; }
    }

    get(endpoint, params) { return this.request('GET', endpoint, params); }
    post(endpoint, data) { return this.request('POST', endpoint, data); }
    put(endpoint, data) { return this.request('PUT', endpoint, data); }
    delete(endpoint) { return this.request('DELETE', endpoint); }
}

// ============================================
// Theme Manager
// ============================================
class ThemeManager {
    constructor() {
        this.theme = localStorage.getItem('theme') || 'light';
        this.apply();
    }

    apply() {
        document.documentElement.setAttribute('data-theme', this.theme);
        const icon = document.querySelector('.theme-toggle-icon');
        if (icon) icon.textContent = this.theme === 'dark' ? '☀️' : '🌙';
    }

    toggle() {
        this.theme = this.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme', this.theme);
        this.apply();
    }
}

// ============================================
// Toast Notifications
// ============================================
class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        this.container = document.getElementById('toast-container');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    }

    show(message, type = 'info', duration = 4000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${this.escapeHtml(message)}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">×</button>
        `;

        this.container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('toast-show'));

        setTimeout(() => {
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    success(msg) { this.show(msg, 'success'); }
    error(msg) { this.show(msg, 'error', 6000); }
    warning(msg) { this.show(msg, 'warning'); }
    info(msg) { this.show(msg, 'info'); }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

// ============================================
// Modal Manager
// ============================================
class ModalManager {
    static open(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    static close(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    static init() {
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
            if (e.target.hasAttribute('data-modal-open')) {
                ModalManager.open(e.target.getAttribute('data-modal-open'));
            }
            if (e.target.hasAttribute('data-modal-close')) {
                ModalManager.close(e.target.getAttribute('data-modal-close'));
            }
        });
    }
}

// ============================================
// Form Utilities
// ============================================
class FormValidator {
    static validate(form) {
        const inputs = form.querySelectorAll('[required], [data-validate]');
        let valid = true;

        inputs.forEach(input => {
            const error = FormValidator.validateField(input);
            const errorEl = input.parentElement.querySelector('.form-error');

            if (error) {
                input.classList.add('is-invalid');
                input.classList.remove('is-valid');
                if (errorEl) errorEl.textContent = error;
                valid = false;
            } else {
                input.classList.remove('is-invalid');
                input.classList.add('is-valid');
                if (errorEl) errorEl.textContent = '';
            }
        });

        return valid;
    }

    static validateField(input) {
        const value = input.value.trim();

        if (input.required && !value) return 'This field is required';
        if (input.type === 'email' && value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Invalid email address';
        if (input.minLength > 0 && value.length < input.minLength) return `Minimum ${input.minLength} characters`;
        if (input.dataset.validate === 'password' && value.length < 8) return 'Password must be at least 8 characters';
        if (input.dataset.match) {
            const matchEl = document.getElementById(input.dataset.match);
            if (matchEl && value !== matchEl.value) return 'Values do not match';
        }

        return null;
    }

    static getFormData(form) {
        const data = {};
        const formData = new FormData(form);
        for (const [key, value] of formData.entries()) {
            data[key] = value;
        }
        return data;
    }
}

// ============================================
// Formatting Utilities
// ============================================
const Format = {
    currency(amount, currency = 'USD') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: 2
        }).format(amount);
    },

    number(num) {
        return new Intl.NumberFormat('en-US').format(num);
    },

    date(dateStr, options = {}) {
        const defaults = { year: 'numeric', month: 'short', day: 'numeric' };
        return new Date(dateStr).toLocaleDateString('en-US', { ...defaults, ...options });
    },

    dateTime(dateStr) {
        return new Date(dateStr).toLocaleString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    },

    relative(dateStr) {
        const diff = Date.now() - new Date(dateStr).getTime();
        const mins = Math.floor(diff / 60000);
        if (mins < 1) return 'Just now';
        if (mins < 60) return `${mins}m ago`;
        const hours = Math.floor(mins / 60);
        if (hours < 24) return `${hours}h ago`;
        const days = Math.floor(hours / 24);
        if (days < 7) return `${days}d ago`;
        return Format.date(dateStr);
    },

    accountNumber(num) {
        return num ? '••••' + num.slice(-4) : '';
    },

    percentage(value) {
        return (value * 100).toFixed(2) + '%';
    },

    initials(firstName, lastName) {
        return ((firstName?.[0] || '') + (lastName?.[0] || '')).toUpperCase();
    }
};

// ============================================
// Sidebar & Navigation
// ============================================
function initSidebar() {
    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    if (toggle && sidebar) {
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay?.classList.toggle('active');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar?.classList.remove('open');
            overlay.classList.remove('active');
        });
    }

    // Mark active nav item
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(item => {
        if (item.getAttribute('href') === currentPath) {
            item.classList.add('active');
        }
    });
}

// ============================================
// Data Tables
// ============================================
class DataTable {
    constructor(tableId, options = {}) {
        this.table = document.getElementById(tableId);
        this.options = {
            perPage: options.perPage || 10,
            onPageChange: options.onPageChange || null,
            ...options
        };
        this.currentPage = 1;
        this.totalPages = 1;
    }

    render(data, columns) {
        if (!this.table) return;
        const tbody = this.table.querySelector('tbody') || this.table.createTBody();
        tbody.innerHTML = '';

        data.forEach(row => {
            const tr = document.createElement('tr');
            columns.forEach(col => {
                const td = document.createElement('td');
                td.innerHTML = col.render ? col.render(row[col.key], row) : this.escapeHtml(row[col.key] ?? '');
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    updatePagination(total, perPage) {
        this.totalPages = Math.ceil(total / perPage);
        const container = this.table.parentElement.querySelector('.pagination');
        if (!container) return;

        container.innerHTML = '';
        for (let i = 1; i <= this.totalPages; i++) {
            const btn = document.createElement('button');
            btn.className = `btn btn-sm ${i === this.currentPage ? 'btn-primary' : 'btn-ghost'}`;
            btn.textContent = i;
            btn.addEventListener('click', () => {
                this.currentPage = i;
                if (this.options.onPageChange) this.options.onPageChange(i);
            });
            container.appendChild(btn);
        }
    }
}

// ============================================
// Initialize Application
// ============================================
const api = new ApiClient();
const theme = new ThemeManager();
const toast = new ToastManager();

document.addEventListener('DOMContentLoaded', () => {
    api.loadTokens();
    api.onUnauthorized = () => {
        toast.warning('Session expired. Please log in again.');
        setTimeout(() => window.location.href = '/index.html', 1500);
    };

    ModalManager.init();
    initSidebar();

    // Theme toggle buttons
    document.querySelectorAll('.theme-toggle').forEach(btn => {
        btn.addEventListener('click', () => theme.toggle());
    });

    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(alert => {
        const close = alert.querySelector('.alert-close');
        if (close) close.addEventListener('click', () => alert.remove());
    });
});
