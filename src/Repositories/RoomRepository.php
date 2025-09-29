<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/RoomRepository.php            *
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

class RoomRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create new room
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO hospitality_rooms (
                stadium_id, name, description, capacity, floor_level,
                location_code, room_type, amenities, is_active,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $data['stadium_id'],
            $data['name'],
            $data['description'] ?? null,
            $data['capacity'] ?? 500,
            $data['floor_level'] ?? null,
            $data['location_code'] ?? null,
            $data['room_type'] ?? 'standard',
            $data['amenities'] ?? null,
            $data['is_active'] ?? 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Find room by ID
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                hr.*,
                s.name as stadium_name
            FROM hospitality_rooms hr
            JOIN stadiums s ON hr.stadium_id = s.id
            WHERE hr.id = ?
        ");
        
        $stmt->execute([$id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $room ?: null;
    }

    /**
     * Find rooms by stadium
     */
    public function findByStadium(int $stadiumId, bool $activeOnly = true): array {
        $sql = "
            SELECT hr.*
            FROM hospitality_rooms hr
            WHERE hr.stadium_id = ?
        ";
        
        $params = [$stadiumId];
        
        if ($activeOnly) {
            $sql .= " AND hr.is_active = 1";
        }
        
        $sql .= " ORDER BY hr.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find rooms by stadium with basic statistics
     */
    public function findByStadiumWithStats(int $stadiumId, bool $activeOnly = true): array {
        $sql = "
            SELECT 
                hr.*,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT ura.user_id) as assigned_hostess
            FROM hospitality_rooms hr
            LEFT JOIN guests g ON g.room_id = hr.id AND g.is_active = 1
            LEFT JOIN user_room_assignments ura ON ura.room_id = hr.id AND ura.is_active = 1
            WHERE hr.stadium_id = ?
        ";
        
        $params = [$stadiumId];
        
        if ($activeOnly) {
            $sql .= " AND hr.is_active = 1";
        }
        
        $sql .= " GROUP BY hr.id ORDER BY hr.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add stats object to each room
        foreach ($rooms as &$room) {
            $room['stats'] = [
                'total_guests' => (int)$room['total_guests'],
                'assigned_hostess' => (int)$room['assigned_hostess']
            ];
            
            // Remove redundant fields
            unset($room['total_guests']);
            unset($room['assigned_hostess']);
        }
        
        return $rooms;
    }

    /**
     * Update room
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'description', 'capacity', 'floor_level',
            'location_code', 'room_type', 'amenities', 'is_active'
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

        $sql = "UPDATE hospitality_rooms SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete room
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE hospitality_rooms 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$id]);
    }

    /**
     * Get detailed room statistics
     */
    public function getRoomStats(int $roomId): array {
        $stmt = $this->db->prepare("
            SELECT 
                -- Guest statistics
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'ultra_vip' THEN g.id END) as ultra_vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'vip' THEN g.id END) as vip_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'premium' THEN g.id END) as premium_count,
                COUNT(DISTINCT CASE WHEN g.vip_level = 'standard' THEN g.id END) as standard_count,
                
                -- Access statistics
                COUNT(DISTINCT ga.id) as total_accesses,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'entry' THEN ga.id END) as total_entries,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'exit' THEN ga.id END) as total_exits,
                
                -- Event statistics
                COUNT(DISTINCT g.event_id) as events_count,
                
                -- Hostess statistics
                COUNT(DISTINCT ura.user_id) as assigned_hostess
                
            FROM hospitality_rooms hr
            LEFT JOIN guests g ON g.room_id = hr.id AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON ga.guest_id = g.id
            LEFT JOIN user_room_assignments ura ON ura.room_id = hr.id AND ura.is_active = 1
            WHERE hr.id = ?
            GROUP BY hr.id
        ");
        
        $stmt->execute([$roomId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // Room exists but has no data yet
            return [
                'total_guests' => 0,
                'ultra_vip_count' => 0,
                'vip_count' => 0,
                'premium_count' => 0,
                'standard_count' => 0,
                'total_accesses' => 0,
                'total_entries' => 0,
                'total_exits' => 0,
                'events_count' => 0,
                'assigned_hostess' => 0
            ];
        }
        
        // Convert string numbers to integers
        return [
            'total_guests' => (int)$result['total_guests'],
            'ultra_vip_count' => (int)$result['ultra_vip_count'],
            'vip_count' => (int)$result['vip_count'],
            'premium_count' => (int)$result['premium_count'],
            'standard_count' => (int)$result['standard_count'],
            'total_accesses' => (int)$result['total_accesses'],
            'total_entries' => (int)$result['total_entries'],
            'total_exits' => (int)$result['total_exits'],
            'events_count' => (int)$result['events_count'],
            'assigned_hostess' => (int)$result['assigned_hostess']
        ];
    }

    /**
     * Get list of hostess assigned to room
     */
    public function getAssignedHostess(int $roomId): array {
        $stmt = $this->db->prepare("
            SELECT 
                u.id,
                u.username,
                u.full_name,
                u.email,
                u.phone,
                ura.assigned_at,
                ura.assigned_by,
                assignedBy.full_name as assigned_by_name
            FROM user_room_assignments ura
            JOIN users u ON ura.user_id = u.id
            LEFT JOIN users assignedBy ON ura.assigned_by = assignedBy.id
            WHERE ura.room_id = ? 
                AND ura.is_active = 1
                AND u.is_active = 1
                AND u.role = 'hostess'
            ORDER BY u.full_name
        ");
        
        $stmt->execute([$roomId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if room name exists in stadium
     */
    public function nameExistsInStadium(string $name, int $stadiumId, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM hospitality_rooms WHERE name = ? AND stadium_id = ?";
        $params = [$name, $stadiumId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get room count by stadium
     */
    public function countByStadium(int $stadiumId, bool $activeOnly = true): int {
        $sql = "SELECT COUNT(*) FROM hospitality_rooms WHERE stadium_id = ?";
        $params = [$stadiumId];

        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
}