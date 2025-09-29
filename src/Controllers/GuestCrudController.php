<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/GuestCrudController.php        *
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

use Hospitality\Config\Database;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Services\LogService;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;
use PDO;

class GuestCrudController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * POST /api/admin/guests
     * Create single guest manually
     */
    public function create(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            // Validate required fields
            $errors = Validator::validateRequired($input, [
                'event_id', 'room_id', 'first_name', 'last_name', 'table_number'
            ]);

            if (!empty($input['contact_email']) && !Validator::validateEmail($input['contact_email'])) {
                $errors[] = 'Invalid email format';
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            // Validate event exists and get stadium_id
            $stmt = $this->db->prepare("SELECT stadium_id FROM events WHERE id = ? AND is_active = 1");
            $stmt->execute([$input['event_id']]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                $this->sendError('Event not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            // Validate room exists in same stadium
            $stmt = $this->db->prepare("
                SELECT id FROM hospitality_rooms 
                WHERE id = ? AND stadium_id = ? AND is_active = 1
            ");
            $stmt->execute([$input['room_id'], $event['stadium_id']]);
            
            if (!$stmt->fetch()) {
                $this->sendError('Room not found or does not belong to event stadium', [], 404);
                return;
            }

            // Create guest
            $stmt = $this->db->prepare("
                INSERT INTO guests (
                    stadium_id, event_id, room_id, first_name, last_name,
                    company_name, table_number, seat_number, vip_level,
                    contact_email, contact_phone, notes, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");

            $stmt->execute([
                $event['stadium_id'],
                $input['event_id'],
                $input['room_id'],
                $input['first_name'],
                $input['last_name'],
                $input['company_name'] ?? null,
                $input['table_number'],
                $input['seat_number'] ?? null,
                $input['vip_level'] ?? 'standard',
                $input['contact_email'] ?? null,
                $input['contact_phone'] ?? null,
                $input['notes'] ?? null
            ]);

            $guestId = $this->db->lastInsertId();

            LogService::log(
                'GUEST_CREATE',
                'Guest created manually',
                [
                    'guest_id' => $guestId,
                    'name' => $input['first_name'] . ' ' . $input['last_name'],
                    'event_id' => $input['event_id']
                ],
                $decoded->user_id,
                $event['stadium_id'],
                'guests',
                $guestId
            );

            // Fetch created guest
            $stmt = $this->db->prepare("
                SELECT g.*, hr.name as room_name, e.name as event_name
                FROM guests g
                JOIN hospitality_rooms hr ON g.room_id = hr.id
                JOIN events e ON g.event_id = e.id
                WHERE g.id = ?
            ");
            $stmt->execute([$guestId]);
            $guest = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->sendSuccess([
                'message' => 'Guest created successfully',
                'guest' => $guest
            ], 201);

        } catch (Exception $e) {
            Logger::error('Guest creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);
            $this->sendError('Guest creation failed', $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/admin/guests/{id}
     * Update guest (admin full access)
     */
    public function update(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            if (empty($input)) {
                $this->sendError('No data provided for update', [], 422);
                return;
            }

            // Get guest and validate access
            $stmt = $this->db->prepare("
                SELECT g.*, e.stadium_id 
                FROM guests g
                JOIN events e ON g.event_id = e.id
                WHERE g.id = ? AND g.is_active = 1
            ");
            $stmt->execute([$id]);
            $guest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$guest) {
                $this->sendError('Guest not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($guest['stadium_id'])) return;

            // Validate email if provided
            if (isset($input['contact_email']) && !empty($input['contact_email'])) {
                if (!Validator::validateEmail($input['contact_email'])) {
                    $this->sendError('Invalid email format', [], 422);
                    return;
                }
            }

            // Build update query
            $fields = [];
            $params = [];

            $allowedFields = [
                'first_name', 'last_name', 'company_name', 'table_number',
                'seat_number', 'vip_level', 'contact_email', 'contact_phone', 'notes'
            ];

            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $fields[] = "{$field} = ?";
                    $params[] = $input[$field];
                }
            }

            if (empty($fields)) {
                $this->sendError('No valid fields to update', [], 400);
                return;
            }

            $fields[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE guests SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            LogService::log(
                'GUEST_UPDATE',
                'Guest updated',
                [
                    'guest_id' => $id,
                    'changes' => array_keys($input)
                ],
                $decoded->user_id,
                $guest['stadium_id'],
                'guests',
                $id
            );

            // Return updated guest
            $stmt = $this->db->prepare("
                SELECT g.*, hr.name as room_name, e.name as event_name
                FROM guests g
                JOIN hospitality_rooms hr ON g.room_id = hr.id
                JOIN events e ON g.event_id = e.id
                WHERE g.id = ?
            ");
            $stmt->execute([$id]);
            $updatedGuest = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->sendSuccess([
                'message' => 'Guest updated successfully',
                'guest' => $updatedGuest
            ]);

        } catch (Exception $e) {
            Logger::error('Guest update failed', [
                'guest_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Guest update failed', $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/admin/guests/{id}
     * Soft delete guest
     */
    public function delete(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            // Get guest and validate access
            $stmt = $this->db->prepare("
                SELECT g.*, e.stadium_id, g.first_name, g.last_name
                FROM guests g
                JOIN events e ON g.event_id = e.id
                WHERE g.id = ? AND g.is_active = 1
            ");
            $stmt->execute([$id]);
            $guest = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$guest) {
                $this->sendError('Guest not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($guest['stadium_id'])) return;

            // Soft delete
            $stmt = $this->db->prepare("
                UPDATE guests 
                SET is_active = 0, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            LogService::log(
                'GUEST_DELETE',
                'Guest deleted',
                [
                    'guest_id' => $id,
                    'name' => $guest['first_name'] . ' ' . $guest['last_name']
                ],
                $decoded->user_id,
                $guest['stadium_id'],
                'guests',
                $id
            );

            $this->sendSuccess([
                'message' => 'Guest deleted successfully',
                'guest_id' => $id
            ]);

        } catch (Exception $e) {
            Logger::error('Guest deletion failed', [
                'guest_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Guest deletion failed', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/admin/guests
     * List guests with filters
     */
    public function list(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $eventId = $_GET['event_id'] ?? null;
            $roomId = $_GET['room_id'] ?? null;
            $vipLevel = $_GET['vip_level'] ?? null;
            $limit = min((int)($_GET['limit'] ?? 100), 500);
            $offset = max((int)($_GET['offset'] ?? 0), 0);

            if (!$eventId) {
                $this->sendError('event_id parameter is required', [], 422);
                return;
            }

            // Validate event access
            $stmt = $this->db->prepare("SELECT stadium_id FROM events WHERE id = ? AND is_active = 1");
            $stmt->execute([$eventId]);
            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {
                $this->sendError('Event not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            // Build query
            $sql = "
                SELECT g.*, hr.name as room_name, e.name as event_name
                FROM guests g
                JOIN hospitality_rooms hr ON g.room_id = hr.id
                JOIN events e ON g.event_id = e.id
                WHERE g.event_id = ? AND g.is_active = 1
            ";
            $params = [$eventId];

            if ($roomId) {
                $sql .= " AND g.room_id = ?";
                $params[] = $roomId;
            }

            if ($vipLevel) {
                $sql .= " AND g.vip_level = ?";
                $params[] = $vipLevel;
            }

            $sql .= " ORDER BY g.last_name, g.first_name LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->sendSuccess([
                'guests' => $guests,
                'total' => count($guests),
                'event_id' => (int)$eventId,
                'filters' => array_filter([
                    'room_id' => $roomId,
                    'vip_level' => $vipLevel
                ])
            ]);

        } catch (Exception $e) {
            Logger::error('Guest list failed', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve guests', [], 500);
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