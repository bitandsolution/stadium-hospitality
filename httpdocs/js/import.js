/**
 * Import Page Controller
 * Handles Excel file upload, validation, preview, and import
 */

const ImportPage = {
    selectedFile: null,
    selectedEventId: null,
    stadiumId: null,
    previewData: [],
    
    async init() {
        console.log('[IMPORT] Initializing...');
        
        const user = Auth.getUser();
        this.stadiumId = user.stadium_id;
        
        console.log('[IMPORT] User info:', {
            stadium_id: this.stadiumId,
            role: user.role
        });
        
        document.getElementById('stadiumName').value = user.stadium_name || 'N/A';
        await this.loadEvents();
        this.setupEventListeners();
    },
    
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
    
    setupEventListeners() {
        document.getElementById('eventSelect').addEventListener('change', (e) => {
            this.selectedEventId = e.target.value ? parseInt(e.target.value) : null;
            console.log('[IMPORT] Event selected:', this.selectedEventId);
        });
        
        document.getElementById('downloadTemplateBtn').addEventListener('click', () => {
            this.downloadTemplate();
        });
        
        document.getElementById('dropzone').addEventListener('click', () => {
            document.getElementById('fileInput').click();
        });
        
        document.getElementById('fileInput').addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                this.handleFile(e.target.files[0]);
            }
        });
        
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
        
        document.getElementById('removeFileBtn').addEventListener('click', () => {
            this.removeFile();
        });
        
        document.getElementById('cancelImportBtn').addEventListener('click', () => {
            this.removeFile();
        });
        
        document.getElementById('confirmImportBtn').addEventListener('click', () => {
            this.confirmImport();
        });
        
        document.getElementById('newImportBtn').addEventListener('click', () => {
            this.resetImport();
        });
    },
    
    handleFile(file) {
        console.log('[IMPORT] File selected:', file.name, file.size, 'bytes');
        
        if (!this.validateFile(file)) {
            return;
        }
        
        this.selectedFile = file;
        
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = this.formatFileSize(file.size);
        document.getElementById('fileInfo').classList.remove('hidden');
        
        this.showPreviewSection(file);
    },
    
    validateFile(file) {
        const validTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel'
        ];
        
        const validExtensions = ['.xlsx', '.xls'];
        const fileName = file.name.toLowerCase();
        const hasValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
        
        if (!validTypes.includes(file.type) && !hasValidExtension) {
            alert('Formato file non valido. Usa file .xlsx o .xls');
            return false;
        }
        
        const maxSize = 10 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('File troppo grande. Dimensione massima: 10MB');
            return false;
        }
        
        return true;
    },
    
    showPreviewSection(file) {
        console.log('[IMPORT] Showing preview section for:', file.name);
        
        document.getElementById('previewSection').classList.remove('hidden');
        document.getElementById('previewCount').textContent = 'File pronto';
        
        const tbody = document.getElementById('previewTableBody');
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                    <i data-lucide="file-search" class="w-12 h-12 mx-auto mb-2 text-gray-400"></i>
                    <p class="font-medium">File pronto per l'import</p>
                    <p class="text-sm mt-2">${file.name} (${this.formatFileSize(file.size)})</p>
                    <p class="text-xs mt-1 text-gray-400">I dati verranno validati durante l'upload</p>
                </td>
            </tr>
        `;
        
        lucide.createIcons();
    },
    
    async confirmImport() {
        try {
            if (!this.selectedEventId) {
                alert('Seleziona un evento prima di procedere');
                return;
            }
            
            if (!this.selectedFile) {
                alert('Seleziona un file da importare');
                return;
            }
            
            console.log('[IMPORT] Starting import...');
            console.log('[IMPORT] Event ID:', this.selectedEventId);
            console.log('[IMPORT] Stadium ID:', this.stadiumId);
            console.log('[IMPORT] File:', this.selectedFile.name);
            
            document.getElementById('progressSection').classList.remove('hidden');
            this.updateProgress(10);
            
            const formData = new FormData();
            formData.append('file', this.selectedFile);
            formData.append('event_id', this.selectedEventId.toString());
            formData.append('stadium_id', this.stadiumId.toString());
            formData.append('dry_run', 'false');
            
            console.log('[IMPORT] FormData prepared:', {
                event_id: this.selectedEventId,
                stadium_id: this.stadiumId,
                file_name: this.selectedFile.name,
                file_size: this.selectedFile.size
            });
            
            this.updateProgress(30);
            
            const token = Auth.getToken();
            const response = await fetch('https://checkindigitale.cloud/api/admin/guests/import', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`
                },
                body: formData
            });
            
            this.updateProgress(70);
            
            console.log('[IMPORT] API response status:', response.status);
            
            const result = await response.json();
            console.log('[IMPORT] API response data:', result);
            
            this.updateProgress(100);
            
            setTimeout(() => {
                document.getElementById('progressSection').classList.add('hidden');
            }, 500);
            
            if (result.success && result.data) {
                const imported = result.data.imported_count || 
                               result.data.summary?.successful || 
                               result.data.imported || 
                               0;
                
                console.log('[IMPORT] Import successful, imported count:', imported);
                
                document.getElementById('successCount').textContent = imported;
                document.getElementById('uploadSection').classList.add('hidden');
                document.getElementById('previewSection').classList.add('hidden');
                document.getElementById('successSection').classList.remove('hidden');
                
                if (result.data.skipped_count > 0) {
                    console.warn('[IMPORT] Some guests were skipped:', result.data.skipped_count);
                }
                
                if (result.data.errors && result.data.errors.length > 0) {
                    console.warn('[IMPORT] Import had errors:', result.data.errors);
                }
                
                if (typeof Utils !== 'undefined') {
                    Utils.showToast(`${imported} ospiti importati con successo!`, 'success', 5000);
                }
            } else {
                const errorMsg = result.message || 'Errore sconosciuto';
                const details = result.details ? '\n\nDettagli: ' + JSON.stringify(result.details, null, 2) : '';
                
                console.error('[IMPORT] Import failed:', result);
                alert('Errore durante l\'import:\n\n' + errorMsg + details);
                
                if (typeof Utils !== 'undefined') {
                    Utils.showToast('Errore durante l\'import', 'error');
                }
            }
            
        } catch (error) {
            console.error('[IMPORT] Import error:', error);
            console.error('[IMPORT] Error stack:', error.stack);
            
            document.getElementById('progressSection').classList.add('hidden');
            
            alert('Errore durante l\'import:\n\n' + error.message);
            
            if (typeof Utils !== 'undefined') {
                Utils.showToast('Errore durante l\'import', 'error');
            }
        }
    },
    
    updateProgress(percent) {
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressPercent').textContent = percent + '%';
    },
    
    removeFile() {
        this.selectedFile = null;
        document.getElementById('fileInput').value = '';
        document.getElementById('fileInfo').classList.add('hidden');
        document.getElementById('previewSection').classList.add('hidden');
        document.getElementById('progressSection').classList.add('hidden');
        this.updateProgress(0);
        console.log('[IMPORT] File removed');
    },
    
    resetImport() {
        this.removeFile();
        document.getElementById('uploadSection').classList.remove('hidden');
        document.getElementById('successSection').classList.add('hidden');
        document.getElementById('eventSelect').value = '';
        this.selectedEventId = null;
    },
    
    async downloadTemplate() {
        console.log('[IMPORT] Downloading template...');
        
        try {
            const token = Auth.getToken();
            const url = 'https://checkindigitale.cloud/api/admin/guests/import/template';
            
            console.log('[IMPORT] Direct download from:', url);
            
            if (!token) {
                alert('Sessione scaduta. Effettua nuovamente il login.');
                return;
            }
            
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${token}`
                }
            });
            
            console.log('[IMPORT] Response status:', response.status);
            
            if (!response.ok) {
                const text = await response.text();
                console.error('[IMPORT] Error response:', text);
                alert('Errore nel download: ' + response.status);
                return;
            }
            
            const blob = await response.blob();
            console.log('[IMPORT] Blob size:', blob.size, 'bytes');
            
            const downloadUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = 'hospitality_import_template.xlsx';
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
            alert('Errore nel download del template: ' + error.message);
        }
    },
    
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },
    
    formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = ImportPage;
}

console.log('[IMPORT] Import module loaded');