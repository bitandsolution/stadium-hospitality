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
        
        // Load user info
        this.loadUserInfo();
        
        // Setup menu visibility based on role
        this.setupMenuVisibility();
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Load dashboard data
        await this.loadDashboardData();
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
            try {
                await this.loadUpcomingEvents(stadiumId);
            } catch (error) {
                console.error('[DASHBOARD] Failed to load events:', error);
                // Continue anyway
            }
            
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
        document.getElementById('statEvents').textContent = formatNumber(stats.events || 0);
        document.getElementById('statRooms').textContent = formatNumber(stats.rooms || 0);
    },
    
    /**
     * Load upcoming events
     */
    async loadUpcomingEvents(stadiumId) {
        console.log('[DASHBOARD] Loading upcoming events for stadium:', stadiumId);
        
        try {
            const role = Auth.getRole();
            
            // Check if API is available
            if (typeof API === 'undefined' || !API.events) {
                console.warn('[DASHBOARD] Events API not available');
                document.getElementById('upcomingEvents').innerHTML = `
                    <p class="text-sm text-gray-500 text-center py-8">
                        Funzionalità eventi in arrivo
                    </p>
                `;
                return;
            }
            
            let eventsData;
            if (role === 'super_admin' && !stadiumId) {
                // For super admin without specific stadium, show message
                document.getElementById('upcomingEvents').innerHTML = `
                    <p class="text-sm text-gray-500 text-center py-8">
                        Seleziona uno stadio specifico per vedere gli eventi
                    </p>
                `;
                return;
            }
            
            eventsData = await API.events.upcoming(stadiumId);
            
            if (eventsData.success && eventsData.data.events && eventsData.data.events.length > 0) {
                const eventsHtml = eventsData.data.events.slice(0, 5).map(event => {
                    const escapeHtml = (text) => {
                        const div = document.createElement('div');
                        div.textContent = text || '';
                        return div.innerHTML;
                    };
                    
                    const formatDate = (dateStr) => {
                        if (!dateStr) return '-';
                        const date = new Date(dateStr);
                        const day = String(date.getDate()).padStart(2, '0');
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const year = date.getFullYear();
                        return `${day}/${month}/${year}`;
                    };
                    
                    const formatTime = (timeStr) => {
                        if (!timeStr) return '-';
                        const parts = timeStr.split(':');
                        return parts.length >= 2 ? `${parts[0]}:${parts[1]}` : timeStr;
                    };
                    
                    return `
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i data-lucide="calendar" class="w-6 h-6 text-purple-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900">${escapeHtml(event.name)}</p>
                                    <p class="text-sm text-gray-500">
                                        ${formatDate(event.event_date)} - ${formatTime(event.event_time)}
                                    </p>
                                    ${event.opponent_team ? `
                                        <p class="text-xs text-gray-400 mt-1">vs ${escapeHtml(event.opponent_team)}</p>
                                    ` : ''}
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-medium text-gray-900">${event.stats?.total_guests || 0} ospiti</p>
                                <p class="text-xs text-gray-500">${event.stats?.total_rooms || 0} sale</p>
                            </div>
                        </div>
                    `;
                }).join('');
                
                document.getElementById('upcomingEvents').innerHTML = eventsHtml;
                
                // Re-initialize Lucide icons
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            } else {
                document.getElementById('upcomingEvents').innerHTML = `
                    <p class="text-sm text-gray-500 text-center py-8">Nessun evento in programma</p>
                `;
            }
            
        } catch (error) {
            console.error('[DASHBOARD] Failed to load upcoming events:', error);
            document.getElementById('upcomingEvents').innerHTML = `
                <p class="text-sm text-gray-500 text-center py-8">Eventi disponibili a breve</p>
            `;
        }
    }
};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Dashboard;
}