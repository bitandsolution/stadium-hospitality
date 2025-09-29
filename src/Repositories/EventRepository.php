<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/EventRepository.php           *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Repositories;

use Hospitality\Config\Database;
use PDO;
use Exception;

class EventRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create new event
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO events (
                stadium_id, name, event_date, event_time, opponent_team,
                competition, season, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $data['stadium_id'],
            $data['name'],
            $data['event_date'],
            $data['event_time'] ?? null,
            $data['opponent_team'] ?? null,
            $data['competition'] ?? null,
            $data['season'] ?? null,
            $data['is_active'] ?? 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find event by ID
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                e.*,
                s.name as stadium_name
            FROM events e
            JOIN stadiums s ON e.stadium_id = s.id
            WHERE e.id = ?
        ");
        
        $stmt->execute([$id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $event ?: null;
    }

    /**
     * Find events by stadium
     */
    public function findByStadium(int $stadiumId, bool $activeOnly = true): array {
        $sql = "
            SELECT e.*
            FROM events e
            WHERE e.stadium_id = ?
        ";
        
        $params = [$stadiumId];
        
        if ($activeOnly) {
            $sql .= " AND e.is_active = 1";
        }
        
        $sql .= " ORDER BY e.event_date DESC, e.event_time DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find events by stadium with statistics
     */
    public function findByStadiumWithStats(int $stadiumId, bool $activeOnly = true): array {
        $sql = "
            SELECT 
                e.*,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT g.room_id) as active_rooms
            FROM events e
            LEFT JOIN guests g ON g.event_id = e.id AND g.is_active = 1
            WHERE e.stadium_id = ?
        ";
        
        $params = [$stadiumId];
        
        if ($activeOnly) {
            $sql .= " AND e.is_active = 1";
        }
        
        $sql .= " GROUP BY e.id ORDER BY e.event_date DESC, e.event_time DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add stats object to each event
        foreach ($events as &$event) {
            $event['stats'] = [
                'total_guests' => (int)$event['total_guests'],
                'active_rooms' => (int)$event['active_rooms']
            ];
            
            // Remove redundant fields
            unset($event['total_guests']);
            unset($event['active_rooms']);
        }
        
        return $events;
    }

    /**
     * Get upcoming events
     */
    public function findUpcoming(int $stadiumId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT 
                e.*,
                COUNT(DISTINCT g.id) as total_guests
            FROM events e
            LEFT JOIN guests g ON g.event_id = e.id AND g.is_active = 1
            WHERE e.stadium_id = ? 
                AND e.is_active = 1
                AND e.event_date >= CURDATE()
            GROUP BY e.id
            ORDER BY e.event_date ASC, e.event_time ASC
            LIMIT ?
        ");
        
        $stmt->execute([$stadiumId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get past events
     */
    public function findPast(int $stadiumId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT 
                e.*,
                COUNT(DISTINCT g.id) as total_guests
            FROM events e
            LEFT JOIN guests g ON g.event_id = e.id AND g.is_active = 1
            WHERE e.stadium_id = ? 
                AND e.is_active = 1
                AND e.event_date < CURDATE()
            GROUP BY e.id
            ORDER BY e.event_date DESC, e.event_time DESC
            LIMIT ?
        ");
        
        $stmt->execute([$stadiumId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update event
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'event_date', 'event_time', 'opponent_team',
            'competition', 'season', 'is_active'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE events SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete event
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE events 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$id]);
    }

    /**
     * Get detailed event statistics
     */
    public function getEventStats(int $eventId): array {
        $stmt = $this->db->prepare("
            SELECT 
                -- Guest statistics
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'ultra_vip' THEN g.id END) as ultra_vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'vip' THEN g.id END) as vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'premium' THEN g.id END) as premium_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'standard' THEN g.id END) as standard_count,
                
                -- Room statistics
                COUNT(DISTINCT g.room_id) as active_rooms,
                
                -- Access statistics
                COUNT(DISTINCT ga.id) as total_accesses,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'entry' THEN ga.id END) as total_entries,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'exit' THEN ga.id END) as total_exits,
                
                -- Check-in status
                COUNT(DISTINCT CASE WHEN ga.access_type = 'entry' THEN g.id END) as checked_in_guests,
                COUNT(DISTINCT CASE WHEN ga.id IS NULL THEN g.id END) as not_checked_in_guests
                
            FROM events e
            LEFT JOIN guests g ON g.event_id = e.id AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON ga.guest_id = g.id
            WHERE e.id = ?
            GROUP BY e.id
        ");
        
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Event exists but has no data yet
            return [
                'total_guests' => 0,
                'ultra_vip_count' => 0,
                'vip_count' => 0,
                'premium_count' => 0,
                'standard_count' => 0,
                'active_rooms' => 0,
                'total_accesses' => 0,
                'total_entries' => 0,
                'total_exits' => 0,
                'checked_in_guests' => 0,
                'not_checked_in_guests' => 0
            ];
        }
        
        // Convert string numbers to integers
        return [
            'total_guests' => (int)$result['total_guests'],
            'ultra_vip_count' => (int)$result['ultra_vip_count'],
            'vip_count' => (int)$result['vip_count'],
            'premium_count' => (int)$result['premium_count'],
            'standard_count' => (int)$result['standard_count'],
            'active_rooms' => (int)$result['active_rooms'],
            'total_accesses' => (int)$result['total_accesses'],
            'total_entries' => (int)$result['total_entries'],
            'total_exits' => (int)$result['total_exits'],
            'checked_in_guests' => (int)$result['checked_in_guests'],
            'not_checked_in_guests' => (int)$result['not_checked_in_guests']
        ];
    }

    /**
     * Get rooms with guest counts for event
     */
    public function getEventRooms(int $eventId): array {
        $stmt = $this->db->prepare("
            SELECT 
                hr.id,
                hr.name,
                hr.capacity,
                COUNT(g.id) as guest_count,
                COUNT(CASE WHEN ga.access_type = 'entry' THEN g.id END) as checked_in_count
            FROM hospitality_rooms hr
            LEFT JOIN guests g ON g.room_id = hr.id AND g.event_id = ? AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON ga.guest_id = g.id AND ga.access_type = 'entry'
            WHERE hr.id IN (
                SELECT DISTINCT room_id FROM guests WHERE event_id = ? AND is_active = 1
            )
            GROUP BY hr.id, hr.name, hr.capacity
            ORDER BY hr.name
        ");
        
        $stmt->execute([$eventId, $eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if event name exists in stadium for date
     */
    public function nameExistsForDate(string $name, string $eventDate, int $stadiumId, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM events WHERE name = ? AND event_date = ? AND stadium_id = ?";
        $params = [$name, $eventDate, $stadiumId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get event count by stadium
     */
    public function countByStadium(int $stadiumId, bool $activeOnly = true): int {
        $sql = "SELECT COUNT(*) FROM events WHERE stadium_id = ?";
        $params = [$stadiumId];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
}