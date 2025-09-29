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

    public function create(array $roomData): int {
        $stmt = $this->db->prepare("
            INSERT INTO hospitality_rooms (
                stadium_id, name, description, capacity,
                floor_level, location_code, room_type,
                amenities, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");

        $stmt->execute([
            $roomData['stadium_id'],
            $roomData['name'],
            $roomData['description'] ?? null,
            $roomData['capacity'] ?? null,
            $roomData['floor_level'] ?? null,
            $roomData['location_code'] ?? null,
            $roomData['room_type'] ?? 'standard',
            isset($roomData['amenities']) ? json_encode($roomData['amenities']) : null
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function findByStadium(int $stadiumId, bool $activeOnly = true): array {
        $sql = "
            SELECT 
                hr.*,
                s.name as stadium_name,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT ura.user_id) as assigned_hostess
            FROM hospitality_rooms hr
            JOIN stadiums s ON hr.stadium_id = s.id
            LEFT JOIN guests g ON hr.id = g.room_id AND g.is_active = 1
            LEFT JOIN user_room_assignments ura ON hr.id = ura.room_id AND ura.is_active = 1
            WHERE hr.stadium_id = ?
        ";

        if ($activeOnly) {
            $sql .= " AND hr.is_active = 1";
        }

        $sql .= " GROUP BY hr.id ORDER BY hr.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$stadiumId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                hr.*,
                s.name as stadium_name,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT ura.user_id) as assigned_hostess
            FROM hospitality_rooms hr
            JOIN stadiums s ON hr.stadium_id = s.id
            LEFT JOIN guests g ON hr.id = g.room_id AND g.is_active = 1
            LEFT JOIN user_room_assignments ura ON hr.id = ura.room_id AND ura.is_active = 1
            WHERE hr.id = ?
            GROUP BY hr.id
        ");

        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'description', 'capacity', 'floor_level',
            'location_code', 'room_type', 'amenities', 'is_active'
        ];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'amenities') {
                    $fields[] = "{$field} = ?";
                    $params[] = json_encode($data[$field]);
                } else {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
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

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE hospitality_rooms 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$id]);
    }

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

    public function getAssignedHostess(int $roomId): array {
        $stmt = $this->db->prepare("
            SELECT 
                u.id, u.username, u.full_name, u.email,
                ura.assigned_at, ura.assigned_by
            FROM user_room_assignments ura
            JOIN users u ON ura.user_id = u.id
            WHERE ura.room_id = ? AND ura.is_active = 1
            ORDER BY u.full_name
        ");

        $stmt->execute([$roomId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStatistics(int $roomId): array {
        $stmt = $this->db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM guests WHERE room_id = ? AND is_active = 1) as total_guests,
                (SELECT COUNT(*) FROM guests g 
                 JOIN guest_accesses ga ON g.id = ga.guest_id 
                 WHERE g.room_id = ? AND ga.access_type = 'entry' 
                 AND ga.id = (SELECT MAX(id) FROM guest_accesses WHERE guest_id = g.id AND access_type = 'entry')
                ) as checked_in_count,
                (SELECT COUNT(*) FROM user_room_assignments WHERE room_id = ? AND is_active = 1) as assigned_hostess_count
        ");

        $stmt->execute([$roomId, $roomId, $roomId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}