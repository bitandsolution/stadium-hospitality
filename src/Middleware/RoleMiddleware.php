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

namespace Hospitality\Middleware;

use Hospitality\Utils\Logger;

class RoleMiddleware {
    
    private static array $roleHierarchy = [
        'super_admin' => 3,
        'stadium_admin' => 2,
        'hostess' => 1
    ];

    /**
     * Richiedi ruolo minimo
     */
    public static function requireRole(string $minimumRole): bool {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            self::sendForbidden('Authentication required');
            return false;
        }

        $userRoleLevel = self::$roleHierarchy[$currentUser['role']] ?? 0;
        $requiredRoleLevel = self::$roleHierarchy[$minimumRole] ?? 999;

        if ($userRoleLevel < $requiredRoleLevel) {
            Logger::warning('Role access denied', [
                'user_id' => $currentUser['id'],
                'user_role' => $currentUser['role'],
                'required_role' => $minimumRole,
                'user_role_level' => $userRoleLevel,
                'required_role_level' => $requiredRoleLevel
            ]);

            self::sendForbidden("Insufficient permissions. Required role: {$minimumRole}");
            return false;
        }

        return true;
    }

    /**
     * Verifica se utente corrente può gestire un altro utente
     */
    public static function canManageUser(array $targetUser): bool {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            return false;
        }

        // Super admin può gestire tutti
        if ($currentUser['role'] === 'super_admin') {
            return true;
        }

        // Stadium admin può gestire hostess del proprio stadio
        if ($currentUser['role'] === 'stadium_admin') {
            return $targetUser['role'] === 'hostess' && 
                   $targetUser['stadium_id'] === $currentUser['stadium_id'];
        }

        // Hostess può modificare solo se stessa
        if ($currentUser['role'] === 'hostess') {
            return $targetUser['id'] === $currentUser['id'];
        }

        return false;
    }

    /**
     * Ottieni livelli di ruolo che l'utente corrente può creare
     */
    public static function getCreatableRoles(): array {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            return [];
        }

        switch ($currentUser['role']) {
            case 'super_admin':
                return ['super_admin', 'stadium_admin', 'hostess'];
            
            case 'stadium_admin':
                return ['hostess'];
                
            case 'hostess':
            default:
                return [];
        }
    }

    private static function sendForbidden(string $message): void {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'error_code' => 'INSUFFICIENT_PERMISSIONS',
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        exit;
    }
}