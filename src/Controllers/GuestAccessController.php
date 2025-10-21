<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/GuestAccessController.php      *
*   Gestione check-in/check-out ospiti                   *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Repositories\GuestRepository;
use Hospitality\Repositories\GuestAccessRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Logger;
use Hospitality\Services\LogService;
use Exception;

class GuestAccessController {
    private GuestRepository $guestRepository;
    private GuestAccessRepository $accessRepository;

    public function __construct() {
        $this->guestRepository = new GuestRepository();
        $this->accessRepository = new GuestAccessRepository();
    }

    /**
     * POST /api/guests/{id}/checkin
     * Effettua check-in ospite
     */
    public function checkin(int $guestId): void {
        $db = \Hospitality\Config\Database::getInstance()->getConnection();
        
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            // 🔒 START TRANSACTION WITH LOCK
            $db->beginTransaction();
            
            try {
                // LOCK the guest row to prevent concurrent check-ins
                $stmt = $db->prepare("
                    SELECT g.*, hr.name as room_name, e.event_date
                    FROM guests g
                    LEFT JOIN hospitality_rooms hr ON g.room_id = hr.id
                    LEFT JOIN events e ON g.event_id = e.id
                    WHERE g.id = ? AND g.stadium_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$guestId, $stadiumId]);
                $guest = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$guest) {
                    $db->rollBack();
                    $this->sendError('Guest not found', [], 404);
                    return;
                }

                // For hostess, verify access to room
                if ($decoded->role === 'hostess') {
                    if (!$this->accessRepository->hostessCanAccessRoom($decoded->user_id, $guest['room_id'])) {
                        $db->rollBack();
                        $this->sendError('Access denied to this guest room', [], 403);
                        return;
                    }
                }

                // Check LAST access (now protected by lock)
                $lastAccess = $this->accessRepository->getLastAccess($guestId);
                
                if ($lastAccess && $lastAccess['access_type'] === 'entry') {
                    $db->rollBack();
                    $this->sendError('Guest already checked in', [
                        'last_checkin' => $lastAccess['access_time'],
                        'checked_in_by' => $lastAccess['hostess_name'] ?? 'Unknown'
                    ], 409); // 409 Conflict
                    return;
                }

                // Perform check-in
                $accessId = $this->accessRepository->createAccess([
                    'guest_id' => $guestId,
                    'hostess_id' => $decoded->user_id,
                    'stadium_id' => $guest['stadium_id'],
                    'room_id' => $guest['room_id'],
                    'event_id' => $guest['event_id'],
                    'access_type' => 'entry',
                    'device_type' => $this->detectDeviceType()
                ]);

                if (!$accessId) {
                    throw new Exception('Failed to create access record');
                }
                
                // 🔓 COMMIT - Everything OK
                $db->commit();

                LogService::log(
                    'GUEST_CHECKIN',
                    'Guest checked in successfully',
                    [
                        'guest_id' => $guestId,
                        'guest_name' => $guest['first_name'] . ' ' . $guest['last_name'],
                        'access_id' => $accessId
                    ],
                    $decoded->user_id,
                    $decoded->stadium_id,
                    'guest_accesses',
                    $accessId
                );

