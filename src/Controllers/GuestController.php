<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/GuestController.php            *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*   This is proprietary software                         *
*   developed by bitAND solution for bitAND solution     *
*                                                        *
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Services\CheckinService;
use Hospitality\Repositories\GuestAccessRepository;
use Hospitality\Repositories\GuestRepository;
use Hospitality\Repositories\UserRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Hospitality\Services\NotificationService;
use Hospitality\Services\LogService;
use Exception;

class GuestController {
    private GuestRepository $guestRepository;
    private UserRepository $userRepository;
    private CheckinService $checkinService;

    public function __construct() {
        $this->guestRepository = new GuestRepository();
        $this->userRepository = new UserRepository();
        $this->checkinService = new CheckinService();
    }

    /**
     * GET /api/guests/search
     * Ricerca ospiti ultra-veloce con filtri multipli
     */
    public function search(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $startTime = microtime(true);

            // Get query parameters
            $searchQuery = trim($_GET['q'] ?? '');
            $roomId = $_GET['room_id'] ?? null;
            $eventId = $_GET['event_id'] ?? null;
            $vipLevel = $_GET['vip_level'] ?? null;
            $accessStatus = $_GET['access_status'] ?? null;
            // Calcola limite dinamico basato su capacità sala
            $requestedLimit = (int)($_GET['limit'] ?? 100);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            $maxLimit = 500;

            if ($decoded->role === 'hostess') {
                $roomCapacity = $this->guestRepository->getTotalCapacityForHostess($decoded->user_id);
                
                if ($roomCapacity > 0) {
                    $maxLimit = min($roomCapacity, 1000);
                    error_log(sprintf("[LIMIT] Hostess %d - Capacity: %d, Limit: %d", $decoded->user_id, $roomCapacity, $maxLimit));
                }
            } else {
                $maxLimit = 1000;
            }

            $limit = min($requestedLimit, $maxLimit);

            // Validate search query
            if (!empty($searchQuery) && !Validator::validateSearchQuery($searchQuery)) {
                $this->sendError('Invalid search query format', [
                    'requirements' => 'Minimum 2 characters, letters and numbers only'
                ], 422);
                return;
            }

            // Build filters
            $filters = [
                'stadium_id' => TenantMiddleware::getStadiumIdForQuery(),
                'limit' => $limit,
                'offset' => $offset
            ];

            if ($searchQuery) $filters['search_query'] = $searchQuery;
            if ($roomId && Validator::validateId($roomId)) $filters['room_ids'] = (int)$roomId;
            if ($eventId && Validator::validateId($eventId)) $filters['event_id'] = (int)$eventId;
            if ($vipLevel && in_array($vipLevel, ['standard', 'premium', 'vip', 'ultra_vip'])) {
                $filters['vip_level'] = $vipLevel;
            }
            if ($accessStatus && in_array($accessStatus, ['checked_in', 'not_checked_in'])) {
                $filters['access_status'] = $accessStatus;
            }

            // For hostess, limit to assigned rooms
            if ($decoded->role === 'hostess') {
                $result = $this->guestRepository->getGuestsForHostess($decoded->user_id, $filters);
            } else {
                $result = $this->guestRepository->searchGuests($filters);
            }

            // Rimuovi limit e offset per ottenere TUTTI i record filtrati
            $statsFilters = $filters;
            unset($statsFilters['limit']);
            unset($statsFilters['offset']);

            // Esegui query per ottenere TUTTI i record filtrati
            if ($decoded->role === 'hostess') {
                $totalCount = $this->guestRepository->countGuestsForHostess($decoded->user_id, $statsFilters);
            } else {
                $countResult = $this->guestRepository->searchGuests(array_merge($statsFilters, ['limit' => 1, 'offset' => 0]));
                $totalCount = $countResult['total_found'] ?? 0;
            }

            $stats = $this->calculateStats($result['results']);
            $stats['total_count'] = $totalCount;


            // Log the search
            LogService::log(
                'GUEST_SEARCH',
                'Guest search performed',
                [
                    'search_query' => $searchQuery,
                    'results_count' => $result['total_found'],
                    'execution_time_ms' => $result['execution_time_ms'],
                    'filters_applied' => array_keys(array_filter($filters))
                ],
                $decoded->user_id,
                $decoded->stadium_id
            );

            $totalTime = (microtime(true) - $startTime) * 1000;

            $this->sendSuccess([
                'guests' => $result['results'],
                'stats' => $stats,  // AGGIUNTO
                'pagination' => [
                    'total_found' => $result['total_found'],
                    'total_count' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => $result['has_more']
                ],
                'search_info' => [
                    'query' => $searchQuery,
                    'filters_applied' => count(array_filter($filters)) - 2,
                    'execution_time_ms' => $result['execution_time_ms'],
                    'total_request_time_ms' => round($totalTime, 2)
                ]
            ]);

            if ($totalTime > 200) {
                Logger::warning('Slow guest search detected', [
                    'total_time_ms' => round($totalTime, 2),
                    'db_time_ms' => $result['execution_time_ms'],
                    'user_id' => $decoded->user_id,
                    'search_query' => $searchQuery
                ]);
            }

        } catch (Exception $e) {
            Logger::error('Guest search failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null,
                'search_query' => $searchQuery ?? null
            ]);

