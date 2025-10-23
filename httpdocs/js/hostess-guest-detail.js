/**
 * Hostess Guest Detail - Check-in/out Logic
 * Gestione dettaglio ospite con operazioni di accesso
 */

class HostessGuestDetail {
    constructor() {
        this.guestId = null;
        this.guest = null;
        this.user = null;
        this.userData = null;
        this.confirmCallback = null;
        this.guestUpdatedAt = null;
        
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
            console.log('[GUEST-DETAIL] Fetching user data...');
            const userData = await Auth.getCurrentUser();
            
            console.log('[GUEST-DETAIL] User data received:', userData);
            
            const userRole = userData.user?.role || userData.role;
            const viewType = userData.role_specific_data?.view_type;
            
            console.log('[GUEST-DETAIL] User role:', userRole);
            console.log('[GUEST-DETAIL] View type:', viewType);
            
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
                console.error('[GUEST-DETAIL] ❌ Access denied');
                alert('Accesso negato. Solo per hostess.');
                window.location.href = 'hostess-dashboard.html';
                return;
            }
            
            console.log('[GUEST-DETAIL] ✅ Access granted');
            
            // Salva i dati utente
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
        console.log('[GUEST-DETAIL] Attaching event listeners...');
        
        // Back button
        const backBtn = document.getElementById('backBtn');
        if (backBtn) {
            backBtn.addEventListener('click', () => {
                window.location.href = 'hostess-dashboard.html';
            });
        }

