<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/RoomController.php             *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/


namespace Hospitality\Controllers;

use Hospitality\Services\RoomService;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Logger;
use Exception;

class RoomController {
    private RoomService $roomService;

    public function __construct() {
        $this->roomService = new RoomService();
    }

    public function create(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            if ($decoded->role !== 'super_admin') {
                $input['stadium_id'] = $decoded->stadium_id;
            } else {
                if (empty($input['stadium_id'])) {
                    $this->sendError('stadium_id is required for super_admin', [], 422);
                    return;
                }
            }

            if (!TenantMiddleware::validateStadiumAccess($input['stadium_id'])) return;

            $room = $this->roomService->createRoom($input);

            $this->sendSuccess([
                'message' => 'Room created successfully',
                'room' => $room
            ], 201);

        } catch (Exception $e) {
            Logger::error('Room creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);

            $this->sendError('Room creation failed', $e->getMessage(), 400);
        }
    }

    public function index(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = $_GET['stadium_id'] ?? null;
            
            if ($decoded->role !== 'super_admin') {
                $stadiumId = $decoded->stadium_id;
            } else {
                if (!$stadiumId) {
                    $this->sendError('stadium_id parameter required for super_admin', [], 422);
                    return;
                }
            }

            if (!TenantMiddleware::validateStadiumAccess((int)$stadiumId)) return;

            $includeInactive = isset($_GET['include_inactive']);
            $rooms = $this->roomService->getRoomsByStadium((int)$stadiumId, !$includeInactive);

            $this->sendSuccess([
                'rooms' => $rooms,
                'total' => count($rooms),
                'stadium_id' => (int)$stadiumId
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list rooms', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve rooms', [], 500);
        }
    }

    public function show(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $result = $this->roomService->getRoomWithStats($id);

            if (!TenantMiddleware::validateStadiumAccess($result['room']['stadium_id'])) return;

            $this->sendSuccess($result);

        } catch (Exception $e) {
            if ($e->getMessage() === 'Room not found') {
                $this->sendError('Room not found', [], 404);
            } else {
                Logger::error('Failed to get room details', [
                    'room_id' => $id,
                    'error' => $e->getMessage()
                ]);
                $this->sendError('Failed to retrieve room details', [], 500);
            }
        }
    }

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

            $room = $this->roomService->getRoomById($id);
            if (!TenantMiddleware::validateStadiumAccess($room['stadium_id'])) return;

            $updated = $this->roomService->updateRoom($id, $input);

            if ($updated) {
                $room = $this->roomService->getRoomById($id);
                $this->sendSuccess([
                    'message' => 'Room updated successfully',
                    'room' => $room
                ]);
            } else {
                $this->sendError('No changes were made', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Room update failed', [
                'room_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Room not found') {
                $this->sendError('Room not found', [], 404);
            } else {
                $this->sendError('Room update failed', $e->getMessage(), 400);
            }
        }
    }

    public function delete(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $room = $this->roomService->getRoomById($id);
            if (!TenantMiddleware::validateStadiumAccess($room['stadium_id'])) return;

            $deleted = $this->roomService->deleteRoom($id);

            if ($deleted) {
                $this->sendSuccess([
                    'message' => 'Room deactivated successfully',
                    'room_id' => $id
                ]);
            } else {
                $this->sendError('Failed to deactivate room', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Room deletion failed', [
                'room_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Room not found') {
                $this->sendError('Room not found', [], 404);
            } else {
                $this->sendError('Room deletion failed', [], 500);
            }
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