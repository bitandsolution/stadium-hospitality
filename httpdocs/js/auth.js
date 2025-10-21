/**
 * Authentication Manager
 * Handles login, logout, token management, and session persistence
 */

const Auth = {
    
    /**
     * Initialize authentication system
     */
    init() {
        console.log('[AUTH] Initializing authentication system');
        
        // Check if already logged in
        if (this.isAuthenticated()) {
            console.log('[AUTH] User already authenticated');
        }
    },
    
    /**
     * Login user
     */
    async login(username, password, stadiumId = null) {
        try {
            console.log('[AUTH] Login attempt:', { username, stadiumId });
            
            const API_BASE_URL = typeof CONFIG !== 'undefined' ? CONFIG.API_BASE_URL : 'https://checkindigitale.cloud/api';
            
            const payload = { username, password };
            if (stadiumId) {
                payload.stadium_id = parseInt(stadiumId);
            }
            
            console.log('[AUTH] Calling API:', `${API_BASE_URL}/auth/login`);
            
            const response = await fetch(`${API_BASE_URL}/auth/login`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            console.log('[AUTH] Response status:', response.status);
            
            const data = await response.json();
            console.log('[AUTH] Response data:', data);
            
            if (data.success) {
                localStorage.setItem('access_token', data.data.tokens.access_token);
                localStorage.setItem('refresh_token', data.data.tokens.refresh_token);
                
                // Store user info con i dati completi dall'API
                const userData = {
                    user: data.data.user,
                    permissions: data.data.permissions || [],
                    role_specific_data: data.data.role_specific_data || {
                        view_type: data.data.user.role === 'hostess' ? 'hostess_checkin' : 
                                  data.data.user.role === 'stadium_admin' ? 'admin_dashboard' : 
                                  'super_admin_dashboard'
                    },
                    assigned_rooms: data.data.assigned_rooms || []
                };
                
                localStorage.setItem('user_data', JSON.stringify(userData));
                
                // Mantieni anche i vecchi nomi per retrocompatibilità
                localStorage.setItem('hm_access_token', data.data.tokens.access_token);
                localStorage.setItem('hm_refresh_token', data.data.tokens.refresh_token);
                localStorage.setItem('hm_user', JSON.stringify(data.data.user));
                localStorage.setItem('hm_permissions', JSON.stringify(data.data.permissions || []));
                
                console.log('[AUTH] Login successful, data saved to localStorage');
                
                return { success: true, user: data.data.user };
            } else {
                console.warn('[AUTH] Login failed:', data.message);
                return { success: false, message: data.message };
            }
            
        } catch (error) {
            console.error('[AUTH] Login error:', error);
            return { 
                success: false, 
                message: 'Errore di connessione: ' + error.message
            };
        }
    },
    
    /**
     * Logout user
     */
    async logout() {
        try {
            const token = this.getToken();
            const refreshToken = this.getRefreshToken();
            
            const API_BASE_URL = typeof CONFIG !== 'undefined' ? CONFIG.API_BASE_URL : 'https://checkindigitale.cloud/api';
            
            if (token && refreshToken) {
                // Call logout API to blacklist tokens
                await fetch(`${API_BASE_URL}/auth/logout`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({
                        refresh_token: refreshToken
                    })
                });
            }
            
        } catch (error) {
            console.error('[AUTH] Logout API error:', error);
        } finally {
            // ✅ FIXED: Clear localStorage invece di sessionStorage
            localStorage.removeItem('access_token');
            localStorage.removeItem('refresh_token');
            localStorage.removeItem('user_data');
            localStorage.removeItem('hm_access_token');
            localStorage.removeItem('hm_refresh_token');
            localStorage.removeItem('hm_user');
            localStorage.removeItem('hm_permissions');
            
            console.log('[AUTH] Logged out successfully');
            
            // Redirect to login
            window.location.href = 'login.html';
        }
    },

    /**
     * Get current user info from API
     */
    async getCurrentUser() {
        try {
            console.log('[AUTH] Fetching current user...');
            
            const API_BASE_URL = typeof CONFIG !== 'undefined' ? CONFIG.API_BASE_URL : 'https://checkindigitale.cloud/api';
            
            const response = await fetch(`${API_BASE_URL}/auth/me`, {
                method: 'GET',
                headers: {
                    'Authorization': this.getAuthHeader(),
                    'Content-Type': 'application/json'
                }
            });
            
            console.log('[AUTH] Response status:', response.status);
            
            if (!response.ok) {
                console.error('[AUTH] Failed to get current user:', response.status);
                
                if (response.status === 401) {
                    console.log('[AUTH] Token invalid/expired, logging out...');
                    this.logout();
                }
                
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            console.log('[AUTH] Raw API response:', data);
            
            // Intelligent format detection
            let normalizedData = null;
            
            if (data.success === true && data.data) {
                console.log('[AUTH] Detected nested format with success flag');
                
                normalizedData = {
                    user: data.data.user,
                    permissions: data.data.permissions || [],
                    session_info: data.data.session_info || null,
                    role_specific_data: data.data.role_specific_data || null,
                    assigned_rooms: data.data.assigned_rooms || []
                };
            } else if (data.user) {
                console.log('[AUTH] Detected flat format');
                normalizedData = {
                    user: data.user,
                    permissions: data.permissions || [],
                    session_info: data.session_info || null,
                    role_specific_data: data.role_specific_data || null,
                    assigned_rooms: data.assigned_rooms || []
                };
            } else {
                console.warn('[AUTH] Using fallback format detection');
                normalizedData = {
                    user: data,
                    permissions: [],
                    session_info: null,
                    role_specific_data: null,
                    assigned_rooms: []
                };
            }
            
            if (!normalizedData || !normalizedData.user || !normalizedData.user.id) {
                console.error('[AUTH] Invalid user data');
                throw new Error('Invalid response format');
            }
            
            // ✅ Salva anche in localStorage per consistenza
            localStorage.setItem('user_data', JSON.stringify(normalizedData));
            
            console.log('[AUTH] User loaded successfully:', {
                id: normalizedData.user.id,
                username: normalizedData.user.username,
                role: normalizedData.user.role,
                stadium_id: normalizedData.user.stadium_id,
                view_type: normalizedData.role_specific_data?.view_type
            });
            
            return normalizedData;
            
        } catch (error) {
            console.error('[AUTH] Get current user error:', error);
            throw error;
        }
    },
    
    /**
     * Check if user is authenticated
     */
    isAuthenticated() {
        const token = this.getToken();
        const user = this.getUser();
        
        return !!(token && user);
    },
    
    /**
     * Get access token
     */
    getToken() {
        // ✅ FIXED: Leggi da localStorage invece di sessionStorage
        return localStorage.getItem('access_token') || localStorage.getItem('hm_access_token');
    },
    
    /**
     * Get refresh token
     */
    getRefreshToken() {
        // ✅ FIXED: Leggi da localStorage invece di sessionStorage
        return localStorage.getItem('refresh_token') || localStorage.getItem('hm_refresh_token');
    },
    
    /**
     * Get current user
     */
    getUser() {
        // Prova prima il nuovo formato
        let userJson = localStorage.getItem('user_data');
        if (userJson) {
            try {
                const userData = JSON.parse(userJson);
                return userData.user || userData;
            } catch (error) {
                console.error('[AUTH] Failed to parse user_data:', error);
            }
        }
        
        // Fallback al vecchio formato
        userJson = localStorage.getItem('hm_user');
        if (!userJson) return null;
        
        try {
            return JSON.parse(userJson);
        } catch (error) {
            console.error('[AUTH] Failed to parse user data:', error);
            return null;
        }
    },
    
    /**
     * Get user permissions
     */
    getPermissions() {
        // Prova prima dal nuovo formato
        const userDataJson = localStorage.getItem('user_data');
        if (userDataJson) {
            try {
                const userData = JSON.parse(userDataJson);
                if (userData.permissions) {
                    return userData.permissions;
                }
            } catch (error) {
                console.error('[AUTH] Failed to parse user_data:', error);
            }
        }
        
        // Fallback al vecchio formato
        const permissionsJson = localStorage.getItem('hm_permissions');
        if (!permissionsJson) return [];
        
        try {
            return JSON.parse(permissionsJson);
        } catch (error) {
            console.error('[AUTH] Failed to parse permissions:', error);
            return [];
        }
    },
    
    /**
     * Check if user has permission
     */
    hasPermission(permission) {
        const permissions = this.getPermissions();
        return permissions.includes(permission);
    },
    
    /**
     * Check if user has role
     */
    hasRole(role) {
        const user = this.getUser();
        return user && user.role === role;
    },
    
    /**
     * Get user role
     */
    getRole() {
        const user = this.getUser();
        return user ? user.role : null;
    },
    
    /**
     * Get stadium ID
     */
    getStadiumId() {
        const user = this.getUser();
        return user ? user.stadium_id : null;
    },
    
    /**
     * Require authentication
     * Redirect to login if not authenticated
     */
    requireAuth() {
        if (!this.isAuthenticated()) {
            console.warn('[AUTH] Authentication required, redirecting to login');
            window.location.href = 'login.html';
            return false;
        }
        return true;
    },
    
    /**
     * Get authorization header
     */
    getAuthHeader() {
        const token = this.getToken();
        return token ? `Bearer ${token}` : null;
    }
};

// Initialize auth system on load
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        Auth.init();
    });
}

console.log('[AUTH] Auth module loaded successfully');