            $this->sendError('Search failed', $e->getMessage(), 500);
        }
    }
    

    /**
     * Calculate statistics from guest results
     */
    private function calculateStats(array $guests): array {
        $total = count($guests);
        $checkedIn = 0;
        $vipCounts = [
            'standard' => 0,
            'premium' => 0,
            'vip' => 0,
            'ultra_vip' => 0
        ];

        foreach ($guests as $guest) {
            if ($guest['access_status'] === 'checked_in') {
                $checkedIn++;
            }
            
            $vipLevel = $guest['vip_level'] ?? 'standard';
            if (isset($vipCounts[$vipLevel])) {
                $vipCounts[$vipLevel]++;
            }
        }

        return [
            'total' => $total,
            'checked_in' => $checkedIn,
            'pending' => $total - $checkedIn,
            'vip_counts' => $vipCounts
        ];
    }

    /**
     * GET /api/guests/quick-search
     * Auto-completamento ultra-veloce per nomi
     */
    public function quickSearch(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $query = trim($_GET['q'] ?? '');
            
            if (strlen($query) < 2) {
                $this->sendError('Query too short', ['minimum' => 2], 400);
                return;
            }

            if (!Validator::validateSearchQuery($query)) {
                $this->sendError('Invalid query format', [], 422);
                return;
            }

            // Get stadium ID - handles super admin fallback
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId && $decoded->role === 'super_admin') {
                $requestedStadiumId = (int)($_GET['stadium_id'] ?? 0);
                
                if ($requestedStadiumId > 0) {
                    $stadiumId = $requestedStadiumId;
                } else {
                    // Fallback: use first available stadium
                    try {
                        $db = \Hospitality\Config\Database::getInstance()->getConnection();
                        $stmt = $db->prepare("SELECT id FROM stadiums WHERE is_active = 1 LIMIT 1");
                        $stmt->execute();
                        $stadiumId = $stmt->fetchColumn();
                        
                        if (!$stadiumId) {
                            $this->sendError('No stadiums available', [], 404);
                            return;
                        }
                    } catch (Exception $e) {
                        Logger::error('Failed to get stadium for quick search', ['error' => $e->getMessage()]);
                        $this->sendError('Failed to determine stadium context', [], 500);
                        return;
                    }
                }
            }

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Get assigned rooms for hostess
            $roomIds = null;
            if ($decoded->role === 'hostess') {
                try {
                    $roomIds = $this->guestRepository->getHostessAssignedRooms($decoded->user_id);
                    
                    if (empty($roomIds)) {
                        $this->sendSuccess([
                            'suggestions' => [],
                            'message' => 'No rooms assigned'
                        ]);
                        return;
                    }
                } catch (Exception $e) {
                    Logger::error('Failed to get hostess rooms for quick search', [
                        'error' => $e->getMessage(),
                        'user_id' => $decoded->user_id
                    ]);
                    $this->sendError('Failed to get assigned rooms', [], 500);
                    return;
                }
            }

            $result = $this->guestRepository->quickSearch($query, $stadiumId, $roomIds, 10);

            $this->sendSuccess([
                'suggestions' => $result['suggestions'],
                'execution_time_ms' => $result['execution_time_ms']
            ]);

        } catch (Exception $e) {
            Logger::error('Quick search failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null,
                'query' => $query ?? null
            ]);

            $this->sendError('Quick search failed', [], 500);
        }
    }

    /**
     * GET /api/guests/{id}
     * Dettagli ospite specifico
     */
    public function show(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            $guest = $this->guestRepository->findById($id, $stadiumId);

            if (!$guest) {
                $this->sendError('Guest not found', [], 404);
                return;
            }

            // For hostess, check if guest is in assigned rooms - FIX: Access DB correctly
            if ($decoded->role === 'hostess') {
                try {
                    $db = \Hospitality\Config\Database::getInstance()->getConnection();
                    $stmt = $db->prepare("
                        SELECT 1 FROM user_room_assignments ura 
                        WHERE ura.user_id = ? AND ura.room_id = ? AND ura.is_active = 1
                    ");
                    $stmt->execute([$decoded->user_id, $guest['room_id']]);
                    
                    if (!$stmt->fetch()) {
                        $this->sendError('Access denied to this guest', [], 403);
                        return;
                    }
                } catch (Exception $e) {
                    Logger::error('Failed to check hostess room access', [
                        'error' => $e->getMessage(),
                        'user_id' => $decoded->user_id,
                        'guest_id' => $id
                    ]);
                    $this->sendError('Failed to verify access permissions', [], 500);
                    return;
                }
            }

            $this->sendSuccess([
                'guest' => $guest
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get guest details', [
                'guest_id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            $this->sendError('Failed to get guest details', [], 500);
        }
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

    /**
     * Legge e decodifica input JSON dalla request
     */
    private function getJsonInput(): array {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON input', ['json_error' => json_last_error_msg()], 400);
            exit;
        }

        return $data ?? [];
    }

    private function sendSuccess(array $data): void {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }

    private function sendError(string $message, mixed $details = [], int $code = 400): void {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'details' => $details,
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/guests/{id}/checkin
     * Check-in ospite
     */
    public function checkin(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Solo hostess  + super_admin per testing possono fare check-in
            if ($decoded->role !== 'hostess' && $decoded->role !== 'super_admin') {
                $this->sendError('Only hostess and super admin can perform check-ins', [], 403);
                return;
            }

            $input = $this->getJsonInput();
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Validazioni base
            if (!Validator::validateId($id)) {
                $this->sendError('Invalid guest ID', [], 422);
                return;
            }

            // Esegui check-in
            $result = $this->checkinService->checkinGuest(
                $id, 
                $decoded->user_id, 
                $stadiumId, 
                [
                    'companions' => $input['companions'] ?? 0,
                    'notes' => $input['notes'] ?? null
                ]
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::error('Check-in request failed', [
                'guest_id' => $id,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            // Errori business specifici
            if (str_contains($e->getMessage(), 'already checked in')) {
                $this->sendError($e->getMessage(), [], 409); // Conflict
            } elseif (str_contains($e->getMessage(), 'does not have access')) {
                $this->sendError($e->getMessage(), [], 403); // Forbidden
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->sendError($e->getMessage(), [], 404); // Not Found
            } else {
                $this->sendError('Check-in failed', [], 500);
            }
        }
    }

    /**
     * POST /api/guests/{id}/checkout
     * Check-out ospite
     */
    public function checkout(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Solo hostess  + super_admin per testing possono fare check-in
            if ($decoded->role !== 'hostess' && $decoded->role !== 'super_admin') {
                $this->sendError('Only hostess and super admin can perform check-ins', [], 403);
                return;
            }

            $input = $this->getJsonInput();
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Validazioni base
            if (!Validator::validateId($id)) {
                $this->sendError('Invalid guest ID', [], 422);
                return;
            }

            // Esegui check-out
            $result = $this->checkinService->checkoutGuest(
                $id, 
                $decoded->user_id, 
                $stadiumId, 
                [
                    'notes' => $input['notes'] ?? null
                ]
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::error('Check-out request failed', [
                'guest_id' => $id,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            // Errori business specifici
            if (str_contains($e->getMessage(), 'not currently checked in')) {
                $this->sendError($e->getMessage(), [], 409); // Conflict
            } elseif (str_contains($e->getMessage(), 'does not have access')) {
                $this->sendError($e->getMessage(), [], 403); // Forbidden
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->sendError($e->getMessage(), [], 404); // Not Found
            } else {
                $this->sendError('Check-out failed', [], 500);
            }
        }
    }

    /**
     * GET /api/guests/{id}/access-history
     * Storico accessi ospite
     */
    public function accessHistory(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Validazioni base
            if (!Validator::validateId($id)) {
                $this->sendError('Invalid guest ID', [], 422);
                return;
            }

            // Ottieni storico
            $result = $this->checkinService->getGuestAccessHistory(
                $id, 
                $decoded->user_id, 
                $stadiumId
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::error('Access history request failed', [
                'guest_id' => $id,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            if (str_contains($e->getMessage(), 'Access denied')) {
                $this->sendError($e->getMessage(), [], 403);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->sendError($e->getMessage(), [], 404);
            } else {
                $this->sendError('Failed to get access history', [], 500);
            }
        }
    }

    /**
     * GET /api/guests/{id}/status
     * Stato attuale ospite (per UI real-time)
     */
    public function status(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Validazioni base
            if (!Validator::validateId($id)) {
                $this->sendError('Invalid guest ID', [], 422);
                return;
            }

            // Ottieni stato attuale
            $result = $this->checkinService->getGuestCurrentStatus($id, $stadiumId);

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::error('Guest status request failed', [
                'guest_id' => $id,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            if (str_contains($e->getMessage(), 'not found')) {
                $this->sendError($e->getMessage(), [], 404);
            } else {
                $this->sendError('Failed to get guest status', [], 500);
            }
        }
    }

    /**
     * GET /api/guests/checkin-stats
     * Statistiche check-in per hostess/admin
     */
    public function checkinStats(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            $roomId = $_GET['room_id'] ?? null;
            $date = $_GET['date'] ?? date('Y-m-d');

            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Per hostess, limitiamo alle proprie sale
            if ($decoded->role === 'hostess' && !$roomId) {
                // Ottieni prima sala assegnata come default
                $db = \Hospitality\Config\Database::getInstance()->getConnection();
                $stmt = $db->prepare("
                    SELECT room_id FROM user_room_assignments 
                    WHERE user_id = ? AND is_active = 1 
                    LIMIT 1
                ");
                $stmt->execute([$decoded->user_id]);
                $roomId = $stmt->fetchColumn();

                if (!$roomId) {
                    $this->sendSuccess([
                        'message' => 'No rooms assigned to hostess',
                        'stats' => [
                            'total_checkins' => 0,
                            'total_checkouts' => 0,
                            'unique_guests' => 0
                        ]
                    ]);
                    return;
                }
            }

            // Ottieni statistiche
            $stats = $this->checkinService->getCheckinStats(
                $stadiumId, 
                $roomId ? (int)$roomId : null, 
                $date
            );

            $this->sendSuccess([
                'stats' => $stats,
                'filters' => [
                    'stadium_id' => $stadiumId,
                    'room_id' => $roomId,
                    'date' => $date
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Check-in stats request failed', [
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Failed to get check-in statistics', [], 500);
        }
    }

    /**
     * PUT /api/guests/{id}
     * Update guest data (hostess can edit all fields, admin notified via email)
     */
    public function update(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $input = $this->getJsonInput();
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            // ✅ LOCK OTTIMISTICO: Ottieni dati correnti con timestamp
            $guestBefore = $this->guestRepository->getGuestDataForDiff($id, $stadiumId);
            
            if (!$guestBefore) {
                $this->sendError('Guest not found', [], 404);
                return;
            }

            // Salva updated_at per lock ottimistico
            $expectedUpdatedAt = $guestBefore['updated_at'];

            // For hostess, verify they can edit this guest
            if ($decoded->role === 'hostess') {
                if (!$this->guestRepository->canHostessEditGuest($id, $decoded->user_id, $stadiumId)) {
                    $this->sendError('You do not have permission to edit this guest', [
                        'reason' => 'Guest is not in your assigned rooms'
                    ], 403);
                    return;
                }
            }

            // Validate input
            $errors = [];
            
            if (isset($input['first_name']) && !Validator::validateString($input['first_name'], 1, 100)) {
                $errors[] = 'First name must be between 1 and 100 characters';
            }

            if (isset($input['last_name']) && !Validator::validateString($input['last_name'], 1, 100)) {
                $errors[] = 'Last name must be between 1 and 100 characters';
            }

            if (isset($input['contact_email']) && !empty($input['contact_email']) && !Validator::validateEmail($input['contact_email'])) {
                $errors[] = 'Invalid email format';
            }

            if (isset($input['contact_phone']) && !empty($input['contact_phone']) && !Validator::validatePhone($input['contact_phone'])) {
                $errors[] = 'Invalid phone format';
            }

            if (isset($input['vip_level']) && !in_array($input['vip_level'], ['standard', 'premium', 'vip', 'ultra_vip'])) {
                $errors[] = 'Invalid VIP level';
            }

            if (isset($input['notes'])) {
                $notes = trim($input['notes']);
                if (mb_strlen($notes) > 2000) {
                    $errors[] = 'Notes must be maximum 2000 characters (current: ' . mb_strlen($notes) . ')';
                }
            }

            if (isset($input['room_id'])) {
                if (!Validator::validateId($input['room_id'])) {
                    $errors[] = 'Invalid room ID';
                } else {
                    if ($decoded->role === 'hostess') {
                        $db = \Hospitality\Config\Database::getInstance()->getConnection();
                        $stmt = $db->prepare("
                            SELECT 1 FROM user_room_assignments 
                            WHERE user_id = ? AND room_id = ? AND is_active = 1
                        ");
                        $stmt->execute([$decoded->user_id, $input['room_id']]);
                        
                        if (!$stmt->fetch()) {
                            $errors[] = 'You can only assign guests to your assigned rooms';
                        }
                    }
                }
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'first_name', 'last_name', 'company_name',
                'contact_email', 'contact_phone', 'vip_level',
                'table_number', 'seat_number', 'room_id', 'notes'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $updateData[$field] = $input[$field];
                }
            }

            if (empty($updateData)) {
                $this->sendError('No fields to update', [], 400);
                return;
            }

            // PERFORM UPDATE CON OPTIMISTIC LOCK
            try {
                $updated = $this->guestRepository->update(
                    $id, 
                    $updateData, 
                    $stadiumId, 
                    $expectedUpdatedAt  // Passa il timestamp per lock
                );

                if (!$updated) {
                    $this->sendError('Failed to update guest', [], 500);
                    return;
                }
            } catch (Exception $e) {
                // GESTIONE CONFLITTO: Un'altra hostess ha modificato il record
                if (strpos($e->getMessage(), 'CONFLICT') !== false) {
                    $this->sendError(
                        'Data conflict', 
                        [
                            'message' => 'Questo ospite è stato modificato da un\'altra hostess. Ricarica i dati e riprova.',
                            'conflict' => true
                        ], 
                        409  // HTTP 409 Conflict
                    );
                    return;
                }
                throw $e;  // Re-throw other exceptions
            }

            // Get updated guest data
            $guestAfter = $this->guestRepository->getGuestDataForDiff($id, $stadiumId);

            // Calculate changes for diff
            $changes = [];
            foreach ($updateData as $field => $newValue) {
                $oldValue = $guestBefore[$field] ?? null;
                
                if ($field === 'room_id' && $oldValue != $newValue) {
                    $changes['room_id'] = [
                        'old' => $guestBefore['room_name'] ?? "ID: {$oldValue}",
                        'new' => $guestAfter['room_name'] ?? "ID: {$newValue}"
                    ];
                } elseif ($oldValue != $newValue) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }

            // Log the operation
            LogService::log(
                'GUEST_EDIT_HOSTESS',
                "Guest data updated by " . ($decoded->role === 'hostess' ? 'hostess' : 'admin'),
                [
                    'guest_id' => $id,
                    'fields_updated' => array_keys($updateData),
                    'changes' => $changes
                ],
                $decoded->user_id,
                $stadiumId,
                'guests',
                $id
            );

            $this->sendSuccess([
                'message' => 'Guest updated successfully',
                'guest' => $guestAfter,
                'changes_made' => count($changes),
                'notification_queued' => $decoded->role === 'hostess' && !empty($changes)
            ]);

            // Chiudi la connessione HTTP (il client non aspetta più)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // Fallback per altri SAPI
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }

            if ($decoded->role === 'hostess' && !empty($changes)) {
                try {
                    $hostessUser = $this->userRepository->findById($decoded->user_id);
                    $hostessName = $hostessUser['full_name'] ?? $hostessUser['username'] ?? 'Unknown';

                    NotificationService::notifyGuestEdit(
                        $id,
                        "{$guestAfter['first_name']} {$guestAfter['last_name']}",
                        $changes,
                        $decoded->user_id,
                        $hostessName,
                        $stadiumId
                    );
                    
                    Logger::info('Guest edit notification sent', [
                        'guest_id' => $id,
                        'hostess_id' => $decoded->user_id,
                        'changes_count' => count($changes)
                    ]);
                    
                } catch (\Exception $e) {
                    Logger::error('Failed to send guest edit notification (non-blocking)', [
                        'guest_id' => $id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (Exception $e) {
            Logger::error('Failed to update guest', [
                'guest_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);

            $this->sendError('Failed to update guest', $e->getMessage(), 500);
        }
    }
}