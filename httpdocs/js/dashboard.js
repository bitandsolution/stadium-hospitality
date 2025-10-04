/**
 * Dashboard Controller
 * Handles dashboard page logic and data loading
 */

const Dashboard = {
    
    /**
     * Initialize dashboard
     */
    async init() {
        if (CONFIG.DEBUG) {
            console.log('[DASHBOARD] Initializing...');
        }

        const user = Auth.getUser();
        
        // Load user info
        this.loadUserInfo();
        
        // Setup menu visibility based on role
        this.setupMenuVisibility();
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Load dashboard data
        await this.loadDashboardData();
        
        // Load stats
        await this.loadStats(user.stadium_id);

        await this.loadUpcomingEvents(user.stadium_id);

    },
    
    /**
     * Load user info into sidebar
     */
    loadUserInfo() {
        const user = Auth.getUser();
        if (!user) return;
        
        // User name
        document.getElementById('userName').textContent = user.full_name || user.username;
        
        // User role
        document.getElementById('userRole').textContent = Utils.getRoleLabel(user.role);
        
        // User initials
        document.getElementById('userInitials').textContent = Utils.getInitials(user.full_name || user.username);
        
        // Stadium name in header
        const stadiumName = user.stadium_name || 'Multi-Stadio';
        document.getElementById('stadiumName').textContent = stadiumName;
    },

    /**
     * Load statistics
     */
    async loadStats(stadiumId) {
        try {
            console.log('[DASHBOARD] Loading stats for stadium:', stadiumId);
            
            const response = await API.dashboard.stats(stadiumId);
            
            console.log('[DASHBOARD] Stats response:', response);
            
            if (response.success) {
                const stats = response.data;
                
                console.log('[DASHBOARD] Stats data:', stats);
                
                // Update con controlli null
                const elements = {
                    statTotalGuests: stats.total_guests,
                    statCheckedIn: stats.checked_in,
                    statPending: stats.pending,
                    statActiveEvents: stats.active_events,
                    statTotalRooms: stats.total_rooms
                };
                
                for (const [id, value] of Object.entries(elements)) {
                    const element = document.getElementById(id);
                    if (element) {
                        element.textContent = value || 0;
                    } else {
                        console.warn('[DASHBOARD] Element not found:', id);
                    }
                }
                
                console.log('[DASHBOARD] Stats updated successfully');
            } else {
                console.error('[DASHBOARD] Stats response error:', response);
            }
        } catch (error) {
            console.error('[DASHBOARD] Failed to load stats:', error);
        }
    },

    /**
     * Load upcoming events
     */
    async loadUpcomingEvents(stadiumId) {
        try {
            const response = await API.dashboard.upcomingEvents(stadiumId);
            
            if (response.success) {
                this.renderUpcomingEvents(response.data.events);
            } else {
                document.getElementById('upcomingEvents').innerHTML = `
                    <p class="text-sm text-red-500 text-center py-8">Errore: ${response.message}</p>
                `;
            }
        } catch (error) {
            console.error('[DASHBOARD] Failed to load events:', error);
            document.getElementById('upcomingEvents').innerHTML = `
                <p class="text-sm text-red-500 text-center py-8">Errore caricamento eventi</p>
            `;
        }
    },


    renderUpcomingEvents(events) {
        const container = document.getElementById('upcomingEvents');
        
        if (!container) {
            console.error('[DASHBOARD] Container upcomingEvents not found');
            return;
        }
        
        if (!events || events.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                    <i data-lucide="calendar-x" class="w-12 h-12 mx-auto mb-2 text-gray-400"></i>
                    <p>Nessun evento in programma</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }
        
        container.innerHTML = events.map(event => {
            const guestsCount = event.total_guests || 0;
            const roomsCount = event.total_rooms || 0;
            const checkinCount = event.total_checkins || 0;
            
            return `
                <div class="border-l-4 border-purple-500 pl-4 py-3 hover:bg-gray-50 rounded-r transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <h4 class="font-medium text-gray-900">${this.escapeHtml(event.name)}</h4>
                            <p class="text-sm text-gray-600">
                                ${this.formatDate(event.event_date)}
                                ${event.event_time ? ' - ' + event.event_time.substring(0, 5) : ''}
                            </p>
                            ${event.opponent_team ? `<p class="text-sm text-gray-500">vs ${this.escapeHtml(event.opponent_team)}</p>` : ''}
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold text-purple-600">${guestsCount}</p>
                            <p class="text-xs text-gray-500">ospiti</p>
                        </div>
                    </div>
                    <div class="mt-2 flex gap-4 text-sm text-gray-600">
                        <span class="flex items-center">
                            <i data-lucide="door-open" class="w-4 h-4 inline mr-1"></i>
                            ${roomsCount} sale
                        </span>
                        <span class="flex items-center">
                            <i data-lucide="check-circle" class="w-4 h-4 inline mr-1"></i>
                            ${checkinCount} check-in
                        </span>
                    </div>
                </div>
            `;
        }).join('');
        
        lucide.createIcons();
    },
    
    /**
     * Setup menu visibility based on user role
     */
    setupMenuVisibility() {
        const role = Auth.getRole();
        
        // Admin menu (for stadium_admin and super_admin)
        const adminMenu = document.getElementById('adminMenu');
        if (role === CONFIG.ROLES.HOSTESS) {
            adminMenu.style.display = 'none';
        } else {
            adminMenu.style.display = 'block';
        }
        
        // Super admin menu (only for super_admin)
        const superAdminMenu = document.getElementById('superAdminMenu');
        if (role === CONFIG.ROLES.SUPER_ADMIN) {
            superAdminMenu.style.display = 'block';
        } else {
            superAdminMenu.style.display = 'none';
        }
    },
    
    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Logout button
        document.getElementById('logoutBtn').addEventListener('click', async () => {
            const confirmed = await Utils.confirm(
                'Sei sicuro di voler uscire?',
                'Conferma Logout'
            );
            
            if (confirmed) {
                Utils.showLoading('Disconnessione in corso...');
                await Auth.logout();
            }
        });
    },

    /**
     * Format date
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    },

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Load dashboard data
     */
    async loadDashboardData() {
        try {
            const stadiumId = Auth.getStadiumId();
            const role = Auth.getRole();
            
            console.log('[DASHBOARD] Loading data for role:', role, 'stadium:', stadiumId);
            
            // Load different data based on role
            if (role === 'super_admin') {
                await this.loadSuperAdminData();
            } else if (role === 'stadium_admin') {
                await this.loadStadiumAdminData(stadiumId);
            } else if (role === 'hostess') {
                await this.loadHostessData(stadiumId);
            } else {
                console.warn('[DASHBOARD] Unknown role:', role);
                // Show placeholder data
                this.updateStats({ totalGuests: 0, checkedIn: 0, events: 0, rooms: 0 });
            }
            
            // Load upcoming events (with error handling)
            // try {
            //     await this.loadUpcomingEvents(stadiumId);
            // } catch (error) {
            //     console.error('[DASHBOARD] Failed to load events:', error);
            //     // Continue anyway
            // }
            
            // Show content
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('dashboardContent').classList.remove('hidden');
            
            console.log('[DASHBOARD] Dashboard loaded successfully');
            
        } catch (error) {
            console.error('[DASHBOARD] Failed to load data:', error);
            
            // Show content anyway with placeholder data
            this.updateStats({ totalGuests: 0, checkedIn: 0, events: 0, rooms: 0 });
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('dashboardContent').classList.remove('hidden');
            
            // Show warning toast
            if (typeof Utils !== 'undefined' && Utils.showToast) {
                Utils.showToast('Alcuni dati potrebbero non essere disponibili', 'warning', 3000);
            }
        }
    },
    
    /**
     * Load super admin dashboard data
     */
    async loadSuperAdminData() {
        console.log('[DASHBOARD] Loading super admin data...');
        
        try {
            // Check if API exists first
            if (typeof API === 'undefined' || !API.stadiums) {
                console.warn('[DASHBOARD] API not available, using placeholder data');
                this.updateStats({ totalGuests: 0, checkedIn: 0, events: 0, rooms: 0 });
                return;
            }
            
            const stadiums = await API.stadiums.list();
            
            if (stadiums.success && stadiums.data && stadiums.data.stadiums) {
                const stats = stadiums.data.stadiums.reduce((acc, stadium) => {
                    acc.totalGuests += stadium.stats?.total_guests || 0;
                    acc.checkedIn += stadium.stats?.checked_in_guests || 0;
                    acc.events += stadium.stats?.active_events || 0;
                    acc.rooms += stadium.stats?.total_rooms || 0;
                    return acc;
                }, {
                    totalGuests: 0,
                    checkedIn: 0,
                    events: 0,
                    rooms: 0
                });
                
                this.updateStats(stats);
                console.log('[DASHBOARD] Super admin stats loaded:', stats);
            } else {
                console.warn('[DASHBOARD] No stadium data available');
                this.updateStats({ totalGuests: 0, checkedIn: 0, events: 0, rooms: 0 });
            }
        } catch (error) {
            console.error('[DASHBOARD] Failed to load super admin data:', error);
            // Use placeholder data
            this.updateStats({ totalGuests: 0, checkedIn: 0, events: 0, rooms: 0 });
        }
    },
    
    /**
     * Load stadium admin dashboard data
     */
    async loadStadiumAdminData(stadiumId) {
        try {
            // Get stadium details with stats
            const stadiumData = await API.stadiums.get(stadiumId);
            
            if (stadiumData.success && stadiumData.data.stadium) {
                const stadium = stadiumData.data.stadium;
                const stats = {
                    totalGuests: stadium.stats?.total_guests || 0,
                    checkedIn: stadium.stats?.checked_in_guests || 0,
                    events: stadium.stats?.active_events || 0,
                    rooms: stadium.stats?.total_rooms || 0
                };
                
                this.updateStats(stats);
            }
        } catch (error) {
            console.error('[DASHBOARD] Failed to load stadium admin data:', error);
        }
    },
    
    /**
     * Load hostess dashboard data
     */
    async loadHostessData(stadiumId) {
        try {
            // For hostess, show stats only for assigned rooms
            const user = Auth.getUser();
            
            // Get assigned rooms
            const roomsData = await API.users.getRooms(user.id);
            
            if (roomsData.success) {
                // Calculate stats from assigned rooms
                const stats = {
                    totalGuests: 0,
                    checkedIn: 0,
                    events: 0,
                    rooms: roomsData.data.assigned_rooms?.length || 0
                };
                
                // Get guests for assigned rooms
                // This would require a new API endpoint or guest search
                // For now, use placeholder
                
                this.updateStats(stats);
            }
        } catch (error) {
            console.error('[DASHBOARD] Failed to load hostess data:', error);
        }
    },
    
    /**
     * Update stats cards
     */
    updateStats(stats) {
        console.log('[DASHBOARD] Updating stats:', stats);
        
        const formatNumber = (num) => {
            if (typeof num === 'undefined' || num === null) return '0';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        };
        
        document.getElementById('statTotalGuests').textContent = formatNumber(stats.totalGuests || 0);
        document.getElementById('statCheckedIn').textContent = formatNumber(stats.checkedIn || 0);
        document.getElementById('statPending').textContent = formatNumber(stats.rooms || 0);
        document.getElementById('statActiveEvents').textContent = formatNumber(stats.events || 0);
        document.getElementById('statTotalRooms').textContent = formatNumber(stats.rooms || 0);
    }

};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Dashboard;
}