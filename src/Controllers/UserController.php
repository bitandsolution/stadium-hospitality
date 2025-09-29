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
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Repositories\UserRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Hospitality\Services\LogService;
use Exception;

class UserController {
    private UserRepository $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    /**
     * GET /api/admin/users
     * Lista utenti (filtrata per ruolo e stadio)
     */
    public function index(): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = $_GET['stadium_id'] ?? null;
            $role = $_GET['role'] ?? null;

            if (!TenantMiddleware::validateStadiumAccess((int)$stadiumId)) return;

            $queryStadiumId = TenantMiddleware::getStadiumIdForQuery($stadiumId ? (int)$stadiumId : null);

            if ($queryStadiumId) {
                $users = $this->userRepository->findByStadiumWithRoomCount($queryStadiumId, $role);
                
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
                $this->sendError('Stadium ID required', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Failed to list users', ['error' => $e->getMessage()]);
            $this->sendError('Failed to retrieve users', [], 500);
        }
    }

    /**
     * GET /api/admin/users/{id}
     * Dettagli utente specifico
     */
    public function show(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to view this user', [], 403);
                return;
            }

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
     * POST /api/admin/users
     * Creazione nuovo utente
     */
    public function create(): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $input = $this->getJsonInput();

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

            $creatableRoles = RoleMiddleware::getCreatableRoles();
            if (!empty($input['role']) && !in_array($input['role'], $creatableRoles)) {
                $errors[] = 'You cannot create users with this role';
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            $currentUser = $GLOBALS['current_user'] ?? null;
            
            if ($currentUser['role'] !== 'super_admin') {
                $input['stadium_id'] = $currentUser['stadium_id'];
            } else {
                if (empty($input['stadium_id'])) {
                    $this->sendError('stadium_id is required for super_admin', [], 422);
                    return;
                }
            }

            if (!TenantMiddleware::validateStadiumAccess($input['stadium_id'])) return;

            if ($this->userRepository->usernameExists($input['username'], $input['stadium_id'])) {
                $this->sendError('Username already exists', [], 409);
                return;
            }

            if ($this->userRepository->emailExists($input['email'], $input['stadium_id'])) {
                $this->sendError('Email already exists', [], 409);
                return;
            }

            $input['created_by'] = $currentUser['id'];
            $userId = $this->userRepository->create($input);

            LogService::log(
                'USER_CREATED',
                "New user created: {$input['username']}",
                [
                    'user_id' => $userId,
                    'role' => $input['role'],
                    'stadium_id' => $input['stadium_id']
                ],
                $currentUser['id'],
                $input['stadium_id'],
                'users',
                $userId
            );

            $user = $this->userRepository->findById($userId);
            unset($user['password_hash']);

            $this->sendSuccess([
                'message' => 'User created successfully',
                'user' => $user
            ], 201);

        } catch (Exception $e) {
            Logger::error('User creation failed', ['error' => $e->getMessage()]);
            $this->sendError('User creation failed', $e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/admin/users/{id}
     * Aggiornamento utente
     */
    public function update(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to edit this user', [], 403);
                return;
            }

            $input = $this->getJsonInput();

            if (empty($input)) {
                $this->sendError('No data provided for update', [], 422);
                return;
            }

            if (isset($input['email']) && !Validator::validateEmail($input['email'])) {
                $this->sendError('Invalid email format', [], 422);
                return;
            }

            if (isset($input['email']) && $input['email'] !== $user['email']) {
                if ($this->userRepository->emailExists($input['email'], $user['stadium_id'], $id)) {
                    $this->sendError('Email already exists', [], 409);
                    return;
                }
            }

            $updated = $this->userRepository->update($id, $input);

            if ($updated) {
                $currentUser = $GLOBALS['current_user'] ?? null;
                
                LogService::log(
                    'USER_UPDATED',
                    "User updated: {$user['username']}",
                    ['user_id' => $id, 'changes' => array_keys($input)],
                    $currentUser['id'] ?? null,
                    $user['stadium_id'],
                    'users',
                    $id
                );

                $updatedUser = $this->userRepository->findById($id);
                unset($updatedUser['password_hash']);

                $this->sendSuccess([
                    'message' => 'User updated successfully',
                    'user' => $updatedUser
                ]);
            } else {
                $this->sendError('No changes were made', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('User update failed', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('User update failed', $e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/admin/users/{id}
     * Disattiva utente (soft delete)
     */
    public function delete(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions to delete this user', [], 403);
                return;
            }

            $currentUser = $GLOBALS['current_user'] ?? null;
            
            if ($currentUser && $currentUser['id'] === $id) {
                $this->sendError('You cannot delete yourself', [], 400);
                return;
            }

            $deleted = $this->userRepository->deactivate($id);

            if ($deleted) {
                LogService::log(
                    'USER_DELETED',
                    "User deactivated: {$user['username']}",
                    ['user_id' => $id],
                    $currentUser['id'] ?? null,
                    $user['stadium_id'],
                    'users',
                    $id
                );

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
            $this->sendError('User deletion failed', [], 500);
        }
    }

    /**
     * GET /api/admin/users/{id}/rooms
     * Ottieni sale assegnate a hostess
     */
    public function getRooms(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions', [], 403);
                return;
            }

            if ($user['role'] !== 'hostess') {
                $this->sendError('This user is not a hostess', [], 400);
                return;
            }

            $rooms = $this->userRepository->getAssignedRooms($id);

            $this->sendSuccess([
                'rooms' => $rooms,
                'total' => count($rooms)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get user rooms', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve rooms', [], 500);
        }
    }

    /**
     * POST /api/admin/users/{id}/rooms
     * Assegna sale a hostess
     */
    public function assignRooms(int $id): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions', [], 403);
                return;
            }

            if ($user['role'] !== 'hostess') {
                $this->sendError('This user is not a hostess', [], 400);
                return;
            }

            $input = $this->getJsonInput();
            
            if (!isset($input['room_ids']) || !is_array($input['room_ids'])) {
                $this->sendError('room_ids array is required', [], 422);
                return;
            }

            $currentUser = $GLOBALS['current_user'] ?? null;

            $this->userRepository->clearRoomAssignments($id);

            if (!empty($input['room_ids'])) {
                foreach ($input['room_ids'] as $roomId) {
                    $this->userRepository->assignRoom($id, (int)$roomId, $currentUser['id']);
                }
            }

            LogService::log(
                'ROOM_ASSIGNMENT',
                "Rooms assigned to hostess: {$user['username']}",
                [
                    'user_id' => $id,
                    'room_ids' => $input['room_ids'],
                    'room_count' => count($input['room_ids'])
                ],
                $currentUser['id'],
                $user['stadium_id']
            );

            $rooms = $this->userRepository->getAssignedRooms($id);

            $this->sendSuccess([
                'message' => 'Rooms assigned successfully',
                'rooms' => $rooms,
                'total' => count($rooms)
            ]);

        } catch (Exception $e) {
            Logger::error('Room assignment failed', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Room assignment failed', $e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/admin/users/{id}/rooms/{roomId}
     * Rimuovi assegnazione singola sala
     */
    public function removeRoom(int $id, int $roomId): void {
        try {
            if (!AuthMiddleware::handle()) return;
            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $user = $this->userRepository->findById($id);
            
            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            if (!RoleMiddleware::canManageUser($user)) {
                $this->sendError('Insufficient permissions', [], 403);
                return;
            }

            $removed = $this->userRepository->removeRoomAssignment($id, $roomId);

            if ($removed) {
                $currentUser = $GLOBALS['current_user'] ?? null;
                
                LogService::log(
                    'ROOM_UNASSIGNMENT',
                    "Room removed from hostess: {$user['username']}",
                    [
                        'user_id' => $id,
                        'room_id' => $roomId
                    ],
                    $currentUser['id'],
                    $user['stadium_id']
                );

                $this->sendSuccess([
                    'message' => 'Room assignment removed successfully',
                    'user_id' => $id,
                    'room_id' => $roomId
                ]);
            } else {
                $this->sendError('Failed to remove room assignment', [], 400);
            }

        } catch (Exception $e) {
            Logger::error('Room removal failed', [
                'user_id' => $id,
                'room_id' => $roomId,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Room removal failed', [], 500);
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