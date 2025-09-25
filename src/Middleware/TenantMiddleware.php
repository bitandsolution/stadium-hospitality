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

class TenantMiddleware {
    
    /**
     * Verifica accesso stadium per utente corrente
     */
    public static function validateStadiumAccess(?int $requestedStadiumId = null): bool {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            self::sendForbidden('User context not available');
            return false;
        }

        // Super admin può accedere a qualsiasi stadio
        if ($currentUser['role'] === 'super_admin') {
            Logger::debug('Super admin access granted', [
                'user_id' => $currentUser['id'],
                'requested_stadium_id' => $requestedStadiumId
            ]);
            return true;
        }

        // Per utenti stadium-specific, valida l'accesso
        if ($requestedStadiumId && $currentUser['stadium_id'] !== $requestedStadiumId) {
            Logger::warning('Stadium access violation attempt', [
                'user_id' => $currentUser['id'],
                'user_stadium_id' => $currentUser['stadium_id'],
                'requested_stadium_id' => $requestedStadiumId,
                'user_role' => $currentUser['role'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

            self::sendForbidden('Access denied for this stadium');
            return false;
        }

        return true;
    }

    /**
     * Filtra query per stadium dell'utente corrente
     */
    public static function addStadiumFilter(array &$params): void {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            return;
        }

        // Super admin vede tutto, altri solo il proprio stadio
        if ($currentUser['role'] !== 'super_admin' && $currentUser['stadium_id']) {
            $params['stadium_id'] = $currentUser['stadium_id'];
        }
    }

    /**
     * Ottieni stadium ID per query (con fallback per super admin)
     */
    public static function getStadiumIdForQuery(?int $requestedStadiumId = null): ?int {
        $currentUser = $GLOBALS['current_user'] ?? null;
        
        if (!$currentUser) {
            return null;
        }

        // Super admin può richiedere stadium specifico
        if ($currentUser['role'] === 'super_admin') {
            return $requestedStadiumId;
        }

        // Altri utenti possono accedere solo al proprio stadio
        return $currentUser['stadium_id'];
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