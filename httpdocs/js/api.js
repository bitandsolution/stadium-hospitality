/**
 * API Client Wrapper
 * Centralized API communication with automatic token refresh and error handling
 */

const API = {
    
    /**
     * Make authenticated API request
     */
    async request(endpoint, options = {}) {
        const url = `${CONFIG.API_BASE_URL}${endpoint}`;
        
        // Default options
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        };
        
        // Add authorization header if user is authenticated
        if (Auth.isAuthenticated()) {
            defaultOptions.headers['Authorization'] = Auth.getAuthHeader();
        }
        
        // Merge options
        const finalOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };
        
        if (CONFIG.DEBUG) {
            console.log(`[API] ${finalOptions.method} ${url}`, finalOptions);
        }
        
        try {
            const response = await fetch(url, finalOptions);
            const data = await response.json();
            
            // Handle 401 Unauthorized - try to refresh token
            if (response.status === 401 && Auth.isAuthenticated()) {
                if (CONFIG.DEBUG) {
                    console.log('[API] Token expired, attempting refresh...');
                }
                
                try {
                    await Auth.refreshToken();
                    // Retry original request with new token
                    finalOptions.headers['Authorization'] = Auth.getAuthHeader();
                    const retryResponse = await fetch(url, finalOptions);
                    return await retryResponse.json();
                } catch (refreshError) {
                    console.error('[API] Token refresh failed:', refreshError);
                    Auth.logout();
                    throw new Error('Session expired. Please login again.');
                }
            }
            
            if (CONFIG.DEBUG) {
                console.log(`[API] Response:`, data);
            }
            
            return data;
            
        } catch (error) {
            console.error('[API] Request failed:', error);
            throw error;
        }
    },
    
    /**
     * GET request
     */
    async get(endpoint, params = {}) {
        // Build query string
        const queryString = new URLSearchParams(params).toString();
        const fullEndpoint = queryString ? `${endpoint}?${queryString}` : endpoint;
        
        return this.request(fullEndpoint, { method: 'GET' });
    },
    
    /**
     * POST request
     */
    async post(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },
    
    /**
     * PUT request
     */
    async put(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },
    
    /**
     * DELETE request
     */
    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    },
    
    /**
     * Upload file
     */
    async uploadFile(endpoint, file, additionalData = {}) {
        const formData = new FormData();
        formData.append('file', file);
        
        // Add additional fields
        for (const [key, value] of Object.entries(additionalData)) {
            formData.append(key, value);
        }
        
        const url = `${CONFIG.API_BASE_URL}${endpoint}`;
        
        const options = {
            method: 'POST',
            headers: {
                'Authorization': Auth.getAuthHeader()
            },
            body: formData
        };
        
        if (CONFIG.DEBUG) {
            console.log(`[API] Uploading file to ${url}`, file.name);
        }
        
        try {
            const response = await fetch(url, options);
            const data = await response.json();
            
            if (CONFIG.DEBUG) {
                console.log(`[API] Upload response:`, data);
            }
            
            return data;
            
        } catch (error) {
            console.error('[API] Upload failed:', error);
            throw error;
        }
    },
    
    // ===================================
    // API ENDPOINTS - Authentication
    // ===================================
    
    auth: {
        me: () => API.get('/auth/me'),
        changePassword: (currentPassword, newPassword) => 
            API.post('/auth/change-password', { current_password: currentPassword, new_password: newPassword })
    },
    
    // ===================================
    // API ENDPOINTS - Stadiums
    // ===================================
    
    stadiums: {
        list: () => API.get('/admin/stadiums'),
        get: (id) => API.get(`/admin/stadiums/${id}`),
        create: (data) => API.post('/admin/stadiums', data),
        update: (id, data) => API.put(`/admin/stadiums/${id}`, data),
        delete: (id) => API.delete(`/admin/stadiums/${id}`)
    },
    
    // ===================================
    // API ENDPOINTS - Rooms
    // ===================================
    
    rooms: {
        list: (stadiumId) => API.get('/admin/rooms', { stadium_id: stadiumId }),
        get: (id) => API.get(`/admin/rooms/${id}`),
        create: (data) => API.post('/admin/rooms', data),
        update: (id, data) => API.put(`/admin/rooms/${id}`, data),
        delete: (id) => API.delete(`/admin/rooms/${id}`)
    },
    
    // ===================================
    // API ENDPOINTS - Events
    // ===================================
    
    events: {
        /**
         * Create new event
         */
        create: async (data) => {
            return await API.post('/admin/events', data);
        },
        
        /**
         * List events for stadium
         */
        list: async (stadiumId, includeInactive = false) => {
            const params = { stadium_id: stadiumId };
            if (includeInactive) {
                params.include_inactive = '1';
            }
            return await API.get('/admin/events', params);
        },
        
        /**
         * Get upcoming events
         */
        upcoming: async (stadiumId, limit = 10) => {
            return await API.get('/admin/events/upcoming', { stadium_id: stadiumId, limit });
        },
        
        /**
         * Get event details
         */
        get: async (eventId) => {
            return await API.get(`/admin/events/${eventId}`);
        },
        
        /**
         * Update event
         */
        update: async (eventId, data) => {
            return await API.put(`/admin/events/${eventId}`, data);
        },
        
        /**
         * Delete event
         */
        delete: async (eventId) => {
            return await API.delete(`/admin/events/${eventId}`);
        }
    },
    
    // ===================================
    // API ENDPOINTS - Users
    // ===================================
    
    users: {
        list: (stadiumId, role = null) => {
            const params = { stadium_id: stadiumId };
            if (role) params.role = role;
            return API.get('/admin/users', params);
        },
        get: (id) => API.get(`/admin/users/${id}`),
        create: (data) => API.post('/admin/users', data),
        update: (id, data) => API.put(`/admin/users/${id}`, data),
        delete: (id) => API.delete(`/admin/users/${id}`),
        
        // Room assignments
        getRooms: (userId) => API.get(`/admin/users/${userId}/rooms`),
        assignRooms: (userId, roomIds) => API.post(`/admin/users/${userId}/rooms`, { room_ids: roomIds }),
        removeRoom: (userId, roomId) => API.delete(`/admin/users/${userId}/rooms/${roomId}`)
    },
      
    // ===================================
    // API ENDPOINTS - Guests
    // ===================================
    
    guests: {
        /**
         * Search guests with filters
         */
        search: async (params = {}) => {
            return await API.get('/guests/search', params);
        },
        
        /**
         * Quick search autocomplete
         */
        quickSearch: async (query, stadiumId = null) => {
            const params = { q: query };
            if (stadiumId) params.stadium_id = stadiumId;
            return await API.get('/guests/quick-search', params);
        },
        
        /**
         * Get guest by ID
         */
        get: async (id) => {  // ✅ Rinominato da getById
            return await API.get(`/guests/${id}`);
        },
        
        /**
         * Update guest
         */
        update: async (id, data) => {
            return await API.put(`/guests/${id}`, data);
        },
        
        /**
         * Check-in guest
         */
        checkin: async (id) => {
            return await API.post(`/guests/${id}/checkin`);
        },
        
        /**
         * Check-out guest
         */
        checkout: async (id) => {
            return await API.post(`/guests/${id}/checkout`);
        },
        
        /**
         * Get guest access history
         */
        getAccessHistory: async (id, limit = 50) => {
            return await API.get(`/guests/${id}/access-history`, { limit });
        },
        
        /**
         * Get current guests in room
         */
        getCurrentInRoom: async (roomId) => {
            return await API.get(`/rooms/${roomId}/current-guests`);
        },
        
        /**
         * Admin CRUD operations
         */
        admin: {
            list: async (params = {}) => {
                return await API.get('/admin/guests', params);
            },
            create: async (data) => {
                return await API.post('/admin/guests', data);
            },
            update: async (id, data) => {
                return await API.put(`/admin/guests/${id}`, data);
            },
            delete: async (id) => {
                return await API.delete(`/admin/guests/${id}`);
            },
            
            // Import/Export
            import: async (file, additionalData = {}) => {
                return await API.uploadFile('/admin/guests/import', file, additionalData);
            },
            downloadTemplate: async () => {
                const url = `${CONFIG.API_BASE_URL}/admin/guests/import/template`;
                window.open(url, '_blank');
            }
        }
    },
    
    // ===================================
    // API ENDPOINTS - Dashboard
    // ===================================

    dashboard: { 
        stats: async (stadiumId) => {
            return await API.get('/dashboard/stats', { stadium_id: stadiumId });
        },
        upcomingEvents: async (stadiumId) => {
            return await API.get('/dashboard/upcoming-events', { stadium_id: stadiumId });
        }
    },

    // ===================================
    // API ENDPOINTS - Statistics
    // ===================================

    statistics: {
        summary: async (params) => {
            return await API.get('/statistics/summary', params);
        },
        accessByEvent: async (params) => {
            return await API.get('/statistics/access-by-event', params);
        },
        accessByRoom: async (params) => {
            return await API.get('/statistics/access-by-room', params);
        },
        exportExcel: async (params) => {
            return await API.get('/statistics/export-excel', params);
        }
    },

    // ===================================
    // API ENDPOINTS - System
    // ===================================
    
    system: {
        health: () => API.get('/health')
    }
};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = API;
}