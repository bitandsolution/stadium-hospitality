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

    /**
     * POST /api/auth/login
     * Login multi-ruolo con database reale
     */
    public function login(): void {
        try {
            $input = $this->getJsonInput();

            // Validazione input
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

            // Estrai stadium_id se fornito (per multi-tenant)
            $stadiumId = !empty($input['stadium_id']) ? (int)$input['stadium_id'] : null;

            // Attempt login
            $result = $this->authService->login(
                trim($input['username']),
                $input['password'],
                $stadiumId
            );

            $this->sendSuccess($result);

        } catch (Exception $e) {
            Logger::warning('Login attempt failed', [
                'username' => $input['username'] ?? 'unknown',
                'stadium_id' => $input['stadium_id'] ?? null,
                'error' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            $this->sendError('Login failed', $e->getMessage(), 401);
        }
    }

    /**
     * POST /api/auth/refresh
     * Rinnovo access token
     */
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

    /**
     * POST /api/auth/logout
     * Logout sicuro con blacklist
     */
    public function logout(): void {
        try {
            // Richiedi autenticazione per logout
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $input = $this->getJsonInput();

            if (empty($input['refresh_token'])) {
                $this->sendError('Refresh token is required for logout', [], 400);
                return;
            }

            // Estrai access token dall'header
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
     * GET /api/auth/me
     * Informazioni utente corrente
     */
    public function me(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Get fresh user data
            $userRepo = new \Hospitality\Repositories\UserRepository();
            $user = $userRepo->findById($decoded->user_id);

            if (!$user) {
                $this->sendError('User not found', [], 404);
                return;
            }

            // Rimuovi dati sensibili
            unset($user['password_hash']);

            $this->sendSuccess([
                'user' => $user,
                'permissions' => $decoded->permissions ?? [],
                'session_info' => [
                    'issued_at' => $decoded->iat,
                    'expires_at' => $decoded->exp,
                    'stadium_access' => $decoded->stadium_id
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get user info', ['error' => $e->getMessage()]);
            $this->sendError('Failed to get user information', [], 500);
        }
    }

    /**
     * POST /api/auth/change-password
     * Cambio password utente corrente
     */
    public function changePassword(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            $input = $this->getJsonInput();

            // Validazioni
            $errors = Validator::validateRequired($input, ['current_password', 'new_password']);
            
            if (!empty($input['new_password'])) {
                $passwordErrors = Validator::validatePassword($input['new_password']);
                $errors = array_merge($errors, $passwordErrors);
            }

            if (!empty($errors)) {
                $this->sendError('Validation failed', $errors, 422);
                return;
            }

            // Verifica password attuale
            $userRepo = new \Hospitality\Repositories\UserRepository();
            $user = $userRepo->findById($decoded->user_id);

            if (!$user || !password_verify($input['current_password'], $user['password_hash'])) {
                $this->sendError('Current password is incorrect', [], 400);
                return;
            }

            // Aggiorna password
            $updated = $userRepo->update($decoded->user_id, [
                'password' => $input['new_password']
            ]);

            if ($updated) {
                Logger::info('Password changed successfully', ['user_id' => $decoded->user_id]);
                
                // Log l'operazione
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