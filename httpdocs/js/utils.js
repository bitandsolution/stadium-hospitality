/**
 * Utility Functions
 * Common helper functions used across the application
 */

const Utils = {
    
    /**
     * Format date
     */
    formatDate(dateString, format = CONFIG.DATE_FORMAT) {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return '-';
        
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        switch (format) {
            case 'DD/MM/YYYY':
                return `${day}/${month}/${year}`;
            case 'HH:mm':
                return `${hours}:${minutes}`;
            case 'DD/MM/YYYY HH:mm':
                return `${day}/${month}/${year} ${hours}:${minutes}`;
            default:
                return `${day}/${month}/${year}`;
        }
    },
    
    /**
     * Format time
     */
    formatTime(timeString) {
        if (!timeString) return '-';
        
        // Handle HH:mm:ss format
        const parts = timeString.split(':');
        if (parts.length >= 2) {
            return `${parts[0]}:${parts[1]}`;
        }
        
        return timeString;
    },
    
    /**
     * Get VIP level badge HTML
     */
    getVipLevelBadge(vipLevel) {
        const colors = {
            standard: 'bg-gray-100 text-gray-800',
            premium: 'bg-blue-100 text-blue-800',
            vip: 'bg-purple-100 text-purple-800',
            ultra_vip: 'bg-yellow-100 text-yellow-800'
        };
        
        const labels = CONFIG.VIP_LEVEL_LABELS;
        const color = colors[vipLevel] || colors.standard;
        const label = labels[vipLevel] || vipLevel;
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${color}">${label}</span>`;
    },
    
    /**
     * Get access status badge HTML
     */
    getAccessStatusBadge(status) {
        if (status === 'checked_in') {
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><i data-lucide="check-circle" class="w-3 h-3 mr-1"></i>Check-in</span>';
        } else {
            return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Attesa</span>';
        }
    },
    
    /**
     * Get role label
     */
    getRoleLabel(role) {
        const labels = {
            super_admin: 'Super Admin',
            stadium_admin: 'Stadium Admin',
            hostess: 'Hostess'
        };
        return labels[role] || role;
    },
    
    /**
     * Get role badge HTML
     */
    getRoleBadge(role) {
        const colors = {
            super_admin: 'bg-red-100 text-red-800',
            stadium_admin: 'bg-purple-100 text-purple-800',
            hostess: 'bg-blue-100 text-blue-800'
        };
        
        const color = colors[role] || 'bg-gray-100 text-gray-800';
        const label = this.getRoleLabel(role);
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${color}">${label}</span>`;
    },
    
    /**
     * Debounce function
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    /**
     * Show toast notification
     */
    showToast(message, type = 'info', duration = 3000) {
        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            warning: 'bg-yellow-500',
            info: 'bg-blue-500'
        };
        
        const icons = {
            success: 'check-circle',
            error: 'alert-circle',
            warning: 'alert-triangle',
            info: 'info'
        };
        
        const color = colors[type] || colors.info;
        const icon = icons[type] || icons.info;
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 ${color} text-white px-6 py-4 rounded-lg shadow-lg flex items-center space-x-3 z-50 transform transition-all duration-300 translate-x-full`;
        toast.innerHTML = `
            <i data-lucide="${icon}" class="w-5 h-5"></i>
            <span class="font-medium">${message}</span>
        `;
        
        document.body.appendChild(toast);
        lucide.createIcons();
        
        // Slide in
        setTimeout(() => {
            toast.classList.remove('translate-x-full');
        }, 100);
        
        // Slide out and remove
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => {
                document.body.removeChild(toast);
            }, 300);
        }, duration);
    },
    
    /**
     * Show confirmation dialog
     */
    async confirm(message, title = 'Conferma') {
        return new Promise((resolve) => {
            const result = window.confirm(`${title}\n\n${message}`);
            resolve(result);
        });
    },
    
    /**
     * Copy to clipboard
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showToast('Copiato negli appunti', 'success');
            return true;
        } catch (error) {
            console.error('Failed to copy:', error);
            this.showToast('Errore durante la copia', 'error');
            return false;
        }
    },
    
    /**
     * Download file
     */
    downloadFile(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },
    
    /**
     * Format number with thousands separator
     */
    formatNumber(number) {
        if (number === null || number === undefined) return '0';
        return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    },
    
    /**
     * Validate email
     */
    isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },
    
    /**
     * Validate phone
     */
    isValidPhone(phone) {
        const re = /^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,9}$/;
        return re.test(phone);
    },
    
    /**
     * Truncate text
     */
    truncate(text, maxLength = 50) {
        if (!text) return '';
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    },
    
    /**
     * Get initials from name
     */
    getInitials(name) {
        if (!name) return '?';
        const parts = name.trim().split(' ');
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    },
    
    /**
     * Parse query string
     */
    parseQueryString() {
        const params = new URLSearchParams(window.location.search);
        const result = {};
        for (const [key, value] of params) {
            result[key] = value;
        }
        return result;
    },
    
    /**
     * Build query string
     */
    buildQueryString(params) {
        const filtered = Object.entries(params)
            .filter(([_, value]) => value !== null && value !== undefined && value !== '')
            .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
            .join('&');
        return filtered ? '?' + filtered : '';
    },
    
    /**
     * Show loading overlay
     */
    showLoading(message = 'Caricamento...') {
        let overlay = document.getElementById('loadingOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            overlay.innerHTML = `
                <div class="bg-white rounded-lg p-8 flex flex-col items-center space-y-4">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-600"></div>
                    <p class="text-gray-700 font-medium">${message}</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        overlay.classList.remove('hidden');
    },
    
    /**
     * Hide loading overlay
     */
    hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.classList.add('hidden');
        }
    },
    
    /**
     * Handle API errors
     */
    handleApiError(error, defaultMessage = 'Si Ã¨ verificato un errore') {
        console.error('API Error:', error);
        
        let message = defaultMessage;
        
        if (error.message) {
            message = error.message;
        } else if (typeof error === 'string') {
            message = error;
        }
        
        this.showToast(message, 'error', 5000);
    },
    
    /**
     * Validate file type
     */
    isValidFileType(filename, allowedTypes = CONFIG.ALLOWED_FILE_TYPES) {
        const extension = '.' + filename.split('.').pop().toLowerCase();
        return allowedTypes.includes(extension);
    },
    
    /**
     * Validate file size
     */
    isValidFileSize(fileSize, maxSize = CONFIG.MAX_FILE_SIZE) {
        return fileSize <= maxSize;
    },
    
    /**
     * Format file size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },
    
    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Generate random ID
     */
    generateId() {
        return 'id_' + Math.random().toString(36).substr(2, 9);
    }
};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Utils;
}