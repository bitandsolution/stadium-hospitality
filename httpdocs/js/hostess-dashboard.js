/**
 * Hostess Dashboard - Main Logic
 * Interfaccia semplificata per check-in veloce ospiti
 */

class HostessDashboard {
    constructor() {
        this.guests = [];
        this.filteredGuests = [];
        this.currentFilter = 'all';
        this.searchQuery = '';
        this.stats = { total: 0, checkedIn: 0, pending: 0 };
        this.refreshInterval = null;
        this.pullToRefresh = {
            startY: 0,
            currentY: 0,
            threshold: 80,
            isRefreshing: false
        };
        
        this.init();
    }

    async init() {
        try {
            // Check authentication
            if (!Auth.isAuthenticated()) {
                window.location.href = 'login.html';
                return;
            }

            // Get user info
            const user = await Auth.getCurrentUser();
            
            // Verify hostess role
            if (user.role !== 'hostess') {
                Utils.showToast('Accesso negato. Solo per hostess.', 'error');
                setTimeout(() => window.location.href = 'index.html', 2000);
                return;
            }

            this.user = user;
            this.setupUI();
            this.attachEventListeners();
            await this.loadGuests();
            
            // Setup auto-refresh ogni 30 secondi
            this.startAutoRefresh();
            
            // Setup pull to refresh
            this.setupPullToRefresh();
            
        } catch (error) {
            console.error('Init error:', error);
            this.showError('Errore di inizializzazione');
        }
    }

    setupUI() {
        // Update user info in header
        document.getElementById('userName').textContent = this.user.full_name || this.user.username;
        
        // Get assigned rooms (from user data if available)
        if (this.user.assigned_rooms) {
            document.getElementById('userRoom').textContent = this.user.assigned_rooms;
        } else {
            document.getElementById('userRoom').textContent = 'Tutte le sale assegnate';
        }
    }

