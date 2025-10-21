<?php
/******************************************************************
*                                                                 *
*   FILE: src/Controllers/AuthController.php - FIXED VERSION      *
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

namespace Hospitality\Controllers;

use Hospitality\Services\AuthService;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class AuthController {
    private AuthService $authService;

    public function __construct() {
        $this->authService = new AuthService();
    }

    public function login(): void {
        try {
            $input = $this->getJsonInput();
            $errors = Validator::validateRequired($input, ['username', 'password']);
            
            if (!empty($input['username']) && !Validator::validateString($input['username'], 3, 50)) {
                $errors[] = 'Username must be between 3 and 50 characters';
            }

            if (!empty($input['password']) && strlen($input['password']) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            $stadiumId = !empty($input['stadium_id']) ? (int)$input['stadium_id'] : null;

            $result = $this->authService->login(
                trim($input['username']),
                $input['password'],
                $stadiumId
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::warning('Login attempt failed', [
                'username' => $input['username'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            $this->sendError('Login failed', $e->getMessage(), 401);
        }
    }

    public function refresh(): void {
        try {
            $input = $this->getJsonInput();

            if (empty($input['refresh_token'])) {
                $this->sendError('Refresh token is required', [], 400);
                return;
            }

            $result = $this->authService->refreshToken($input['refresh_token']);
            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::warning('Token refresh failed', ['error' => $e->getMessage()]);
            $this->sendError('Token refresh failed', $e->getMessage(), 401);
        }
    }

    public function logout(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $input = $this->getJsonInput();

            if (empty($input['refresh_token'])) {
                $this->sendError('Refresh token is required for logout', [], 400);
                return;
            }

            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $accessToken = $matches[1];
            } else {
                $this->sendError('Access token not found in header', [], 400);
                return;
            }

            $result = $this->authService->logout(
                $accessToken,
                $input['refresh_token'],
                $decoded->user_id,
                $decoded->stadium_id
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::error('Logout failed', ['error' => $e->getMessage()]);
            $this->sendError('Logout failed', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/auth/me - VERSIONE SICURA E OTTIMIZZATA
     */
    public function me(): void {
        try {
            // Step 1: Authenticate
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            Logger::debug('Getting user info', ['user_id' => $decoded->user_id]);

            // Step 2: Get basic user data
            $userRepo = new \Hospitality\Repositories\UserRepository();
            $user = $userRepo->findById($decoded->user_id);

            if (!$user) {
                Logger::error('User not found in database', ['user_id' => $decoded->user_id]);
                $this->sendError('User not found', [], 404);
                return;
            }

            // Remove sensitive data
            unset($user['password_hash']);

            // Step 3: Build base response
            $responseData = [
                'user' => $user,
                'permissions' => $decoded->permissions ?? [],
                'session_info' => [
                    'issued_at' => $decoded->iat,
                    'expires_at' => $decoded->exp,
                    'stadium_access' => $decoded->stadium_id
                ]
            ];

            // Step 4: Add role-specific data
            try {
                if ($user['role'] === 'hostess') {
                    $responseData = $this->addHostessData($responseData, $decoded->user_id);
                } elseif ($user['role'] === 'stadium_admin') {
                    $responseData = $this->addStadiumAdminData($responseData);
                } elseif ($user['role'] === 'super_admin') {
                    $responseData = $this->addSuperAdminData($responseData);
                }
            } catch (Exception $e) {
                // Log error but continue - don't fail the entire request
                Logger::error('Failed to add role-specific data', [
                    'user_id' => $decoded->user_id,
                    'role' => $user['role'],
                    'error' => $e->getMessage()
                ]);
                
                // Add basic role data as fallback
                $responseData['role_specific_data'] = [
                    'view_type' => $user['role'] === 'hostess' ? 'hostess_checkin' : 'admin_dashboard',
                    'error' => 'Failed to load complete role data'
                ];
            }

            $this->sendSuccess($responseData);

        } catch (Exception $e) {
            Logger::error('Failed to get user info', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $decoded->user_id ?? null
            ]);
            
            $this->sendError('Failed to get user information', [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add hostess-specific data
     */
    private function addHostessData(array $responseData, int $userId): array {
        try {
            $db = \Hospitality\Config\Database::getInstance()->getConnection();
            
            // Query 1: Get assigned rooms (simplified)
            $stmt = $db->prepare("
                SELECT 
                    hr.id as room_id,
                    hr.name as room_name,
                    hr.capacity
                FROM user_room_assignments ura
                INNER JOIN hospitality_rooms hr ON ura.room_id = hr.id
                WHERE ura.user_id = ? 
                    AND ura.is_active = 1
                    AND hr.is_active = 1
                ORDER BY hr.name
            ");
            
            $stmt->execute([$userId]);
            $assignedRooms = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            Logger::debug('Loaded assigned rooms', [
                'user_id' => $userId,
                'rooms_count' => count($assignedRooms)
            ]);
            
            // Query 2: Get guest counts for each room (separate query for safety)
            foreach ($assignedRooms as &$room) {
                try {
                    $stmt = $db->prepare("
                        SELECT 
                            COUNT(DISTINCT g.id) as total_guests,
                            COUNT(DISTINCT CASE WHEN ga.id IS NOT NULL THEN g.id END) as checked_in_guests
                        FROM guests g
                        LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                            AND ga.access_type = 'entry'
                        WHERE g.room_id = ? AND g.is_active = 1
                    ");
                    
                    $stmt->execute([$room['room_id']]);
                    $counts = $stmt->fetch(\PDO::FETCH_ASSOC);
                    
                    $room['total_guests'] = (int)($counts['total_guests'] ?? 0);
                    $room['checked_in_guests'] = (int)($counts['checked_in_guests'] ?? 0);
                    
                } catch (Exception $e) {
                    Logger::warning('Failed to get guest counts for room', [
                        'room_id' => $room['room_id'],
                        'error' => $e->getMessage()
                    ]);
                    
                    $room['total_guests'] = 0;
                    $room['checked_in_guests'] = 0;
                }
            }
            
            $responseData['assigned_rooms'] = $assignedRooms;
            $responseData['role_specific_data'] = [
                'total_assigned_rooms' => count($assignedRooms),
                'can_checkin' => true,
                'can_checkout' => true,
                'view_type' => 'hostess_checkin'
            ];
            
            Logger::info('Hostess data loaded successfully', [
                'user_id' => $userId,
                'rooms_count' => count($assignedRooms)
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to load hostess data', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback data
            $responseData['assigned_rooms'] = [];
            $responseData['role_specific_data'] = [
                'total_assigned_rooms' => 0,
                'can_checkin' => true,
                'can_checkout' => true,
                'view_type' => 'hostess_checkin',
                'error' => 'Failed to load room assignments'
            ];
        }
        
        return $responseData;
    }

    /**
     * Add stadium admin data
     */
    private function addStadiumAdminData(array $responseData): array {
        $responseData['role_specific_data'] = [
            'can_manage_users' => true,
            'can_manage_rooms' => true,
            'can_manage_events' => true,
            'can_import_export' => true,
            'view_type' => 'admin_dashboard'
        ];
        
        return $responseData;
    }

    /**
     * Add super admin data
     */
    private function addSuperAdminData(array $responseData): array {
        $responseData['role_specific_data'] = [
            'can_manage_stadiums' => true,
            'can_manage_all' => true,
            'view_type' => 'super_admin_dashboard'
        ];
        
        return $responseData;
    }

    public function changePassword(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $input = $this->getJsonInput();

            $errors = Validator::validateRequired($input, ['current_password', 'new_password']);
            
            if (!empty($input['new_password'])) {
                $passwordErrors = Validator::validatePassword($input['new_password']);
                $errors = array_merge($errors, $passwordErrors);
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            $userRepo = new \Hospitality\Repositories\UserRepository();
            $user = $userRepo->findById($decoded->user_id);

            if (!$user || !password_verify($input['current_password'], $user['password_hash'])) {
                $this->sendError('Current password is incorrect', [], 400);
                return;
            }

            $updated = $userRepo->update($decoded->user_id, [
                'password' => $input['new_password']
            ]);

            if ($updated) {
                Logger::info('Password changed successfully', ['user_id' => $decoded->user_id]);
                
                \Hospitality\Services\LogService::log(
                    'AUTH_PASSWORD_CHANGE', 
                    'User changed password', 
                    [], 
                    $decoded->user_id, 
                    $decoded->stadium_id
                );

                $this->sendSuccess(['message' => 'Password changed successfully']);
            } else {
                $this->sendError('Failed to update password', [], 500);
            }

        } catch (Exception $e) {
            Logger::error('Password change failed', [
                'user_id' => $decoded->user_id ?? null,
                'error' => $e->getMessage()
            ]);
            $this->sendError('Password change failed', [], 500);
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
            'error_code' => $this->getErrorCode($code),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }

    private function getErrorCode(int $httpCode): string {
        $codes = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            422 => 'VALIDATION_ERROR',
            500 => 'INTERNAL_ERROR'
        ];

        return $codes[$httpCode] ?? 'UNKNOWN_ERROR';
    }
}