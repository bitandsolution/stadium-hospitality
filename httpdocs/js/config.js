/**
 * Configuration File
 * Global settings for the application
 */

const CONFIG = {
    // API Configuration
    API_BASE_URL: 'https://checkindigitale.cloud/api',
    
    // Session Configuration
    SESSION_KEY: 'hm_session',
    TOKEN_KEY: 'hm_access_token',
    REFRESH_KEY: 'hm_refresh_token',
    USER_KEY: 'hm_user',
    
    // API Timeouts
    REQUEST_TIMEOUT: 30000, // 30 seconds
    
    // Pagination
    DEFAULT_PAGE_SIZE: 50,
    MAX_PAGE_SIZE: 100,
    
    // Guest Search
    SEARCH_DEBOUNCE_MS: 300,
    MIN_SEARCH_CHARS: 2,
    
    // File Upload
    MAX_FILE_SIZE: 10 * 1024 * 1024, // 10MB
    ALLOWED_FILE_TYPES: ['.xlsx', '.xls'],

    // Logo Upload
    MAX_LOGO_SIZE: 2 * 1024 * 1024, // 2MB
    ALLOWED_LOGO_TYPES: ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'],
    ALLOWED_LOGO_EXTENSIONS: ['.png', '.jpg', '.jpeg', '.svg'],
    
    // Roles
    ROLES: {
        SUPER_ADMIN: 'super_admin',
        STADIUM_ADMIN: 'stadium_admin',
        HOSTESS: 'hostess'
    },
    
    // VIP Levels
    VIP_LEVELS: {
        STANDARD: 'standard',
        PREMIUM: 'premium',
        VIP: 'vip',
        ULTRA_VIP: 'ultra_vip'
    },
    
    // VIP Level Labels
    VIP_LEVEL_LABELS: {
        standard: 'Standard',
        premium: 'Premium',
        vip: 'VIP',
        ultra_vip: 'Ultra VIP'
    },
    
    // VIP Level Colors
    VIP_LEVEL_COLORS: {
        standard: 'gray',
        premium: 'blue',
        vip: 'purple',
        ultra_vip: 'yellow'
    },
    
    // Access Status
    ACCESS_STATUS: {
        CHECKED_IN: 'checked_in',
        NOT_CHECKED_IN: 'not_checked_in'
    },
    
    // Date Format
    DATE_FORMAT: 'DD/MM/YYYY',
    TIME_FORMAT: 'HH:mm',
    DATETIME_FORMAT: 'DD/MM/YYYY HH:mm',
    
    // Environment
    IS_DEVELOPMENT: window.location.hostname === 'localhost' || 
                    window.location.hostname === '127.0.0.1',
    
    // Debug
    DEBUG: true,
    
    // Application Info
    APP_NAME: 'Hospitality Manager',
    APP_VERSION: '1.5.0'
};

// Freeze configuration to prevent modifications
Object.freeze(CONFIG);

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CONFIG;
}

// Log configuration in development
if (CONFIG.DEBUG && CONFIG.IS_DEVELOPMENT) {
    console.log('[CONFIG] Application configuration loaded:', CONFIG);
}