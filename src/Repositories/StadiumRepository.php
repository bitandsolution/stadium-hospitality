<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/StadiumRepository.php         *
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

class StadiumRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create new stadium with initial admin user
     */
    public function create(array $stadiumData, array $adminData): array {
        try {
            $this->db->beginTransaction();

            // 1. Insert stadium
            $stmt = $this->db->prepare("
                INSERT INTO stadiums (
                    name, address, city, country, capacity,
                    primary_color, secondary_color, logo_url,
                    contact_email, contact_phone, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");

            $stmt->execute([
                $stadiumData['name'],
                $stadiumData['address'] ?? null,
                $stadiumData['city'] ?? null,
                $stadiumData['country'] ?? 'IT',
                $stadiumData['capacity'] ?? null,
                $stadiumData['primary_color'] ?? '#2563eb',
                $stadiumData['secondary_color'] ?? '#1e40af',
                $stadiumData['logo_url'] ?? null,
                $stadiumData['contact_email'] ?? null,
                $stadiumData['contact_phone'] ?? null
            ]);

            $stadiumId = $this->db->lastInsertId();

            // 2. Create stadium admin user
            $stmt = $this->db->prepare("
                INSERT INTO users (
                    stadium_id, username, email, password_hash, role,
                    full_name, phone, language, created_by, is_active, created_at
                ) VALUES (?, ?, ?, ?, 'stadium_admin', ?, ?, 'it', 1, 1, NOW())
            ");

            $stmt->execute([
                $stadiumId,
                $adminData['username'],
                $adminData['email'],
                password_hash($adminData['password'], PASSWORD_DEFAULT, ['cost' => 12]),
                $adminData['full_name'],
                $adminData['phone'] ?? null
            ]);

            $adminId = $this->db->lastInsertId();

            $this->db->commit();

            return [
                'stadium_id' => (int)$stadiumId,
                'stadium_name' => $stadiumData['name'],
                'admin_id' => (int)$adminId,
                'admin_username' => $adminData['username']
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Find all stadiums (super_admin only)
     */
    public function findAll(bool $activeOnly = true): array {
        $sql = "
            SELECT 
                s.*,
                COUNT(DISTINCT u.id) as total_users,
                COUNT(DISTINCT hr.id) as total_rooms,
                COUNT(DISTINCT e.id) as total_events
            FROM stadiums s
            LEFT JOIN users u ON s.id = u.stadium_id AND u.is_active = 1
            LEFT JOIN hospitality_rooms hr ON s.id = hr.stadium_id AND hr.is_active = 1
            LEFT JOIN events e ON s.id = e.stadium_id AND e.is_active = 1
        ";

        if ($activeOnly) {
            $sql .= " WHERE s.is_active = 1";
        }

        $sql .= " GROUP BY s.id ORDER BY s.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find stadium by ID
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                s.*,
                COUNT(DISTINCT u.id) as total_users,
                COUNT(DISTINCT hr.id) as total_rooms,
                COUNT(DISTINCT e.id) as total_events,
                COUNT(DISTINCT g.id) as total_guests
            FROM stadiums s
            LEFT JOIN users u ON s.id = u.stadium_id AND u.is_active = 1
            LEFT JOIN hospitality_rooms hr ON s.id = hr.stadium_id AND hr.is_active = 1
            LEFT JOIN events e ON s.id = e.stadium_id AND e.is_active = 1
            LEFT JOIN guests g ON s.id = g.stadium_id AND g.is_active = 1
            WHERE s.id = ?
            GROUP BY s.id
        ");

        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Update stadium details
     */
    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowedFields = [
            'name', 'address', 'city', 'country', 'capacity',
            'primary_color', 'secondary_color', 'logo_url',
            'contact_email', 'contact_phone', 'is_active'
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

        $sql = "UPDATE stadiums SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete stadium
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE stadiums 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");

        return $stmt->execute([$id]);
    }

    /**
     * Check if stadium name exists
     */
    public function nameExists(string $name, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM stadiums WHERE name = ?";
        $params = [$name];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get stadium statistics
     */
    public function getStatistics(int $stadiumId): array {
        $stmt = $this->db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM users WHERE stadium_id = ? AND is_active = 1) as total_users,
                (SELECT COUNT(*) FROM users WHERE stadium_id = ? AND role = 'hostess' AND is_active = 1) as total_hostess,
                (SELECT COUNT(*) FROM hospitality_rooms WHERE stadium_id = ? AND is_active = 1) as total_rooms,
                (SELECT COUNT(*) FROM events WHERE stadium_id = ? AND is_active = 1) as total_events,
                (SELECT COUNT(*) FROM guests WHERE stadium_id = ? AND is_active = 1) as total_guests,
                (SELECT COUNT(*) FROM guest_accesses WHERE stadium_id = ? AND access_type = 'entry') as total_checkins
        ");

        $stmt->execute([$stadiumId, $stadiumId, $stadiumId, $stadiumId, $stadiumId, $stadiumId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}