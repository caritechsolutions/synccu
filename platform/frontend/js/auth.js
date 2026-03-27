// SyncCU Platform - Authentication Module

class AuthManager {
    constructor(apiClient) {
        this.api = apiClient;
        this.user = null;
        this.tokenCheckInterval = null;
    }

    async login(email, password, rememberMe = false) {
        const response = await this.api.post('/auth/login', { email, password });

        this.api.setTokens(response.access_token, response.refresh_token);
        this.user = response.user;

        if (rememberMe) {
            localStorage.setItem('remembered_email', email);
        } else {
            localStorage.removeItem('remembered_email');
        }

        this.startTokenCheck();
        return response;
    }

    async register(userData) {
        const response = await this.api.post('/auth/register', userData);
        return response;
    }

    async logout() {
        try {
            await this.api.post('/auth/logout');
        } catch (e) {
            // Continue with local logout even if server call fails
        }
        this.clearSession();
        window.location.href = '/index.html';
    }

    async getCurrentUser() {
        if (this.user) return this.user;
        try {
            this.user = await this.api.get('/auth/me');
            return this.user;
        } catch {
            return null;
        }
    }

    async changePassword(currentPassword, newPassword) {
        return await this.api.post('/auth/change-password', {
            current_password: currentPassword,
            new_password: newPassword
        });
    }

    async forgotPassword(email) {
        return await this.api.post('/auth/forgot-password', { email });
    }

    isAuthenticated() {
        return !!this.api.accessToken;
    }

    getRole() {
        if (!this.api.accessToken) return null;
        try {
            const payload = JSON.parse(atob(this.api.accessToken.split('.')[1]));
            return payload.role;
        } catch { return null; }
    }

    requireAuth(allowedRoles = null) {
        if (!this.isAuthenticated()) {
            window.location.href = '/index.html';
            return false;
        }
        if (allowedRoles) {
            const role = this.getRole();
            if (!allowedRoles.includes(role)) {
                toast.error('Access denied');
                window.location.href = '/index.html';
                return false;
            }
        }
        return true;
    }

    clearSession() {
        this.api.clearTokens();
        this.user = null;
        if (this.tokenCheckInterval) {
            clearInterval(this.tokenCheckInterval);
            this.tokenCheckInterval = null;
        }
    }

    startTokenCheck() {
        if (this.tokenCheckInterval) clearInterval(this.tokenCheckInterval);
        this.tokenCheckInterval = setInterval(() => {
            if (!this.api.accessToken) return;
            try {
                const payload = JSON.parse(atob(this.api.accessToken.split('.')[1]));
                const expiresIn = (payload.exp * 1000) - Date.now();
                if (expiresIn < 60000) { // Less than 1 minute
                    this.api.refreshAccessToken();
                }
            } catch {
                this.clearSession();
            }
        }, 30000); // Check every 30 seconds
    }

    getRememberedEmail() {
        return localStorage.getItem('remembered_email') || '';
    }
}

// Password Strength Meter
class PasswordStrength {
    static calculate(password) {
        let score = 0;
        if (!password) return { score: 0, label: '', class: '' };

        if (password.length >= 8) score += 1;
        if (password.length >= 12) score += 1;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score += 1;
        if (/\d/.test(password)) score += 1;
        if (/[^a-zA-Z0-9]/.test(password)) score += 1;

        const levels = [
            { label: 'Very Weak', class: 'strength-very-weak' },
            { label: 'Weak', class: 'strength-weak' },
            { label: 'Fair', class: 'strength-fair' },
            { label: 'Strong', class: 'strength-strong' },
            { label: 'Very Strong', class: 'strength-very-strong' }
        ];

        const level = levels[Math.min(score, 4)];
        return { score, ...level, percent: (score / 5) * 100 };
    }

    static attachTo(inputId, meterId) {
        const input = document.getElementById(inputId);
        const meter = document.getElementById(meterId);
        if (!input || !meter) return;

        input.addEventListener('input', () => {
            const result = PasswordStrength.calculate(input.value);
            meter.innerHTML = `
                <div class="strength-bar">
                    <div class="strength-fill ${result.class}" style="width:${result.percent}%"></div>
                </div>
                <span class="strength-label ${result.class}">${result.label}</span>
            `;
        });
    }
}

// Initialize auth
const auth = new AuthManager(api);

// Login form handler
document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        // Pre-fill remembered email
        const emailInput = loginForm.querySelector('[name="email"]');
        if (emailInput) emailInput.value = auth.getRememberedEmail();

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const submitBtn = loginForm.querySelector('button[type="submit"]');
            const email = loginForm.querySelector('[name="email"]').value.trim();
            const password = loginForm.querySelector('[name="password"]').value;
            const remember = loginForm.querySelector('[name="remember"]')?.checked || false;

            if (!email || !password) {
                toast.error('Please enter email and password');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner spinner-sm"></span> Signing in...';

            try {
                const response = await auth.login(email, password, remember);
                toast.success('Login successful!');

                // Redirect based on role
                const role = auth.getRole();
                setTimeout(() => {
                    if (['super_admin', 'admin', 'manager', 'teller'].includes(role)) {
                        window.location.href = '/admin/dashboard.html';
                    } else {
                        window.location.href = '/portal/dashboard.html';
                    }
                }, 500);
            } catch (error) {
                toast.error(error.message || 'Invalid email or password');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Sign In';
            }
        });
    }

    // Password strength meter on registration/change forms
    PasswordStrength.attachTo('new-password', 'password-strength');
    PasswordStrength.attachTo('reg-password', 'reg-password-strength');
});
