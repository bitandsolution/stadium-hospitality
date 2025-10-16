<?php
/******************************************************************
*                                                                 *
*   FILE: src/Controller/DashboardController.php                *
*                                                                 *
*   Author: Antonio Tartaglia - bitAND solution                   *
*   website: https://www.bitandsolution.it                        *
*   email:   info@bitandsolution.it                               *
*                                                                 *
*   Owner: bitAND solution                                        *
*                                                                 *
*   This is proprietary software                                  *
*   developed by bitAND solution for bitAND solution              *
*                                                                 *
******************************************************************/

namespace Hospitality\Controllers;

use Hospitality\Repositories\UserRepository;
use Hospitality\Repositories\GuestRepository;
use Hospitality\Repositories\EventRepository;
use Hospitality\Repositories\RoomRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Logger;
use Hospitality\Config\Database;
use Exception;

class DashboardController {
    
    public function __construct() {
        // Constructor
    }
    
    /**
     * GET /api/dashboard/stats
     * Dashboard statistics
     */
    public function stats(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;
            
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium ID required', [], 400);
                return;
            }
            
            $db = Database::getInstance()->getConnection();
            
            // 1. Total Guests
            $stmt = $db->prepare("
                SELECT COUNT(*) as total
                FROM guests
                WHERE stadium_id = ? AND is_active = 1
            ");
            $stmt->execute([$stadiumId]);
            $totalGuests = (int)$stmt->fetchColumn();
            
            // 2. Checked-in Guests (today or latest event)
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT ga.guest_id) as checked_in
                FROM guest_accesses ga
                JOIN guests g ON ga.guest_id = g.id
                WHERE g.stadium_id = ? 
                    AND ga.access_type = 'entry'
                    AND DATE(ga.access_time) = CURDATE()
            ");
            $stmt->execute([$stadiumId]);
            $checkedIn = (int)$stmt->fetchColumn();
            
            // 3. Pending Guests (not checked in today)
            $stmt = $db->prepare("
                SELECT COUNT(DISTINCT g.id) as pending
                FROM guests g
                JOIN events e ON g.event_id = e.id
                LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                    AND ga.access_type = 'entry'
                    AND DATE(ga.access_time) = CURDATE()
                WHERE g.stadium_id = ? 
                    AND g.is_active = 1
                    AND e.event_date = CURDATE()
                    AND e.is_active = 1
                    AND ga.id IS NULL
            ");
            $stmt->execute([$stadiumId]);
            $pending = (int)$stmt->fetchColumn();
            
            // 4. Active Events (today or future)
            $stmt = $db->prepare("
                SELECT COUNT(*) as active_events
                FROM events
                WHERE stadium_id = ? 
                    AND is_active = 1
                    AND event_date >= CURDATE()
            ");
            $stmt->execute([$stadiumId]);
            $activeEvents = (int)$stmt->fetchColumn();
            
            // 5. Total Rooms
            $stmt = $db->prepare("
                SELECT COUNT(*) as total_rooms
                FROM hospitality_rooms
                WHERE stadium_id = ? AND is_active = 1
            ");
            $stmt->execute([$stadiumId]);
            $totalRooms = (int)$stmt->fetchColumn();
            
            $this->sendSuccess([
                'total_guests' => $totalGuests,
                'checked_in' => $checkedIn,
                'pending' => $pending,
                'active_events' => $activeEvents,
                'total_rooms' => $totalRooms
            ]);
            
        } catch (Exception $e) {
            Logger::error('Dashboard stats failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);
            $this->sendError('Failed to load statistics', [], 500);
        }
    }
    
    /**
     * GET /api/dashboard/upcoming-events
     * Upcoming events with stats
     */
    public function upcomingEvents(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;
            
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium ID required', [], 400);
                return;
            }
            
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    e.id,
                    e.name,
                    e.event_date,
                    e.event_time,
                    e.opponent_team,
                    e.competition,
                    (SELECT COUNT(*) FROM guests g WHERE g.event_id = e.id AND g.is_active = 1) as total_guests,
                    (SELECT COUNT(DISTINCT g.room_id) FROM guests g WHERE g.event_id = e.id AND g.is_active = 1) as total_rooms, 
                    (SELECT COUNT(DISTINCT ga.guest_id) 
                    FROM guest_accesses ga 
                    JOIN guests g ON ga.guest_id = g.id 
                    WHERE g.event_id = e.id AND ga.access_type = 'entry') as total_checkins 
                FROM events e
                WHERE e.stadium_id = ? 
                    AND e.is_active = 1
                    AND e.event_date >= CURDATE()
                ORDER BY e.event_date ASC, e.event_time ASC
                LIMIT 5
            ");
            
            $stmt->execute([$stadiumId]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Format events
            foreach ($events as &$event) {
                $event['total_guests'] = (int)$event['total_guests'];
                $event['total_rooms'] = (int)$event['total_rooms'];
                $event['total_checkins'] = (int)$event['total_checkins'];
            }
            
            $this->sendSuccess([
                'events' => $events
            ]);
            
        } catch (Exception $e) {
            Logger::error('Dashboard upcoming events failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);
            $this->sendError('Failed to load upcoming events', [], 500);
        }
    }
    
    // Utility methods
    
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