                $this->sendSuccess([
                    'message' => 'Check-in completed successfully',
                    'access_id' => $accessId,
                    'access_time' => date('c'),
                    'guest' => [
                        'id' => $guest['id'],
                        'name' => $guest['first_name'] . ' ' . $guest['last_name']
                    ]
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Check-in failed', [
                'guest_id' => $guestId,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Check-in failed', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/guests/{id}/checkout
     * Effettua check-out ospite
     */
    public function checkout(int $guestId): void {
        $db = \Hospitality\Config\Database::getInstance()->getConnection();
        
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            // 🔒 START TRANSACTION WITH LOCK
            $db->beginTransaction();
            
            try {
                // LOCK the guest row to prevent concurrent check-outs
                $stmt = $db->prepare("
                    SELECT g.*, hr.name as room_name
                    FROM guests g
                    LEFT JOIN hospitality_rooms hr ON g.room_id = hr.id
                    WHERE g.id = ? AND g.stadium_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$guestId, $stadiumId]);
                $guest = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$guest) {
                    $db->rollBack();
                    $this->sendError('Guest not found', [], 404);
                    return;
                }

                // For hostess, verify access to room
                if ($decoded->role === 'hostess') {
                    if (!$this->accessRepository->hostessCanAccessRoom($decoded->user_id, $guest['room_id'])) {
                        $db->rollBack();
                        $this->sendError('Access denied to this guest room', [], 403);
                        return;
                    }
                }

                // Check if checked in (now protected by lock)
                $lastAccess = $this->accessRepository->getLastAccess($guestId);
                
                if (!$lastAccess || $lastAccess['access_type'] !== 'entry') {
                    $db->rollBack();
                    $this->sendError('Guest is not checked in', [], 409); // 409 Conflict
                    return;
                }

                // Perform check-out
                $accessId = $this->accessRepository->createAccess([
                    'guest_id' => $guestId,
                    'hostess_id' => $decoded->user_id,
                    'stadium_id' => $guest['stadium_id'],
                    'room_id' => $guest['room_id'],
                    'event_id' => $guest['event_id'],
                    'access_type' => 'exit',
                    'device_type' => $this->detectDeviceType()
                ]);

                if (!$accessId) {
                    throw new Exception('Failed to create access record');
                }
                
                // 🔓 COMMIT - Everything OK
                $db->commit();

                $duration = $this->calculateDuration($lastAccess['access_time']);

                LogService::log(
                    'GUEST_CHECKOUT',
                    'Guest checked out successfully',
                    [
                        'guest_id' => $guestId,
                        'guest_name' => $guest['first_name'] . ' ' . $guest['last_name'],
                        'room_id' => $guest['room_id'],
                        'room_name' => $guest['room_name'],
                        'access_id' => $accessId,
                        'duration_minutes' => $duration
                    ],
                    $decoded->user_id,
                    $decoded->stadium_id,
                    'guest_accesses',
                    $accessId
                );

                $this->sendSuccess([
                    'message' => 'Check-out completed successfully',
                    'access_id' => $accessId,
                    'access_time' => date('c'),
                    'duration_minutes' => $duration,
                    'guest' => [
                        'id' => $guest['id'],
                        'name' => $guest['first_name'] . ' ' . $guest['last_name'],
                        'room' => $guest['room_name']
                    ]
                ]);

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Check-out failed', [
                'guest_id' => $guestId,
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Check-out failed', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/guests/{id}/access-history
     * Storico accessi ospite
     */
    public function getAccessHistory(int $guestId): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Get guest details
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            $guest = $this->guestRepository->findById($guestId, $stadiumId);

            if (!$guest) {
                $this->sendError('Guest not found', [], 404);
                return;
            }

            // For hostess, verify access to room
            if ($decoded->role === 'hostess') {
                if (!$this->accessRepository->hostessCanAccessRoom($decoded->user_id, $guest['room_id'])) {
                    $this->sendError('Access denied to this guest room', [], 403);
                    return;
                }
            }

            // Get access history
            $history = $this->accessRepository->getGuestAccessHistory($guestId);

            $this->sendSuccess([
                'guest' => [
                    'id' => $guest['id'],
                    'name' => $guest['first_name'] . ' ' . $guest['last_name']
                ],
                'access_history' => $history,
                'total_accesses' => count($history)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get access history', [
                'guest_id' => $guestId,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Failed to get access history', [], 500);
        }
    }

    /**
     * GET /api/rooms/{roomId}/current-guests
     * Ospiti attualmente in sala (checked in)
     */
    public function getCurrentGuestsInRoom(int $roomId): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // For hostess, verify access to room
            if ($decoded->role === 'hostess') {
                if (!$this->accessRepository->hostessCanAccessRoom($decoded->user_id, $roomId)) {
                    $this->sendError('Access denied to this room', [], 403);
                    return;
                }
            }

            $currentGuests = $this->accessRepository->getCurrentGuestsInRoom($roomId);

            $this->sendSuccess([
                'room_id' => $roomId,
                'current_guests' => $currentGuests,
                'total_present' => count($currentGuests)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get current guests', [
                'room_id' => $roomId,
                'error' => $e->getMessage()
            ]);

            $this->sendError('Failed to get current guests', [], 500);
        }
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

    private function detectDeviceType(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (stripos($userAgent, 'mobile') !== false || 
            stripos($userAgent, 'android') !== false ||
            stripos($userAgent, 'iphone') !== false) {
            return 'mobile';
        }
        
        if (stripos($userAgent, 'hospitality-pwa') !== false) {
            return 'pwa';
        }
        
        return 'web';
    }

    private function calculateDuration(string $startTime): int {
        $start = strtotime($startTime);
        $end = time();
        return (int)round(($end - $start) / 60); // Minutes
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
}