<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/UserController.php             *
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

use Hospitality\Services\UserService;
use Hospitality\Repositories\UserRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class UserController {
    private UserService $userService;
    private UserRepository $userRepository;

    public function __construct() {
        $this->userService = new UserService();
        $this->userRepository = new UserRepository();
    }

    public function index(): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = $_GET['stadium_id'] ?? null;
            $role = $_GET['role'] ?? null;

            $currentUser = $GLOBALS['current_user'];
            
            if ($currentUser['role'] !== 'super_admin') {
                $stadiumId = $currentUser['stadium_id'];
            } else {
                if (!$stadiumId) {
                    $this->sendError('stadium_id parameter required for super_admin', [], 422);
                    return;
                }
            }

            if (!TenantMiddleware::validateStadiumAccess((int)$stadiumId)) return;

            $users = $this->userService->getUsersByStadium((int)$stadiumId, $role);

            $this->sendSuccess([
                'users' => $users,
                'total' => count($users),
                'stadium_id' => (int)$stadiumId,
                'filters' => ['role' => $role]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to list users', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve users', [], 500);
        }
    }

    public function show(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;

            $user = $this->userService->getUserById($id);

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to view this user', [], 403);
                return;
            }

            $this->sendSuccess(['user' => $user]);

        } catch (Exception $e) {
            if ($e->getMessage() === 'User not found') {
                $this->sendError('User not found', [], 404);
            } else {
                Logger::error('Failed to get user details', [
                    'user_id' => $id,
                    'error' => $e->getMessage()
                ]);
                $this->sendError('Failed to retrieve user details', [], 500);
            }
        }
    }

    public function create(): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

            $currentUser = $GLOBALS['current_user'];

            // Stadium admin can only create in their stadium
            if ($currentUser['role'] !== 'super_admin') {
                $input['stadium_id'] = $currentUser['stadium_id'];
            } else {
                if (empty($input['stadium_id'])) {
                    $this->sendError('stadium_id is required for super_admin', [], 422);
                    return;
                }
            }

            // Check if user can create this role
            $creatableRoles = RoleMiddleware::getCreatableRoles();
            if (!in_array($input['role'], $creatableRoles)) {
                $this->sendError('You cannot create users with this role', [], 403);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($input['stadium_id'])) return;

            $user = $this->userService->createUser($input);

            $this->sendSuccess([
                'message' => 'User created successfully',
                'user' => $user
            ], 201);

        } catch (Exception $e) {
            Logger::error('User creation failed', ['error' => $e->getMessage()]);
            $this->sendError('User creation failed', $e->getMessage(), 400);
        }
    }

    public function update(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;

            $input = $this->getJsonInput();

            if (empty($input)) {
                $this->sendError('No data provided for update', [], 422);
                return;
            }

            $user = $this->userService->getUserById($id);

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to update this user', [], 403);
                return;
            }

            $updated = $this->userService->updateUser($id, $input);

            if ($updated) {
                $user = $this->userService->getUserById($id);
                $this->sendSuccess([
                    'message' => 'User updated successfully',
                    'user' => $user
                ]);
            } else {
                $this->sendError('No changes were made', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('User update failed', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'User not found') {
                $this->sendError('User not found', [], 404);
            } else {
                $this->sendError('User update failed', $e->getMessage(), 400);
            }
        }
    }

    public function delete(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userService->getUserById($id);

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to delete this user', [], 403);
                return;
            }

            $deleted = $this->userService->deactivateUser($id);

            if ($deleted) {
                $this->sendSuccess([
                    'message' => 'User deactivated successfully',
                    'user_id' => $id
                ]);
            } else {
                $this->sendError('Failed to deactivate user', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('User deletion failed', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);

            if ($e->getMessage() === 'User not found') {
                $this->sendError('User not found', [], 404);
            } else {
                $this->sendError('User deletion failed', [], 500);
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