    attachEventListeners() {
        // Search input
        const searchInput = document.getElementById('searchInput');
        const clearSearch = document.getElementById('clearSearch');
        
        searchInput.addEventListener('input', (e) => {
            this.searchQuery = e.target.value.toLowerCase().trim();
            clearSearch.classList.toggle('hidden', !this.searchQuery);
            this.filterGuests();
        });
        
        clearSearch.addEventListener('click', () => {
            searchInput.value = '';
            this.searchQuery = '';
            clearSearch.classList.add('hidden');
            this.filterGuests();
            searchInput.focus();
        });

        // Filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.currentFilter = e.target.dataset.filter;
                this.updateFilterButtons();
                this.filterGuests();
            });
        });

        // Menu
        document.getElementById('menuBtn').addEventListener('click', () => this.openMenu());
        document.getElementById('closeMenu').addEventListener('click', () => this.closeMenu());
        document.getElementById('menuOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'menuOverlay') this.closeMenu();
        });

        // Logout
        document.getElementById('logoutBtn').addEventListener('click', () => this.handleLogout());

        // Retry button
        document.getElementById('retryBtn').addEventListener('click', () => this.loadGuests());

        // Toast close
        document.getElementById('closeToast').addEventListener('click', () => {
            document.getElementById('toast').classList.add('hidden');
        });
    }

    async loadGuests(showLoading = true) {
        try {
            if (showLoading) {
                this.showLoading();
            }

            // Use guest search endpoint with hostess filters
            const response = await API.guests.search({
                limit: 500, // Load all guests assigned to hostess
                access_status: this.currentFilter === 'all' ? null : this.currentFilter
            });

            if (response.success) {
                this.guests = response.data.guests || [];
                this.updateStats();
                this.filterGuests();
                this.hideLoading();
                
                if (this.guests.length === 0) {
                    this.showEmpty('Non hai ospiti assegnati nelle tue sale');
                }
            } else {
                throw new Error(response.message || 'Errore nel caricamento ospiti');
            }

        } catch (error) {
            console.error('Load guests error:', error);
            this.hideLoading();
            this.showError(error.message || 'Errore di connessione');
        }
    }

    showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        const toastIcon = document.getElementById('toastIcon');
        
        if (!toast || !toastMessage) {
            // Fallback: usa console se toast non esiste
            console.log(`[Toast ${type}]:`, message);
            return;
        }
        
        // Set message
        toastMessage.textContent = message;
        
        // Set icon based on type
        const icons = {
            success: '<i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>',
            error: '<i data-lucide="alert-circle" class="w-5 h-5 text-red-600"></i>',
            info: '<i data-lucide="info" class="w-5 h-5 text-blue-600"></i>'
        };
        
        if (toastIcon) {
            toastIcon.innerHTML = icons[type] || icons.info;
            lucide.createIcons();
        }
        
        // Show toast
        toast.classList.remove('hidden');
        toast.style.transform = 'translateY(0)';
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            toast.style.transform = 'translateY(-8rem)';
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 300);
        }, 3000);
    }

    filterGuests() {
        let filtered = [...this.guests];

        // Apply filter
        if (this.currentFilter === 'checked_in') {
            filtered = filtered.filter(g => g.access_status === 'checked_in');
        } else if (this.currentFilter === 'not_checked_in') {
            filtered = filtered.filter(g => g.access_status === 'not_checked_in');
        } else if (this.currentFilter === 'vip') {
            filtered = filtered.filter(g => 
                g.vip_level === 'vip' || g.vip_level === 'ultra_vip'
            );
        }

        // Apply search
        if (this.searchQuery) {
            filtered = filtered.filter(g => {
                const fullName = `${g.first_name} ${g.last_name}`.toLowerCase();
                const company = (g.company_name || '').toLowerCase();
                return fullName.includes(this.searchQuery) || 
                       company.includes(this.searchQuery);
            });
        }

        this.filteredGuests = filtered;
        this.renderGuests();
    }

    renderGuests() {
        const container = document.getElementById('guestsList');
        const emptyState = document.getElementById('emptyState');
        
        if (!container) {
            console.error('guestsList container not found');
            return;
        }
        
        if (this.filteredGuests.length === 0) {
            container.innerHTML = '';
            if (emptyState) {
                emptyState.classList.remove('hidden');
            }
            return;
        }

        if (emptyState) {
            emptyState.classList.add('hidden');
        }
        
        container.innerHTML = this.filteredGuests.map(guest => {
            const lastName = guest.last_name || '';
            const firstName = guest.first_name || '';
            const roomName = guest.room_name || 'N/A';
            const tableNumber = guest.table_number || '';
            const accessStatus = guest.access_status || 'not_checked_in';
            const isCheckedIn = accessStatus === 'checked_in';
            
            return `
                <div class="guest-card bg-white rounded-lg p-3 shadow-sm border-l-4 ${isCheckedIn ? 'border-green-500' : 'border-orange-400'}" 
                    data-guest-id="${guest.id}">
                    <div class="flex items-center justify-between gap-3">
                        <!-- Guest Info -->
                        <div class="flex-1 min-w-0"
                            onclick="HostessDashboardInstance.showGuestDetail(${guest.id})"
                            style="cursor: pointer;">
                            <div class="font-semibold text-gray-900 truncate">
                                ${lastName} ${firstName}
                            </div>
                            <div class="text-xs text-gray-500 flex items-center gap-2 mt-1">
                                <span>${roomName}</span>
                                ${tableNumber ? `<span>• Tavolo ${tableNumber}</span>` : ''}
                            </div>
                        </div>
                        
                        <!-- Action Button -->
                        ${isCheckedIn ? `
                            <button 
                                onclick="event.stopPropagation(); HostessDashboardInstance.quickCheckout(${guest.id}, '${lastName} ${firstName}')"
                                class="flex-shrink-0 px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                <span>Check-out</span>
                            </button>
                        ` : `
                            <button 
                                onclick="event.stopPropagation(); HostessDashboardInstance.quickCheckin(${guest.id}, '${lastName} ${firstName}')"
                                class="flex-shrink-0 px-4 py-2 bg-green-500 hover:bg-green-600 text-white text-sm font-medium rounded-lg transition flex items-center gap-2">
                                <i data-lucide="check" class="w-4 h-4"></i>
                                <span>Check-in</span>
                            </button>
                        `}
                    </div>
                </div>
            `;
        }).join('');

        // Re-initialize Lucide icons
        lucide.createIcons();
    }

    getVipBorderColor(vipLevel) {
        const colors = {
            'ultra_vip': 'border-purple-500',
            'vip': 'border-blue-500',
            'premium': 'border-green-500',
            'standard': 'border-gray-300'
        };
        return colors[vipLevel] || colors.standard;
    }

    getVipBadge(vipLevel) {
        if (vipLevel === 'ultra_vip') {
            return '<span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Ultra VIP</span>';
        } else if (vipLevel === 'vip') {
            return '<span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">VIP</span>';
        } else if (vipLevel === 'premium') {
            return '<span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Premium</span>';
        }
        return '';
    }

    getStatusBadge(status) {
        if (status === 'checked_in') {
            return '<span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800"><i data-lucide="check" class="w-3 h-3 mr-1"></i>Check-in</span>';
        }
        return '<span class="status-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800"><i data-lucide="clock" class="w-3 h-3 mr-1"></i>Attesa</span>';
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        return date.toLocaleTimeString('it-IT', { 
            hour: '2-digit', 
            minute: '2-digit' 
        });
    }

    updateStats() {
        this.stats.total = this.guests.length;
        this.stats.checkedIn = this.guests.filter(g => g.access_status === 'checked_in').length;
        this.stats.pending = this.stats.total - this.stats.checkedIn;

        document.getElementById('statTotal').textContent = this.stats.total;
        document.getElementById('statCheckedIn').textContent = this.stats.checkedIn;
        document.getElementById('statPending').textContent = this.stats.pending;
    }

    updateFilterButtons() {
        document.querySelectorAll('.filter-btn').forEach(btn => {
            if (btn.dataset.filter === this.currentFilter) {
                btn.classList.remove('bg-white', 'text-gray-700', 'border-gray-200');
                btn.classList.add('bg-purple-600', 'text-white');
            } else {
                btn.classList.remove('bg-purple-600', 'text-white');
                btn.classList.add('bg-white', 'text-gray-700', 'border', 'border-gray-200');
            }
        });
    }

    async showGuestDetail(guestId) {
        // Redirect to guest detail page
        window.location.href = `hostess-guest-detail.html?id=${guestId}`;
    }

    setupPullToRefresh() {
        const mainContent = document.getElementById('mainContent');
        const indicator = document.getElementById('ptrIndicator');

        mainContent.addEventListener('touchstart', (e) => {
            if (mainContent.scrollTop === 0) {
                this.pullToRefresh.startY = e.touches[0].clientY;
            }
        });

        mainContent.addEventListener('touchmove', (e) => {
            if (this.pullToRefresh.isRefreshing) return;
            
            if (mainContent.scrollTop === 0 && this.pullToRefresh.startY > 0) {
                this.pullToRefresh.currentY = e.touches[0].clientY;
                const distance = this.pullToRefresh.currentY - this.pullToRefresh.startY;
                
                if (distance > 0 && distance < this.pullToRefresh.threshold * 2) {
                    e.preventDefault();
                    indicator.style.transform = `translateX(-50%) translateY(${Math.min(distance / 2, this.pullToRefresh.threshold)}px)`;
                    
                    if (distance > this.pullToRefresh.threshold) {
                        indicator.classList.add('active');
                    } else {
                        indicator.classList.remove('active');
                    }
                }
            }
        });

        mainContent.addEventListener('touchend', async () => {
            const distance = this.pullToRefresh.currentY - this.pullToRefresh.startY;
            
            if (distance > this.pullToRefresh.threshold && !this.pullToRefresh.isRefreshing) {
                this.pullToRefresh.isRefreshing = true;
                indicator.classList.add('active');
                
                await this.loadGuests(false);
                Utils.showToast('Dati aggiornati', 'success');
                
                setTimeout(() => {
                    indicator.classList.remove('active');
                    indicator.style.transform = 'translateX(-50%) translateY(-100%)';
                    this.pullToRefresh.isRefreshing = false;
                }, 500);
            } else {
                indicator.classList.remove('active');
                indicator.style.transform = 'translateX(-50%) translateY(-100%)';
            }
            
            this.pullToRefresh.startY = 0;
            this.pullToRefresh.currentY = 0;
        });
    }

    startAutoRefresh() {
        // Auto-refresh ogni 30 secondi
        this.refreshInterval = setInterval(() => {
            this.loadGuests(false);
        }, 30000);
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    openMenu() {
        document.getElementById('menuOverlay').classList.remove('hidden');
        setTimeout(() => {
            document.getElementById('menuPanel').style.transform = 'translateX(0)';
        }, 10);
    }

    closeMenu() {
        document.getElementById('menuPanel').style.transform = 'translateX(100%)';
        setTimeout(() => {
            document.getElementById('menuOverlay').classList.add('hidden');
        }, 300);
    }

    async handleLogout() {
        if (confirm('Vuoi davvero uscire?')) {
            try {
                await Auth.logout();
                window.location.href = 'login.html';
            } catch (error) {
                Utils.showToast('Errore durante il logout', 'error');
            }
        }
    }

    async quickCheckin(guestId, guestName) {
        // Nessuna conferma per check-in (veloce)
        try {
            const result = await API.guests.checkin(guestId);
            
            if (result.success) {
                const guest = this.guests.find(g => g.id === guestId);
                if (guest) {
                    guest.access_status = 'checked_in';
                    guest.last_access_time = new Date().toISOString();
                }
                
                this.updateStats();
                this.filterGuests();
                this.showToast(`✓ Check-in: ${guestName}`, 'success');
            }
        } catch (error) {
            console.error('Quick check-in error:', error);
            this.showToast('Errore durante il check-in', 'error');
        }
    }

    async quickCheckout(guestId, guestName) {
        // Conferma per check-out (operazione più critica)
        if (!confirm(`Confermi check-out per ${guestName}?`)) {
            return;
        }
        
        try {
            const result = await API.guests.checkout(guestId);
            
            if (result.success) {
                const guest = this.guests.find(g => g.id === guestId);
                if (guest) {
                    guest.access_status = 'not_checked_in';
                    guest.last_access_time = null;
                }
                
                this.updateStats();
                this.filterGuests();
                this.showToast(`✓ Check-out: ${guestName}`, 'success');
            }
        } catch (error) {
            console.error('Quick check-out error:', error);
            this.showToast('Errore durante il check-out', 'error');
        }
    }

    showLoading() {
        const loadingSkeleton = document.getElementById('loadingSkeleton');
        const guestsList = document.getElementById('guestsList');
        const emptyState = document.getElementById('emptyState');
        const errorState = document.getElementById('errorState');
        
        if (loadingSkeleton) loadingSkeleton.classList.remove('hidden');
        if (guestsList) guestsList.classList.add('hidden');
        if (emptyState) emptyState.classList.add('hidden');
        if (errorState) errorState.classList.add('hidden');
    }

    hideLoading() {
        const loadingSkeleton = document.getElementById('loadingSkeleton');
        const guestsList = document.getElementById('guestsList');
        
        if (loadingSkeleton) {
            loadingSkeleton.classList.add('hidden');
        }
        if (guestsList) {
            guestsList.classList.remove('hidden');
        }
    }

    showEmpty(message) {
        document.getElementById('guestsList').classList.add('hidden');
        document.getElementById('emptyState').classList.remove('hidden');
        document.getElementById('errorState').classList.add('hidden');
    }

    showError(message) {
        document.getElementById('guestsList').classList.add('hidden');
        document.getElementById('emptyState').classList.add('hidden');
        document.getElementById('errorState').classList.remove('hidden');
        document.getElementById('errorMessage').textContent = message;
    }

    showError(message) {
        const guestsList = document.getElementById('guestsList');
        const emptyState = document.getElementById('emptyState');
        const errorState = document.getElementById('errorState');
        const loadingSkeleton = document.getElementById('loadingSkeleton');
        
        // Hide all states
        if (guestsList) guestsList.classList.add('hidden');
        if (emptyState) emptyState.classList.add('hidden');
        if (loadingSkeleton) loadingSkeleton.classList.add('hidden');
        
        // Show error
        if (errorState) {
            errorState.classList.remove('hidden');
            const errorMessage = document.getElementById('errorMessage');
            if (errorMessage) {
                errorMessage.textContent = message;
            }
        }
        
        console.error('Dashboard Error:', message);
    }
}

// Initialize dashboard when DOM is ready
let HostessDashboardInstance;
document.addEventListener('DOMContentLoaded', () => {
    HostessDashboardInstance = new HostessDashboard();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (HostessDashboardInstance) {
        HostessDashboardInstance.stopAutoRefresh();
    }
});