        // Edit button
        const editBtn = document.getElementById('editGuestBtn');
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                this.showEditModal();
            });
        }

        // More options
        const moreBtn = document.getElementById('moreBtn');
        const moreMenu = document.getElementById('moreMenu');
        const cancelMoreBtn = document.getElementById('cancelMoreBtn');
        
        if (moreBtn && moreMenu) {
            moreBtn.addEventListener('click', () => {
                moreMenu.style.display = 'flex';
            });
        }
        
        if (cancelMoreBtn && moreMenu) {
            cancelMoreBtn.addEventListener('click', () => {
                moreMenu.style.display = 'none';
            });
        }

        // Check-in button
        const checkinBtn = document.getElementById('checkinBtn');
        if (checkinBtn) {
            checkinBtn.addEventListener('click', () => {
                this.showConfirmation(
                    'Conferma Check-in',
                    `Vuoi effettuare il check-in?`,
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
                    `Vuoi effettuare il check-out?`,
                    () => this.performCheckout()
                );
            });
        }

        // Confirmation modal
        const confirmYes = document.getElementById('confirmYes');
        const confirmNo = document.getElementById('confirmNo');
        
        if (confirmYes) {
            confirmYes.addEventListener('click', () => {
                this.hideConfirmation();
                if (this.confirmCallback) {
                    this.confirmCallback();
                }
            });
        }
        
        if (confirmNo) {
            confirmNo.addEventListener('click', () => {
                this.hideConfirmation();
            });
        }

        // Retry button
        const retryBtn = document.getElementById('retryBtn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                this.loadGuestDetail();
            });
        }

        // Force buttons
        const forceCheckinBtn = document.getElementById('forceCheckinBtn');
        if (forceCheckinBtn) {
            forceCheckinBtn.addEventListener('click', () => {
                if (confirm('ATTENZIONE: Stai forzando il check-in. Continuare?')) {
                    this.forceState('entry');
                }
            });
        }

        const forceCheckoutBtn = document.getElementById('forceCheckoutBtn');
        if (forceCheckoutBtn) {
            forceCheckoutBtn.addEventListener('click', () => {
                if (confirm('ATTENZIONE: Stai forzando il check-out. Continuare?')) {
                    this.forceState('exit');
                }
            });
        }
    }

    /**
     * Attacca event listener dinamici ai bottoni check-in/out
     * Chiamato dopo ogni render per garantire che i bottoni funzionino
     */
    attachDynamicEventListeners() {
        console.log('[GUEST-DETAIL] Attaching dynamic event listeners to action buttons...');
        
        // Check-in button
        const checkinBtn = document.getElementById('checkinBtn');
        if (checkinBtn) {
            // Rimuovi vecchi listener (se esistono)
            checkinBtn.replaceWith(checkinBtn.cloneNode(true));
            const newCheckinBtn = document.getElementById('checkinBtn');
            
            newCheckinBtn.addEventListener('click', () => {
                console.log('[GUEST-DETAIL] Check-in button clicked');
                this.showConfirmation(
                    'Conferma Check-in',
                    `Vuoi effettuare il check-in di ${this.guest.first_name} ${this.guest.last_name}?`,
                    () => this.performCheckin()
                );
            });
            console.log('[GUEST-DETAIL] Check-in listener attached');
        } else {
            console.warn('[GUEST-DETAIL] Check-in button not found in DOM');
        }

        // Check-out button
        const checkoutBtn = document.getElementById('checkoutBtn');
        if (checkoutBtn) {
            // Rimuovi vecchi listener (se esistono)
            checkoutBtn.replaceWith(checkoutBtn.cloneNode(true));
            const newCheckoutBtn = document.getElementById('checkoutBtn');
            
            newCheckoutBtn.addEventListener('click', () => {
                console.log('[GUEST-DETAIL] Check-out button clicked');
                this.showConfirmation(
                    'Conferma Check-out',
                    `Vuoi effettuare il check-out di ${this.guest.first_name} ${this.guest.last_name}?`,
                    () => this.performCheckout()
                );
            });
            console.log('[GUEST-DETAIL] Check-out listener attached');
        } else {
            console.warn('[GUEST-DETAIL] Check-out button not found in DOM');
        }
    }

    async loadGuestDetail() {
        try {
            console.log('[GUEST-DETAIL] Loading guest detail for ID:', this.guestId);
            this.showLoading();

            const response = await API.guests.get(this.guestId);
            
            if (response.success && response.data && response.data.guest) {
                this.guest = response.data.guest;
                
                if (!this.guest.first_name || !this.guest.last_name) {
                    throw new Error('Dati ospite incompleti');
                }
                
                this.guestUpdatedAt = this.guest.updated_at;
                
                this.renderGuestDetail();
                this.hideLoading();
                this.attachDynamicEventListeners();
                
            } else {
                throw new Error(response.message || 'Ospite non trovato');
            }

        } catch (error) {
            console.error('[GUEST-DETAIL] Load error:', error);
            this.hideLoading();
            
            let errorMessage = 'Errore nel caricamento';
            
            if (error.status === 403) {
                errorMessage = 'Non hai accesso a questo ospite';
            } else if (error.status === 404) {
                errorMessage = 'Ospite non trovato';
            } else if (error.message) {
                errorMessage = error.message;
            }
            
            this.showError(errorMessage);
        }
    }

    renderGuestDetail() {
        console.log('[GUEST-DETAIL] Rendering guest detail...');

        // Guest name (con controllo null)
        const guestName = document.getElementById('guestName');
        if (guestName) {
            guestName.textContent = `${this.guest.first_name} ${this.guest.last_name}`;
        }

        // Company (con controllo null)
        const guestCompany = document.getElementById('guestCompany');
        if (guestCompany) {
            guestCompany.textContent = this.guest.company_name || '';
            guestCompany.style.display = this.guest.company_name ? 'block' : 'none';
        }

        // VIP Badge
        this.renderVipBadge();

        // Status Indicator
        this.updateStatusIndicator(this.guest.access_status, this.guest.last_access_time);

        // Room (con controllo null)
        const guestRoom = document.getElementById('guestRoom');
        if (guestRoom) {
            guestRoom.textContent = this.guest.room_name || 'Sala non assegnata';
        }

        // Table (con controllo null)
        const guestTable = document.getElementById('guestTable');
        const tableInfo = document.getElementById('tableInfo');
        if (this.guest.table_number) {
            if (guestTable) guestTable.textContent = `Tavolo ${this.guest.table_number}`;
            if (tableInfo) tableInfo.style.display = 'flex';
        } else {
            if (tableInfo) tableInfo.style.display = 'none';
        }

        // Seat (con controllo null)
        const guestSeat = document.getElementById('guestSeat');
        const seatInfo = document.getElementById('seatInfo');
        if (this.guest.seat_number) {
            if (guestSeat) guestSeat.textContent = `Posto ${this.guest.seat_number}`;
            if (seatInfo) seatInfo.style.display = 'flex';
        } else {
            if (seatInfo) seatInfo.style.display = 'none';
        }

        // Contact Info
        this.renderContactInfo();

        // Event Info (con controllo null)
        const eventName = document.getElementById('eventName');
        const eventDate = document.getElementById('eventDate');
        if (eventName) eventName.textContent = this.guest.event_name || 'Evento non disponibile';
        if (eventDate && this.guest.event_date) {
            const date = new Date(this.guest.event_date);
            eventDate.textContent = date.toLocaleDateString('it-IT', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }

        // Notes (con controllo null)
        const notesSection = document.getElementById('notesSection');
        const guestNotes = document.getElementById('guestNotes');
        if (this.guest.notes) {
            if (guestNotes) guestNotes.textContent = this.guest.notes;
            if (notesSection) notesSection.classList.remove('hidden');
        } else {
            if (notesSection) notesSection.classList.add('hidden');
        }

        // Action buttons
        this.updateActionButtons(this.guest.access_status);

        // Show main content (con controllo null)
        const mainContent = document.getElementById('mainContent');
        if (mainContent) mainContent.classList.remove('hidden');
    }

    renderVipBadge() {
        const vipBadge = document.getElementById('vipBadge');
        if (!vipBadge) return;

        const badges = {
            'ultra_vip': '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-purple-100 text-purple-800">Ultra VIP</span>',
            'vip': '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-800">VIP</span>',
            'premium': '<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-800">Premium</span>'
        };

        vipBadge.innerHTML = badges[this.guest.vip_level] || '';
    }

    updateStatusIndicator(status, lastAccessTime) {
        const indicator = document.getElementById('statusIndicator');
        const guestCard = document.getElementById('guestCard');
        
        if (!indicator) return;

        if (status === 'checked_in') {
            const time = lastAccessTime ? new Date(lastAccessTime).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) : '';
            indicator.innerHTML = `
                <i data-lucide="check-circle" class="w-5 h-5 text-green-600"></i>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-green-900">Check-in effettuato</div>
                    ${time ? `<div class="text-xs text-green-600">Ore ${time}</div>` : ''}
                </div>
            `;
            indicator.className = 'flex items-center space-x-2 py-3 px-4 rounded-lg mb-4 bg-green-50';
            if (guestCard) guestCard.className = 'bg-white rounded-xl shadow-sm p-6 border-l-4 border-green-500';
        } else {
            indicator.innerHTML = `
                <i data-lucide="clock" class="w-5 h-5 text-orange-600"></i>
                <div class="flex-1">
                    <div class="text-sm font-semibold text-orange-900">In attesa di check-in</div>
                </div>
            `;
            indicator.className = 'flex items-center space-x-2 py-3 px-4 rounded-lg mb-4 bg-orange-50';
            if (guestCard) guestCard.className = 'bg-white rounded-xl shadow-sm p-6 border-l-4 border-orange-400';
        }

        lucide.createIcons();
    }

    renderContactInfo() {
        const emailSection = document.getElementById('emailSection');
        const phoneSection = document.getElementById('phoneSection');
        const noContactInfo = document.getElementById('noContactInfo');
        const guestEmail = document.getElementById('guestEmail');
        const guestPhone = document.getElementById('guestPhone');
        const emailLink = document.getElementById('emailLink');
        const phoneLink = document.getElementById('phoneLink');

        let hasContact = false;

        if (this.guest.contact_email) {
            if (guestEmail) guestEmail.textContent = this.guest.contact_email;
            if (emailLink) emailLink.href = `mailto:${this.guest.contact_email}`;
            if (emailSection) emailSection.classList.remove('hidden');
            hasContact = true;
        } else {
            if (emailSection) emailSection.classList.add('hidden');
        }

        if (this.guest.contact_phone) {
            if (guestPhone) guestPhone.textContent = this.guest.contact_phone;
            if (phoneLink) phoneLink.href = `tel:${this.guest.contact_phone}`;
            if (phoneSection) phoneSection.classList.remove('hidden');
            hasContact = true;
        } else {
            if (phoneSection) phoneSection.classList.add('hidden');
        }

        if (noContactInfo) {
            noContactInfo.style.display = hasContact ? 'none' : 'block';
        }
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
    }

    async performCheckin() {
        try {
            console.log('[GUEST-DETAIL] Performing check-in...');
            this.showProcessing();

            const response = await API.guests.checkin(this.guestId);
            
            if (response.success) {
                this.showSuccess(
                    'Check-in effettuato!',
                    `${this.guest.first_name} ${this.guest.last_name} è stato registrato in ingresso.`
                );
                
                setTimeout(() => {
                    this.hideSuccess();
                    this.hideProcessing();
                    this.loadGuestDetail();
                }, 2000);
            } else {
                throw new Error(response.message || 'Errore durante il check-in');
            }
        } catch (error) {
            console.error('[GUEST-DETAIL] Check-in error:', error);
            this.hideProcessing();
            
            // Gestione conflitto
            if (error.status === 409 || error.message?.includes('conflict')) {
                alert('⚠️ Un\'altra hostess ha modificato questo ospite.\n\nRicarico i dati...');
                await this.loadGuestDetail();
            } else {
                alert('Errore durante il check-in: ' + error.message);
            }
        }
    }

    async performCheckout() {
        try {
            console.log('[GUEST-DETAIL] Performing check-out...');
            this.showProcessing();

            const response = await API.guests.checkout(this.guestId);
            
            if (response.success) {
                this.showSuccess(
                    'Check-out effettuato!',
                    `${this.guest.first_name} ${this.guest.last_name} è stato registrato in uscita.`
                );
                
                setTimeout(() => {
                    this.hideSuccess();
                    this.hideProcessing();
                    this.loadGuestDetail();
                }, 2000);
            } else {
                throw new Error(response.message || 'Errore durante il check-out');
            }
        } catch (error) {
            console.error('[GUEST-DETAIL] Check-out error:', error);
            this.hideProcessing();
            
            // Gestione conflitto
            if (error.status === 409 || error.message?.includes('conflict')) {
                alert('⚠️ Un\'altra hostess ha modificato questo ospite.\n\nRicarico i dati...');
                await this.loadGuestDetail();
            } else {
                alert('Errore durante il check-out: ' + error.message);
            }
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
                    'Stato forzato!',
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
            this.hideProcessing();
            alert('Errore: ' + error.message);
        }
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
        
        if (loadingState) loadingState.classList.remove('hidden');
        if (mainContent) mainContent.classList.add('hidden');
        if (errorState) errorState.classList.add('hidden');
    }

    hideLoading() {
        const loadingState = document.getElementById('loadingState');
        if (loadingState) loadingState.classList.add('hidden');
    }

    showError(message) {
        const loadingState = document.getElementById('loadingState');
        const mainContent = document.getElementById('mainContent');
        const errorState = document.getElementById('errorState');
        const errorMessage = document.getElementById('errorMessage');
        
        if (loadingState) loadingState.classList.add('hidden');
        if (mainContent) mainContent.classList.add('hidden');
        if (errorState) errorState.classList.remove('hidden');
        if (errorMessage) errorMessage.textContent = message;
    }

    /**
     * Show edit guest modal
     */
    async showEditModal() {
        try {
            console.log('[GUEST-DETAIL] Opening edit modal');
            
            if (!this.guest) {
                alert('Dati ospite non disponibili');
                return;
            }
            
            // Create modal if not exists
            let modal = document.getElementById('editGuestModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'editGuestModal';
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4';
                document.body.appendChild(modal);
            }
            
            const g = this.guest;
            
            // Build modal HTML
            modal.innerHTML = `
                <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between bg-gradient-to-r from-purple-600 to-blue-600">
                        <h3 class="text-xl font-bold text-white flex items-center">
                            <i data-lucide="edit" class="w-6 h-6 mr-2"></i>
                            Modifica Ospite
                        </h3>
                        <button onclick="document.getElementById('editGuestModal').classList.add('hidden')" 
                            class="text-white hover:text-gray-200 transition">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <!-- Alert Info -->
                    <div class="px-6 py-3 bg-yellow-50 border-b border-yellow-100">
                        <div class="flex items-start space-x-2 text-sm">
                            <i data-lucide="info" class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5"></i>
                            <div class="text-yellow-800">
                                <strong>Attenzione:</strong> Le modifiche verranno notificate all'amministratore.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form -->
                    <form id="editGuestForm" class="flex-1 overflow-y-auto px-6 py-4">
                        <div class="space-y-4">
                            
                            <!-- Nome -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Nome <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="editFirstName" required
                                    value="${this.escapeHtml(g.first_name)}"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Cognome -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cognome <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="editLastName" required
                                    value="${this.escapeHtml(g.last_name)}"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Azienda -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Azienda
                                </label>
                                <input type="text" id="editCompanyName"
                                    value="${this.escapeHtml(g.company_name || '')}"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Email
                                </label>
                                <input type="email" id="editContactEmail"
                                    value="${this.escapeHtml(g.contact_email || '')}"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Telefono -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Telefono
                                </label>
                                <input type="tel" id="editContactPhone"
                                    value="${this.escapeHtml(g.contact_phone || '')}"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Tavolo e Posto -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Numero Tavolo
                                    </label>
                                    <input type="text" id="editTableNumber"
                                        value="${this.escapeHtml(g.table_number || '')}"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Numero Posto
                                    </label>
                                    <input type="text" id="editSeatNumber"
                                        value="${this.escapeHtml(g.seat_number || '')}"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                </div>
                            </div>
                            
                            <!-- VIP Level -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Livello VIP <span class="text-red-500">*</span>
                                </label>
                                <select id="editVipLevel" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="standard" ${g.vip_level === 'standard' ? 'selected' : ''}>Standard</option>
                                    <option value="premium" ${g.vip_level === 'premium' ? 'selected' : ''}>Premium</option>
                                    <option value="vip" ${g.vip_level === 'vip' ? 'selected' : ''}>VIP</option>
                                    <option value="ultra_vip" ${g.vip_level === 'ultra_vip' ? 'selected' : ''}>Ultra VIP</option>
                                </select>
                            </div>
                            
                            <!-- Note -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Note <span class="text-xs text-gray-500" id="notesCounter">0 / 2000</span>
                                </label>
                                <textarea id="editNotes" rows="4" maxlength="2000"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">${this.escapeHtml(g.notes || '')}</textarea>
                            </div>
                            
                        </div>
                    </form>
                    
                    <!-- Footer -->
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="document.getElementById('editGuestModal').classList.add('hidden')" 
                            class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition font-medium">
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
            
            if (notesTextarea && notesCounter) {
                notesTextarea.addEventListener('input', updateCounter);
                updateCounter();
            }
            
            // Focus first input
            setTimeout(() => {
                const firstNameInput = document.getElementById('editFirstName');
                if (firstNameInput) firstNameInput.focus();
            }, 100);
            
        } catch (error) {
            console.error('[GUEST-DETAIL] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
    }

    /**
     * Save guest edits
     */
    async saveGuestEdit() {
        try {
            console.log('[GUEST-DETAIL] Saving guest edits...');
            
            // Get form data
            const data = {
                first_name: document.getElementById('editFirstName').value.trim(),
                last_name: document.getElementById('editLastName').value.trim(),
                company_name: document.getElementById('editCompanyName').value.trim(),
                contact_email: document.getElementById('editContactEmail').value.trim(),
                contact_phone: document.getElementById('editContactPhone').value.trim(),
                table_number: document.getElementById('editTableNumber').value.trim(),
                seat_number: document.getElementById('editSeatNumber').value.trim(),
                vip_level: document.getElementById('editVipLevel').value,
                notes: document.getElementById('editNotes').value.trim(),
                expected_updated_at: this.guestUpdatedAt
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
            if (!confirm('Confermi le modifiche?\n\nVerrà inviata una notifica all\'amministratore.')) {
                return;
            }
            
            // Close modal
            const modal = document.getElementById('editGuestModal');
            if (modal) modal.classList.add('hidden');
            
            // Show processing
            this.showProcessing();
            
            try {
                // Call API
                const response = await API.guests.update(this.guestId, data);
                
                if (response.success) {
                    // Update local guest data
                    Object.assign(this.guest, data);
                    
                    // Aggiorna updated_at con il nuovo valore dal server
                    if (response.data && response.data.guest && response.data.guest.updated_at) {
                        this.guestUpdatedAt = response.data.guest.updated_at;
                        console.log('[GUEST-DETAIL] Updated updated_at:', this.guestUpdatedAt);
                    }
                    
                    // Show success
                    this.showSuccess(
                        'Modifiche salvate!',
                        `I dati di ${data.first_name} ${data.last_name} sono stati aggiornati.`
                    );
                    
                    // Re-render detail after 2 seconds
                    setTimeout(() => {
                        this.hideSuccess();
                        this.hideProcessing();
                        // Ricarica i dati aggiornati dal server
                        this.loadGuestDetail();
                    }, 2000);
                    
                } else {
                    throw new Error(response.message || 'Errore nel salvataggio');
                }
            } catch (error) {
                // GESTIONE CONFLITTO HTTP 409
                if (error.status === 409 || 
                    (error.response && error.response.data && error.response.data.conflict) ||
                    error.message?.includes('conflict')) {
                    
                    console.warn('[GUEST-DETAIL] Data conflict detected');
                    this.hideProcessing();
                    
                    alert('⚠️ CONFLITTO DATI\n\n' +
                          'Un\'altra hostess ha modificato questo ospite mentre stavi lavorando.\n\n' +
                          'I dati verranno ricaricati con le modifiche più recenti.');
                    
                    // Ricarica i dati aggiornati
                    await this.loadGuestDetail();
                    
                    return;
                }
                
                // Altri errori
                throw error;
            }
            
        } catch (error) {
            console.error('[GUEST-DETAIL] Save edit error:', error);
            this.hideProcessing();
            alert('Errore nel salvataggio: ' + error.message);
        }
    }

    /**
     * Escape HTML helper
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

}

// Initialize when DOM is ready
let HostessGuestDetailInstance;  // ✅ Variabile globale
document.addEventListener('DOMContentLoaded', () => {
    console.log('[GUEST-DETAIL] DOM loaded, creating instance...');
    HostessGuestDetailInstance = new HostessGuestDetail();  // ✅ Salva l'istanza
});