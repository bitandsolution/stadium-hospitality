/**
 * Guests Page Controller
 * Handles guest list, search, filters, and CRUD operations
 */

const Guests = {
    // State
    currentPage: 1,
    pageSize: 50,
    totalGuests: 0,
    guests: [],
    filters: {
        search: '',
        room_id: '',
        vip_level: '',
        access_status: ''
    },
    rooms: [],
    
    /**
     * Initialize guests page
     */
    async init() {
        console.log('[GUESTS] Initializing...');
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Load rooms for filter
        await this.loadRooms();
        
        // Load guests
        await this.loadGuests();
    },
    
    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Search input with debouncing
        const searchInput = document.getElementById('searchInput');
        let searchTimeout;
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.filters.search = e.target.value.trim();
                this.currentPage = 1;
                this.loadGuests();
            }, 300); // 300ms debounce
        });
        
        // Room filter
        document.getElementById('roomFilter').addEventListener('change', (e) => {
            this.filters.room_id = e.target.value;
            this.currentPage = 1;
            this.loadGuests();
        });
        
        // VIP filter
        document.getElementById('vipFilter').addEventListener('change', (e) => {
            this.filters.vip_level = e.target.value;
            this.currentPage = 1;
            this.loadGuests();
        });
        
        // Status filter
        document.getElementById('statusFilter').addEventListener('change', (e) => {
            this.filters.access_status = e.target.value;
            this.currentPage = 1;
            this.loadGuests();
        });
        
        // Clear filters
        document.getElementById('clearFiltersBtn').addEventListener('click', () => {
            this.clearFilters();
        });
        
        // Pagination
        document.getElementById('prevPageBtn').addEventListener('click', () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.loadGuests();
            }
        });
        
        document.getElementById('nextPageBtn').addEventListener('click', () => {
            const totalPages = Math.ceil(this.totalGuests / this.pageSize);
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.loadGuests();
            }
        });
        
        // New guest button
        document.getElementById('newGuestBtn').addEventListener('click', () => {
            this.showEditModal(null);
        });
        
        // Modal: Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const modal = document.getElementById('editModal');
                if (!modal.classList.contains('hidden')) {
                    this.closeEditModal();
                }
            }
        });
        
        // Modal: Close on background click
        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target.id === 'editModal') {
                this.closeEditModal();
            }
        });
    },
    
    /**
     * Load rooms for filter dropdown
     */
    async loadRooms() {
        try {
            const user = Auth.getUser();
            const stadiumId = user.stadium_id;
            
            if (!stadiumId) {
                console.log('[GUESTS] No stadium_id for super admin');
                return;
            }
            
            console.log('[GUESTS] Loading rooms for stadium:', stadiumId);
            
            const response = await API.rooms.list(stadiumId);
            
            if (response.success && response.data.rooms) {
                this.rooms = response.data.rooms;
                
                // Populate room filter
                const roomFilter = document.getElementById('roomFilter');
                this.rooms.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.id;
                    option.textContent = room.name;
                    roomFilter.appendChild(option);
                });
                
                console.log('[GUESTS] Loaded', this.rooms.length, 'rooms');
            }
        } catch (error) {
            console.error('[GUESTS] Failed to load rooms:', error);
        }
    },
    
    /**
     * Load guests with current filters
     */
    async loadGuests() {
        try {
            console.log('[GUESTS] Loading guests with filters:', this.filters);
            
            const startTime = Date.now();
            
            // Show loading
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('guestsTableContainer').classList.add('hidden');
            document.getElementById('emptyState').classList.add('hidden');
            
            // Build query params
            const params = {
                limit: this.pageSize,
                offset: (this.currentPage - 1) * this.pageSize
            };
            
            // Add filters
            if (this.filters.search) params.q = this.filters.search;
            if (this.filters.room_id) params.room_id = this.filters.room_id;
            if (this.filters.vip_level) params.vip_level = this.filters.vip_level;
            if (this.filters.access_status) params.access_status = this.filters.access_status;
            
            console.log('[GUESTS] API params:', params);
            
            // Call API
            const response = await API.guests.search(params);
            
            const executionTime = Date.now() - startTime;
            console.log('[GUESTS] API response in', executionTime, 'ms:', response);
            
            if (response.success && response.data.guests) {
                this.guests = response.data.guests;
                this.totalGuests = response.data.pagination?.total_count || response.data.guests.length;
                
                // Update UI
                this.renderGuestsTable();
                this.updatePagination();
                this.updateResultsCount(executionTime);
                
                // Show table or empty state
                if (this.guests.length > 0) {
                    document.getElementById('guestsTableContainer').classList.remove('hidden');
                } else {
                    document.getElementById('emptyState').classList.remove('hidden');
                }
            } else {
                console.error('[GUESTS] API error:', response);
                this.guests = [];
                this.totalGuests = 0;
                document.getElementById('emptyState').classList.remove('hidden');
            }
            
            // Hide loading
            document.getElementById('loadingState').classList.add('hidden');
            
        } catch (error) {
            console.error('[GUESTS] Failed to load guests:', error);
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('emptyState').classList.remove('hidden');
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore nel caricamento degli ospiti', 'error');
            }
        }
    },
    
    /**
     * Render guests table
     */
    renderGuestsTable() {
        const tbody = document.getElementById('guestsTableBody');
        tbody.innerHTML = '';
        
        this.guests.forEach(guest => {
            const row = document.createElement('tr');
            row.className = 'table-hover';
            
            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div>
                            <div class="text-sm font-medium text-gray-900">
                                ${this.escapeHtml(guest.last_name)} ${this.escapeHtml(guest.first_name)}
                            </div>
                            ${guest.company_name ? `
                                <div class="text-sm text-gray-500">${this.escapeHtml(guest.company_name)}</div>
                            ` : ''}
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${this.escapeHtml(guest.room_name || '-')}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${guest.table_number || '-'}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    ${this.getVipLevelBadge(guest.vip_level)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    ${this.getAccessStatusBadge(guest.access_status)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button onclick="Guests.showEditModal(${guest.id})" class="text-purple-600 hover:text-purple-900 mr-3">
                        <i data-lucide="edit" class="w-4 h-4 inline"></i>
                    </button>
                    <button onclick="Guests.showGuestDetails(${guest.id})" class="text-blue-600 hover:text-blue-900">
                        <i data-lucide="eye" class="w-4 h-4 inline"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(row);
        });
        
        // Re-initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    },
    
    /**
     * Update pagination UI
     */
    updatePagination() {
        const totalPages = Math.ceil(this.totalGuests / this.pageSize);
        const start = (this.currentPage - 1) * this.pageSize + 1;
        const end = Math.min(this.currentPage * this.pageSize, this.totalGuests);
        
        document.getElementById('pageStart').textContent = start;
        document.getElementById('pageEnd').textContent = end;
        document.getElementById('pageTotal').textContent = this.totalGuests;
        document.getElementById('pageInfo').textContent = `Pagina ${this.currentPage} di ${totalPages || 1}`;
        
        // Enable/disable buttons
        document.getElementById('prevPageBtn').disabled = this.currentPage <= 1;
        document.getElementById('nextPageBtn').disabled = this.currentPage >= totalPages;
    },
    
    /**
     * Update results count
     */
    updateResultsCount(executionTime) {
        document.getElementById('resultsCount').textContent = this.totalGuests;
        document.getElementById('searchTime').textContent = executionTime ? `(${executionTime}ms)` : '';
    },
    
    /**
     * Clear all filters
     */
    clearFilters() {
        this.filters = {
            search: '',
            room_id: '',
            vip_level: '',
            access_status: ''
        };
        
        document.getElementById('searchInput').value = '';
        document.getElementById('roomFilter').value = '';
        document.getElementById('vipFilter').value = '';
        document.getElementById('statusFilter').value = '';
        
        this.currentPage = 1;
        this.loadGuests();
    },
    
    /**
     * Show edit modal
     */
    async showEditModal(guestId) {
        console.log('[GUESTS] Opening edit modal for guest:', guestId);
        
        try {
            let guest = null;
            
            // Load guest data if editing existing guest
            if (guestId) {
                const response = await API.guests.get(guestId);
                if (response.success && response.data.guest) {
                    guest = response.data.guest;
                } else {
                    alert('Errore nel caricamento dei dati ospite');
                    return;
                }
            }
            
            // Load events and rooms for selects (needed for new guest)
            let eventsHtml = '<option value="">Seleziona evento...</option>';
            let roomsHtml = '<option value="">Seleziona sala...</option>';
            
            if (!guest) {
                // Load events
                try {
                    const user = Auth.getUser();
                    const stadiumId = user.stadium_id;
                    
                    if (stadiumId) {
                        const eventsResp = await API.events.list(stadiumId);
                        if (eventsResp.success && eventsResp.data.events) {
                            eventsResp.data.events.forEach(event => {
                                eventsHtml += `<option value="${event.id}">${this.escapeHtml(event.name)} - ${this.formatDate(event.event_date)}</option>`;
                            });
                        }
                        
                        // Rooms already loaded
                        this.rooms.forEach(room => {
                            roomsHtml += `<option value="${room.id}">${this.escapeHtml(room.name)}</option>`;
                        });
                    }
                } catch (error) {
                    console.error('[GUESTS] Failed to load events/rooms:', error);
                }
            }
            
            // Create modal HTML
            const modal = document.getElementById('editModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            ${guest ? 'Modifica Ospite' : 'Nuovo Ospite'}
                        </h3>
                        <button onclick="Guests.closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <form id="editGuestForm" class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            
                            ${!guest ? `
                            <!-- Event (only for new guest) -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Evento <span class="text-red-500">*</span>
                                </label>
                                <select id="event_id" name="event_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    ${eventsHtml}
                                </select>
                            </div>
                            
                            <!-- Room (only for new guest) -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Sala <span class="text-red-500">*</span>
                                </label>
                                <select id="room_id" name="room_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    ${roomsHtml}
                                </select>
                            </div>
                            ` : `
                            <!-- Show event/room info for existing guest -->
                            <div class="col-span-2 p-3 bg-gray-50 rounded-lg">
                                <div class="text-sm text-gray-600">
                                    <strong>Evento:</strong> ${this.escapeHtml(guest.event_name || '-')}<br>
                                    <strong>Sala:</strong> ${this.escapeHtml(guest.room_name || '-')}
                                </div>
                            </div>
                            `}
                            
                            <!-- First Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Nome <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="first_name" name="first_name" required
                                    value="${guest ? this.escapeHtml(guest.first_name) : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Last Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cognome <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="last_name" name="last_name" required
                                    value="${guest ? this.escapeHtml(guest.last_name) : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Company Name -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Azienda
                                </label>
                                <input type="text" id="company_name" name="company_name"
                                    value="${guest ? this.escapeHtml(guest.company_name || '') : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Email -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Email
                                </label>
                                <input type="email" id="contact_email" name="contact_email"
                                    value="${guest ? this.escapeHtml(guest.contact_email || '') : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Phone -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Telefono
                                </label>
                                <input type="tel" id="contact_phone" name="contact_phone"
                                    value="${guest ? this.escapeHtml(guest.contact_phone || '') : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Table Number -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Numero Tavolo
                                </label>
                                <input type="text" id="table_number" name="table_number"
                                    value="${guest ? this.escapeHtml(guest.table_number || '') : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Seat Number -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Numero Posto
                                </label>
                                <input type="text" id="seat_number" name="seat_number"
                                    value="${guest ? this.escapeHtml(guest.seat_number || '') : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- VIP Level -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Livello VIP <span class="text-red-500">*</span>
                                </label>
                                <select id="vip_level" name="vip_level" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="standard" ${!guest || guest.vip_level === 'standard' ? 'selected' : ''}>Standard</option>
                                    <option value="premium" ${guest && guest.vip_level === 'premium' ? 'selected' : ''}>Premium</option>
                                    <option value="vip" ${guest && guest.vip_level === 'vip' ? 'selected' : ''}>VIP</option>
                                    <option value="ultra_vip" ${guest && guest.vip_level === 'ultra_vip' ? 'selected' : ''}>Ultra VIP</option>
                                </select>
                            </div>
                            
                            <!-- Notes -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Note
                                </label>
                                <textarea id="notes" name="notes" rows="3"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">${guest ? this.escapeHtml(guest.notes || '') : ''}</textarea>
                            </div>
                            
                        </div>
                    </form>
                    
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="Guests.closeEditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button onclick="Guests.saveGuest(${guestId || 'null'})" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            ${guest ? 'Salva Modifiche' : 'Crea Ospite'}
                        </button>
                    </div>
                </div>
            `;
            
            // Show modal
            modal.classList.remove('hidden');
            
            // Re-initialize Lucide icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            // Focus first input
            setTimeout(() => {
                if (guest) {
                    document.getElementById('first_name').focus();
                } else {
                    document.getElementById('event_id').focus();
                }
            }, 100);
            
        } catch (error) {
            console.error('[GUESTS] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
    },
    
    /**
     * Format date helper
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    },
    
    /**
     * Close edit modal
     */
    closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.innerHTML = '';
    },
    
    /**
     * Save guest (create or update)
     */
    async saveGuest(guestId) {
        try {
            console.log('[GUESTS] Saving guest:', guestId);
            
            // Get form data
            const formData = {
                first_name: document.getElementById('first_name').value.trim(),
                last_name: document.getElementById('last_name').value.trim(),
                company_name: document.getElementById('company_name').value.trim(),
                contact_email: document.getElementById('contact_email').value.trim(),
                contact_phone: document.getElementById('contact_phone').value.trim(),
                table_number: document.getElementById('table_number').value.trim(),
                seat_number: document.getElementById('seat_number').value.trim(),
                vip_level: document.getElementById('vip_level').value,
                notes: document.getElementById('notes').value.trim()
            };
            
            // Add event_id and room_id for new guest
            if (!guestId) {
                const eventId = document.getElementById('event_id');
                const roomId = document.getElementById('room_id');
                
                if (eventId) formData.event_id = parseInt(eventId.value);
                if (roomId) formData.room_id = parseInt(roomId.value);
                
                // Add stadium_id for new guest
                const user = Auth.getUser();
                if (user.stadium_id) {
                    formData.stadium_id = user.stadium_id;
                }
            }
            
            // Validate required fields
            if (!formData.first_name || !formData.last_name) {
                alert('Nome e Cognome sono obbligatori');
                return;
            }
            
            // Validate event_id and room_id for new guest
            if (!guestId) {
                if (!formData.event_id) {
                    alert('Seleziona un evento');
                    return;
                }
                if (!formData.room_id) {
                    alert('Seleziona una sala');
                    return;
                }
                if (!formData.stadium_id) {
                    alert('Stadium ID mancante');
                    return;
                }
            }
            
            // Validate email if provided
            if (formData.contact_email && !this.isValidEmail(formData.contact_email)) {
                alert('Formato email non valido');
                return;
            }
            
            console.log('[GUESTS] Form data:', formData);
            
            // Show loading
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }
            
            let response;
            if (guestId) {
                // Update existing guest
                response = await API.guests.update(guestId, formData);
            } else {
                // Create new guest (admin only)
                response = await API.guests.admin.create(formData);
            }
            
            // Hide loading
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            console.log('[GUESTS] Save response:', response);
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(
                        guestId ? 'Ospite aggiornato con successo' : 'Ospite creato con successo',
                        'success'
                    );
                }
                
                // Close modal
                this.closeEditModal();
                
                // Reload guests
                await this.loadGuests();
            } else {
                const errorMsg = response.message || 'Impossibile salvare l\'ospite';
                const details = response.details ? '\n\n' + JSON.stringify(response.details, null, 2) : '';
                alert('Errore: ' + errorMsg + details);
            }
            
        } catch (error) {
            console.error('[GUESTS] Failed to save guest:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nel salvataggio dell\'ospite: ' + error.message);
        }
    },
    
    /**
     * Validate email format
     */
    isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },
    
    /**
     * Show guest details
     */
    async showGuestDetails(guestId) {
        console.log('[GUESTS] Showing details for guest:', guestId);
        try {
            const response = await API.guests.get(guestId);
            
            if (response.success && response.data.guest) {
                const guest = response.data.guest;
                alert(`Dettagli Ospite:\n\n` +
                    `Nome: ${guest.first_name} ${guest.last_name}\n` +
                    `Azienda: ${guest.company_name || '-'}\n` +
                    `Sala: ${guest.room_name}\n` +
                    `Tavolo: ${guest.table_number}\n` +
                    `VIP: ${guest.vip_level}\n` +
                    `Email: ${guest.contact_email || '-'}\n` +
                    `Telefono: ${guest.contact_phone || '-'}`
                );
            }
        } catch (error) {
            console.error('[GUESTS] Failed to get guest details:', error);
            alert('Errore nel caricamento dei dettagli');
        }
    },
    
    /**
     * Helper: Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },
    
    /**
     * Helper: Get VIP level badge
     */
    getVipLevelBadge(vipLevel) {
        const colors = {
            standard: 'bg-gray-100 text-gray-800',
            premium: 'bg-blue-100 text-blue-800',
            vip: 'bg-purple-100 text-purple-800',
            ultra_vip: 'bg-yellow-100 text-yellow-800'
        };
        
        const labels = {
            standard: 'Standard',
            premium: 'Premium',
            vip: 'VIP',
            ultra_vip: 'Ultra VIP'
        };
        
        const color = colors[vipLevel] || colors.standard;
        const label = labels[vipLevel] || vipLevel;
        
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${color}">${label}</span>`;
    },
    
    /**
     * Helper: Get access status badge
     */
    getAccessStatusBadge(status) {
        if (status === 'checked_in') {
            return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                Check-in
            </span>`;
        } else {
            return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>
                In attesa
            </span>`;
        }
    }
};

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Guests;
}

console.log('[GUESTS] Guests module loaded');