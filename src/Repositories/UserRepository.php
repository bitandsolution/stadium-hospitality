<?php
/******************************************************************
*                                                                 *
*   FILE: src/Repositories/UserRepository.php - User Data Access  *
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

namespace Hospitality\Repositories;

use Hospitality\Config\Database;
use PDO;
use Exception;

class UserRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Trova utente per username con controllo multi-tenant
     */
    public function findByUsername(string $username, ?int $stadiumId = null): ?array {
        $sql = "
            SELECT u.*, s.name as stadium_name, s.primary_color, s.secondary_color
            FROM users u 
            LEFT JOIN stadiums s ON u.stadium_id = s.id
            WHERE u.username = ? AND u.is_active = 1
        ";
        
        $params = [$username];

        // Per non-super admin, filtra per stadium
        if ($stadiumId !== null) {
            $sql .= " AND (u.stadium_id = ? OR u.role = 'super_admin')";
            $params[] = $stadiumId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    /**
     * Trova utente per ID
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT u.*, s.name as stadium_name, s.primary_color, s.secondary_color
            FROM users u 
            LEFT JOIN stadiums s ON u.stadium_id = s.id
            WHERE u.id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $user ?: null;
    }

    /**
     * Aggiorna ultimo login
     */
    public function updateLastLogin(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET last_login = NOW(), last_activity = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$userId]);
    }

    /**
     * Lista utenti per stadio con filtro ruolo
     */
    public function findByStadium(int $stadiumId, ?string $role = null): array {
        $sql = "
            SELECT u.id, u.username, u.email, u.full_name, u.role, u.phone, 
                   u.language, u.is_active, u.created_at, u.last_login,
                   s.name as stadium_name
            FROM users u 
            LEFT JOIN stadiums s ON u.stadium_id = s.id
            WHERE u.stadium_id = ? AND u.is_active = 1
        ";
        
        $params = [$stadiumId];

        if ($role) {
            $sql .= " AND u.role = ?";
            $params[] = $role;
        }

        $sql .= " ORDER BY u.role DESC, u.full_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea nuovo utente
     */
    public function create(array $userData): int {
        $stmt = $this->db->prepare("
            INSERT INTO users (
                stadium_id, username, email, password_hash, role, 
                full_name, phone, language, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $userData['stadium_id'],
            $userData['username'],
            $userData['email'],
            password_hash($userData['password'], PASSWORD_DEFAULT, ['cost' => 12]),
            $userData['role'],
            $userData['full_name'],
            $userData['phone'] ?? null,
            $userData['language'] ?? 'it',
            $userData['created_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Aggiorna utente
     */
    public function update(int $id, array $userData): bool {
        $fields = [];
        $params = [];

        // Campi aggiornabili
        $allowedFields = ['username', 'email', 'full_name', 'phone', 'language', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($userData[$field])) {
                $fields[] = "{$field} = ?";
                $params[] = $userData[$field];
            }
        }

        // Password separata per sicurezza
        if (isset($userData['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($userData['password'], PASSWORD_DEFAULT, ['cost' => 12]);
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Disattiva utente (soft delete)
     */
    public function deactivate(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET is_active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$id]);
    }

    /**
     * Verifica se username esiste
     */
    public function usernameExists(string $username, int $stadiumId, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM users WHERE username = ? AND stadium_id = ?";
        $params = [$username, $stadiumId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica se email esiste
     */
    public function emailExists(string $email, int $stadiumId, ?int $excludeId = null): bool {
        $sql = "SELECT id FROM users WHERE email = ? AND stadium_id = ?";
        $params = [$email, $stadiumId];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get hostess assegnate a specifiche sale
     */
    public function getHostessWithRooms(int $stadiumId): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.full_name, u.email, u.phone,
                   GROUP_CONCAT(hr.name ORDER BY hr.name SEPARATOR ', ') as assigned_rooms,
                   COUNT(ura.room_id) as room_count
            FROM users u
            LEFT JOIN user_room_assignments ura ON u.id = ura.user_id AND ura.is_active = 1
            LEFT JOIN hospitality_rooms hr ON ura.room_id = hr.id
            WHERE u.stadium_id = ? AND u.role = 'hostess' AND u.is_active = 1
            GROUP BY u.id, u.username, u.full_name, u.email, u.phone
            ORDER BY u.full_name
        ");

        $stmt->execute([$stadiumId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ottieni statistiche utenti per stadio
     */
    public function getStadiumUserStats(int $stadiumId): array {
        $stmt = $this->db->prepare("
            SELECT 
                role,
                COUNT(*) as count,
                COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as active_last_30_days
            FROM users 
            WHERE stadium_id = ? AND is_active = 1
            GROUP BY role
        ");

        $stmt->execute([$stadiumId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatta risultati
        $stats = [
            'stadium_admin' => ['count' => 0, 'active_last_30_days' => 0],
            'hostess' => ['count' => 0, 'active_last_30_days' => 0],
            'total' => ['count' => 0, 'active_last_30_days' => 0]
        ];

        foreach ($results as $result) {
            $stats[$result['role']] = [
                'count' => (int)$result['count'],
                'active_last_30_days' => (int)$result['active_last_30_days']
            ];
            $stats['total']['count'] += (int)$result['count'];
            $stats['total']['active_last_30_days'] += (int)$result['active_last_30_days'];
        }

        return $stats;
    }
    /**
     * Find users by stadium with room assignment count
     */
    public function findByStadiumWithRoomCount(int $stadiumId, ?string $role = null): array {
        $sql = "
            SELECT u.id, u.username, u.email, u.full_name, u.role, u.phone, 
                u.language, u.is_active, u.created_at, u.last_login,
                s.name as stadium_name,
                COUNT(DISTINCT ura.room_id) as assigned_rooms_count,
                GROUP_CONCAT(DISTINCT hr.name ORDER BY hr.name SEPARATOR ', ') as assigned_rooms_names
            FROM users u 
            LEFT JOIN stadiums s ON u.stadium_id = s.id
            LEFT JOIN user_room_assignments ura ON u.id = ura.user_id AND ura.is_active = 1
            LEFT JOIN hospitality_rooms hr ON ura.room_id = hr.id AND hr.is_active = 1
            WHERE u.stadium_id = ? AND u.is_active = 1
        ";
        
        $params = [$stadiumId];

        if ($role) {
            $sql .= " AND u.role = ?";
            $params[] = $role;
        }

        $sql .= " GROUP BY u.id, u.username, u.email, u.full_name, u.role, u.phone, 
                u.language, u.is_active, u.created_at, u.last_login, s.name
                ORDER BY u.role DESC, u.full_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find user by email
     * 
     * @param string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            WHERE email = ? 
            LIMIT 1
        ");
        
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    /**
     * Get assigned rooms for user
     */
    public function getAssignedRooms(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT 
                hr.id,
                hr.name,
                hr.capacity,
                hr.floor_level,
                ura.assigned_at,
                assignedBy.full_name as assigned_by_name
            FROM user_room_assignments ura
            JOIN hospitality_rooms hr ON ura.room_id = hr.id
            LEFT JOIN users assignedBy ON ura.assigned_by = assignedBy.id
            WHERE ura.user_id = ? AND ura.is_active = 1
            ORDER BY hr.name
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Clear all room assignments for user
     */
    public function clearRoomAssignments(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE user_room_assignments 
            SET is_active = 0 
            WHERE user_id = ?
        ");
        
        return $stmt->execute([$userId]);
    }

    /**
     * Assign room to user
     */
    public function assignRoom(int $userId, int $roomId, ?int $assignedBy = null): bool {
        // Check if assignment already exists
        $stmt = $this->db->prepare("
            SELECT id FROM user_room_assignments 
            WHERE user_id = ? AND room_id = ?
        ");
        $stmt->execute([$userId, $roomId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Reactivate existing assignment
            $stmt = $this->db->prepare("
                UPDATE user_room_assignments 
                SET is_active = 1, assigned_by = ?, assigned_at = NOW()
                WHERE user_id = ? AND room_id = ?
            ");
            return $stmt->execute([$assignedBy, $userId, $roomId]);
        } else {
            // Create new assignment
            $stmt = $this->db->prepare("
                INSERT INTO user_room_assignments 
                (user_id, room_id, assigned_by, assigned_at, is_active)
                VALUES (?, ?, ?, NOW(), 1)
            ");
            return $stmt->execute([$userId, $roomId, $assignedBy]);
        }
    }

    /**
     * Remove room assignment
     */
    public function removeRoomAssignment(int $userId, int $roomId): bool {
        $stmt = $this->db->prepare("
            UPDATE user_room_assignments 
            SET is_active = 0 
            WHERE user_id = ? AND room_id = ?
        ");
        
        return $stmt->execute([$userId, $roomId]);
    }
}