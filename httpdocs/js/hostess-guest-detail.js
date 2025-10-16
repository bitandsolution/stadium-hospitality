/**
 * Hostess Guest Detail - Check-in/out Logic
 * Gestione dettaglio ospite con operazioni di accesso
 */

class HostessGuestDetail {
    constructor() {
        this.guestId = null;
        this.guest = null;
        this.user = null;
        
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
            this.user = await Auth.getCurrentUser();
            
            if (this.user.role !== 'hostess') {
                Utils.showToast('Accesso negato', 'error');
                setTimeout(() => window.location.href = 'index.html', 2000);
                return;
            }

            // Get guest ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            this.guestId = parseInt(urlParams.get('id'));
            
            if (!this.guestId) {
                throw new Error('ID ospite non valido');
            }

            this.attachEventListeners();
            await this.loadGuestDetail();
            
        } catch (error) {
            console.error('Init error:', error);
            this.showError(error.message || 'Errore di inizializzazione');
        }
    }

    attachEventListeners() {
        // Back button
        document.getElementById('backBtn').addEventListener('click', () => {
            window.location.href = 'hostess-dashboard.html';
        });

        // Check-in button
        document.getElementById('checkinBtn').addEventListener('click', () => {
            this.showConfirmation(
                'Conferma Check-in',
                'Vuoi effettuare il check-in di questo ospite?',
                () => this.performCheckin()
            );
        });

        // Check-out button
        document.getElementById('checkoutBtn').addEventListener('click', () => {
            this.showConfirmation(
                'Conferma Check-out',
                'Vuoi effettuare il check-out di questo ospite?',
                () => this.performCheckout()
            );
        });

        // Retry button
        document.getElementById('retryBtn').addEventListener('click', () => {
            this.loadGuestDetail();
        });

        // Modal buttons
        document.getElementById('confirmYes').addEventListener('click', () => {
            if (this.confirmCallback) {
                this.confirmCallback();
            }
            this.hideConfirmation();
        });

        document.getElementById('confirmNo').addEventListener('click', () => {
            this.hideConfirmation();
        });
        
        // More menu
        document.getElementById('moreBtn').addEventListener('click', () => {
            document.getElementById('moreMenu').style.display = 'flex';
        });

        document.getElementById('cancelMoreBtn').addEventListener('click', () => {
            document.getElementById('moreMenu').style.display = 'none';
        });

        document.getElementById('moreMenu').addEventListener('click', (e) => {
            if (e.target.id === 'moreMenu') {
                document.getElementById('moreMenu').style.display = 'none';
            }
        });

        // Force check-in
        document.getElementById('forceCheckinBtn').addEventListener('click', () => {
            if (confirm('ATTENZIONE: Stai forzando lo stato a CHECK-IN.\nQuesta operazione sovrascriverà eventuali errori.\n\nContinuare?')) {
                this.forceState('entry');
            }
        });

        // Force check-out
        document.getElementById('forceCheckoutBtn').addEventListener('click', () => {
            if (confirm('ATTENZIONE: Stai forzando lo stato a CHECK-OUT.\nQuesta operazione sovrascriverà eventuali errori.\n\nContinuare?')) {
                this.forceState('exit');
            }
        });
        
    }

    async forceState(accessType) {
        try {
            document.getElementById('moreMenu').style.display = 'none';
            this.showProcessing();

            const endpoint = accessType === 'entry' ? 'checkin' : 'checkout';
            
            // Usa API wrapper
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
                // Se API fallisce comunque, aggiorna solo UI locale
                throw new Error(response.message);
            }

        } catch (error) {
            console.error('Force state error:', error);
            
            // FALLBACK: aggiorna solo UI se backend non risponde
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
            this.showLoading();

            // Call API to get FRESH guest details
            const response = await API.guests.get(this.guestId);

            if (response.success) {
                this.guest = response.data.guest;
                this.renderGuestDetail();
                this.hideLoading();
            } else {
                throw new Error(response.message || 'Errore nel caricamento ospite');
            }

        } catch (error) {
            console.error('Load guest error:', error);
            this.hideLoading();
            this.showError(error.message || 'Errore di connessione');
        }
    }

    renderGuestDetail() {
        const g = this.guest;

        // Update guest card
        document.getElementById('guestName').textContent = `${g.last_name} ${g.first_name}`;
        
        if (g.company_name) {
            document.getElementById('guestCompany').textContent = g.company_name;
        } else {
            document.getElementById('guestCompany').classList.add('hidden');
        }

        // VIP badge
        const vipBadge = this.getVipBadge(g.vip_level);
        if (vipBadge) {
            document.getElementById('vipBadge').innerHTML = vipBadge;
        }

        // Border color based on VIP level
        const borderColors = {
            'ultra_vip': 'border-purple-500',
            'vip': 'border-blue-500',
            'premium': 'border-green-500',
            'standard': 'border-gray-300'
        };
        document.getElementById('guestCard').className = 
            `bg-white rounded-xl shadow-sm p-6 border-l-4 ${borderColors[g.vip_level] || borderColors.standard}`;

        // Status indicator
        this.updateStatusIndicator(g.access_status, g.last_access_time);

        // Room and table info
        document.getElementById('guestRoom').textContent = g.room_name;
        
        if (g.table_number) {
            document.getElementById('guestTable').textContent = `Tavolo ${g.table_number}`;
        } else {
            document.getElementById('tableInfo').classList.add('hidden');
        }

        if (g.seat_number) {
            document.getElementById('guestSeat').textContent = `Posto ${g.seat_number}`;
        } else {
            document.getElementById('seatInfo').classList.add('hidden');
        }

        // Contact info
        const hasEmail = g.contact_email && g.contact_email.trim();
        const hasPhone = g.contact_phone && g.contact_phone.trim();

        if (hasEmail) {
            document.getElementById('emailSection').classList.remove('hidden');
            document.getElementById('guestEmail').textContent = g.contact_email;
            document.getElementById('emailLink').href = `mailto:${g.contact_email}`;
            document.getElementById('noContactInfo').classList.add('hidden');
        }

        if (hasPhone) {
            document.getElementById('phoneSection').classList.remove('hidden');
            document.getElementById('guestPhone').textContent = g.contact_phone;
            document.getElementById('phoneLink').href = `tel:${g.contact_phone}`;
            document.getElementById('noContactInfo').classList.add('hidden');
        }

        if (!hasEmail && !hasPhone) {
            document.getElementById('noContactInfo').classList.remove('hidden');
        }

        // Event info
        document.getElementById('eventName').textContent = g.event_name || 'Evento sconosciuto';
        if (g.event_date) {
            const eventDate = new Date(g.event_date);
            document.getElementById('eventDate').textContent = eventDate.toLocaleDateString('it-IT', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }

        // Notes
        if (g.notes && g.notes.trim()) {
            document.getElementById('notesSection').classList.remove('hidden');
            document.getElementById('guestNotes').textContent = g.notes;
        }

        // Action buttons
        this.updateActionButtons(g.access_status);

        // Re-initialize icons
        lucide.createIcons();
    }

    updateStatusIndicator(status, lastAccessTime) {
        const indicator = document.getElementById('statusIndicator');
        
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
        document.getElementById('actionButtons').classList.remove('hidden');
        
        if (status === 'checked_in') {
            document.getElementById('checkinBtn').classList.add('hidden');
            document.getElementById('checkoutBtn').classList.remove('hidden');
        } else {
            document.getElementById('checkinBtn').classList.remove('hidden');
            document.getElementById('checkoutBtn').classList.add('hidden');
        }

        lucide.createIcons();
    }

    async performCheckin() {
        try {
            this.showProcessing();

            // USA API wrapper invece di fetch diretto
            const response = await API.guests.checkin(this.guestId);

            if (response.success) {
                this.showSuccess(
                    'Check-in completato!',
                    `Check-in effettuato per ${this.guest.first_name} ${this.guest.last_name}`
                );

                // Update guest status
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
            console.error('Check-in error:', error);
            this.hideProcessing();
            alert('Errore durante il check-in: ' + error.message);
        }
    }

    async performCheckout() {
        try {
            this.showProcessing();

            // USA API wrapper
            const response = await API.guests.checkout(this.guestId);

            if (response.success) {
                this.showSuccess(
                    'Check-out completato!',
                    `Check-out effettuato per ${this.guest.first_name} ${this.guest.last_name}`
                );

                // Update guest status
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
            console.error('Check-out error:', error);
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
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        document.getElementById('confirmModal').classList.remove('hidden');
    }

    hideConfirmation() {
        document.getElementById('confirmModal').classList.add('hidden');
        this.confirmCallback = null;
    }

    showSuccess(title, message) {
        document.getElementById('successTitle').textContent = title;
        document.getElementById('successMessage').textContent = message;
        document.getElementById('successModal').classList.remove('hidden');
    }

    hideSuccess() {
        document.getElementById('successModal').classList.add('hidden');
    }

    showProcessing() {
        document.getElementById('checkinBtn').classList.add('hidden');
        document.getElementById('checkoutBtn').classList.add('hidden');
        document.getElementById('processingBtn').classList.remove('hidden');
    }

    hideProcessing() {
        document.getElementById('processingBtn').classList.add('hidden');
    }

    showLoading() {
        document.getElementById('loadingState').classList.remove('hidden');
        document.getElementById('mainContent').classList.add('hidden');
        document.getElementById('errorState').classList.add('hidden');
        document.getElementById('actionButtons').classList.add('hidden');
    }

    hideLoading() {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('mainContent').classList.remove('hidden');
    }

    showError(message) {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('mainContent').classList.add('hidden');
        document.getElementById('errorState').classList.remove('hidden');
        document.getElementById('actionButtons').classList.add('hidden');
        document.getElementById('errorMessage').textContent = message;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new HostessGuestDetail();
});