<?php
/*********************************************************
*                                                        *
*   FILE: src/Utils/AuthHelper.php                       *
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

namespace Hospitality\Utils;

class AuthHelper {
    
    /**
     * Estrae token dall'header Authorization gestendo entrambi i formati
     */
    public static function extractTokenFromHeader(): ?string {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        
        if (!$authHeader) {
            return null;
        }
        
        // Handle both "Bearer TOKEN" and just "TOKEN" formats
        $token = $authHeader;
        
        // Remove "Bearer " prefix if present
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }
        
        return $token;
    }
    
    /**
     * Valida token JWT e restituisce i dati decodificati
     */
    public static function validateToken(?string $token = null): ?object {
        if (!$token) {
            $token = self::extractTokenFromHeader();
        }
        
        if (!$token) {
            return null;
        }
        
        try {
            $secret = $_ENV['JWT_SECRET'] ?? 'hospitality-test-secret-key-change-in-production-32chars-min';
            return \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
        } catch (\Exception $e) {
            error_log('JWT validation failed: ' . $e->getMessage());
            return null;
        }
    }
}
?>