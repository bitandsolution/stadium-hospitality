<?php
/***************************************************************
*                                                              *
*   FILE: src/Services/AuthService.php - Authentication Logic  *
*                                                              *
*   Author: Antonio Tartaglia - bitAND solution                *
*   website: https://www.bitandsolution.it                     *
*   email:   info@bitandsolution.it                            *
*                                                              *
*   Owner: bitAND solution                                     *
*                                                              *
*   This is proprietary software                               *
*   developed by bitAND solution for bitAND solution           *
*                                                              *
***************************************************************/

namespace Hospitality\Services;

use Hospitality\Repositories\UserRepository;
use Hospitality\Utils\JWT;
use Hospitality\Utils\Logger;
use Exception;

class AuthService {
    private UserRepository $userRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
    }

    /**
     * Login con database reale
     */
    public function login(string $username, string $password, ?int $stadiumId = null): array {
        try {
            // Rate limiting check
            if (!$this->checkRateLimit($username)) {
                throw new Exception('Too many login attempts. Please try again later.');
            }

            // Trova utente nel database
            $user = $this->userRepository->findByUsername($username, $stadiumId);
            
            if (!$user || !$user['is_active']) {
                $this->recordFailedAttempt($username);
                Logger::warning('Login failed - user not found or inactive', [
                    'username' => $username,
                    'stadium_id' => $stadiumId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                throw new Exception('Invalid credentials');
            }

            // Verifica password
            if (!password_verify($password, $user['password_hash'])) {
                $this->recordFailedAttempt($username);
                Logger::warning('Login failed - invalid password', [
                    'user_id' => $user['id'],
                    'username' => $username,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                throw new Exception('Invalid credentials');
            }

            // Controllo accesso stadio per non-super admin
            if ($user['role'] !== 'super_admin' && $stadiumId && $user['stadium_id'] !== $stadiumId) {
                Logger::warning('Login failed - stadium access denied', [
                    'user_id' => $user['id'],
                    'user_stadium_id' => $user['stadium_id'],
                    'requested_stadium_id' => $stadiumId
                ]);
                throw new Exception('Access denied for this stadium');
            }

            // Aggiorna ultimo login
            $this->userRepository->updateLastLogin($user['id']);

            // Genera tokens
            $permissions = $this->getUserPermissions($user['role']);

            $accessToken = JWT::generateAccessToken([
                'user_id' => $user['id'],
                'stadium_id' => $user['stadium_id'],
                'role' => $user['role'],
                'permissions' => $permissions
            ]);

            $refreshToken = JWT::generateRefreshToken($user['id'], $user['stadium_id']);

            // Pulisci tentativi falliti
            $this->clearFailedAttempts($username);

            // Log login successo
            LogService::log('AUTH_LOGIN', 'User logged in successfully', [
                'user_id' => $user['id'],
                'stadium_id' => $user['stadium_id'],
                'role' => $user['role'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ], $user['id'], $user['stadium_id']);

            // Prepara risposta (rimuovi dati sensibili)
            $userResponse = [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'stadium_id' => $user['stadium_id'],
                'stadium_name' => $user['stadium_name'],
                'language' => $user['language'] ?? 'it',
                'last_login' => $user['last_login']
            ];

            // Aggiungi info stadio se presente
            if ($user['primary_color']) {
                $userResponse['stadium_branding'] = [
                    'primary_color' => $user['primary_color'],
                    'secondary_color' => $user['secondary_color']
                ];
            }

            return [
                'success' => true,
                'user' => $userResponse,
                'tokens' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_in' => (int)($_ENV['JWT_EXPIRY'] ?? 3600)
                ],
                'permissions' => $permissions
            ];

        } catch (Exception $e) {
            Logger::error('Login process failed', [
                'username' => $username,
                'stadium_id' => $stadiumId,
                'error' => $e->getMessage(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            throw $e;
        }
    }

    /**
     * Refresh token
     */
    public function refreshToken(string $refreshToken): array {
        try {
            $decoded = JWT::validateToken($refreshToken);
            
            if (!$decoded || !isset($decoded->type) || $decoded->type !== 'refresh') {
                throw new Exception('Invalid refresh token');
            }

            // Verifica che l'utente esista ancora ed è attivo
            $user = $this->userRepository->findById($decoded->user_id);
            
            if (!$user || !$user['is_active']) {
                Logger::warning('Refresh token failed - user not found or inactive', [
                    'user_id' => $decoded->user_id
                ]);
                throw new Exception('User not found or inactive');
            }

            // Genera nuovo access token
            $permissions = $this->getUserPermissions($user['role']);
            
            $newAccessToken = JWT::generateAccessToken([
                'user_id' => $user['id'],
                'stadium_id' => $user['stadium_id'],
                'role' => $user['role'],
                'permissions' => $permissions
            ]);

            // Log refresh
            LogService::log('AUTH_REFRESH', 'Token refreshed successfully', [], $user['id'], $user['stadium_id']);

            return [
                'success' => true,
                'tokens' => [
                    'access_token' => $newAccessToken,
                    'expires_in' => (int)($_ENV['JWT_EXPIRY'] ?? 3600)
                ]
            ];

        } catch (Exception $e) {
            Logger::warning('Token refresh failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Logout sicuro
     */
    public function logout(string $accessToken, string $refreshToken, int $userId, ?int $stadiumId): array {
        try {
            // Blacklist entrambi i token
            JWT::blacklistToken($accessToken, $userId, $stadiumId, 'logout');
            JWT::blacklistToken($refreshToken, $userId, $stadiumId, 'logout');

            // Log logout
            LogService::log('AUTH_LOGOUT', 'User logged out successfully', [], $userId, $stadiumId);

            return [
                'success' => true,
                'message' => 'Logged out successfully'
            ];

        } catch (Exception $e) {
            Logger::error('Logout failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Ottieni permessi per ruolo
     */
    private function getUserPermissions(string $role): array {
        $permissions = [
            'super_admin' => [
                'manage_stadiums',
                'manage_all_users',
                'view_all_analytics',
                'manage_system_settings',
                'export_all_data'
            ],
            'stadium_admin' => [
                'manage_stadium_users',
                'manage_rooms',
                'manage_events',
                'manage_guests',
                'import_export_data',
                'view_stadium_analytics',
                'assign_hostess_rooms'
            ],
            'hostess' => [
                'view_assigned_rooms',
                'search_guests',
                'checkin_guests',
                'checkout_guests',
                'update_guest_data',
                'view_guest_history'
            ]
        ];

        return $permissions[$role] ?? [];
    }

    /**
     * Rate limiting semplice (file-based)
     */
    private function checkRateLimit(string $username): bool {
        $cacheFile = sys_get_temp_dir() . '/login_attempts_' . hash('sha256', $username);
        
        if (!file_exists($cacheFile)) {
            return true;
        }

        $attempts = json_decode(file_get_contents($cacheFile), true);
        $maxAttempts = (int)($_ENV['MAX_LOGIN_ATTEMPTS'] ?? 5);
        $lockoutDuration = (int)($_ENV['LOCKOUT_DURATION'] ?? 900); // 15 minutes

        if ($attempts['count'] >= $maxAttempts) {
            $timeSinceLastAttempt = time() - $attempts['last_attempt'];
            return $timeSinceLastAttempt > $lockoutDuration;
        }

        return true;
    }

    /**
     * Registra tentativo fallito
     */
    private function recordFailedAttempt(string $username): void {
        $cacheFile = sys_get_temp_dir() . '/login_attempts_' . hash('sha256', $username);
        
        $attempts = ['count' => 0, 'last_attempt' => 0];
        if (file_exists($cacheFile)) {
            $attempts = json_decode(file_get_contents($cacheFile), true) ?: $attempts;
        }

        $attempts['count']++;
        $attempts['last_attempt'] = time();

        file_put_contents($cacheFile, json_encode($attempts), LOCK_EX);
    }

    /**
     * Pulisci tentativi falliti
     */
    private function clearFailedAttempts(string $username): void {
        $cacheFile = sys_get_temp_dir() . '/login_attempts_' . hash('sha256', $username);
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
}
