/**
 * Hostess Guest Detail - Check-in/out Logic
 * Gestione dettaglio ospite con operazioni di accesso
 */

class HostessGuestDetail {
    constructor() {
        this.guestId = null;
        this.guest = null;
        this.user = null;
        this.userData = null; // ✅ Aggiungi questo
        
        this.init();
    }

    async init() {
        try {
            console.log('[GUEST-DETAIL] Starting initialization...');
            
            // Check authentication
            if (!Auth.isAuthenticated()) {
                console.error('[GUEST-DETAIL] Not authenticated');
                window.location.href = 'login.html';
                return;
            }

            // Get user info
            const userData = await Auth.getCurrentUser();
            
            console.log('[GUEST-DETAIL] User data received:', userData);
            
            // ✅ FIX: Estrai correttamente il ruolo - VERIFICA MULTIPLA
            const userRole = userData.user?.role || userData.role;
            const viewType = userData.role_specific_data?.view_type;
            
            console.log('[GUEST-DETAIL] User role:', userRole);
            console.log('[GUEST-DETAIL] View type:', viewType);
            
            // ✅ VERIFICA MULTIPLA - Accetta se UNO di questi è vero
            const isHostess = (
                userRole === 'hostess' ||
                viewType === 'hostess_checkin' ||
                (userData.permissions && userData.permissions.includes('checkin_guests'))
            );
            
            console.log('[GUEST-DETAIL] Is hostess check:', {
                userRole,
                viewType,
                hasCheckinPermission: userData.permissions?.includes('checkin_guests'),
                finalResult: isHostess
            });
            
            if (!isHostess) {
                console.error('[GUEST-DETAIL] ❌ Access denied - User is not a hostess');
                console.error('   User role:', userRole);
                console.error('   View type:', viewType);
                
                Utils.showToast('Accesso negato. Solo per hostess.', 'error');
                
                // ✅ Redirect alla dashboard corretta per il ruolo
                setTimeout(() => {
                    if (userRole === 'stadium_admin') {
                        console.log('[GUEST-DETAIL] Redirecting to admin dashboard');
                        window.location.href = 'admin-dashboard.html';
                    } else if (userRole === 'super_admin') {
                        console.log('[GUEST-DETAIL] Redirecting to super admin dashboard');
                        window.location.href = 'super-admin-dashboard.html';
                    } else {
                        console.log('[GUEST-DETAIL] Redirecting to hostess dashboard');
                        window.location.href = 'hostess-dashboard.html';
                    }
                }, 2000);
                return;
            }
            
            console.log('[GUEST-DETAIL] ✅ Access granted');
            
            // ✅ Salva i dati utente completi
            this.user = userData.user || userData;
            this.userData = userData;

            // Get guest ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            this.guestId = parseInt(urlParams.get('id'));
            
            if (!this.guestId) {
                throw new Error('ID ospite non valido');
            }
            
            console.log('[GUEST-DETAIL] Guest ID:', this.guestId);

            this.attachEventListeners();
            await this.loadGuestDetail();
            
            console.log('[GUEST-DETAIL] ✅ Initialization complete');
            
        } catch (error) {
            console.error('[GUEST-DETAIL] ❌ Init error:', error);
            this.showError(error.message || 'Errore di inizializzazione');
        }
    }

