/**
 * EarnSphere - Main Application JavaScript
 * Mobile-first interactions, AJAX handlers, and utility functions
 */

'use strict';

const App = {
    baseUrl: window.location.origin,
    
    /**
     * Initialize application
     */
    init() {
        this.initCSRF();
        this.initTooltips();
        this.initCopyButtons();
        this.initPasswordToggle();
        this.initFormHandlers();
        this.initBottomNav();
        this.initPullToRefresh();
    },
    
    /**
     * Setup CSRF token for AJAX requests
     */
    initCSRF() {
        const token = document.querySelector('meta[name="csrf-token"]');
        if (token) {
            this.csrfToken = token.content;
        }
        
        // Intercept all fetch requests and add CSRF token header
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            if (typeof url === 'string' && App.csrfToken) {
                options.headers = options.headers || {};
                if (!(options.headers instanceof Headers) || !options.headers.has('X-CSRF-Token')) {
                    if (options.headers instanceof Headers) {
                        options.headers.set('X-CSRF-Token', App.csrfToken);
                    } else {
                        options.headers['X-CSRF-Token'] = App.csrfToken;
                    }
                }
            }
            return originalFetch.call(this, url, options);
        };
    },
    
    /**
     * Initialize Bootstrap tooltips
     */
    initTooltips() {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    },
    
    /**
     * Copy to clipboard functionality
     */
    initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const target = btn.getAttribute('data-copy');
                const text = document.querySelector(target)?.value || 
                             document.querySelector(target)?.textContent || 
                             target;
                
                this.copyToClipboard(text.trim()).then(() => {
                    this.showToast('Imenakiliwa!', 'success');
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check me-1"></i>Imenakiliwa';
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.classList.remove('btn-success');
                    }, 2000);
                });
            });
        });
    },
    
    /**
     * Copy text to clipboard
     */
    async copyToClipboard(text) {
        if (navigator.clipboard) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    },
    
    /**
     * Password visibility toggle
     */
    initPasswordToggle() {
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.parentElement.querySelector('input');
                if (input) {
                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    btn.innerHTML = isPassword ? 
                        '<i class="fas fa-eye-slash"></i>' : 
                        '<i class="fas fa-eye"></i>';
                }
            });
        });
    },
    
    /**
     * AJAX Form Handler
     */
    initFormHandlers() {
        document.querySelectorAll('form[data-ajax]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                await this.submitForm(form);
            });
        });
    },
    
    /**
     * Submit form via AJAX
     */
    async submitForm(form) {
        const btn = form.querySelector('[type="submit"]');
        const originalText = btn?.innerHTML;
        
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Inatuma...';
        }
        
        try {
            const formData = new FormData(form);
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showToast(data.message || 'Imefanikiwa!', 'success');
                
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 500);
                }
                
                if (data.reload) {
                    setTimeout(() => window.location.reload(), 500);
                }
            } else {
                this.showToast(data.message || data.errors?.[0] || 'Hitilafu imetokea', 'error');
                
                // Show field errors
                if (data.errors) {
                    this.showFieldErrors(form, data.errors);
                }
            }
        } catch (error) {
            console.error('Form submission error:', error);
            this.showToast('Hitilafu ya mtandao. Tafadhali jaribu tena.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    },
    
    /**
     * Show field validation errors
     */
    showFieldErrors(form, errors) {
        // Clear previous errors
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
        
        if (typeof errors === 'object' && !Array.isArray(errors)) {
            Object.entries(errors).forEach(([field, message]) => {
                const input = form.querySelector(`[name="${field}"]`);
                if (input) {
                    input.classList.add('is-invalid');
                    const feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback';
                    feedback.textContent = message;
                    input.parentElement.appendChild(feedback);
                }
            });
        }
    },
    
    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle',
        };
        
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6',
        };
        
        const toast = document.createElement('div');
        toast.className = 'custom-toast';
        toast.innerHTML = `
            <i class="fas fa-${icons[type]}" style="color: ${colors[type]}; font-size: 1.25rem;"></i>
            <span style="flex: 1; font-size: 0.9rem; font-weight: 600;">${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none;border:none;color:#9ca3af;padding:4px;">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },
    
    /**
     * Initialize bottom navigation active state
     */
    initBottomNav() {
        const currentPath = window.location.pathname.split('/').pop() || 'index.php';
        document.querySelectorAll('.mobile-nav .nav-item').forEach(item => {
            const href = item.getAttribute('href');
            if (href) {
                const itemPath = href.split('/').pop();
                if (itemPath === currentPath) {
                    item.classList.add('active');
                }
            }
        });
    },
    
    /**
     * AJAX data fetcher
     */
    async fetchData(endpoint, params = {}) {
        const url = new URL(this.baseUrl + endpoint);
        Object.entries(params).forEach(([key, val]) => url.searchParams.set(key, val));
        
        const response = await fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        
        return response.json();
    },
    
    /**
     * Confirm action dialog
     */
    confirm(message = 'Una uhakika?') {
        return new Promise(resolve => {
            if (window.confirm(message)) {
                resolve(true);
            } else {
                resolve(false);
            }
        });
    },
    
    /**
     * Format currency
     */
    formatCurrency(amount) {
        return 'TZS ' + Number(amount).toLocaleString('en-US');
    },
    
    /**
     * Initialize pull-to-refresh (mobile)
     */
    initPullToRefresh() {
        let startY = 0;
        let pulling = false;
        
        document.addEventListener('touchstart', (e) => {
            if (window.scrollY === 0) {
                startY = e.touches[0].clientY;
                pulling = true;
            }
        });
        
        document.addEventListener('touchmove', (e) => {
            if (!pulling) return;
            const diff = e.touches[0].clientY - startY;
            if (diff > 100 && window.scrollY === 0) {
                pulling = false;
                // Could trigger refresh here
            }
        });
        
        document.addEventListener('touchend', () => {
            pulling = false;
        });
    },
    
    /**
     * Check payment status (polling)
     */
    async checkPaymentStatus(orderId, callback) {
        const maxAttempts = 60;
        let attempts = 0;
        
        const check = async () => {
            if (attempts >= maxAttempts) {
                callback({ status: 'timeout' });
                return;
            }
            
            try {
                const data = await this.fetchData('/api/check_payment.php', { order_id: orderId });
                
                if (data.status === 'completed') {
                    callback({ status: 'completed', data });
                } else if (data.status === 'failed') {
                    callback({ status: 'failed', data });
                } else {
                    attempts++;
                    setTimeout(check, 3000);
                }
            } catch (err) {
                attempts++;
                setTimeout(check, 5000);
            }
        };
        
        check();
    },
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => App.init());
