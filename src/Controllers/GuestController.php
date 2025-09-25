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
            // Require authentication
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $startTime = microtime(true);

            // Get query parameters
            $searchQuery = trim($_GET['q'] ?? '');
            $roomId = $_GET['room_id'] ?? null;
            $eventId = $_GET['event_id'] ?? null;
            $vipLevel = $_GET['vip_level'] ?? null;
            $accessStatus = $_GET['access_status'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 50), 100); // Max 100 per request
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            // Validate search query
            if (!empty($searchQuery) && !Validator::validateSearchQuery($searchQuery)) {
                $this->sendError('Invalid search query format', [
                    'requirements' => 'Minimum 2 characters, letters and numbers only'
                ], 422);
                return;
            }

            // Build filters based on user role
            $filters = [
                'stadium_id' => TenantMiddleware::getStadiumIdForQuery(),
                'limit' => $limit,
                'offset' => $offset
            ];

            if ($searchQuery) {
                $filters['search_query'] = $searchQuery;
            }

            if ($roomId && Validator::validateId($roomId)) {
                $filters['room_ids'] = (int)$roomId;
            }

            if ($eventId && Validator::validateId($eventId)) {
                $filters['event_id'] = (int)$eventId;
            }

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
                // Stadium admin or super admin - all rooms in stadium
                $result = $this->guestRepository->searchGuests($filters);
            }

            // Get total count for pagination (only if needed)
            $totalCount = null;
            if ($offset === 0 && count($result['results']) === $limit) {
                // Only count if we might have more results
                $totalCount = $this->guestRepository->countGuests($filters);
            }

            // Log the search for analytics
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
                'pagination' => [
                    'total_found' => $result['total_found'],
                    'total_count' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => $result['has_more']
                ],
                'search_info' => [
                    'query' => $searchQuery,
                    'filters_applied' => count(array_filter($filters)) - 2, // Exclude limit/offset
                    'execution_time_ms' => $result['execution_time_ms'],
                    'total_request_time_ms' => round($totalTime, 2)
                ]
            ]);

            // Performance alert
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
                    $db = \Hospitality\Config\Database::getInstance()->getConnection();
                    $stmt = $db->prepare("
                        SELECT room_id FROM user_room_assignments 
                        WHERE user_id = ? AND is_active = 1
                    ");
                    $stmt->execute([$decoded->user_id]);
                    $roomIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
                    
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

            // Per hostess, verifica accesso alla sala
            if ($decoded->role === 'hostess') {
                $guestAccessRepo = new GuestAccessRepository();
                if (!$guestAccessRepo->canHostessAccessGuest($decoded->user_id, $id, $stadiumId)) {
                    $this->sendError('Access denied to this guest', [], 403);
                    return;
                }
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
}