<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/EventController.php            *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Services\EventService;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Logger;
use Exception;

class EventController {
    private EventService $eventService;

    public function __construct() {
        $this->eventService = new EventService();
    }

    /**
     * POST /api/admin/events
     * Create new event
     */
    public function create(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            // Set stadium_id based on role
            if ($decoded->role !== 'super_admin') {
                $input['stadium_id'] = $decoded->stadium_id;
            } else {
                if (empty($input['stadium_id'])) {
                    $this->sendError('stadium_id is required for super_admin', [], 422);
                    return;
                }
            }

            if (!TenantMiddleware::validateStadiumAccess($input['stadium_id'])) return;

            $event = $this->eventService->createEvent($input);

            $this->sendSuccess([
                'message' => 'Event created successfully',
                'event' => $event
            ], 201);

        } catch (Exception $e) {
            Logger::error('Event creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);

            $this->sendError('Event creation failed', $e->getMessage(), 400);
        }
    }

    /**
     * GET /api/admin/events
     * List events with filters
     */
    public function index(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = $_GET['stadium_id'] ?? null;
            
            // Stadium admin can only see own stadium
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
            $events = $this->eventService->getEventsByStadium((int)$stadiumId, !$includeInactive);

            $this->sendSuccess([
                'events' => $events,
                'total' => count($events),
                'stadium_id' => (int)$stadiumId
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list events', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve events', [], 500);
        }
    }

    /**
     * GET /api/admin/events/upcoming
     * Get upcoming events
     */
    public function upcoming(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = $_GET['stadium_id'] ?? $decoded->stadium_id;
            $limit = min((int)($_GET['limit'] ?? 10), 50);

            if (!TenantMiddleware::validateStadiumAccess((int)$stadiumId)) return;

            $events = $this->eventService->getUpcomingEvents((int)$stadiumId, $limit);

            $this->sendSuccess([
                'events' => $events,
                'total' => count($events)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get upcoming events', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve upcoming events', [], 500);
        }
    }

    /**
     * GET /api/admin/events/{id}
     * Get event details with statistics
     */
    public function show(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $result = $this->eventService->getEventWithStats($id);

            if (!TenantMiddleware::validateStadiumAccess($result['event']['stadium_id'])) return;

            $this->sendSuccess($result);

        } catch (Exception $e) {
            if ($e->getMessage() === 'Event not found') {
                $this->sendError('Event not found', [], 404);
            } else {
                Logger::error('Failed to get event details', [
                    'event_id' => $id,
                    'error' => $e->getMessage()
                ]);
                $this->sendError('Failed to retrieve event details', [], 500);
            }
        }
    }

    /**
     * PUT /api/admin/events/{id}
     * Update event
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

            // Verify access to event's stadium
            $event = $this->eventService->getEventById($id);
            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            $updated = $this->eventService->updateEvent($id, $input);

            if ($updated) {
                $event = $this->eventService->getEventById($id);
                $this->sendSuccess([
                    'message' => 'Event updated successfully',
                    'event' => $event
                ]);
            } else {
                $this->sendError('No changes were made', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Event update failed', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Event not found') {
                $this->sendError('Event not found', [], 404);
            } else {
                $this->sendError('Event update failed', $e->getMessage(), 400);
            }
        }
    }

    /**
     * DELETE /api/admin/events/{id}
     * Soft delete event
     */
    public function delete(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            // Verify access to event's stadium
            $event = $this->eventService->getEventById($id);
            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            $deleted = $this->eventService->deleteEvent($id);

            if ($deleted) {
                $this->sendSuccess([
                    'message' => 'Event deactivated successfully',
                    'event_id' => $id
                ]);
            } else {
                $this->sendError('Failed to deactivate event', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Event deletion failed', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Event not found') {
                $this->sendError('Event not found', [], 404);
            } else {
                $this->sendError('Event deletion failed', [], 500);
            }
        }
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

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