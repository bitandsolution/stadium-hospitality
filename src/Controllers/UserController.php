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

use Hospitality\Repositories\UserRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class UserController {
    private UserRepository $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    /**
     * GET /api/users
     * Lista utenti (filtrata per ruolo e stadio)
     */
    public function index(): void {
        try {
            // Require authentication
            if (!AuthMiddleware::handle()) return;

            // Get query parameters
            $stadiumId = $_GET['stadium_id'] ?? null;
            $role = $_GET['role'] ?? null;

            // Validate stadium access
            if ($stadiumId && !TenantMiddleware::validateStadiumAccess((int)$stadiumId)) return;

            // Get stadium ID for query (with tenant filtering)
            $queryStadiumId = TenantMiddleware::getStadiumIdForQuery($stadiumId ? (int)$stadiumId : null);

            if ($queryStadiumId) {
                $users = $this->userRepository->findByStadium($queryStadiumId, $role);
                
                // Remove sensitive data
                foreach ($users as &$user) {
                    unset($user['password_hash']);
                }
                
                $this->sendSuccess([
                    'users' => $users,
                    'total' => count($users),
                    'filters' => [
                        'stadium_id' => $queryStadiumId,
                        'role' => $role
                    ]
                ]);
            } else {
                // Super admin without stadium filter - not implemented yet
                $this->sendError('Multi-stadium listing not yet implemented', [], 501);
            }

        } catch (Exception $e) {
            Logger::error('Failed to list users', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve users', [], 500);
        }
    }

    /**
     * GET /api/users/{id}
     * Dettagli utente specifico
     */
    public function show(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            // Check if current user can view this user
            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to view this user', [], 403);
                return;
            }

            // Remove sensitive data
            unset($user['password_hash']);

            $this->sendSuccess(['user' => $user]);

        } catch (Exception $e) {
            Logger::error('Failed to get user details', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve user details', [], 500);
        }
    }

    /**
     * POST /api/users
     * Creazione nuovo utente
     */
    public function create(): void {
        try {
            if (!AuthMiddleware::handle()) return;

            $input = $this->getJsonInput();

            // Validation
            $errors = Validator::validateRequired($input, [
                'username', 'email', 'password', 'full_name', 'role'
            ]);

            if (!empty($input['email']) && !Validator::validateEmail($input['email'])) {
                $errors[] = 'Invalid email format';
            }

            if (!empty($input['role']) && !Validator::validateRole($input['role'])) {
                $errors[] = 'Invalid role';
            }

            if (!empty($input['password'])) {
                $passwordErrors = Validator::validatePassword($input['password']);
                $errors = array_merge($errors, $passwordErrors);
            }

            // Check if user can create this role
            $creatableRoles = RoleMiddleware::getCreatableRoles();
            if (!empty($input['role']) && !in_array($input['role'], $creatableRoles)) {
                $errors[] = 'You cannot create users with this role';
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            // Implementation placeholder
            $this->sendError('User creation not yet implemented', [], 501);

        } catch (Exception $e) {
            Logger::error('Failed to create user', ['error' => $e->getMessage()]);
            $this->sendError('Failed to create user', [], 500);
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

    private function sendSuccess(array $data): void {
        http_response_code(200);
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