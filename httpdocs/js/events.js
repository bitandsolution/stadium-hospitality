/**
 * Events Page Controller
 * Handles event CRUD operations
 */

const Events = {
    events: [],
    stadiumId: null,
    currentFilter: 'all', // all, upcoming, past
    
    /**
     * Initialize events page
     */
    async init() {
        console.log('[EVENTS] Initializing...');
        
        // Get stadium ID
        const user = Auth.getUser();
        this.stadiumId = user.stadium_id;
        
        if (!this.stadiumId) {
            alert('Stadium ID mancante. Impossibile caricare gli eventi.');
            return;
        }
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Load events
        await this.loadEvents();
    },
    
    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // New event button
        document.getElementById('newEventBtn').addEventListener('click', () => {
            this.showEditModal(null);
        });
        
        // Filter buttons
        document.getElementById('filterAllBtn').addEventListener('click', () => {
            this.applyFilter('all');
        });
        
        document.getElementById('filterUpcomingBtn').addEventListener('click', () => {
            this.applyFilter('upcoming');
        });
        
        document.getElementById('filterPastBtn').addEventListener('click', () => {
            this.applyFilter('past');
        });
    },
    
    /**
     * Apply filter
     */
    applyFilter(filter) {
        this.currentFilter = filter;
        
        // Update button styles
        const buttons = {
            'all': document.getElementById('filterAllBtn'),
            'upcoming': document.getElementById('filterUpcomingBtn'),
            'past': document.getElementById('filterPastBtn')
        };
        
        Object.keys(buttons).forEach(key => {
            if (key === filter) {
                buttons[key].classList.add('bg-gray-100');
                buttons[key].classList.remove('hover:bg-gray-100');
            } else {
                buttons[key].classList.remove('bg-gray-100');
                buttons[key].classList.add('hover:bg-gray-100');
            }
        });
        
        // Re-render with filter
        this.renderEvents();
    },
    
    /**
     * Load events
     */
    async loadEvents() {
        try {
            console.log('[EVENTS] Loading events for stadium:', this.stadiumId);
            
            // Show loading
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('eventsGrid').classList.add('hidden');
            document.getElementById('emptyState').classList.add('hidden');
            
            const response = await API.events.list(this.stadiumId);
            
            console.log('[EVENTS] Response:', response);
            
            if (response.success && response.data.events) {
                this.events = response.data.events;
                
                if (this.events.length > 0) {
                    this.renderEvents();
                    document.getElementById('eventsGrid').classList.remove('hidden');
                } else {
                    document.getElementById('emptyState').classList.remove('hidden');
                }
            } else {
                document.getElementById('emptyState').classList.remove('hidden');
            }
            
            // Hide loading
            document.getElementById('loadingState').classList.add('hidden');
            
        } catch (error) {
            console.error('[EVENTS] Failed to load events:', error);
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('emptyState').classList.remove('hidden');
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore nel caricamento degli eventi', 'error');
            }
        }
    },
    
    /**
     * Render events grid with filter
     */
    renderEvents() {
        const grid = document.getElementById('eventsGrid');
        grid.innerHTML = '';
        
        // Filter events
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        let filteredEvents = this.events;
        
        if (this.currentFilter === 'upcoming') {
            filteredEvents = this.events.filter(event => {
                const eventDate = new Date(event.event_date);
                return eventDate >= today;
            });
        } else if (this.currentFilter === 'past') {
            filteredEvents = this.events.filter(event => {
                const eventDate = new Date(event.event_date);
                return eventDate < today;
            });
        }
        
        if (filteredEvents.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center py-12 text-gray-500">Nessun evento trovato per questo filtro</div>';
            return;
        }
        
        filteredEvents.forEach(event => {
            const eventDate = new Date(event.event_date);
            const isUpcoming = eventDate >= today;
            
            const card = document.createElement('div');
            card.className = 'event-card bg-white rounded-lg shadow hover:shadow-lg transition-all p-6';
            
            card.innerHTML = `
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center">
                        <div class="w-12 h-12 ${isUpcoming ? 'bg-green-100' : 'bg-gray-100'} rounded-lg flex items-center justify-center mr-3">
                            <i data-lucide="calendar" class="w-6 h-6 ${isUpcoming ? 'text-green-600' : 'text-gray-600'}"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">${this.escapeHtml(event.name)}</h3>
                            <p class="text-sm text-gray-500">${event.competition || 'Amichevole'}</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${event.is_active ? (isUpcoming ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') : 'bg-red-100 text-red-800'}">
                        ${event.is_active ? (isUpcoming ? 'Prossimo' : 'Passato') : 'Disattivo'}
                    </span>
                </div>
                
                <div class="space-y-2 mb-4">
                    <div class="flex items-center text-sm text-gray-600">
                        <i data-lucide="calendar-days" class="w-4 h-4 mr-2"></i>
                        ${this.formatDate(event.event_date)}
                    </div>
                    
                    ${event.event_time ? `
                        <div class="flex items-center text-sm text-gray-600">
                            <i data-lucide="clock" class="w-4 h-4 mr-2"></i>
                            ${event.event_time}
                        </div>
                    ` : ''}
                    
                    ${event.opponent_team ? `
                        <div class="flex items-center text-sm text-gray-600">
                            <i data-lucide="shield" class="w-4 h-4 mr-2"></i>
                            vs ${this.escapeHtml(event.opponent_team)}
                        </div>
                    ` : ''}
                    
                    ${event.season ? `
                        <div class="flex items-center text-sm text-gray-600">
                            <i data-lucide="trophy" class="w-4 h-4 mr-2"></i>
                            Stagione ${event.season}
                        </div>
                    ` : ''}
                </div>
                
                ${event.stats ? `
                    <div class="border-t border-gray-200 pt-4 mb-4">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-gray-500">Ospiti totali</p>
                                <p class="font-medium text-gray-900">${event.stats.total_guests || 0}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Sale attive</p>
                                <p class="font-medium text-gray-900">${event.stats.active_rooms || 0}</p>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-gray-200">
                    <button onclick="Events.showEditModal(${event.id})" class="inline-flex items-center px-3 py-1.5 text-sm text-purple-600 hover:bg-purple-50 rounded-lg">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        Modifica
                    </button>
                    <button onclick="Events.deleteEvent(${event.id})" class="inline-flex items-center px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 rounded-lg">
                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                        Elimina
                    </button>
                </div>
            `;
            
            grid.appendChild(card);
        });
        
        // Re-initialize icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    },
    
    /**
     * Show edit modal
     */
    async showEditModal(eventId) {
        console.log('[EVENTS] Opening edit modal for event:', eventId);
        
        try {
            let event = null;
            
            // Load event data if editing
            if (eventId) {
                const response = await API.events.get(eventId);
                if (response.success && response.data.event) {
                    event = response.data.event;
                } else {
                    alert('Errore nel caricamento dei dati dell\'evento');
                    return;
                }
            }
            
            // Create modal HTML
            const modal = document.getElementById('editModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            ${event ? 'Modifica Evento' : 'Nuovo Evento'}
                        </h3>
                        <button onclick="Events.closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <form id="editEventForm" class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            
                            <!-- Name -->
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Nome Evento <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="name" name="name" required
                                    value="${event ? this.escapeHtml(event.name) : ''}"
                                    placeholder="Es: Inter vs Milan"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Event Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Data Evento <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="event_date" name="event_date" required
                                    value="${event ? event.event_date : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Event Time -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Orario
                                </label>
                                <input type="time" id="event_time" name="event_time"
                                    value="${event ? event.event_time || '' : ''}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Opponent Team -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Squadra Avversaria
                                </label>
                                <input type="text" id="opponent_team" name="opponent_team"
                                    value="${event ? this.escapeHtml(event.opponent_team || '') : ''}"
                                    placeholder="Es: Milan"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Competition -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Competizione
                                </label>
                                <select id="competition" name="competition"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">Seleziona...</option>
                                    <option value="Serie A" ${event && event.competition === 'Serie A' ? 'selected' : ''}>Serie A</option>
                                    <option value="Serie B" ${event && event.competition === 'Serie B' ? 'selected' : ''}>Serie B</option>
                                    <option value="Coppa Italia" ${event && event.competition === 'Coppa Italia' ? 'selected' : ''}>Coppa Italia</option>
                                    <option value="Champions League" ${event && event.competition === 'Champions League' ? 'selected' : ''}>Champions League</option>
                                    <option value="Europa League" ${event && event.competition === 'Europa League' ? 'selected' : ''}>Europa League</option>
                                    <option value="Conference League" ${event && event.competition === 'Conference League' ? 'selected' : ''}>Conference League</option>
                                    <option value="Amichevole" ${event && event.competition === 'Amichevole' ? 'selected' : ''}>Amichevole</option>
                                    <option value="Altro" ${event && event.competition === 'Altro' ? 'selected' : ''}>Altro</option>
                                </select>
                            </div>
                            
                            <!-- Season -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Stagione
                                </label>
                                <input type="text" id="season" name="season"
                                    value="${event ? this.escapeHtml(event.season || '') : new Date().getFullYear() + '/' + (new Date().getFullYear() + 1)}"
                                    placeholder="Es: 2024/2025"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <!-- Active Status -->
                            <div class="col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="is_active" name="is_active" 
                                        ${!event || event.is_active ? 'checked' : ''}
                                        class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                                    <span class="ml-2 text-sm font-medium text-gray-700">Evento attivo</span>
                                </label>
                            </div>
                            
                        </div>
                    </form>
                    
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="Events.closeEditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button onclick="Events.saveEvent(${eventId || 'null'})" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            ${event ? 'Salva Modifiche' : 'Crea Evento'}
                        </button>
                    </div>
                </div>
            `;
            
            // Show modal
            modal.classList.remove('hidden');
            
            // Re-initialize icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            // Focus first input
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);
            
        } catch (error) {
            console.error('[EVENTS] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
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
     * Save event
     */
    async saveEvent(eventId) {
        try {
            console.log('[EVENTS] Saving event:', eventId);
            
            // Get form data
            const formData = {
                name: document.getElementById('name').value.trim(),
                event_date: document.getElementById('event_date').value,
                event_time: document.getElementById('event_time').value || null,
                opponent_team: document.getElementById('opponent_team').value.trim() || null,
                competition: document.getElementById('competition').value || null,
                season: document.getElementById('season').value.trim() || null,
                is_active: document.getElementById('is_active').checked ? 1 : 0
            };
            
            // Add stadium_id for new event
            if (!eventId) {
                formData.stadium_id = this.stadiumId;
            }
            
            // Validate
            if (!formData.name || !formData.event_date) {
                alert('Nome e Data sono obbligatori');
                return;
            }
            
            console.log('[EVENTS] Form data:', formData);
            
            // Show loading
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }
            
            let response;
            if (eventId) {
                response = await API.events.update(eventId, formData);
            } else {
                response = await API.events.create(formData);
            }
            
            // Hide loading
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            console.log('[EVENTS] Save response:', response);
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(
                        eventId ? 'Evento aggiornato con successo' : 'Evento creato con successo',
                        'success'
                    );
                }
                
                // Close modal
                this.closeEditModal();
                
                // Reload events
                await this.loadEvents();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile salvare l\'evento'));
            }
            
        } catch (error) {
            console.error('[EVENTS] Failed to save event:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nel salvataggio dell\'evento: ' + error.message);
        }
    },
    
    /**
     * Delete event
     */
    async deleteEvent(eventId) {
        try {
            const confirmed = confirm('Sei sicuro di voler eliminare questo evento?\n\nQuesta azione non può essere annullata.');
            
            if (!confirmed) return;
            
            console.log('[EVENTS] Deleting event:', eventId);
            
            // Show loading
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Eliminazione in corso...');
            }
            
            const response = await API.events.delete(eventId);
            
            // Hide loading
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Evento eliminato con successo', 'success');
                }
                
                // Reload events
                await this.loadEvents();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile eliminare l\'evento'));
            }
            
        } catch (error) {
            console.error('[EVENTS] Failed to delete event:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nell\'eliminazione dell\'evento: ' + error.message);
        }
    },
    
    /**
     * Format date for display
     */
    formatDate(dateString) {
        const date = new Date(dateString);
        const options = { 
            weekday: 'long', 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        };
        return date.toLocaleDateString('it-IT', options);
    },
    
    /**
     * Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

console.log('[EVENTS] Events module loaded');