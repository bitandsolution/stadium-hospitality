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

    public function __construct() {
        $this->guestRepository = new GuestRepository();
        $this->userRepository = new UserRepository();
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

            // For super admin, use stadium_id from query parameter or default to first stadium
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();

            if (!$stadiumId && $decoded->role === 'super_admin') {
                // Super admin can specify stadium_id or we use a default
                $requestedStadiumId = (int)($_GET['stadium_id'] ?? 0);
                
                if ($requestedStadiumId > 0) {
                    $stadiumId = $requestedStadiumId;
                } else {
                    // Get first available stadium as fallback
                    try {
                        $stmt = $this->guestRepository->db->prepare("SELECT id FROM stadiums WHERE is_active = 1 LIMIT 1");
                        $stmt->execute();
                        $stadiumId = $stmt->fetchColumn();
                        
                        if (!$stadiumId) {
                            $this->sendError('No stadiums available', [], 404);
                            return;
                        }
                    } catch (Exception $e) {
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
                $stmt = $this->guestRepository->db->prepare("
                    SELECT room_id FROM user_room_assignments 
                    WHERE user_id = ? AND is_active = 1
                ");
                $stmt->execute([$decoded->user_id]);
                $roomIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (empty($roomIds)) {
                    $this->sendSuccess([
                        'suggestions' => [],
                        'message' => 'No rooms assigned'
                    ]);
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
                'user_id' => $decoded->user_id ?? null
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

            // For hostess, check if guest is in assigned rooms
            if ($decoded->role === 'hostess') {
                $stmt = $this->guestRepository->db->prepare("
                    SELECT 1 FROM user_room_assignments ura 
                    WHERE ura.user_id = ? AND ura.room_id = ? AND ura.is_active = 1
                ");
                $stmt->execute([$decoded->user_id, $guest['room_id']]);
                
                if (!$stmt->fetch()) {
                    $this->sendError('Access denied to this guest', [], 403);
                    return;
                }
            }

            $this->sendSuccess([
                'guest' => $guest
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get guest details', [
                'guest_id' => $id,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Failed to get guest details', [], 500);
        }
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

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
}