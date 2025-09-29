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

    public function create(array $eventData): int {
        $stmt = $this->db->prepare("
            INSERT INTO events (
                stadium_id, name, description, event_date, event_time,
                event_type, capacity, status, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'upcoming', 1, NOW())
        ");

        $stmt->execute([
            $eventData['stadium_id'],
            $eventData['name'],
            $eventData['description'] ?? null,
            $eventData['event_date'],
            $eventData['event_time'] ?? null,
            $eventData['event_type'] ?? 'match',
            $eventData['capacity'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function findByStadium(int $stadiumId, array $filters = []): array {
        $sql = "
            SELECT 
                e.*,
                s.name as stadium_name,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT ga.id) as checked_in_count
            FROM events e
            JOIN stadiums s ON e.stadium_id = s.id
            LEFT JOIN guests g ON e.id = g.event_id AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE e.stadium_id = ?
        ";

        $params = [$stadiumId];

        if (!empty($filters['active_only'])) {
            $sql .= " AND e.is_active = 1";
        }

        if (!empty($filters['status'])) {
            $sql .= " AND e.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND e.event_date >= ?";
            $params[] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND e.event_date <= ?";
            $params[] = $filters['to_date'];
        }

        $sql .= " GROUP BY e.id ORDER BY e.event_date DESC, e.event_time DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                e.*,
                s.name as stadium_name,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT ga.id) as checked_in_count,
                COUNT(DISTINCT hr.id) as total_rooms
            FROM events e
            JOIN stadiums s ON e.stadium_id = s.id
            LEFT JOIN guests g ON e.id = g.event_id AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
            LEFT JOIN hospitality_rooms hr ON e.stadium_id = hr.stadium_id AND hr.is_active = 1
            WHERE e.id = ?
            GROUP BY e.id
        ");

        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'description', 'event_date', 'event_time',
            'event_type', 'capacity', 'status', 'is_active'
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

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE events 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$id]);
    }

    public function nameExistsInStadium(string $name, int $stadiumId, string $eventDate, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM events WHERE name = ? AND stadium_id = ? AND event_date = ?";
        $params = [$name, $stadiumId, $eventDate];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function getEventStatistics(int $eventId): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'ultra_vip' THEN g.id END) as ultra_vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'vip' THEN g.id END) as vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'premium' THEN g.id END) as premium_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'standard' THEN g.id END) as standard_count,
                COUNT(DISTINCT ga.guest_id) as checked_in_count,
                COUNT(DISTINCT g.room_id) as rooms_in_use
            FROM guests g
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE g.event_id = ? AND g.is_active = 1
        ");

        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUpcomingEvents(int $stadiumId, int $limit = 5): array {
        $stmt = $this->db->prepare("
            SELECT 
                e.id, e.name, e.event_date, e.event_time,
                COUNT(DISTINCT g.id) as total_guests
            FROM events e
            LEFT JOIN guests g ON e.id = g.event_id AND g.is_active = 1
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
}