<?php
/******************************************************************
*                                                                 *
*   FILE: src/Middleware/AuthMiddleware.php - JWT Authentication  *
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

namespace Hospitality\Middleware;

use Hospitality\Utils\JWT;
use Hospitality\Utils\Logger;

class AuthMiddleware {
    
    public static function handle(): ?object {
        $token = \Hospitality\Utils\AuthHelper::extractTokenFromHeader();
        
        if (!$token) {
            self::sendUnauthorized('Missing authorization header');
            return null;
        }

        $decoded = \Hospitality\Utils\AuthHelper::validateToken($token);
        
        if (!$decoded) {
            self::sendUnauthorized('Invalid or expired token');
            return null;
        }

        // Check token type (must be access token, not refresh)
        if (isset($decoded->type) && $decoded->type === 'refresh') {
            self::sendUnauthorized('Invalid token type');
            return null;
        }

        // Store user info in globals for easy access
        $GLOBALS['current_user'] = [
            'id' => $decoded->user_id,
            'stadium_id' => $decoded->stadium_id,
            'role' => $decoded->role,
            'permissions' => $decoded->permissions ?? []
        ];

        return $decoded;
    }

    public static function requirePermission(string $permission): bool {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            self::sendForbidden('Authentication required');
            return false;
        }

        $permissions = $currentUser['permissions'] ?? [];

        if (!in_array($permission, $permissions)) {
            Logger::warning('Permission denied', [
                'user_id' => $currentUser['id'],
                'required_permission' => $permission,
                'user_permissions' => $permissions
            ]);
            
            self::sendForbidden("Missing required permission: {$permission}");
            return false;
        }

        return true;
    }

    private static function sendUnauthorized(string $message): void {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        exit;
    }

    private static function sendForbidden(string $message): void {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'error_code' => 'FORBIDDEN',
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        exit;
    }
}