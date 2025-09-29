/**
 * Import Page Controller
 * Handles Excel file upload, validation, preview, and import
 */

const ImportPage = {
    selectedFile: null,
    selectedEventId: null,
    stadiumId: null,
    previewData: [],
    
    /**
     * Initialize import page
     */
    async init() {
        console.log('[IMPORT] Initializing...');
        
        // Get user info
        const user = Auth.getUser();
        this.stadiumId = user.stadium_id;
        
        // Load stadium name
        document.getElementById('stadiumName').value = user.stadium_name || 'N/A';
        
        // Load events
        await this.loadEvents();
        
        // Setup event listeners
        this.setupEventListeners();
    },
    
    /**
     * Load events for dropdown
     */
    async loadEvents() {
        try {
            if (!this.stadiumId) {
                console.warn('[IMPORT] No stadium_id available');
                return;
            }
            
            console.log('[IMPORT] Loading events for stadium:', this.stadiumId);
            
            const response = await API.events.list(this.stadiumId);
            
            if (response.success && response.data.events) {
                const select = document.getElementById('eventSelect');
                
                response.data.events.forEach(event => {
                    const option = document.createElement('option');
                    option.value = event.id;
                    option.textContent = `${event.name} - ${this.formatDate(event.event_date)}`;
                    select.appendChild(option);
                });
                
                console.log('[IMPORT] Loaded', response.data.events.length, 'events');
            }
        } catch (error) {
            console.error('[IMPORT] Failed to load events:', error);
        }
    },
    
    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Event selection
        document.getElementById('eventSelect').addEventListener('change', (e) => {
            this.selectedEventId = e.target.value ? parseInt(e.target.value) : null;
            console.log('[IMPORT] Event selected:', this.selectedEventId);
        });
        
        // Download template
        document.getElementById('downloadTemplateBtn').addEventListener('click', () => {
            this.downloadTemplate();
        });
        
        // Dropzone click
        document.getElementById('dropzone').addEventListener('click', () => {
            document.getElementById('fileInput').click();
        });
        
        // File input change
        document.getElementById('fileInput').addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.handleFile(e.target.files[0]);
            }
        });
        
        // Drag and drop
        const dropzone = document.getElementById('dropzone');
        
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        
        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dragover');
        });
        
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            
            if (e.dataTransfer.files.length > 0) {
                this.handleFile(e.dataTransfer.files[0]);
            }
        });
        
        // Remove file
        document.getElementById('removeFileBtn').addEventListener('click', () => {
            this.removeFile();
        });
        
        // Cancel import
        document.getElementById('cancelImportBtn').addEventListener('click', () => {
            this.removeFile();
        });
        
        // Confirm import
        document.getElementById('confirmImportBtn').addEventListener('click', () => {
            this.confirmImport();
        });
        
        // New import
        document.getElementById('newImportBtn').addEventListener('click', () => {
            this.resetImport();
        });
    },
    
    /**
     * Handle file selection
     */
    handleFile(file) {
        console.log('[IMPORT] File selected:', file.name, file.size, 'bytes');
        
        // Validate file
        if (!this.validateFile(file)) {
            return;
        }
        
        // Store file
        this.selectedFile = file;
        
        // Show file info
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = this.formatFileSize(file.size);
        document.getElementById('fileInfo').classList.remove('hidden');
        
        // Parse and show preview
        this.parseAndPreview(file);
    },
    
    /**
     * Validate file
     */
    validateFile(file) {
        // Check file type
        const validTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'application/vnd.ms-excel' // .xls
        ];
        
        const validExtensions = ['.xlsx', '.xls'];
        const fileName = file.name.toLowerCase();
        const hasValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
        
        if (!validTypes.includes(file.type) && !hasValidExtension) {
            alert('Formato file non valido. Usa file .xlsx o .xls');
            return false;
        }
        
        // Check file size (10MB max)
        const maxSize = 10 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('File troppo grande. Dimensione massima: 10MB');
            return false;
        }
        
        return true;
    },
    
    /**
     * Parse file and show preview
     */
    async parseAndPreview(file) {
        try {
            console.log('[IMPORT] Parsing file...');
            
            // Show loading
            if (typeof Utils !== 'undefined') {
                Utils.showLoading('Analisi file in corso...');
            }
            
            // For now, just show a placeholder
            // Real Excel parsing would require a library like SheetJS
            // which we don't have in the frontend
            
            // Simulate parsing delay
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // Hide loading
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
            }
            
            // Show info that we'll parse on server
            alert('Il file verrà analizzato dal server durante l\'upload.\n\nClicca "Conferma Import" per procedere.');
            
            // Show preview section with placeholder
            document.getElementById('previewSection').classList.remove('hidden');
            document.getElementById('previewCount').textContent = '?';
            
            const tbody = document.getElementById('previewTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                        <i data-lucide="file-search" class="w-12 h-12 mx-auto mb-2 text-gray-400"></i>
                        <p>L'anteprima dei dati verrà mostrata dopo il caricamento</p>
                    </td>
                </tr>
            `;
            
            lucide.createIcons();
            
        } catch (error) {
            console.error('[IMPORT] Parse error:', error);
            
            if (typeof Utils !== 'undefined') {
                Utils.hideLoading();
                Utils.showToast('Errore nell\'analisi del file', 'error');
            }
        }
    },
    
    /**
     * Confirm import
     */
    async confirmImport() {
        try {
            // Validate selections
            if (!this.selectedEventId) {
                alert('Seleziona un evento prima di procedere');
                return;
            }
            
            if (!this.selectedFile) {
                alert('Seleziona un file da importare');
                return;
            }
            
            console.log('[IMPORT] Starting import...');
            console.log('Event ID:', this.selectedEventId);
            console.log('Stadium ID:', this.stadiumId);
            console.log('File:', this.selectedFile.name);
            
            // Show progress
            document.getElementById('progressSection').classList.remove('hidden');
            
            // Simulate upload progress
            this.simulateProgress();
            
            // Call API
            const response = await API.guests.admin.import(
                this.selectedFile,
                this.selectedEventId,
                this.stadiumId
            );
            
            console.log('[IMPORT] Import response:', response);
            
            // Hide progress
            document.getElementById('progressSection').classList.add('hidden');
            
            if (response.success) {
                // Show success
                const imported = response.data.imported_count || response.data.summary?.successful || 0;
                document.getElementById('successCount').textContent = imported;
                
                // Hide other sections
                document.getElementById('uploadSection').classList.add('hidden');
                document.getElementById('previewSection').classList.add('hidden');
                
                // Show success
                document.getElementById('successSection').classList.remove('hidden');
                
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(`${imported} ospiti importati con successo!`, 'success', 5000);
                }
            } else {
                alert('Errore durante l\'import:\n\n' + (response.message || 'Errore sconosciuto'));
            }
            
        } catch (error) {
            console.error('[IMPORT] Import error:', error);
            
            document.getElementById('progressSection').classList.add('hidden');
            
            alert('Errore durante l\'import:\n\n' + error.message);
        }
    },
    
    /**
     * Simulate upload progress
     */
    simulateProgress() {
        let progress = 0;
        const interval = setInterval(() => {
            progress += 10;
            
            if (progress <= 100) {
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressPercent').textContent = progress + '%';
            } else {
                clearInterval(interval);
            }
        }, 200);
    },
    
    /**
     * Remove selected file
     */
    removeFile() {
        this.selectedFile = null;
        
        // Reset file input
        document.getElementById('fileInput').value = '';
        
        // Hide sections
        document.getElementById('fileInfo').classList.add('hidden');
        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('progressSection').classList.add('hidden');
        
        // Reset progress
        document.getElementById('progressBar').style.width = '0%';
        document.getElementById('progressPercent').textContent = '0%';
        
        console.log('[IMPORT] File removed');
    },
    
    /**
     * Reset import (after success)
     */
    resetImport() {
        this.removeFile();
        
        // Show upload section
        document.getElementById('uploadSection').classList.remove('hidden');
        
        // Hide success section
        document.getElementById('successSection').classList.add('hidden');
        
        // Reset event selection
        document.getElementById('eventSelect').value = '';
        this.selectedEventId = null;
    },
    
    /**
     * Download Excel template
     */
    async downloadTemplate() {
        console.log('[IMPORT] Downloading template...');
        
        try {
            // Direct download implementation
            const token = Auth.getToken();
            const url = 'https://checkindigitale.cloud/api/admin/guests/import/template';
            
            console.log('[IMPORT] Direct download from:', url);
            console.log('[IMPORT] Token exists:', !!token);
            
            if (!token) {
                alert('Sessione scaduta. Effettua nuovamente il login.');
                return;
            }
            
            // Direct fetch call
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                }
            });
            
            console.log('[IMPORT] Response status:', response.status);
            console.log('[IMPORT] Response headers:', response.headers);
            
            if (!response.ok) {
                const text = await response.text();
                console.error('[IMPORT] Error response:', text);
                alert('Errore nel download: ' + response.status + ' - ' + text.substring(0, 100));
                return;
            }
            
            const blob = await response.blob();
            console.log('[IMPORT] Blob size:', blob.size, 'bytes');
            console.log('[IMPORT] Blob type:', blob.type);
            
            // Create download link
            const downloadUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = 'template_import_ospiti.xlsx';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(downloadUrl);
            
            console.log('[IMPORT] Template downloaded successfully!');
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Download template completato', 'success');
            }
            
        } catch (error) {
            console.error('[IMPORT] Download failed:', error);
            console.error('[IMPORT] Error stack:', error.stack);
            
            alert('Errore nel download del template: ' + error.message);
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore nel download del template', 'error');
            }
        }
    },
    
    /**
     * Format file size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },
    
    /**
     * Format date
     */
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    }
};

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ImportPage;
}

console.log('[IMPORT] Import module loaded');