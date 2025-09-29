/**
 * Rooms Page Controller
 * Handles room CRUD operations
 */

const Rooms = {
    rooms: [],
    stadiumId: null,
    
    async init() {
        console.log('[ROOMS] Initializing...');
        
        const user = Auth.getUser();
        this.stadiumId = user.stadium_id;
        
        if (!this.stadiumId) {
            alert('Stadium ID mancante. Impossibile caricare le sale.');
            return;
        }
        
        this.setupEventListeners();
        await this.loadRooms();
    },
    
    setupEventListeners() {
        document.getElementById('newRoomBtn').addEventListener('click', () => {
            this.showEditModal(null);
        });
    },
    
    async loadRooms() {
        try {
            console.log('[ROOMS] Loading rooms for stadium:', this.stadiumId);
            
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('roomsGrid').classList.add('hidden');
            document.getElementById('emptyState').classList.add('hidden');
            
            const response = await API.rooms.list(this.stadiumId);
            
            if (response.success && response.data.rooms) {
                this.rooms = response.data.rooms;
                
                if (this.rooms.length > 0) {
                    this.renderRooms();
                    document.getElementById('roomsGrid').classList.remove('hidden');
                } else {
                    document.getElementById('emptyState').classList.remove('hidden');
                }
            } else {
                document.getElementById('emptyState').classList.remove('hidden');
            }
            
            document.getElementById('loadingState').classList.add('hidden');
            
        } catch (error) {
            console.error('[ROOMS] Failed to load rooms:', error);
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('emptyState').classList.remove('hidden');
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore nel caricamento delle sale', 'error');
            }
        }
    },
    
    renderRooms() {
        const grid = document.getElementById('roomsGrid');
        grid.innerHTML = '';
        
        this.rooms.forEach(room => {
            const card = document.createElement('div');
            card.className = 'bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-6';
            
            card.innerHTML = `
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                            <i data-lucide="door-open" class="w-6 h-6 text-purple-600"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">${this.escapeHtml(room.name)}</h3>
                            <p class="text-sm text-gray-500">${room.room_type || 'Standard'}</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${room.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}">
                        ${room.is_active ? 'Attiva' : 'Disattiva'}
                    </span>
                </div>
                
                ${room.description ? `
                    <p class="text-sm text-gray-600 mb-4">${this.escapeHtml(room.description)}</p>
                ` : ''}
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <p class="text-xs text-gray-500">Capacità</p>
                        <p class="text-lg font-semibold text-gray-900">${room.capacity || 500}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Piano</p>
                        <p class="text-lg font-semibold text-gray-900">${room.floor_level || '-'}</p>
                    </div>
                </div>
                
                ${room.stats ? `
                    <div class="border-t border-gray-200 pt-4 mb-4">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-gray-500">Ospiti totali</p>
                                <p class="font-medium text-gray-900">${room.stats.total_guests || 0}</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Hostess</p>
                                <p class="font-medium text-gray-900">${room.stats.assigned_hostess || 0}</p>
                            </div>
                        </div>
                    </div>
                ` : ''}
                
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-gray-200">
                    <button onclick="Rooms.showEditModal(${room.id})" class="inline-flex items-center px-3 py-1.5 text-sm text-purple-600 hover:bg-purple-50 rounded-lg">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        Modifica
                    </button>
                    <button onclick="Rooms.deleteRoom(${room.id})" class="inline-flex items-center px-3 py-1.5 text-sm text-red-600 hover:bg-red-50 rounded-lg">
                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                        Elimina
                    </button>
                </div>
            `;
            
            grid.appendChild(card);
        });
        
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    },
    
    async showEditModal(roomId) {
        try {
            let room = null;
            
            if (roomId) {
                const response = await API.rooms.get(roomId);
                if (response.success && response.data.room) {
                    room = response.data.room;
                } else {
                    alert('Errore nel caricamento dei dati della sala');
                    return;
                }
            }
            
            const modal = document.getElementById('editModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            ${room ? 'Modifica Sala' : 'Nuova Sala'}
                        </h3>
                        <button onclick="Rooms.closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <form id="editRoomForm" class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Nome Sala <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="name" name="name" required
                                    value="${room ? this.escapeHtml(room.name) : ''}"
                                    placeholder="Es: Hospitality VIP 1"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Descrizione
                                </label>
                                <textarea id="description" name="description" rows="3"
                                    placeholder="Descrizione della sala..."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">${room ? this.escapeHtml(room.description || '') : ''}</textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Capacità
                                </label>
                                <input type="number" id="capacity" name="capacity" min="1"
                                    value="${room ? room.capacity : ''}"
                                    placeholder="Default: 500"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <p class="text-xs text-gray-500 mt-1">Se non specificata, verrà usato 500</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Piano
                                </label>
                                <input type="text" id="floor_level" name="floor_level"
                                    value="${room ? this.escapeHtml(room.floor_level || '') : ''}"
                                    placeholder="Es: 2, Primo, etc."
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Codice Posizione
                                </label>
                                <input type="text" id="location_code" name="location_code"
                                    value="${room ? this.escapeHtml(room.location_code || '') : ''}"
                                    placeholder="Es: VIP-A1"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Tipo Sala
                                </label>
                                <select id="room_type" name="room_type"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="vip" ${room && room.room_type === 'vip' ? 'selected' : ''}>VIP</option>
                                    <option value="premium" ${room && room.room_type === 'premium' ? 'selected' : ''}>Premium</option>
                                    <option value="standard" ${!room || room.room_type === 'standard' ? 'selected' : ''}>Standard</option>
                                    <option value="buffet" ${room && room.room_type === 'buffet' ? 'selected' : ''}>Buffet</option>
                                </select>
                            </div>
                            
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Servizi
                                </label>
                                <input type="text" id="amenities" name="amenities"
                                    value="${room ? this.escapeHtml(room.amenities || '') : ''}"
                                    placeholder="Es: WiFi, TV, Buffet, Bar"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                <p class="text-xs text-gray-500 mt-1">Separa con virgole i vari servizi</p>
                            </div>
                            
                            <div class="col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="is_active" name="is_active" 
                                        ${!room || room.is_active ? 'checked' : ''}
                                        class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                                    <span class="ml-2 text-sm font-medium text-gray-700">Sala attiva</span>
                                </label>
                            </div>
                            
                        </div>
                    </form>
                    
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="Rooms.closeEditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button onclick="Rooms.saveRoom(${roomId || 'null'})" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            ${room ? 'Salva Modifiche' : 'Crea Sala'}
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
            
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);
            
        } catch (error) {
            console.error('[ROOMS] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
    },
    
    closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.innerHTML = '';
    },
    
    async saveRoom(roomId) {
        try {
            const formData = {
                name: document.getElementById('name').value.trim(),
                description: document.getElementById('description').value.trim(),
                capacity: parseInt(document.getElementById('capacity').value) || 500,
                floor_level: document.getElementById('floor_level').value.trim(),
                location_code: document.getElementById('location_code').value.trim(),
                room_type: document.getElementById('room_type').value,
                amenities: document.getElementById('amenities').value.trim(),
                is_active: document.getElementById('is_active').checked ? 1 : 0
            };
            
            if (!roomId) {
                formData.stadium_id = this.stadiumId;
            }
            
            // Only validate name as required
            if (!formData.name) {
                alert('Nome è obbligatorio');
                return;
            }
            
            // Ensure capacity has a valid default
            if (!formData.capacity || formData.capacity < 1) {
                formData.capacity = 500;
            }
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }
            
            let response;
            if (roomId) {
                response = await API.rooms.update(roomId, formData);
            } else {
                response = await API.rooms.create(formData);
            }
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(
                        roomId ? 'Sala aggiornata con successo' : 'Sala creata con successo',
                        'success'
                    );
                }
                
                this.closeEditModal();
                await this.loadRooms();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile salvare la sala'));
            }
            
        } catch (error) {
            console.error('[ROOMS] Failed to save room:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nel salvataggio della sala: ' + error.message);
        }
    },
    
    async deleteRoom(roomId) {
        try {
            const confirmed = confirm('Sei sicuro di voler eliminare questa sala?\n\nQuesta azione non può essere annullata.');
            
            if (!confirmed) return;
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Eliminazione in corso...');
            }
            
            const response = await API.rooms.delete(roomId);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Sala eliminata con successo', 'success');
                }
                
                await this.loadRooms();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile eliminare la sala'));
            }
            
        } catch (error) {
            console.error('[ROOMS] Failed to delete room:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nell\'eliminazione della sala: ' + error.message);
        }
    },
    
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

console.log('[ROOMS] Rooms module loaded');