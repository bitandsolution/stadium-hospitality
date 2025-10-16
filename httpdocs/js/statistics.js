/**
 * Statistics Controller
 * Manages statistics visualization and Excel export
 */

const Statistics = {
    currentView: 'charts',
    currentUser: null,
    eventChart: null,
    roomChart: null,
    currentEventChartType: 'bar',
    currentRoomChartType: 'bar',

    /**
     * Initialize the statistics page
     */
    async init() {
        console.log('Statistics: Initializing...');

        // Check authentication
        if (!Auth.isAuthenticated()) {
            console.log('Statistics: Not authenticated, redirecting to login');
            window.location.href = 'index.html';
            return;
        }

        try {
            // Load user info
            this.currentUser = await Auth.getCurrentUser();
            this.displayUserInfo();

            // Check permissions
            if (!this.hasRequiredPermissions()) {
                Utils.showToast('Non hai i permessi per accedere a questa pagina', 'error');
                setTimeout(() => window.location.href = 'dashboard.html', 2000);
                return;
            }

            // Set default date range (last 30 days)
            this.setDefaultDateRange();

            // Load events for filter
            await this.loadEventsFilter();

            // Load initial data
            await this.loadData();

            console.log('Statistics: Initialization complete');

        } catch (error) {
            console.error('Statistics: Initialization failed', error);
            Utils.showToast('Errore durante l\'inizializzazione', 'error');
        }
    },

    /**
     * Check if user has required permissions
     */
    hasRequiredPermissions() {
        const role = this.currentUser?.user?.role;
        return role === 'super_admin' || role === 'stadium_admin';
    },

    /**
     * Display user info in navbar
     */
    displayUserInfo() {
        const userInfoEl = document.getElementById('userInfo');
        if (userInfoEl && this.currentUser?.user) {
            const user = this.currentUser.user;
            userInfoEl.textContent = `${user.full_name || user.username} (${user.role})`;
        }
    },

    /**
     * Set default date range
     */
    setDefaultDateRange() {
        const today = new Date();
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(today.getDate() - 30);

        document.getElementById('dateFrom').valueAsDate = thirtyDaysAgo;
        document.getElementById('dateTo').valueAsDate = today;
    },

    /**
     * Reset filters to default
     */
    resetFilters() {
        this.setDefaultDateRange();
        document.getElementById('eventFilter').value = '';
        this.loadData();
    },

    /**
     * Load events for filter dropdown
     */
    async loadEventsFilter() {
        try {
            const stadiumId = this.currentUser?.user?.stadium_id;
            if (!stadiumId) {
                console.warn('Statistics: No stadium_id available');
                return;
            }

            console.log('Statistics: Loading events for stadium:', stadiumId);
            
            // Get API base URL (try different variable names)
            const API_BASE_URL = typeof CONFIG !== 'undefined' ? CONFIG.API_BASE_URL : 
                               typeof Config !== 'undefined' ? Config.API_BASE_URL : 
                               'https://checkindigitale.cloud/api';
            
            // Get events from correct admin endpoint
            const response = await fetch(`${API_BASE_URL}/admin/events?stadium_id=${stadiumId}`, {
                method: 'GET',
                headers: {
                    'Authorization': Auth.getAuthHeader(),
                    'Content-Type': 'application/json'
                }
            });
            
            if (!response.ok) {
                console.error('Statistics: Failed to load events:', response.status);
                return;
            }
            
            const data = await response.json();
            console.log('Statistics: Events API response:', data);
            
            // Check different response formats
            let events = [];
            
            if (data.success && data.data && data.data.events) {
                events = data.data.events;
            } else if (data.success && Array.isArray(data.data)) {
                events = data.data;
            } else if (Array.isArray(data.events)) {
                events = data.events;
            } else if (Array.isArray(data)) {
                events = data;
            }
            
            console.log('Statistics: Parsed events:', events);
            
            if (events.length > 0) {
                const select = document.getElementById('eventFilter');
                if (!select) {
                    console.error('Statistics: eventFilter select not found');
                    return;
                }
                
                // Clear existing options except "Tutti gli eventi"
                while (select.options.length > 1) {
                    select.remove(1);
                }
                
                // Add event options
                events.forEach(event => {
                    const option = document.createElement('option');
                    option.value = event.id;
                    option.textContent = `${event.name} - ${Utils.formatDate(event.event_date)}`;
                    select.appendChild(option);
                });
                
                console.log('Statistics: Events filter populated with', events.length, 'events');
            } else {
                console.warn('Statistics: No events found');
            }

        } catch (error) {
            console.error('Statistics: Failed to load events filter', error);
            // Non bloccare l'app se non ci sono eventi
        }
    },

    /**
     * Load all statistics data
     */
    async loadData() {
        try {
            const stadiumId = this.currentUser?.user?.stadium_id;
            if (!stadiumId) {
                Utils.showToast('Stadium ID non disponibile', 'error');
                return;
            }

            const params = this.getFilterParams(stadiumId);

            // Show loading
            document.getElementById('chartsLoading').classList.remove('hidden');
            document.getElementById('chartsContainer').classList.add('hidden');

            // Load all data in parallel
            const [summary, eventStats, roomStats] = await Promise.all([
                API.get('/statistics/summary', params),
                API.get('/statistics/access-by-event', params),
                API.get('/statistics/access-by-room', params)
            ]);

            // Update summary cards
            this.renderSummaryCards(summary.data.summary);

            // Update charts
            this.renderEventChart(eventStats.data.statistics);
            this.renderRoomChart(roomStats.data.statistics);

            // Update export info
            this.updateExportInfo(params);

            // Hide loading, show charts
            document.getElementById('chartsLoading').classList.add('hidden');
            document.getElementById('chartsContainer').classList.remove('hidden');

            Utils.showToast('Statistiche caricate con successo', 'success');

        } catch (error) {
            console.error('Failed to load statistics', error);
            Utils.showToast('Errore nel caricamento delle statistiche', 'error');
            document.getElementById('chartsLoading').classList.add('hidden');
        }
    },

    /**
     * Get filter parameters
     */
    getFilterParams(stadiumId) {
        const params = {
            stadium_id: stadiumId,
            date_from: document.getElementById('dateFrom').value,
            date_to: document.getElementById('dateTo').value
        };

        const eventId = document.getElementById('eventFilter').value;
        if (eventId) {
            params.event_id = eventId;
        }

        return params;
    },

    /**
     * Render summary cards
     */
    renderSummaryCards(summary) {
        const summarySection = document.getElementById('summarySection');
        
        const cards = [
            {
                title: 'Eventi Totali',
                value: summary.total_events || 0,
                icon: 'calendar',
                color: 'blue'
            },
            {
                title: 'Ospiti Totali',
                value: summary.total_guests || 0,
                icon: 'users',
                color: 'indigo'
            },
            {
                title: 'Check-in Effettuati',
                value: summary.total_checked_in || 0,
                icon: 'check-circle',
                color: 'green'
            },
            {
                title: 'Tasso Check-in',
                value: `${summary.overall_check_in_rate || 0}%`,
                icon: 'trending-up',
                color: summary.overall_check_in_rate > 75 ? 'green' : summary.overall_check_in_rate > 50 ? 'yellow' : 'red'
            }
        ];

        summarySection.innerHTML = cards.map(card => `
            <div class="stat-card bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600 mb-1">${Utils.escapeHtml(card.title)}</p>
                        <p class="text-3xl font-bold text-${card.color}-600">${Utils.escapeHtml(String(card.value))}</p>
                    </div>
                    <div class="bg-${card.color}-100 rounded-full p-3">
                        <i data-lucide="${card.icon}" class="w-8 h-8 text-${card.color}-600"></i>
                    </div>
                </div>
            </div>
        `).join('');

        lucide.createIcons();
    },

    /**
     * Render event chart
     */
    renderEventChart(eventStats) {
        const ctx = document.getElementById('eventChart').getContext('2d');

        // Destroy existing chart
        if (this.eventChart) {
            this.eventChart.destroy();
        }

        // Prepare data
        const labels = eventStats.map(e => e.event_name);
        const checkedIn = eventStats.map(e => parseInt(e.checked_in));
        const notCheckedIn = eventStats.map(e => parseInt(e.not_checked_in));

        // Chart configuration
        const config = {
            type: this.currentEventChartType,
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Entrati',
                        data: checkedIn,
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderColor: 'rgba(34, 197, 94, 1)',
                        borderWidth: 2
                    },
                    {
                        label: 'Non Entrati',
                        data: notCheckedIn,
                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                        borderColor: 'rgba(239, 68, 68, 1)',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            footer: (tooltipItems) => {
                                const index = tooltipItems[0].dataIndex;
                                const total = checkedIn[index] + notCheckedIn[index];
                                const percentage = ((checkedIn[index] / total) * 100).toFixed(1);
                                return `Totale: ${total}\nTasso: ${percentage}%`;
                            }
                        }
                    }
                },
                scales: this.currentEventChartType !== 'pie' ? {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                } : {}
            }
        };

        this.eventChart = new Chart(ctx, config);
    },

    /**
     * Render room chart
     */
    renderRoomChart(roomStats) {
        const ctx = document.getElementById('roomChart').getContext('2d');

        // Destroy existing chart
        if (this.roomChart) {
            this.roomChart.destroy();
        }

        // Prepare data
        const labels = roomStats.map(r => r.room_name);
        const checkedIn = roomStats.map(r => parseInt(r.checked_in));
        const notCheckedIn = roomStats.map(r => parseInt(r.not_checked_in));

        // Chart type
        const type = this.currentRoomChartType === 'horizontalBar' ? 'bar' : this.currentRoomChartType;
        const indexAxis = this.currentRoomChartType === 'horizontalBar' ? 'y' : 'x';

        // Chart configuration
        const config = {
            type: type,
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Entrati',
                        data: checkedIn,
                        backgroundColor: 'rgba(79, 70, 229, 0.8)',
                        borderColor: 'rgba(79, 70, 229, 1)',
                        borderWidth: 2
                    },
                    {
                        label: 'Non Entrati',
                        data: notCheckedIn,
                        backgroundColor: 'rgba(156, 163, 175, 0.8)',
                        borderColor: 'rgba(156, 163, 175, 1)',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: indexAxis,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            footer: (tooltipItems) => {
                                const index = tooltipItems[0].dataIndex;
                                const total = checkedIn[index] + notCheckedIn[index];
                                const percentage = ((checkedIn[index] / total) * 100).toFixed(1);
                                return `Totale: ${total}\nTasso: ${percentage}%`;
                            }
                        }
                    }
                },
                scales: type !== 'doughnut' ? {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                } : {}
            }
        };

        this.roomChart = new Chart(ctx, config);
    },

    /**
     * Change event chart type
     */
    changeEventChartType(type) {
        this.currentEventChartType = type;
        
        // Reload only if data is already loaded
        if (this.eventChart) {
            const eventStats = this.eventChart.data.labels.map((label, i) => ({
                event_name: label,
                checked_in: this.eventChart.data.datasets[0].data[i],
                not_checked_in: this.eventChart.data.datasets[1].data[i]
            }));
            this.renderEventChart(eventStats);
        }
    },

    /**
     * Change room chart type
     */
    changeRoomChartType(type) {
        this.currentRoomChartType = type;
        
        // Reload only if data is already loaded
        if (this.roomChart) {
            const roomStats = this.roomChart.data.labels.map((label, i) => ({
                room_name: label,
                checked_in: this.roomChart.data.datasets[0].data[i],
                not_checked_in: this.roomChart.data.datasets[1].data[i]
            }));
            this.renderRoomChart(roomStats);
        }
    },

    /**
     * Switch between views
     */
    switchView(view) {
        this.currentView = view;

        // Update tabs
        document.getElementById('tabCharts').classList.toggle('active', view === 'charts');
        document.getElementById('tabCharts').classList.toggle('border-indigo-600', view === 'charts');
        document.getElementById('tabCharts').classList.toggle('text-indigo-600', view === 'charts');
        document.getElementById('tabCharts').classList.toggle('border-transparent', view !== 'charts');
        document.getElementById('tabCharts').classList.toggle('text-gray-500', view !== 'charts');

        document.getElementById('tabExport').classList.toggle('active', view === 'export');
        document.getElementById('tabExport').classList.toggle('border-indigo-600', view === 'export');
        document.getElementById('tabExport').classList.toggle('text-indigo-600', view === 'export');
        document.getElementById('tabExport').classList.toggle('border-transparent', view !== 'export');
        document.getElementById('tabExport').classList.toggle('text-gray-500', view !== 'export');

        // Show/hide views
        document.getElementById('chartsView').classList.toggle('hidden', view !== 'charts');
        document.getElementById('exportView').classList.toggle('hidden', view !== 'export');
    },

    /**
     * Update export info
     */
    updateExportInfo(params) {
        const dateRange = `${Utils.formatDate(params.date_from)} - ${Utils.formatDate(params.date_to)}`;
        document.getElementById('exportDateRange').textContent = dateRange;

        const eventFilter = document.getElementById('eventFilter');
        const selectedEvent = eventFilter.options[eventFilter.selectedIndex].text;
        document.getElementById('exportEventFilter').textContent = params.event_id ? selectedEvent : 'Tutti';
    },

    /**
     * Export to Excel
     */
    async exportToExcel() {
        try {
            const stadiumId = this.currentUser?.user?.stadium_id;
            if (!stadiumId) {
                Utils.showToast('Stadium ID non disponibile', 'error');
                return;
            }

            const params = this.getFilterParams(stadiumId);
            console.log('Export params:', params);

            // Show progress
            const btnExport = document.getElementById('btnExport');
            const exportProgress = document.getElementById('exportProgress');
            
            if (btnExport) btnExport.disabled = true;
            if (exportProgress) exportProgress.classList.remove('hidden');

            // Get API base URL (try different variable names)
            const API_BASE_URL = typeof CONFIG !== 'undefined' ? CONFIG.API_BASE_URL : 
                               typeof Config !== 'undefined' ? Config.API_BASE_URL : 
                               'https://checkindigitale.cloud/api';

            // Build query string manually
            const queryParams = new URLSearchParams(params).toString();
            const exportUrl = `${API_BASE_URL}/statistics/export-excel?${queryParams}`;
            
            console.log('Export URL:', exportUrl);

            // Request export using fetch to handle errors better
            const response = await fetch(exportUrl, {
                method: 'GET',
                headers: {
                    'Authorization': Auth.getAuthHeader(),
                    'Content-Type': 'application/json'
                }
            });

            console.log('Export response status:', response.status);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('Export failed:', errorText);
                throw new Error(`HTTP ${response.status}: ${errorText}`);
            }

            const data = await response.json();
            console.log('Export response:', data);

            if (data.success && data.data && data.data.download_url) {
                // Download file
                const downloadUrl = API_BASE_URL + data.data.download_url;
                console.log('Downloading from:', downloadUrl);
                
                // Create temporary link and click it
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = data.data.file || 'statistiche.xlsx';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                Utils.showToast(`Export completato! ${data.data.record_count} record esportati`, 'success');
            } else {
                console.error('Invalid export response:', data);
                throw new Error(data.message || 'Export failed - invalid response');
            }

        } catch (error) {
            console.error('Export failed:', error);
            Utils.showToast('Errore durante l\'export: ' + error.message, 'error');
        } finally {
            // Hide progress
            const btnExport = document.getElementById('btnExport');
            const exportProgress = document.getElementById('exportProgress');
            
            if (btnExport) btnExport.disabled = false;
            if (exportProgress) exportProgress.classList.add('hidden');
        }
    }
};