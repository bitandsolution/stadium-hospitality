/*********************************************************
*                                                        *
*   FILE: js/stadiums.js                                 *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

/**
 * Stadiums Controller
 * Manages stadium CRUD operations for super_admin
 */
const Stadiums = {
    stadiums: [],
    currentStadiumId: null,
    logoFile: null,

    /**
     * Initialize the controller
     */
    async init() {
        console.log('[STADIUMS] Initializing...');
        
        try {
            // Load stadiums
            await this.loadStadiums();
            
            // Setup event listeners
            this.setupEventListeners();
            
            console.log('[STADIUMS] Initialized successfully');
            
        } catch (error) {
            console.error('[STADIUMS] Initialization failed:', error);
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore durante l\'inizializzazione', 'error');
            }
        }
    },

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Search input
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.filterStadiums();
                }, CONFIG.SEARCH_DEBOUNCE_MS);
            });
        }

        // Include inactive checkbox
        const includeInactive = document.getElementById('includeInactive');
        if (includeInactive) {
            includeInactive.addEventListener('change', () => {
                this.loadStadiums();
            });
        }

        // Modal: Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const editModal = document.getElementById('editModal');
                const viewModal = document.getElementById('viewModal');
                
                if (editModal && !editModal.classList.contains('hidden')) {
                    this.closeEditModal();
                }
                if (viewModal && !viewModal.classList.contains('hidden')) {
                    this.closeViewModal();
                }
            }
        });

        // Modal: Close on background click
        const editModal = document.getElementById('editModal');
        if (editModal) {
            editModal.addEventListener('click', (e) => {
                if (e.target.id === 'editModal') {
                    this.closeEditModal();
                }
            });
        }

        const viewModal = document.getElementById('viewModal');
        if (viewModal) {
            viewModal.addEventListener('click', (e) => {
                if (e.target.id === 'viewModal') {
                    this.closeViewModal();
                }
            });
        }
    },

    /**
     * Load all stadiums from API
     */
    async loadStadiums() {
        try {
            console.log('[STADIUMS] Loading stadiums...');
            
            if (typeof Utils !== 'undefined') {
                Utils.showLoading();
            }

            const includeInactive = document.getElementById('includeInactive')?.checked || false;
            const response = await API.stadiums.list(includeInactive);

            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }

            if (response.success && response.data.stadiums) {
                this.stadiums = response.data.stadiums;
                console.log('[STADIUMS] Loaded', this.stadiums.length, 'stadiums');
                
                this.updateStats();
                this.renderStadiums();
            } else {
                throw new Error('Failed to load stadiums');
            }

        } catch (error) {
            console.error('[STADIUMS] Failed to load stadiums:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
                Utils.showToast('Errore nel caricamento degli stadi', 'error');
            }
        }
    },

    /**
     * Update statistics cards
     */
    updateStats() {
        const activeStadiums = this.stadiums.filter(s => s.is_active === '1' || s.is_active === 1);
        const totalEvents = this.stadiums.reduce((sum, s) => sum + parseInt(s.total_events || 0), 0);
        const totalUsers = this.stadiums.reduce((sum, s) => sum + parseInt(s.total_users || 0), 0);

        const statTotal = document.getElementById('statTotalStadiums');
        const statActive = document.getElementById('statActiveStadiums');
        const statEvents = document.getElementById('statTotalEvents');
        const statUsers = document.getElementById('statTotalUsers');

        if (statTotal) statTotal.textContent = this.stadiums.length;
        if (statActive) statActive.textContent = activeStadiums.length;
        if (statEvents) statEvents.textContent = totalEvents;
        if (statUsers) statUsers.textContent = totalUsers;
    },

    /**
     * Filter stadiums based on search input
     */
    filterStadiums() {
        const searchTerm = document.getElementById('searchInput')?.value.toLowerCase().trim() || '';
        
        if (!searchTerm) {
            this.renderStadiums();
            return;
        }

        const filtered = this.stadiums.filter(stadium => {
            return (
                (stadium.name && stadium.name.toLowerCase().includes(searchTerm)) ||
                (stadium.city && stadium.city.toLowerCase().includes(searchTerm)) ||
                (stadium.country && stadium.country.toLowerCase().includes(searchTerm))
            );
        });

        this.renderStadiums(filtered);
    },

    /**
     * Render stadiums table
     */
    renderStadiums(stadiumsToRender = null) {
        const stadiums = stadiumsToRender || this.stadiums;
        const tbody = document.getElementById('stadiumsTableBody');
        const emptyState = document.getElementById('emptyState');

        if (!tbody) return;

        if (stadiums.length === 0) {
            tbody.innerHTML = '';
            if (emptyState) {
                emptyState.classList.remove('hidden');
            }
            return;
        }

        if (emptyState) {
            emptyState.classList.add('hidden');
        }

        tbody.innerHTML = stadiums.map(stadium => {
            const isActive = stadium.is_active === '1' || stadium.is_active === 1;
            const statusBadge = isActive 
                ? '<span class="px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">Attivo</span>'
                : '<span class="px-2 py-1 text-xs font-medium text-red-700 bg-red-100 rounded-full">Disattivo</span>';

            const logoHtml = stadium.logo_url 
                ? `<img src="${this.escapeHtml(stadium.logo_url)}" alt="Logo" class="w-12 h-12 object-contain rounded">`
                : `<div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center">
                     <i data-lucide="building" class="w-6 h-6 text-gray-400"></i>
                   </div>`;

            return `
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        ${logoHtml}
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">${this.escapeHtml(stadium.name)}</div>
                        ${stadium.address ? `<div class="text-xs text-gray-500">${this.escapeHtml(stadium.address)}</div>` : ''}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">${this.escapeHtml(stadium.city || '-')}</div>
                        <div class="text-xs text-gray-500">${this.escapeHtml(stadium.country || '-')}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${stadium.capacity ? parseInt(stadium.capacity).toLocaleString('it-IT') : '-'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${stadium.total_events || 0}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        ${stadium.total_users || 0}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        ${statusBadge}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                        <button onclick="Stadiums.showViewModal(${stadium.id})" 
                            class="text-blue-600 hover:text-blue-900" title="Visualizza dettagli">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                        <button onclick="Stadiums.showEditModal(${stadium.id})" 
                            class="text-purple-600 hover:text-purple-900" title="Modifica">
                            <i data-lucide="edit" class="w-5 h-5"></i>
                        </button>
                        <button onclick="Stadiums.deleteStadium(${stadium.id}, '${this.escapeHtml(stadium.name)}')" 
                            class="text-red-600 hover:text-red-900" title="Elimina">
                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        // Re-initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    },

    /**
     * Show edit/create modal
     */
    async showEditModal(stadiumId) {
        console.log('[STADIUMS] Opening edit modal for stadium:', stadiumId);
        this.currentStadiumId = stadiumId;
        this.logoFile = null;

        try {
            let stadium = null;

            // Load stadium data if editing
            if (stadiumId) {
                const response = await API.stadiums.get(stadiumId);
                if (!response.success || !response.data.stadium) {
                    alert('Errore nel caricamento dei dati dello stadio');
                    return;
                }
                stadium = response.data.stadium;
                console.log('[STADIUMS] Stadium loaded:', stadium);
            }

            // Create modal HTML
            const modal = document.getElementById('editModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">
                            ${stadium ? 'Modifica Stadio' : 'Nuovo Stadio'}
                        </h3>
                        <button onclick="Stadiums.closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>

                    <form id="editStadiumForm" class="px-6 py-4">
                        <div class="space-y-6">
                            
                            <!-- Stadium Info Section -->
                            <div>
                                <h4 class="text-md font-semibold text-gray-900 mb-4">Informazioni Stadio</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    
                                    <!-- Name -->
                                    <div class="col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Nome Stadio <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" id="name" required
                                            value="${stadium ? this.escapeHtml(stadium.name) : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="Es: Stadio San Siro">
                                    </div>

                                    <!-- Address -->
                                    <div class="col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Indirizzo
                                        </label>
                                        <input type="text" id="address"
                                            value="${stadium ? this.escapeHtml(stadium.address || '') : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="Via Giuseppe Meazza, 1">
                                    </div>

                                    <!-- City -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Città <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" id="city" required
                                            value="${stadium ? this.escapeHtml(stadium.city || '') : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="Milano">
                                    </div>

                                    <!-- Country -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Nazione
                                        </label>
                                        <input type="text" id="country" maxlength="2"
                                            value="${stadium ? this.escapeHtml(stadium.country || 'IT') : 'IT'}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="IT">
                                    </div>

                                    <!-- Capacity -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Capienza
                                        </label>
                                        <input type="number" id="capacity" min="0"
                                            value="${stadium ? stadium.capacity || '' : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="80000">
                                    </div>

                                    <!-- Logo Upload -->
                                    <div class="col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Logo Stadio
                                        </label>
                                        ${stadium && stadium.logo_url ? `
                                            <div class="mb-2">
                                                <img src="${this.escapeHtml(stadium.logo_url)}" alt="Current logo" class="w-24 h-24 object-contain border border-gray-300 rounded">
                                            </div>
                                        ` : ''}
                                        <input type="file" id="logoFile" accept="image/png,image/jpeg,image/jpg,image/svg+xml"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            onchange="Stadiums.handleLogoChange(event)">
                                        <p class="text-xs text-gray-500 mt-1">Formati: PNG, JPG, SVG. Max 2MB</p>
                                        <div id="logoPreview" class="mt-2"></div>
                                    </div>

                                </div>
                            </div>

                            <!-- Branding Section -->
                            <div>
                                <h4 class="text-md font-semibold text-gray-900 mb-4">Personalizzazione Brand</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    
                                    <!-- Primary Color -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Colore Primario
                                        </label>
                                        <div class="flex items-center space-x-2">
                                            <input type="color" id="primaryColor"
                                                value="${stadium ? stadium.primary_color || '#2563eb' : '#2563eb'}"
                                                class="w-12 h-10 border border-gray-300 rounded cursor-pointer">
                                            <input type="text" id="primaryColorText"
                                                value="${stadium ? stadium.primary_color || '#2563eb' : '#2563eb'}"
                                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                                placeholder="#2563eb">
                                        </div>
                                    </div>

                                    <!-- Secondary Color -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Colore Secondario
                                        </label>
                                        <div class="flex items-center space-x-2">
                                            <input type="color" id="secondaryColor"
                                                value="${stadium ? stadium.secondary_color || '#1e40af' : '#1e40af'}"
                                                class="w-12 h-10 border border-gray-300 rounded cursor-pointer">
                                            <input type="text" id="secondaryColorText"
                                                value="${stadium ? stadium.secondary_color || '#1e40af' : '#1e40af'}"
                                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                                placeholder="#1e40af">
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <!-- Contact Section -->
                            <div>
                                <h4 class="text-md font-semibold text-gray-900 mb-4">Contatti</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    
                                    <!-- Contact Email -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Email
                                        </label>
                                        <input type="email" id="contactEmail"
                                            value="${stadium ? this.escapeHtml(stadium.contact_email || '') : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="info@stadio.it">
                                    </div>

                                    <!-- Contact Phone -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Telefono
                                        </label>
                                        <input type="tel" id="contactPhone"
                                            value="${stadium ? this.escapeHtml(stadium.contact_phone || '') : ''}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="+39 02 1234567">
                                    </div>

                                </div>
                            </div>

                            ${!stadium ? `
                            <!-- Admin User Section (only for new stadium) -->
                            <div class="border-t border-gray-200 pt-6">
                                <h4 class="text-md font-semibold text-gray-900 mb-4">Amministratore Stadio</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    
                                    <!-- Admin Full Name -->
                                    <div class="col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Nome Completo <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" id="adminFullName" required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="Mario Rossi">
                                    </div>

                                    <!-- Admin Username -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Username <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" id="adminUsername" required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="mario.rossi">
                                    </div>

                                    <!-- Admin Email -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Email <span class="text-red-500">*</span>
                                        </label>
                                        <input type="email" id="adminEmail" required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="mario.rossi@stadio.it">
                                    </div>

                                    <!-- Admin Phone -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Telefono
                                        </label>
                                        <input type="tel" id="adminPhone"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="+39 333 1234567">
                                    </div>

                                    <!-- Admin Password -->
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Password <span class="text-red-500">*</span>
                                        </label>
                                        <input type="password" id="adminPassword" required minlength="8"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                            placeholder="Min. 8 caratteri">
                                        <p class="text-xs text-gray-500 mt-1">Minimo 8 caratteri</p>
                                    </div>

                                </div>
                            </div>
                            ` : ''}

                            ${stadium ? `
                            <!-- Status (only for existing stadium) -->
                            <div class="flex items-center space-x-2">
                                <input type="checkbox" id="isActive" ${stadium.is_active === '1' || stadium.is_active === 1 ? 'checked' : ''}
                                    class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <label for="isActive" class="text-sm font-medium text-gray-700">Stadio Attivo</label>
                            </div>
                            ` : ''}

                        </div>

                        <!-- Form Actions -->
                        <div class="mt-6 flex justify-end space-x-3 border-t border-gray-200 pt-4">
                            <button type="button" onclick="Stadiums.closeEditModal()"
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                                Annulla
                            </button>
                            <button type="button" onclick="Stadiums.saveStadium(${stadiumId})"
                                class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium">
                                ${stadium ? 'Salva Modifiche' : 'Crea Stadio'}
                            </button>
                        </div>
                    </form>
                </div>
            `;

            // Show modal
            modal.classList.remove('hidden');

            // Setup color pickers sync
            this.setupColorPickers();

            // Re-initialize Lucide icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            // Focus first input
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);

        } catch (error) {
            console.error('[STADIUMS] Failed to show edit modal:', error);
            alert('Errore nell\'apertura del modulo: ' + error.message);
        }
    },

    /**
     * Setup color pickers sync between color input and text input
     */
    setupColorPickers() {
        const primaryColor = document.getElementById('primaryColor');
        const primaryColorText = document.getElementById('primaryColorText');
        const secondaryColor = document.getElementById('secondaryColor');
        const secondaryColorText = document.getElementById('secondaryColorText');

        if (primaryColor && primaryColorText) {
            primaryColor.addEventListener('input', (e) => {
                primaryColorText.value = e.target.value;
            });
            primaryColorText.addEventListener('input', (e) => {
                if (/^#[0-9A-F]{6}$/i.test(e.target.value)) {
                    primaryColor.value = e.target.value;
                }
            });
        }

        if (secondaryColor && secondaryColorText) {
            secondaryColor.addEventListener('input', (e) => {
                secondaryColorText.value = e.target.value;
            });
            secondaryColorText.addEventListener('input', (e) => {
                if (/^#[0-9A-F]{6}$/i.test(e.target.value)) {
                    secondaryColor.value = e.target.value;
                }
            });
        }
    },

    /**
     * Handle logo file selection
     */
    handleLogoChange(event) {
        const file = event.target.files[0];
        if (!file) {
            this.logoFile = null;
            document.getElementById('logoPreview').innerHTML = '';
            return;
        }

        // Validate file size (max 2MB)
        const maxSize = 2 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('Il file è troppo grande. Dimensione massima: 2MB');
            event.target.value = '';
            return;
        }

        // Validate file type
        const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'];
        if (!allowedTypes.includes(file.type)) {
            alert('Formato file non valido. Usa PNG, JPG o SVG');
            event.target.value = '';
            return;
        }

        this.logoFile = file;

        // Show preview
        const reader = new FileReader();
        reader.onload = (e) => {
            document.getElementById('logoPreview').innerHTML = `
                <div class="mt-2">
                    <p class="text-sm text-gray-600 mb-2">Anteprima:</p>
                    <img src="${e.target.result}" alt="Logo preview" class="w-24 h-24 object-contain border border-gray-300 rounded">
                </div>
            `;
        };
        reader.readAsDataURL(file);
    },

    /**
     * Close edit modal
     */
    closeEditModal() {
        const modal = document.getElementById('editModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.innerHTML = '';
        }
        this.currentStadiumId = null;
        this.logoFile = null;
    },

    /**
     * Save stadium (create or update)
     */
    async saveStadium(stadiumId) {
        try {
            console.log('[STADIUMS] Saving stadium:', stadiumId);

            // Get form data
            const formData = {
                name: document.getElementById('name').value.trim(),
                address: document.getElementById('address').value.trim() || null,
                city: document.getElementById('city').value.trim(),
                country: document.getElementById('country').value.trim() || 'IT',
                capacity: document.getElementById('capacity').value || null,
                primary_color: document.getElementById('primaryColorText').value,
                secondary_color: document.getElementById('secondaryColorText').value,
                contact_email: document.getElementById('contactEmail').value.trim() || null,
                contact_phone: document.getElementById('contactPhone').value.trim() || null
            };

            // Validate required fields
            if (!formData.name || !formData.city) {
                alert('Nome e Città sono obbligatori');
                return;
            }

            // For new stadium, collect admin data
            if (!stadiumId) {
                const adminData = {
                    full_name: document.getElementById('adminFullName').value.trim(),
                    username: document.getElementById('adminUsername').value.trim(),
                    email: document.getElementById('adminEmail').value.trim(),
                    phone: document.getElementById('adminPhone').value.trim() || null,
                    password: document.getElementById('adminPassword').value
                };

                // Validate admin fields
                if (!adminData.full_name || !adminData.username || !adminData.email || !adminData.password) {
                    alert('Tutti i campi amministratore sono obbligatori (tranne telefono)');
                    return;
                }

                if (adminData.password.length < 8) {
                    alert('La password deve essere di almeno 8 caratteri');
                    return;
                }

                formData.admin = adminData;
            } else {
                // For existing stadium, add is_active
                formData.is_active = document.getElementById('isActive').checked ? 1 : 0;
            }

            console.log('[STADIUMS] Form data:', formData);

            // Show loading
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Salvataggio in corso...');
            }

            let response;

            // Create or update stadium
            if (stadiumId) {
                response = await API.stadiums.update(stadiumId, formData);
                
                // Upload logo if file was selected
                if (response.success && this.logoFile) {
                    try {
                        const logoResponse = await API.stadiums.uploadLogo(stadiumId, this.logoFile);
                        if (!logoResponse.success) {
                            console.warn('[STADIUMS] Logo upload failed:', logoResponse.message);
                        }
                    } catch (logoError) {
                        console.error('[STADIUMS] Logo upload error:', logoError);
                    }
                }
            } else {
                response = await API.stadiums.create({ stadium: formData, admin: formData.admin });
                
                // Upload logo after stadium creation if file was selected
                if (response.success && response.data.stadium_id && this.logoFile) {
                    try {
                        const logoResponse = await API.stadiums.uploadLogo(response.data.stadium_id, this.logoFile);
                        if (!logoResponse.success) {
                            console.warn('[STADIUMS] Logo upload failed:', logoResponse.message);
                        }
                    } catch (logoError) {
                        console.error('[STADIUMS] Logo upload error:', logoError);
                    }
                }
            }

            // Hide loading
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }

            console.log('[STADIUMS] Save response:', response);

            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(
                        stadiumId ? 'Stadio aggiornato con successo' : 'Stadio creato con successo',
                        'success'
                    );
                }

                this.closeEditModal();
                await this.loadStadiums();
            } else {
                throw new Error(response.message || 'Errore durante il salvataggio');
            }

        } catch (error) {
            console.error('[STADIUMS] Failed to save stadium:', error);

            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
                Utils.showToast('Errore: ' + error.message, 'error');
            } else {
                alert('Errore durante il salvataggio: ' + error.message);
            }
        }
    },

    /**
     * Show stadium details modal
     */
    async showViewModal(stadiumId) {
        console.log('[STADIUMS] Opening view modal for stadium:', stadiumId);

        try {
            if (typeof Utils !== 'undefined') {
                Utils.showLoading();
            }

            const response = await API.stadiums.get(stadiumId);

            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }

            if (!response.success || !response.data.stadium) {
                throw new Error('Impossibile caricare i dettagli dello stadio');
            }

            const stadium = response.data.stadium;
            const stats = response.data.statistics || {};

            const modal = document.getElementById('viewModal');
            modal.innerHTML = `
                <div class="bg-white rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Dettagli Stadio</h3>
                        <button onclick="Stadiums.closeViewModal()" class="text-gray-400 hover:text-gray-600">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </button>
                    </div>

                    <div class="px-6 py-4 space-y-6">
                        
                        <!-- Logo and Basic Info -->
                        <div class="flex items-start space-x-4">
                            ${stadium.logo_url ? `
                                <img src="${this.escapeHtml(stadium.logo_url)}" alt="Logo" class="w-24 h-24 object-contain border border-gray-300 rounded">
                            ` : `
                                <div class="w-24 h-24 bg-gray-200 rounded flex items-center justify-center">
                                    <i data-lucide="building" class="w-12 h-12 text-gray-400"></i>
                                </div>
                            `}
                            <div class="flex-1">
                                <h2 class="text-2xl font-bold text-gray-900">${this.escapeHtml(stadium.name)}</h2>
                                ${stadium.address ? `<p class="text-sm text-gray-600">${this.escapeHtml(stadium.address)}</p>` : ''}
                                <p class="text-sm text-gray-600">${this.escapeHtml(stadium.city || '-')}, ${this.escapeHtml(stadium.country || '-')}</p>
                                ${stadium.capacity ? `<p class="text-sm text-gray-600 mt-1">Capienza: <strong>${parseInt(stadium.capacity).toLocaleString('it-IT')}</strong></p>` : ''}
                            </div>
                        </div>

                        <!-- Statistics -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-md font-semibold text-gray-900 mb-3">Statistiche</h4>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <p class="text-sm text-blue-600">Utenti</p>
                                    <p class="text-2xl font-bold text-blue-900">${stats.total_users || 0}</p>
                                </div>
                                <div class="bg-purple-50 p-4 rounded-lg">
                                    <p class="text-sm text-purple-600">Eventi</p>
                                    <p class="text-2xl font-bold text-purple-900">${stats.total_events || 0}</p>
                                </div>
                                <div class="bg-green-50 p-4 rounded-lg">
                                    <p class="text-sm text-green-600">Ospiti</p>
                                    <p class="text-2xl font-bold text-green-900">${stats.total_guests || 0}</p>
                                </div>
                                <div class="bg-orange-50 p-4 rounded-lg">
                                    <p class="text-sm text-orange-600">Sale</p>
                                    <p class="text-2xl font-bold text-orange-900">${stats.total_rooms || 0}</p>
                                </div>
                                <div class="bg-indigo-50 p-4 rounded-lg">
                                    <p class="text-sm text-indigo-600">Check-in</p>
                                    <p class="text-2xl font-bold text-indigo-900">${stats.total_checkins || 0}</p>
                                </div>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <p class="text-sm text-gray-600">Hostess</p>
                                    <p class="text-2xl font-bold text-gray-900">${stats.total_hostess || 0}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Branding -->
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-md font-semibold text-gray-900 mb-3">Personalizzazione Brand</h4>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm text-gray-600">Colore Primario</p>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <div class="w-8 h-8 rounded border border-gray-300" style="background-color: ${stadium.primary_color || '#2563eb'}"></div>
                                        <span class="text-sm font-mono">${stadium.primary_color || '#2563eb'}</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-600">Colore Secondario</p>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <div class="w-8 h-8 rounded border border-gray-300" style="background-color: ${stadium.secondary_color || '#1e40af'}"></div>
                                        <span class="text-sm font-mono">${stadium.secondary_color || '#1e40af'}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Info -->
                        ${stadium.contact_email || stadium.contact_phone ? `
                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-md font-semibold text-gray-900 mb-3">Contatti</h4>
                            <div class="space-y-2">
                                ${stadium.contact_email ? `
                                    <div class="flex items-center space-x-2">
                                        <i data-lucide="mail" class="w-4 h-4 text-gray-400"></i>
                                        <span class="text-sm">${this.escapeHtml(stadium.contact_email)}</span>
                                    </div>
                                ` : ''}
                                ${stadium.contact_phone ? `
                                    <div class="flex items-center space-x-2">
                                        <i data-lucide="phone" class="w-4 h-4 text-gray-400"></i>
                                        <span class="text-sm">${this.escapeHtml(stadium.contact_phone)}</span>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        ` : ''}

                        <!-- Status and Dates -->
                        <div class="border-t border-gray-200 pt-4">
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-gray-600">Stato</p>
                                    ${stadium.is_active === '1' || stadium.is_active === 1 
                                        ? '<span class="px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded-full">Attivo</span>'
                                        : '<span class="px-2 py-1 text-xs font-medium text-red-700 bg-red-100 rounded-full">Disattivo</span>'
                                    }
                                </div>
                                <div>
                                    <p class="text-gray-600">Data Creazione</p>
                                    <p class="font-medium">${stadium.created_at ? this.formatDateTime(stadium.created_at) : '-'}</p>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                        <button onclick="Stadiums.closeViewModal()" 
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                            Chiudi
                        </button>
                        <button onclick="Stadiums.showEditModal(${stadiumId}); Stadiums.closeViewModal();" 
                            class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium">
                            Modifica
                        </button>
                    </div>
                </div>
            `;

            modal.classList.remove('hidden');

            // Re-initialize Lucide icons
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

        } catch (error) {
            console.error('[STADIUMS] Failed to show view modal:', error);
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
                Utils.showToast('Errore nel caricamento dei dettagli', 'error');
            }
        }
    },

    /**
     * Close view modal
     */
    closeViewModal() {
        const modal = document.getElementById('viewModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.innerHTML = '';
        }
    },

    /**
     * Delete stadium (soft delete)
     */
    async deleteStadium(stadiumId, stadiumName) {
        if (!confirm(`Sei sicuro di voler disattivare lo stadio "${stadiumName}"?\n\nQuesta azione disattiverà anche tutti gli utenti associati.`)) {
            return;
        }

        try {
            console.log('[STADIUMS] Deleting stadium:', stadiumId);

            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Eliminazione in corso...');
            }

            const response = await API.stadiums.delete(stadiumId);

            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }

            if (response.success) {
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Stadio disattivato con successo', 'success');
                }
                await this.loadStadiums();
            } else {
                throw new Error(response.message || 'Errore durante l\'eliminazione');
            }

        } catch (error) {
            console.error('[STADIUMS] Failed to delete stadium:', error);

            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
                Utils.showToast('Errore: ' + error.message, 'error');
            } else {
                alert('Errore durante l\'eliminazione: ' + error.message);
            }
        }
    },

    /**
     * Format date time in IT locale
     */
    formatDateTime(dateString) {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        return `${day}/${month}/${year} ${hours}:${minutes}`;
    },

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (typeof text !== 'string') return '';
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
};