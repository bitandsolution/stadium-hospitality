<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/RoomAssignmentController.php   *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Config\Database;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Services\LogService;
use Hospitality\Utils\Logger;
use Exception;
use PDO;

class RoomAssignmentController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * POST /api/admin/users/{id}/rooms
     * Assign rooms to hostess
     */
    public function assignRooms(int $userId): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            if (empty($input['room_ids']) || !is_array($input['room_ids'])) {
                $this->sendError('room_ids array is required', [], 422);
                return;
            }

            // Get user to validate
            $stmt = $this->db->prepare("SELECT id, role, stadium_id, full_name FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if ($user['role'] !== 'hostess') {
                $this->sendError('Can only assign rooms to hostess users', [], 400);
                return;
            }

            // Validate stadium access
            if (!TenantMiddleware::validateStadiumAccess($user['stadium_id'])) return;

            // Validate all rooms belong to same stadium
            $placeholders = str_repeat('?,', count($input['room_ids']) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT id, stadium_id, name 
                FROM hospitality_rooms 
                WHERE id IN ($placeholders) AND is_active = 1
            ");
            $stmt->execute($input['room_ids']);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rooms) !== count($input['room_ids'])) {
                $this->sendError('Some rooms not found or inactive', [], 404);
                return;
            }

            foreach ($rooms as $room) {
                if ($room['stadium_id'] !== $user['stadium_id']) {
                    $this->sendError('All rooms must belong to user\'s stadium', [], 400);
                    return;
                }
            }

            $this->db->beginTransaction();

            try {
                // Deactivate existing assignments
                $stmt = $this->db->prepare("
                    UPDATE user_room_assignments 
                    SET is_active = 0 
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);

                // Create new assignments
                $stmt = $this->db->prepare("
                    INSERT INTO user_room_assignments (user_id, room_id, assigned_by, assigned_at, is_active)
                    VALUES (?, ?, ?, NOW(), 1)
                    ON DUPLICATE KEY UPDATE 
                        assigned_by = VALUES(assigned_by),
                        assigned_at = NOW(),
                        is_active = 1
                ");

                $currentUserId = $GLOBALS['current_user']['id'] ?? 1;

                foreach ($input['room_ids'] as $roomId) {
                    $stmt->execute([$userId, $roomId, $currentUserId]);
                }

                $this->db->commit();

                LogService::log(
                    'ROOM_ASSIGNMENT',
                    'Rooms assigned to hostess',
                    [
                        'hostess_id' => $userId,
                        'hostess_name' => $user['full_name'],
                        'room_count' => count($input['room_ids']),
                        'room_ids' => $input['room_ids']
                    ],
                    $currentUserId,
                    $user['stadium_id']
                );

                $this->sendSuccess([
                    'message' => 'Rooms assigned successfully',
                    'user_id' => $userId,
                    'assigned_rooms' => count($input['room_ids'])
                ]);

            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Room assignment failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Room assignment failed', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/users/{id}/rooms
     * Get assigned rooms for hostess
     */
    public function getAssignedRooms(int $userId): void {
        try {
            if (!AuthMiddleware::handle()) return;

            // Get user
            $stmt = $this->db->prepare("SELECT id, role, stadium_id, full_name FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            // Validate access
            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions', [], 403);
                return;
            }

            // Get assigned rooms
            $stmt = $this->db->prepare("
                SELECT 
                    hr.id,
                    hr.name,
                    hr.capacity,
                    hr.description,
                    hr.room_type,
                    ura.assigned_at,
                    u.full_name as assigned_by_name,
                    COUNT(DISTINCT g.id) as total_guests
                FROM user_room_assignments ura
                JOIN hospitality_rooms hr ON ura.room_id = hr.id
                LEFT JOIN users u ON ura.assigned_by = u.id
                LEFT JOIN guests g ON hr.id = g.room_id AND g.is_active = 1
                WHERE ura.user_id = ? AND ura.is_active = 1
                GROUP BY hr.id
                ORDER BY hr.name
            ");
            $stmt->execute([$userId]);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->sendSuccess([
                'user' => [
                    'id' => $user['id'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ],
                'assigned_rooms' => $rooms,
                'total_assigned' => count($rooms)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get assigned rooms', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve assigned rooms', [], 500);
        }
    }

    /**
     * DELETE /api/admin/users/{id}/rooms/{roomId}
     * Remove single room assignment
     */
    public function removeRoomAssignment(int $userId, int $roomId): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            // Get user
            $stmt = $this->db->prepare("SELECT id, stadium_id, full_name FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($user['stadium_id'])) return;

            // Deactivate assignment
            $stmt = $this->db->prepare("
                UPDATE user_room_assignments 
                SET is_active = 0 
                WHERE user_id = ? AND room_id = ?
            ");
            $stmt->execute([$userId, $roomId]);

            if ($stmt->rowCount() > 0) {
                LogService::log(
                    'ROOM_ASSIGNMENT_REMOVE',
                    'Room assignment removed',
                    [
                        'hostess_id' => $userId,
                        'room_id' => $roomId
                    ],
                    $GLOBALS['current_user']['id'] ?? 1,
                    $user['stadium_id']
                );

                $this->sendSuccess([
                    'message' => 'Room assignment removed successfully',
                    'user_id' => $userId,
                    'room_id' => $roomId
                ]);
            } else {
                $this->sendError('Assignment not found or already removed', [], 404);
            }

        } catch (Exception $e) {
            Logger::error('Failed to remove room assignment', [
                'user_id' => $userId,
                'room_id' => $roomId,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to remove room assignment', [], 500);
        }
    }

    private function getJsonInput(): array {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Invalid JSON input', ['json_error' => json_last_error_msg()], 400);
            exit;
        }

        return $data ?? [];
    }

    private function sendSuccess(array $data, int $code = 200): void {
        http_response_code($code);
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