    attachEventListeners() {
        // Back button
        const backBtn = document.getElementById('backBtn');
        if (backBtn) {
            backBtn.addEventListener('click', () => {
                window.location.href = 'hostess-dashboard.html';
            });
        }

        // Check-in button
        const checkinBtn = document.getElementById('checkinBtn');
        if (checkinBtn) {
            checkinBtn.addEventListener('click', () => {
                this.showConfirmation(
                    'Conferma Check-in',
                    'Vuoi effettuare il check-in di questo ospite?',
                    () => this.performCheckin()
                );
            });
        }

        // Check-out button
        const checkoutBtn = document.getElementById('checkoutBtn');
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', () => {
                this.showConfirmation(
                    'Conferma Check-out',
                    'Vuoi effettuare il check-out di questo ospite?',
                    () => this.performCheckout()
                );
            });
        }

        // Retry button
        const retryBtn = document.getElementById('retryBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                this.loadGuestDetail();
            });
        }

        // Modal buttons
        const confirmYes = document.getElementById('confirmYes');
        if (confirmYes) {
            confirmYes.addEventListener('click', () => {
                if (this.confirmCallback) {
                    this.confirmCallback();
                }
                this.hideConfirmation();
            });
        }

        const confirmNo = document.getElementById('confirmNo');
        if (confirmNo) {
            confirmNo.addEventListener('click', () => {
                this.hideConfirmation();
            });
        }
        
        // More menu
        const moreBtn = document.getElementById('moreBtn');
        if (moreBtn) {
            moreBtn.addEventListener('click', () => {
                const moreMenu = document.getElementById('moreMenu');
                if (moreMenu) moreMenu.style.display = 'flex';
            });
        }

        const cancelMoreBtn = document.getElementById('cancelMoreBtn');
        if (cancelMoreBtn) {
            cancelMoreBtn.addEventListener('click', () => {
                const moreMenu = document.getElementById('moreMenu');
                if (moreMenu) moreMenu.style.display = 'none';
            });
        }

        const moreMenu = document.getElementById('moreMenu');
        if (moreMenu) {
            moreMenu.addEventListener('click', (e) => {
                if (e.target.id === 'moreMenu') {
                    moreMenu.style.display = 'none';
                }
            });
        }

        const editGuestBtn = document.getElementById('editGuestBtn');
        if (editGuestBtn) {
            editGuestBtn.addEventListener('click', () => {
                this.showEditGuestModal();
            });
        }

        // Force check-in
        const forceCheckinBtn = document.getElementById('forceCheckinBtn');
        if (forceCheckinBtn) {
            forceCheckinBtn.addEventListener('click', () => {
                if (confirm('ATTENZIONE: Stai forzando lo stato a CHECK-IN.\nQuesta operazione sovrascriverà eventuali errori.\n\nContinuare?')) {
                    this.forceState('entry');
                }
            });
        }

        // Force check-out
        const forceCheckoutBtn = document.getElementById('forceCheckoutBtn');
        if (forceCheckoutBtn) {
            forceCheckoutBtn.addEventListener('click', () => {
                if (confirm('ATTENZIONE: Stai forzando lo stato a CHECK-OUT.\nQuesta operazione sovrascriverà eventuali errori.\n\nContinuare?')) {
                    this.forceState('exit');
                }
            });
        }
    }

    async forceState(accessType) {
        try {
            const moreMenu = document.getElementById('moreMenu');
            if (moreMenu) moreMenu.style.display = 'none';
            
            this.showProcessing();

            const endpoint = accessType === 'entry' ? 'checkin' : 'checkout';
            
            const response = await API.guests[endpoint](this.guestId);

            if (response.success) {
                const newStatus = accessType === 'entry' ? 'checked_in' : 'not_checked_in';
                
                this.guest.access_status = newStatus;
                this.guest.last_access_time = accessType === 'entry' ? new Date().toISOString() : null;

                this.showSuccess(
                    'Stato forzato con successo!',
                    `Ospite ora: ${accessType === 'entry' ? 'CHECK-IN' : 'CHECK-OUT'}`
                );

                setTimeout(() => {
                    this.hideSuccess();
                    this.updateStatusIndicator(newStatus, this.guest.last_access_time);
                    this.updateActionButtons(newStatus);
                    this.hideProcessing();
                }, 2000);

            } else {
                throw new Error(response.message);
            }

        } catch (error) {
            console.error('[GUEST-DETAIL] Force state error:', error);
            
            const confirmForce = confirm('Errore backend. Vuoi aggiornare solo visualmente lo stato?\n\nATTENZIONE: Il database potrebbe non essere allineato.');
            
            if (confirmForce) {
                const newStatus = accessType === 'entry' ? 'checked_in' : 'not_checked_in';
                this.guest.access_status = newStatus;
                this.updateStatusIndicator(newStatus);
                this.updateActionButtons(newStatus);
            }
            
            this.hideProcessing();
        }
    }

    async loadGuestDetail() {
        try {
            console.log('[GUEST-DETAIL] Loading guest details...');
            this.showLoading();

            const response = await API.guests.get(this.guestId);

            console.log('[GUEST-DETAIL] API response:', response);

            if (response.success) {
                this.guest = response.data.guest;
                console.log('[GUEST-DETAIL] Guest loaded:', this.guest);
                
                this.renderGuestDetail();
                this.hideLoading();
            } else {
                throw new Error(response.message || 'Errore nel caricamento ospite');
            }

        } catch (error) {
            console.error('[GUEST-DETAIL] Load guest error:', error);
            this.hideLoading();
            this.showError(error.message || 'Errore di connessione');
        }
    }

    renderGuestDetail() {
        const g = this.guest;

        // Update guest card
        const guestName = document.getElementById('guestName');
        if (guestName) guestName.textContent = `${g.last_name} ${g.first_name}`;
        
        const guestCompany = document.getElementById('guestCompany');
        if (g.company_name && guestCompany) {
            guestCompany.textContent = g.company_name;
        } else if (guestCompany) {
            guestCompany.classList.add('hidden');
        }

        // VIP badge
        const vipBadge = this.getVipBadge(g.vip_level);
        const vipBadgeEl = document.getElementById('vipBadge');
        if (vipBadge && vipBadgeEl) {
            vipBadgeEl.innerHTML = vipBadge;
        }

        // Border color based on VIP level
        const borderColors = {
            'ultra_vip': 'border-purple-500',
            'vip': 'border-blue-500',
            'premium': 'border-green-500',
            'standard': 'border-gray-300'
        };
        const guestCard = document.getElementById('guestCard');
        if (guestCard) {
            guestCard.className = 
                `bg-white rounded-xl shadow-sm p-6 border-l-4 ${borderColors[g.vip_level] || borderColors.standard}`;
        }

        // Status indicator
        this.updateStatusIndicator(g.access_status, g.last_access_time);

        // Room and table info
        const guestRoom = document.getElementById('guestRoom');
        if (guestRoom) guestRoom.textContent = g.room_name;
        
        const guestTable = document.getElementById('guestTable');
        const tableInfo = document.getElementById('tableInfo');
        if (g.table_number && guestTable) {
            guestTable.textContent = `Tavolo ${g.table_number}`;
        } else if (tableInfo) {
            tableInfo.classList.add('hidden');
        }

        const guestSeat = document.getElementById('guestSeat');
        const seatInfo = document.getElementById('seatInfo');
        if (g.seat_number && guestSeat) {
            guestSeat.textContent = `Posto ${g.seat_number}`;
        } else if (seatInfo) {
            seatInfo.classList.add('hidden');
        }

        // Contact info
        const hasEmail = g.contact_email && g.contact_email.trim();
        const hasPhone = g.contact_phone && g.contact_phone.trim();

        const emailSection = document.getElementById('emailSection');
        const phoneSection = document.getElementById('phoneSection');
        const noContactInfo = document.getElementById('noContactInfo');

        if (hasEmail && emailSection) {
            emailSection.classList.remove('hidden');
            const guestEmail = document.getElementById('guestEmail');
            const emailLink = document.getElementById('emailLink');
            if (guestEmail) guestEmail.textContent = g.contact_email;
            if (emailLink) emailLink.href = `mailto:${g.contact_email}`;
            if (noContactInfo) noContactInfo.classList.add('hidden');
        }

        if (hasPhone && phoneSection) {
            phoneSection.classList.remove('hidden');
            const guestPhone = document.getElementById('guestPhone');
            const phoneLink = document.getElementById('phoneLink');
            if (guestPhone) guestPhone.textContent = g.contact_phone;
            if (phoneLink) phoneLink.href = `tel:${g.contact_phone}`;
            if (noContactInfo) noContactInfo.classList.add('hidden');
        }

        if (!hasEmail && !hasPhone && noContactInfo) {
            noContactInfo.classList.remove('hidden');
        }

        // Event info
        const eventName = document.getElementById('eventName');
        if (eventName) eventName.textContent = g.event_name || 'Evento sconosciuto';
        
        if (g.event_date) {
            const eventDate = new Date(g.event_date);
            const eventDateEl = document.getElementById('eventDate');
            if (eventDateEl) {
                eventDateEl.textContent = eventDate.toLocaleDateString('it-IT', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
            }
        }

        // Notes
        const notesSection = document.getElementById('notesSection');
        const guestNotes = document.getElementById('guestNotes');
        if (g.notes && g.notes.trim() && notesSection && guestNotes) {
            notesSection.classList.remove('hidden');
            guestNotes.textContent = g.notes;
        }

        // Action buttons
        this.updateActionButtons(g.access_status);

        // Re-initialize icons
        lucide.createIcons();
    }

    updateStatusIndicator(status, lastAccessTime) {
        const indicator = document.getElementById('statusIndicator');
        if (!indicator) return;
        
        if (status === 'checked_in') {
            indicator.className = 'flex items-center space-x-2 py-3 px-4 rounded-lg mb-4 bg-green-50 border border-green-200';
            indicator.innerHTML = `
                <i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>
                <div class="flex-1">
                    <div class="text-sm font-medium text-green-900">Check-in effettuato</div>
                    <div class="text-xs text-green-700">${this.formatDateTime(lastAccessTime)}</div>
                </div>
            `;
        } else {
            indicator.className = 'flex items-center space-x-2 py-3 px-4 rounded-lg mb-4 bg-orange-50 border border-orange-200';
            indicator.innerHTML = `
                <i data-lucide="clock" class="w-5 h-5 text-orange-600"></i>
                <div class="flex-1">
                    <div class="text-sm font-medium text-orange-900">In attesa di check-in</div>
                    <div class="text-xs text-orange-700">L'ospite non è ancora arrivato</div>
                </div>
            `;
        }

        lucide.createIcons();
    }

    updateActionButtons(status) {
        const actionButtons = document.getElementById('actionButtons');
        const checkinBtn = document.getElementById('checkinBtn');
        const checkoutBtn = document.getElementById('checkoutBtn');
        
        if (actionButtons) actionButtons.classList.remove('hidden');
        
        if (status === 'checked_in') {
            if (checkinBtn) checkinBtn.classList.add('hidden');
            if (checkoutBtn) checkoutBtn.classList.remove('hidden');
        } else {
            if (checkinBtn) checkinBtn.classList.remove('hidden');
            if (checkoutBtn) checkoutBtn.classList.add('hidden');
        }

        lucide.createIcons();
    }

    async performCheckin() {
        try {
            console.log('[GUEST-DETAIL] Performing check-in...');
            this.showProcessing();

            const response = await API.guests.checkin(this.guestId);

            if (response.success) {
                this.showSuccess(
                    'Check-in completato!',
                    `Check-in effettuato per ${this.guest.first_name} ${this.guest.last_name}`
                );

                this.guest.access_status = 'checked_in';
                this.guest.last_access_time = new Date().toISOString();

                setTimeout(() => {
                    this.hideSuccess();
                    this.updateStatusIndicator('checked_in', this.guest.last_access_time);
                    this.updateActionButtons('checked_in');
                    this.hideProcessing();
                }, 2000);

            } else {
                throw new Error(response.message || 'Errore durante il check-in');
            }

        } catch (error) {
            console.error('[GUEST-DETAIL] Check-in error:', error);
            this.hideProcessing();
            alert('Errore durante il check-in: ' + error.message);
        }
    }

    async performCheckout() {
        try {
            console.log('[GUEST-DETAIL] Performing check-out...');
            this.showProcessing();

            const response = await API.guests.checkout(this.guestId);

            if (response.success) {
                this.showSuccess(
                    'Check-out completato!',
                    `Check-out effettuato per ${this.guest.first_name} ${this.guest.last_name}`
                );

                this.guest.access_status = 'not_checked_in';

                setTimeout(() => {
                    this.hideSuccess();
                    this.updateStatusIndicator('not_checked_in');
                    this.updateActionButtons('not_checked_in');
                    this.hideProcessing();
                }, 2000);

            } else {
                throw new Error(response.message || 'Errore durante il check-out');
            }

        } catch (error) {
            console.error('[GUEST-DETAIL] Check-out error:', error);
            this.hideProcessing();
            alert('Errore durante il check-out: ' + error.message);
        }
    }

    getVipBadge(vipLevel) {
        const badges = {
            'ultra_vip': '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">Ultra VIP</span>',
            'vip': '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">VIP</span>',
            'premium': '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Premium</span>'
        };
        return badges[vipLevel] || '';
    }

    formatDateTime(timestamp) {
        if (!timestamp) return '';
        const date = new Date(timestamp);
        return date.toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    showConfirmation(title, message, callback) {
        this.confirmCallback = callback;
        const confirmTitle = document.getElementById('confirmTitle');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmModal = document.getElementById('confirmModal');
        
        if (confirmTitle) confirmTitle.textContent = title;
        if (confirmMessage) confirmMessage.textContent = message;
        if (confirmModal) confirmModal.classList.remove('hidden');
    }

    hideConfirmation() {
        const confirmModal = document.getElementById('confirmModal');
        if (confirmModal) confirmModal.classList.add('hidden');
        this.confirmCallback = null;
    }

    showSuccess(title, message) {
        const successTitle = document.getElementById('successTitle');
        const successMessage = document.getElementById('successMessage');
        const successModal = document.getElementById('successModal');
        
        if (successTitle) successTitle.textContent = title;
        if (successMessage) successMessage.textContent = message;
        if (successModal) successModal.classList.remove('hidden');
    }

    hideSuccess() {
        const successModal = document.getElementById('successModal');
        if (successModal) successModal.classList.add('hidden');
    }

    showProcessing() {
        const checkinBtn = document.getElementById('checkinBtn');
        const checkoutBtn = document.getElementById('checkoutBtn');
        const processingBtn = document.getElementById('processingBtn');
        
        if (checkinBtn) checkinBtn.classList.add('hidden');
        if (checkoutBtn) checkoutBtn.classList.add('hidden');
        if (processingBtn) processingBtn.classList.remove('hidden');
    }

    hideProcessing() {
        const processingBtn = document.getElementById('processingBtn');
        if (processingBtn) processingBtn.classList.add('hidden');
    }

    showLoading() {
        const loadingState = document.getElementById('loadingState');
        const mainContent = document.getElementById('mainContent');
        const errorState = document.getElementById('errorState');
        const actionButtons = document.getElementById('actionButtons');
        
        if (loadingState) loadingState.classList.remove('hidden');
        if (mainContent) mainContent.classList.add('hidden');
        if (errorState) errorState.classList.add('hidden');
        if (actionButtons) actionButtons.classList.add('hidden');
    }

    hideLoading() {
        const loadingState = document.getElementById('loadingState');
        const mainContent = document.getElementById('mainContent');
        
        if (loadingState) loadingState.classList.add('hidden');
        if (mainContent) mainContent.classList.remove('hidden');
    }

    showError(message) {
        const loadingState = document.getElementById('loadingState');
        const mainContent = document.getElementById('mainContent');
        const errorState = document.getElementById('errorState');
        const actionButtons = document.getElementById('actionButtons');
        const errorMessage = document.getElementById('errorMessage');
        
        if (loadingState) loadingState.classList.add('hidden');
        if (mainContent) mainContent.classList.add('hidden');
        if (errorState) errorState.classList.remove('hidden');
        if (actionButtons) actionButtons.classList.add('hidden');
        if (errorMessage) errorMessage.textContent = message;
        
        console.error('[GUEST-DETAIL] Error:', message);
    }

    /**
     * Show edit guest modal
     */
    showEditGuestModal() {
        try {
            console.log('[GUEST-DETAIL] Opening edit guest modal...');
            
            if (!this.guest) {
                alert('Errore: dati ospite non disponibili');
                return;
            }
            
            // Get or create modal
            let modal = document.getElementById('editGuestModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'editGuestModal';
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4';
                document.body.appendChild(modal);
            }
            
            const g = this.guest;
            
            // Build modal HTML - ✅ FIX: Usa HostessGuestDetailInstance invece di HostessDashboardInstance
            modal.innerHTML = `
                <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gradient-to-r from-purple-600 to-blue-600">
                        <h3 class="text-xl font-bold text-white flex items-center">
                            <i data-lucide="edit" class="w-6 h-6 mr-2"></i>
                            Modifica Ospite
                        </h3>
                        <button onclick="HostessGuestDetailInstance.closeEditModal()" 
                            class="text-white hover:text-gray-200 transition">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <!-- Alert Info -->
                    <div class="px-6 py-3 bg-yellow-50 border-b border-yellow-100">
                        <div class="flex items-start space-x-2 text-sm">
                            <i data-lucide="info" class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5"></i>
                            <div class="text-yellow-800">
                                <strong>Attenzione:</strong> Le modifiche verranno notificate all'amministratore dello stadio via email.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Content -->
                    <div class="flex-1 overflow-y-auto px-6 py-4">
                        <form id="editGuestForm" class="space-y-4">
                            
                            <!-- Nome e Cognome -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Nome <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                        id="editFirstName" 
                                        name="first_name" 
                                        required
                                        maxlength="100"
                                        value="${this.escapeHtml(g.first_name || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                    <p class="mt-1 text-xs text-gray-500">Max 100 caratteri</p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Cognome <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" 
                                        id="editLastName" 
                                        name="last_name" 
                                        required
                                        maxlength="100"
                                        value="${this.escapeHtml(g.last_name || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                    <p class="mt-1 text-xs text-gray-500">Max 100 caratteri</p>
                                </div>
                            </div>
                            
                            <!-- Company -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Azienda
                                </label>
                                <input type="text" 
                                    id="editCompanyName" 
                                    name="company_name"
                                    maxlength="200"
                                    value="${this.escapeHtml(g.company_name || '')}"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                            </div>
                            
                            <!-- Email e Telefono -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Email
                                    </label>
                                    <input type="email" 
                                        id="editContactEmail" 
                                        name="contact_email"
                                        maxlength="255"
                                        value="${this.escapeHtml(g.contact_email || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Telefono
                                    </label>
                                    <input type="tel" 
                                        id="editContactPhone" 
                                        name="contact_phone"
                                        maxlength="20"
                                        value="${this.escapeHtml(g.contact_phone || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                </div>
                            </div>
                            
                            <!-- Tavolo e Posto -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Numero Tavolo
                                    </label>
                                    <input type="text" 
                                        id="editTableNumber" 
                                        name="table_number"
                                        maxlength="50"
                                        value="${this.escapeHtml(g.table_number || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Numero Posto
                                    </label>
                                    <input type="text" 
                                        id="editSeatNumber" 
                                        name="seat_number"
                                        maxlength="50"
                                        value="${this.escapeHtml(g.seat_number || '')}"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                </div>
                            </div>
                            
                            <!-- VIP Level -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Livello VIP
                                </label>
                                <select id="editVipLevel" 
                                    name="vip_level"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition">
                                    <option value="standard" ${g.vip_level === 'standard' ? 'selected' : ''}>Standard</option>
                                    <option value="premium" ${g.vip_level === 'premium' ? 'selected' : ''}>Premium</option>
                                    <option value="vip" ${g.vip_level === 'vip' ? 'selected' : ''}>VIP</option>
                                    <option value="ultra_vip" ${g.vip_level === 'ultra_vip' ? 'selected' : ''}>Ultra VIP</option>
                                </select>
                            </div>
                            
                            <!-- Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Note
                                </label>
                                <textarea id="editNotes" 
                                    name="notes" 
                                    rows="4"
                                    maxlength="2000"
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition resize-none"
                                    placeholder="Note aggiuntive sull'ospite...">${this.escapeHtml(g.notes || '')}</textarea>
                                <div class="flex items-center justify-between mt-1">
                                    <p class="text-xs text-gray-500">Max 2000 caratteri</p>
                                    <p class="text-xs text-gray-500" id="notesCounter">0 / 2000</p>
                                </div>
                            </div>
                            
                        </form>
                    </div>
                    
                    <!-- Footer Buttons -->
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3 bg-gray-50">
                        <button onclick="HostessGuestDetailInstance.closeEditModal()" 
                            class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-100 transition">
                            Annulla
                        </button>
                        <button onclick="HostessGuestDetailInstance.saveGuestEdit()" 
                            class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 text-white rounded-lg font-medium hover:from-purple-700 hover:to-blue-700 transition shadow-lg flex items-center space-x-2">
                            <i data-lucide="save" class="w-5 h-5"></i>
                            <span>Salva Modifiche</span>
                        </button>
                    </div>
                </div>
            `;
            
            // Show modal
            modal.classList.remove('hidden');
            
            // Initialize icons
            lucide.createIcons();
            
            // Setup character counter for notes
            const notesTextarea = document.getElementById('editNotes');
            const notesCounter = document.getElementById('notesCounter');
            
            const updateCounter = () => {
                const length = notesTextarea.value.length;
                notesCounter.textContent = `${length} / 2000`;
                if (length > 1900) {
                    notesCounter.classList.add('text-orange-600', 'font-medium');
                } else {
                    notesCounter.classList.remove('text-orange-600', 'font-medium');
                }
            };
            
            notesTextarea.addEventListener('input', updateCounter);
            updateCounter();
            
            // Focus first input
            setTimeout(() => {
                document.getElementById('editFirstName').focus();
            }, 100);
            
        } catch (error) {
            console.error('[GUEST-DETAIL] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
    }

    /**
     * Close edit modal
     */
    closeEditModal() {
        const modal = document.getElementById('editGuestModal');
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    /**
     * Save guest edits
     */
    async saveGuestEdit() {
        try {
            console.log('[GUEST-DETAIL] Saving guest edits...');
            
            // Get form data
            const form = document.getElementById('editGuestForm');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            // Collect data
            const data = {
                first_name: document.getElementById('editFirstName').value.trim(),
                last_name: document.getElementById('editLastName').value.trim(),
                company_name: document.getElementById('editCompanyName').value.trim(),
                contact_email: document.getElementById('editContactEmail').value.trim(),
                contact_phone: document.getElementById('editContactPhone').value.trim(),
                table_number: document.getElementById('editTableNumber').value.trim(),
                seat_number: document.getElementById('editSeatNumber').value.trim(),
                vip_level: document.getElementById('editVipLevel').value,
                notes: document.getElementById('editNotes').value.trim()
            };
            
            // Validate required fields
            if (!data.first_name || !data.last_name) {
                alert('Nome e Cognome sono obbligatori');
                return;
            }
            
            // Validate notes length
            if (data.notes.length > 2000) {
                alert('Le note non possono superare i 2000 caratteri');
                return;
            }
            
            // Confirm action
            if (!confirm('Confermi le modifiche?\n\nVerrà inviata una notifica all\'amministratore con il riepilogo delle modifiche effettuate.')) {
                return;
            }
            
            // Show loading
            this.showProcessing();
            this.closeEditModal();
            
            // Call API
            const response = await API.guests.update(this.guestId, data);
            
            if (response.success) {
                // Update local guest data
                Object.assign(this.guest, data);
                
                // Show success
                this.showSuccess(
                    'Modifiche salvate!',
                    `I dati di ${data.first_name} ${data.last_name} sono stati aggiornati.${response.data.notification_queued ? '\n\nL\'amministratore è stato notificato.' : ''}`
                );
                
                // Reload guest details after 2 seconds
                setTimeout(async () => {
                    this.hideSuccess();
                    await this.loadGuestDetail();
                    this.hideProcessing();
                }, 2000);
                
                console.log('[GUEST-DETAIL] Guest updated successfully');
                
            } else {
                throw new Error(response.message || 'Errore durante il salvataggio');
            }
            
        } catch (error) {
            console.error('[GUEST-DETAIL] Failed to save guest edits:', error);
            this.hideProcessing();
            
            let errorMessage = 'Errore durante il salvataggio delle modifiche';
            
            if (error.message) {
                errorMessage += ':\n' + error.message;
            }
            
            alert(errorMessage);
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }


}

// Initialize when DOM is ready
let HostessGuestDetailInstance;
document.addEventListener('DOMContentLoaded', () => {
    HostessGuestDetailInstance = new HostessGuestDetail();
});