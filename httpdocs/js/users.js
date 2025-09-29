/**
 * Users Page Controller
 * Handles user CRUD operations and room assignments
 */

const Users = {
    users: [],
    rooms: [],
    stadiumId: null,
    currentFilter: 'all', // all, stadium_admin, hostess
    
    async init() {
        console.log('[USERS] Initializing...');
        
        const user = Auth.getUser();
        this.stadiumId = user.stadium_id;
        
        if (!this.stadiumId) {
            alert('Stadium ID mancante. Impossibile caricare gli utenti.');
            return;
        }
        
        this.setupEventListeners();
        await this.loadUsers();
        await this.loadRooms();
    },
    
    setupEventListeners() {
        document.getElementById('newUserBtn').addEventListener('click', () => {
            this.showEditModal(null);
        });
        
        document.getElementById('filterAllBtn').addEventListener('click', () => {
            this.applyFilter('all');
        });
        
        document.getElementById('filterAdminBtn').addEventListener('click', () => {
            this.applyFilter('stadium_admin');
        });
        
        document.getElementById('filterHostessBtn').addEventListener('click', () => {
            this.applyFilter('hostess');
        });
    },
    
    applyFilter(filter) {
        this.currentFilter = filter;
        
        const buttons = {
            'all': document.getElementById('filterAllBtn'),
            'stadium_admin': document.getElementById('filterAdminBtn'),
            'hostess': document.getElementById('filterHostessBtn')
        };
        
        Object.keys(buttons).forEach(key => {
            if (key === filter) {
                buttons[key].classList.add('bg-gray-100');
            } else {
                buttons[key].classList.remove('bg-gray-100');
            }
        });
        
        this.renderUsers();
    },
    
    async loadUsers() {
        try {
            console.log('[USERS] Loading users for stadium:', this.stadiumId);
            
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('usersTable').classList.add('hidden');
            document.getElementById('emptyState').classList.add('hidden');
            
            const response = await API.users.list(this.stadiumId);
            
            console.log('[USERS] Response:', response);
            
            if (response.success && response.data.users) {
                this.users = response.data.users;
                
                if (this.users.length > 0) {
                    this.renderUsers();
                    document.getElementById('usersTable').classList.remove('hidden');
                } else {
                    document.getElementById('emptyState').classList.remove('hidden');
                }
            } else {
                document.getElementById('emptyState').classList.remove('hidden');
            }
            
            document.getElementById('loadingState').classList.add('hidden');
            
        } catch (error) {
            console.error('[USERS] Failed to load users:', error);
            document.getElementById('loadingState').classList.add('hidden');
            document.getElementById('emptyState').classList.remove('hidden');
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore nel caricamento degli utenti', 'error');
            }
        }
    },
    
    async loadRooms() {
        try {
            const response = await API.rooms.list(this.stadiumId);
            if (response.success && response.data.rooms) {
                this.rooms = response.data.rooms;
            }
        } catch (error) {
            console.error('[USERS] Failed to load rooms:', error);
        }
    },
    
    renderUsers() {
        const tbody = document.getElementById('usersTableBody');
        tbody.innerHTML = '';
        
        let filteredUsers = this.users;
        
        if (this.currentFilter !== 'all') {
            filteredUsers = this.users.filter(user => user.role === this.currentFilter);
        }
        
        if (filteredUsers.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-12 text-center text-gray-500">Nessun utente trovato per questo filtro</td></tr>';
            return;
        }
        
        filteredUsers.forEach(user => {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-gray-50';
            
            const roleDisplay = {
                'stadium_admin': 'Stadium Admin',
                'hostess': 'Hostess',
                'super_admin': 'Super Admin'
            };
            
            const roleColor = {
                'stadium_admin': 'bg-blue-100 text-blue-800',
                'hostess': 'bg-green-100 text-green-800',
                'super_admin': 'bg-purple-100 text-purple-800'
            };
            
            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 h-10 w-10 bg-purple-100 rounded-full flex items-center justify-center">
                            <span class="text-purple-600 font-semibold text-sm">${this.getInitials(user.full_name || user.username)}</span>
                        </div>
                        <div class="ml-4">
                            <div class="text-sm font-medium text-gray-900">${this.escapeHtml(user.full_name || user.username)}</div>
                            <div class="text-sm text-gray-500">${this.escapeHtml(user.username)}</div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${roleColor[user.role] || 'bg-gray-100 text-gray-800'}">
                        ${roleDisplay[user.role] || user.role}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">${user.email ? this.escapeHtml(user.email) : '-'}</div>
                    <div class="text-sm text-gray-500">${user.phone ? this.escapeHtml(user.phone) : '-'}</div>
                </td>
                <td class="px-6 py-4">
                    ${user.role === 'hostess' ? `
                        <button onclick="Users.showRoomAssignment(${user.id})" 
                            class="text-sm text-purple-600 hover:text-purple-900 flex items-start group">
                            <i data-lucide="door-open" class="w-4 h-4 mr-1 mt-0.5 flex-shrink-0"></i>
                            <div class="text-left">
                                <div class="font-medium">${user.assigned_rooms_count || 0} sale</div>
                                ${user.assigned_rooms_names ? `
                                    <div class="text-xs text-gray-500 group-hover:text-purple-700 max-w-xs">
                                        ${this.escapeHtml(user.assigned_rooms_names)}
                                    </div>
                                ` : ''}
                            </div>
                        </button>
                    ` : '<span class="text-sm text-gray-500">-</span>'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${user.last_login ? this.formatDate(user.last_login) : 'Mai'}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                        ${user.is_active ? 'Attivo' : 'Disattivato'}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button onclick="Users.showEditModal(${user.id})" class="text-purple-600 hover:text-purple-900 mr-3">
                        <i data-lucide="edit" class="w-4 h-4"></i>
                    </button>
                    <button onclick="Users.deleteUser(${user.id})" class="text-red-600 hover:text-red-900">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </td>
            `;
            
            tbody.appendChild(tr);
        });
        
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    },
    
    async showEditModal(userId) {
        console.log('[USERS] Opening edit modal for user:', userId);
        
        try {
            let user = null;
            
            if (userId) {
                const response = await API.users.get(userId);
                if (response.success && response.data.user) {
                    user = response.data.user;
                } else {
                    alert('Errore nel caricamento dei dati utente');
                    return;
                }
            }
            
            const modal = document.getElementById('editModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            ${user ? 'Modifica Utente' : 'Nuovo Utente'}
                        </h3>
                        <button onclick="Users.closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>
                    
                    <form id="editUserForm" class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-4">
                            
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Nome Completo <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="full_name" name="full_name" required
                                    value="${user ? this.escapeHtml(user.full_name) : ''}"
                                    placeholder="Es: Maria Rossi"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Username <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="username" name="username" required
                                    value="${user ? this.escapeHtml(user.username) : ''}"
                                    placeholder="Es: maria.rossi"
                                    ${user ? 'disabled' : ''}
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 ${user ? 'bg-gray-100' : ''}">
                                ${user ? '<p class="text-xs text-gray-500 mt-1">Username non modificabile</p>' : ''}
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Ruolo <span class="text-red-500">*</span>
                                </label>
                                <select id="role" name="role" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">Seleziona...</option>
                                    <option value="stadium_admin" ${user && user.role === 'stadium_admin' ? 'selected' : ''}>Stadium Admin</option>
                                    <option value="hostess" ${user && user.role === 'hostess' ? 'selected' : ''}>Hostess</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Email <span class="text-red-500">*</span>
                                </label>
                                <input type="email" id="email" name="email" required
                                    value="${user ? this.escapeHtml(user.email) : ''}"
                                    placeholder="email@esempio.it"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Telefono
                                </label>
                                <input type="tel" id="phone" name="phone"
                                    value="${user ? this.escapeHtml(user.phone || '') : ''}"
                                    placeholder="+39 333 1234567"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            
                            ${!user ? `
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Password <span class="text-red-500">*</span>
                                    </label>
                                    <input type="password" id="password" name="password" required
                                        placeholder="Minimo 8 caratteri"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                                    <p class="text-xs text-gray-500 mt-1">Minimo 8 caratteri, almeno una maiuscola, una minuscola e un numero</p>
                                </div>
                            ` : ''}
                            
                            <div class="col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="is_active" name="is_active" 
                                        ${!user || user.is_active ? 'checked' : ''}
                                        class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                                    <span class="ml-2 text-sm font-medium text-gray-700">Utente attivo</span>
                                </label>
                            </div>
                            
                        </div>
                    </form>
                    
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="Users.closeEditModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button onclick="Users.saveUser(${userId || 'null'})" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            ${user ? 'Salva Modifiche' : 'Crea Utente'}
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
            
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
            
            setTimeout(() => {
                document.getElementById('full_name').focus();
            }, 100);
            
        } catch (error) {
            console.error('[USERS] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo di modifica');
        }
    },
    
    closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.innerHTML = '';
    },
    
    async saveUser(userId) {
        try {
            const formData = {
                full_name: document.getElementById('full_name').value.trim(),
                email: document.getElementById('email').value.trim(),
                phone: document.getElementById('phone').value.trim() || null,
                role: document.getElementById('role').value,
                is_active: document.getElementById('is_active').checked ? 1 : 0
            };
            
            if (!userId) {
                formData.username = document.getElementById('username').value.trim();
                formData.password = document.getElementById('password').value;
                formData.stadium_id = this.stadiumId;
            }
            
            if (!formData.full_name || !formData.email || !formData.role) {
                alert('Nome, Email e Ruolo sono obbligatori');
                return;
            }
            
            if (!userId && !formData.password) {
                alert('Password è obbligatoria per nuovi utenti');
                return;
            }
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }
            
            let response;
            if (userId) {
                response = await API.users.update(userId, formData);
            } else {
                response = await API.users.create(formData);
            }
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(
                        userId ? 'Utente aggiornato con successo' : 'Utente creato con successo',
                        'success'
                    );
                }
                
                this.closeEditModal();
                await this.loadUsers();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile salvare l\'utente'));
            }
            
        } catch (error) {
            console.error('[USERS] Failed to save user:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nel salvataggio: ' + error.message);
        }
    },
    
    async deleteUser(userId) {
        try {
            const confirmed = confirm('Sei sicuro di voler disattivare questo utente?\n\nPotrà essere riattivato successivamente.');
            
            if (!confirmed) return;
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Eliminazione in corso...');
            }
            
            const response = await API.users.delete(userId);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Utente disattivato con successo', 'success');
                }
                
                await this.loadUsers();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile eliminare l\'utente'));
            }
            
        } catch (error) {
            console.error('[USERS] Failed to delete user:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nell\'eliminazione: ' + error.message);
        }
    },
    
    async showRoomAssignment(userId) {
        console.log('[USERS] Opening room assignment for user:', userId);
        
        try {
            const userResponse = await API.users.get(userId);
            if (!userResponse.success) {
                alert('Errore nel caricamento dati utente');
                return;
            }
            
            const user = userResponse.data.user;
            
            const roomsResponse = await API.users.getRooms(userId);
            const assignedRoomIds = roomsResponse.success ? 
                (roomsResponse.data.rooms || []).map(r => r.id) : [];
            
            const modal = document.getElementById('roomAssignmentModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Assegnazione Sale - ${this.escapeHtml(user.full_name)}
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">Seleziona le sale da assegnare alla hostess</p>
                    </div>
                    
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-2 gap-3">
                            ${this.rooms.map(room => `
                                <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50 ${assignedRoomIds.includes(room.id) ? 'bg-purple-50 border-purple-300' : 'border-gray-200'}">
                                    <input type="checkbox" 
                                        class="room-checkbox w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500" 
                                        value="${room.id}"
                                        ${assignedRoomIds.includes(room.id) ? 'checked' : ''}>
                                    <span class="ml-3 text-sm font-medium text-gray-900">${this.escapeHtml(room.name)}</span>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-end space-x-3">
                        <button onclick="Users.closeRoomAssignmentModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button onclick="Users.saveRoomAssignment(${userId})" 
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            Salva Assegnazioni
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.remove('hidden');
            
        } catch (error) {
            console.error('[USERS] Failed to show room assignment:', error);
            alert('Errore nell\'apertura assegnazione sale');
        }
    },
    
    closeRoomAssignmentModal() {
        const modal = document.getElementById('roomAssignmentModal');
        modal.classList.add('hidden');
        modal.innerHTML = '';
    },
    
    async saveRoomAssignment(userId) {
        try {
            const checkboxes = document.querySelectorAll('.room-checkbox:checked');
            const roomIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }
            
            const response = await API.users.assignRooms(userId, roomIds);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Sale assegnate con successo', 'success');
                }
                
                this.closeRoomAssignmentModal();
                await this.loadUsers();
            } else {
                alert('Errore: ' + (response.message || 'Impossibile salvare le assegnazioni'));
            }
            
        } catch (error) {
            console.error('[USERS] Failed to save room assignment:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            alert('Errore nel salvataggio: ' + error.message);
        }
    },
    
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    },
    
    getInitials(name) {
        return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
    },
    
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

console.log('[USERS] Users module loaded');