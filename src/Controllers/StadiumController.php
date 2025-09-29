<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/StadiumController.php          *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/


namespace Hospitality\Controllers;

use Hospitality\Services\StadiumService;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Utils\Logger;
use Exception;

class StadiumController {
    private StadiumService $stadiumService;

    public function __construct() {
        $this->stadiumService = new StadiumService();
    }

    /**
     * POST /api/admin/stadiums
     * Create new stadium (super_admin only)
     */
    public function create(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Only super_admin can create stadiums
            if (!RoleMiddleware::requireRole('super_admin')) return;

            $input = $this->getJsonInput();

            // Validate required data
            if (empty($input['stadium']) || empty($input['admin'])) {
                $this->sendError('Missing required data: stadium and admin objects', [], 422);
                return;
            }

            $result = $this->stadiumService->createStadium(
                $input['stadium'],
                $input['admin']
            );

            $this->sendSuccess($result, 201);

        } catch (Exception $e) {
            Logger::error('Stadium creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);

            $this->sendError('Stadium creation failed', $e->getMessage(), 400);
        }
    }

    /**
     * GET /api/admin/stadiums
     * List all stadiums (super_admin only)
     */
    public function index(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('super_admin')) return;

            $activeOnly = isset($_GET['include_inactive']) ? false : true;
            $stadiums = $this->stadiumService->getAllStadiums($activeOnly);

            $this->sendSuccess([
                'stadiums' => $stadiums,
                'total' => count($stadiums)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list stadiums', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve stadiums', [], 500);
        }
    }

    /**
     * GET /api/admin/stadiums/{id}
     * Get stadium details
     */
    public function show(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Super admin can view any stadium
            // Stadium admin can only view own stadium
            if ($decoded->role !== 'super_admin' && $decoded->stadium_id !== $id) {
                $this->sendError('Access denied to this stadium', [], 403);
                return;
            }

            $stadium = $this->stadiumService->getStadiumById($id);
            $stats = $this->stadiumService->getStadiumStatistics($id);

            $this->sendSuccess([
                'stadium' => $stadium,
                'statistics' => $stats
            ]);

        } catch (Exception $e) {
            if ($e->getMessage() === 'Stadium not found') {
                $this->sendError('Stadium not found', [], 404);
            } else {
                Logger::error('Failed to get stadium details', [
                    'stadium_id' => $id,
                    'error' => $e->getMessage()
                ]);
                $this->sendError('Failed to retrieve stadium details', [], 500);
            }
        }
    }

    /**
     * PUT /api/admin/stadiums/{id}
     * Update stadium
     */
    public function update(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Super admin can update any stadium
            // Stadium admin can only update own stadium
            if ($decoded->role !== 'super_admin' && $decoded->stadium_id !== $id) {
                $this->sendError('Access denied to update this stadium', [], 403);
                return;
            }

            $input = $this->getJsonInput();

            if (empty($input)) {
                $this->sendError('No data provided for update', [], 422);
                return;
            }

            $updated = $this->stadiumService->updateStadium($id, $input);

            if ($updated) {
                $stadium = $this->stadiumService->getStadiumById($id);
                $this->sendSuccess([
                    'message' => 'Stadium updated successfully',
                    'stadium' => $stadium
                ]);
            } else {
                $this->sendError('No changes were made', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Stadium update failed', [
                'stadium_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Stadium not found') {
                $this->sendError('Stadium not found', [], 404);
            } else {
                $this->sendError('Stadium update failed', $e->getMessage(), 400);
            }
        }
    }

    /**
     * DELETE /api/admin/stadiums/{id}
     * Soft delete stadium (super_admin only)
     */
    public function delete(int $id): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('super_admin')) return;

            $deleted = $this->stadiumService->deleteStadium($id);

            if ($deleted) {
                $this->sendSuccess([
                    'message' => 'Stadium deactivated successfully',
                    'stadium_id' => $id
                ]);
            } else {
                $this->sendError('Failed to deactivate stadium', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Stadium deletion failed', [
                'stadium_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'Stadium not found') {
                $this->sendError('Stadium not found', [], 404);
            } else {
                $this->sendError('Stadium deletion failed', [], 500);
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