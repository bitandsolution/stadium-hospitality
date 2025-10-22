/**
 * Hostess Dashboard - Main Logic
 * Interfaccia semplificata per check-in veloce ospiti
 * FIXED: Corretta gestione ruolo hostess e redirect
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
            console.log('[HOSTESS] Starting initialization...');
            
            // Check authentication
            if (!Auth.isAuthenticated()) {
                console.error('[HOSTESS] Not authenticated');
                window.location.href = 'login.html';
                return;
            }

            // Get user info
            console.log('[HOSTESS] Fetching user data...');
            const userData = await Auth.getCurrentUser();
            
            console.log('[HOSTESS] User data received:', userData);
            
            // ✅ FIX: Estrai CORRETTAMENTE il ruolo - supporta TUTTE le strutture
            let userRole = null;
            let viewType = null;
            let permissions = [];
            
            // Caso 1: userData.user.role (struttura normalizzata)
            if (userData.user && userData.user.role) {
                userRole = userData.user.role;
            }
            // Caso 2: userData.role (struttura flat)
            else if (userData.role) {
                userRole = userData.role;
            }
            
            // View type
            if (userData.role_specific_data && userData.role_specific_data.view_type) {
                viewType = userData.role_specific_data.view_type;
            }
            
            // Permissions
            if (userData.permissions && Array.isArray(userData.permissions)) {
                permissions = userData.permissions;
            }
            
            console.log('[HOSTESS] Extracted data:', {
                userRole,
                viewType,
                permissions
            });
            
            // ✅ VERIFICA MULTIPLA - Accetta se ALMENO UNO è vero
            const isHostess = (
                userRole === 'hostess' ||
                viewType === 'hostess_checkin' ||
                permissions.includes('checkin_guests')
            );
            
            console.log('[HOSTESS] Is hostess check:', {
                roleCheck: userRole === 'hostess',
                viewTypeCheck: viewType === 'hostess_checkin',
                permissionCheck: permissions.includes('checkin_guests'),
                finalResult: isHostess
            });
            
            if (!isHostess) {
                console.error('[HOSTESS] ❌ Access denied - User is not a hostess');
                console.error('   User role:', userRole);
                console.error('   View type:', viewType);
                console.error('   Permissions:', permissions);
                
                // ✅ FIX: Non mostrare toast, redirect diretto
                console.log('[HOSTESS] Redirecting to correct dashboard...');
                
                // Redirect alla dashboard corretta per il ruolo
                if (userRole === 'stadium_admin') {
                    window.location.href = 'dashboard.html';
                } else if (userRole === 'super_admin') {
                    window.location.href = 'dashboard.html';
                } else {
                    window.location.href = 'login.html';
                }
                return;
            }
            
            console.log('[HOSTESS] ✅ Access granted');

            // ✅ Salva i dati utente completi
            this.user = userData.user || { 
                id: userData.id,
                username: userData.username,
                full_name: userData.full_name,
                role: userRole,
                stadium_id: userData.stadium_id
            };
            
            // Salva l'intero oggetto userData per assigned_rooms
            this.userData = userData;
            
            console.log('[HOSTESS] Saved user data:', {
                user: this.user,
                hasAssignedRooms: !!this.userData.assigned_rooms,
                assignedRoomsCount: this.userData.assigned_rooms?.length
            });
            
            this.setupUI();
            this.attachEventListeners();
            await this.loadGuests();
            
            // Setup auto-refresh ogni 30 secondi
            this.startAutoRefresh();
            
            // Setup pull to refresh
            this.setupPullToRefresh();
            
            console.log('[HOSTESS] ✅ Initialization complete');
            
        } catch (error) {
            console.error('[HOSTESS] ❌ Init error:', error);
            this.showError('Errore di inizializzazione: ' + error.message);
        }
    }

    setupUI() {
        console.log('[HOSTESS] Setting up UI...');
        
        // Update user info in header
        const userName = this.user.full_name || this.user.username || 'Hostess';
        const userNameEl = document.getElementById('userName');
        if (userNameEl) {
            userNameEl.textContent = userName;
        }
        
        // ✅ FIX: Usa userData invece di user per assigned_rooms
        const userRoomEl = document.getElementById('userRoom');
        if (userRoomEl) {
            if (this.userData.assigned_rooms && this.userData.assigned_rooms.length > 0) {
                const roomNames = this.userData.assigned_rooms.map(r => r.room_name).join(', ');
                userRoomEl.textContent = roomNames;
                console.log('[HOSTESS] Assigned rooms:', roomNames);
            } else {
                userRoomEl.textContent = 'Nessuna sala assegnata';
                console.warn('[HOSTESS] No assigned rooms found');
            }
        }
    }

    attachEventListeners() {
        console.log('[HOSTESS] Attaching event listeners...');
        
        // Search input
        const searchInput = document.getElementById('searchInput');
        const clearSearch = document.getElementById('clearSearch');
        
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchQuery = e.target.value.toLowerCase().trim();
                if (clearSearch) {
                    clearSearch.classList.toggle('hidden', !this.searchQuery);
                }
                this.filterGuests();
            });
        }
        
        if (clearSearch) {
            clearSearch.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    this.searchQuery = '';
                    clearSearch.classList.add('hidden');
                    this.filterGuests();
                    searchInput.focus();
                }
            });
        }

        // Filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                this.currentFilter = e.target.dataset.filter;
                this.updateFilterButtons();
                this.filterGuests();
            });
        });

        // Menu
        const menuBtn = document.getElementById('menuBtn');
        const closeMenu = document.getElementById('closeMenu');
        const menuOverlay = document.getElementById('menuOverlay');
        
        if (menuBtn) menuBtn.addEventListener('click', () => this.openMenu());
        if (closeMenu) closeMenu.addEventListener('click', () => this.closeMenu());
        if (menuOverlay) {
            menuOverlay.addEventListener('click', (e) => {
                if (e.target.id === 'menuOverlay') this.closeMenu();
            });
        }

        // Logout
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => this.handleLogout());
        }

        // Retry button
        const retryBtn = document.getElementById('retryBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => this.loadGuests());
        }

        // Toast close
        const closeToast = document.getElementById('closeToast');
        if (closeToast) {
            closeToast.addEventListener('click', () => {
                const toast = document.getElementById('toast');
                if (toast) toast.classList.add('hidden');
            });
        }
    }

    async loadGuests(showLoading = true) {
        try {
            console.log('[HOSTESS] Loading guests...');
            
            if (showLoading) {
                this.showLoading();
            }

            // Use guest search endpoint with hostess filters
            const response = await API.guests.search({
                limit: 500,
                access_status: this.currentFilter === 'all' ? null : this.currentFilter
            });

            console.log('[HOSTESS] API response:', response);

            if (response.success) {
                this.guests = response.data.guests || [];
                console.log('[HOSTESS] Loaded', this.guests.length, 'guests');
                
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
            console.error('[HOSTESS] Load guests error:', error);
            this.hideLoading();
            this.showError(error.message || 'Errore di connessione');
        }
    }

    showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        const toastIcon = document.getElementById('toastIcon');
        
        if (!toast || !toastMessage) {
            console.log(`[Toast ${type}]:`, message);
            return;
        }
        
        toastMessage.textContent = message;
        
        const icons = {
            success: '<i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>',
            error: '<i data-lucide="alert-circle" class="w-5 h-5 text-red-600"></i>',
            info: '<i data-lucide="info" class="w-5 h-5 text-blue-600"></i>'
        };
        
        if (toastIcon) {
            toastIcon.innerHTML = icons[type] || icons.info;
            lucide.createIcons();
        }
        
        toast.classList.remove('hidden');
        toast.style.transform = 'translateY(0)';
        
        setTimeout(() => {
            toast.style.transform = 'translateY(-8rem)';
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 300);
        }, 3000);
    }

    filterGuests() {
        let filtered = [...this.guests];

        if (this.currentFilter === 'checked_in') {
            filtered = filtered.filter(g => g.access_status === 'checked_in');
        } else if (this.currentFilter === 'not_checked_in') {
            filtered = filtered.filter(g => g.access_status === 'not_checked_in');
        } else if (this.currentFilter === 'vip') {
            filtered = filtered.filter(g => 
                g.vip_level === 'vip' || g.vip_level === 'ultra_vip'
            );
        }

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
            console.error('[HOSTESS] guestsList container not found');
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

        lucide.createIcons();
    }

    updateStats() {
        this.stats.total = this.guests.length;
        this.stats.checkedIn = this.guests.filter(g => g.access_status === 'checked_in').length;
        this.stats.pending = this.stats.total - this.stats.checkedIn;

        const statTotal = document.getElementById('statTotal');
        const statCheckedIn = document.getElementById('statCheckedIn');
        const statPending = document.getElementById('statPending');
        
        if (statTotal) statTotal.textContent = this.stats.total;
        if (statCheckedIn) statCheckedIn.textContent = this.stats.checkedIn;
        if (statPending) statPending.textContent = this.stats.pending;
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
        window.location.href = `hostess-guest-detail.html?id=${guestId}`;
    }

    setupPullToRefresh() {
        const mainContent = document.getElementById('mainContent');
        const indicator = document.getElementById('ptrIndicator');
        
        if (!mainContent || !indicator) return;

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
                this.showToast('Dati aggiornati', 'success');
                
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
        const overlay = document.getElementById('menuOverlay');
        const panel = document.getElementById('menuPanel');
        
        if (overlay) overlay.classList.remove('hidden');
        if (panel) {
            setTimeout(() => {
                panel.style.transform = 'translateX(0)';
            }, 10);
        }
    }

    closeMenu() {
        const overlay = document.getElementById('menuOverlay');
        const panel = document.getElementById('menuPanel');
        
        if (panel) panel.style.transform = 'translateX(100%)';
        if (overlay) {
            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 300);
        }
    }

    async handleLogout() {
        if (confirm('Vuoi davvero uscire?')) {
            try {
                await Auth.logout();
            } catch (error) {
                console.error('[HOSTESS] Logout error:', error);
            }
        }
    }

    async quickCheckin(guestId, guestName) {
        try {
            console.log('[HOSTESS] Quick check-in:', guestId, guestName);
            
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
            console.error('[HOSTESS] Quick check-in error:', error);
            this.showToast('Errore durante il check-in', 'error');
        }
    }

    async quickCheckout(guestId, guestName) {
        if (!confirm(`Confermi check-out per ${guestName}?`)) {
            return;
        }
        
        try {
            console.log('[HOSTESS] Quick check-out:', guestId, guestName);
            
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
            console.error('[HOSTESS] Quick check-out error:', error);
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
        
        if (loadingSkeleton) loadingSkeleton.classList.add('hidden');
        if (guestsList) guestsList.classList.remove('hidden');
    }

    showEmpty(message) {
        const guestsList = document.getElementById('guestsList');
        const emptyState = document.getElementById('emptyState');
        const errorState = document.getElementById('errorState');
        
        if (guestsList) guestsList.classList.add('hidden');
        if (emptyState) emptyState.classList.remove('hidden');
        if (errorState) errorState.classList.add('hidden');
    }

    showError(message) {
        const guestsList = document.getElementById('guestsList');
        const emptyState = document.getElementById('emptyState');
        const errorState = document.getElementById('errorState');
        const loadingSkeleton = document.getElementById('loadingSkeleton');
        const errorMessage = document.getElementById('errorMessage');
        
        if (guestsList) guestsList.classList.add('hidden');
        if (emptyState) emptyState.classList.add('hidden');
        if (loadingSkeleton) loadingSkeleton.classList.add('hidden');
        
        if (errorState) errorState.classList.remove('hidden');
        if (errorMessage) errorMessage.textContent = message;
        
        console.error('[HOSTESS] Dashboard Error:', message);
    }
}

// Initialize dashboard when DOM is ready
let HostessDashboardInstance;
document.addEventListener('DOMContentLoaded', () => {
    console.log('[HOSTESS] DOM loaded, creating dashboard instance...');
    HostessDashboardInstance = new HostessDashboard();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (HostessDashboardInstance) {
        HostessDashboardInstance.stopAutoRefresh();
    }
});