<?php
/*********************************************************
*                                                        *
*   FILE: src/Utils/JWT.php - JWT Utility Class          *
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

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Hospitality\Config\Database;
use Hospitality\Utils\Logger;
use Exception;

class JWT {
    private static ?string $secret = null;
    private static string $algorithm = 'HS256';
    private static int $expiry = 3600;
    private static int $refreshExpiry = 604800;

    private static function init(): void {
        if (self::$secret === null) {
            self::$secret = $_ENV['JWT_SECRET'] ?? 'fallback-secret-change-in-production';
            self::$algorithm = $_ENV['JWT_ALGORITHM'] ?? 'HS256';
            self::$expiry = (int)($_ENV['JWT_EXPIRY'] ?? 3600);
            self::$refreshExpiry = (int)($_ENV['JWT_REFRESH_EXPIRY'] ?? 604800);
        }
    }

    public static function generateAccessToken(array $payload): string {
        self::init();
        
        $issuedAt = time();
        $expiresAt = $issuedAt + self::$expiry;

        $token = [
            'iss' => 'hospitality-api',
            'aud' => 'hospitality-app',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'user_id' => $payload['user_id'],
            'stadium_id' => $payload['stadium_id'],
            'role' => $payload['role'],
            'permissions' => $payload['permissions'] ?? []
        ];

        return FirebaseJWT::encode($token, self::$secret, self::$algorithm);
    }

    public static function generateRefreshToken(int $userId, ?int $stadiumId): string {
        self::init();
        
        $issuedAt = time();
        $expiresAt = $issuedAt + self::$refreshExpiry;

        $token = [
            'iss' => 'hospitality-api',
            'aud' => 'hospitality-refresh',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'user_id' => $userId,
            'stadium_id' => $stadiumId,
            'type' => 'refresh'
        ];

        return FirebaseJWT::encode($token, self::$secret, self::$algorithm);
    }

    public static function validateToken(string $token): ?object {
        try {
            self::init();
            
            // Check if token is blacklisted first
            if (self::isBlacklisted($token)) {
                Logger::warning('Blacklisted token attempted', [
                    'token_hash' => hash('sha256', $token)
                ]);
                return null;
            }
            
            // Decode using Firebase JWT v6 syntax
            $decoded = FirebaseJWT::decode($token, new Key(self::$secret, self::$algorithm));
            
            return $decoded;
            
        } catch (Exception $e) {
            Logger::warning('JWT validation failed', [
                'error' => $e->getMessage(),
                'token_hash' => hash('sha256', $token)
            ]);
            return null;
        }
    }

    public static function blacklistToken(string $token, int $userId, ?int $stadiumId, string $reason = 'logout'): bool {
        try {
            $db = Database::getInstance()->getConnection();
            
            $tokenHash = hash('sha256', $token);
            $decoded = self::validateTokenWithoutBlacklistCheck($token);
            
            if (!$decoded) {
                return false;
            }

            $stmt = $db->prepare("
                INSERT INTO jwt_blacklist (stadium_id, token_hash, user_id, expires_at, reason) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    reason = VALUES(reason),
                    blacklisted_at = CURRENT_TIMESTAMP
            ");

            $expiresAt = date('Y-m-d H:i:s', $decoded->exp);
            
            return $stmt->execute([
                $stadiumId,
                $tokenHash,
                $userId,
                $expiresAt,
                $reason
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to blacklist token', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return false;
        }
    }

    private static function isBlacklisted(string $token): bool {
        try {
            $db = Database::getInstance()->getConnection();
            $tokenHash = hash('sha256', $token);

            $stmt = $db->prepare("
                SELECT id FROM jwt_blacklist 
                WHERE token_hash = ? AND expires_at > NOW()
            ");
            
            $stmt->execute([$tokenHash]);
            
            return $stmt->rowCount() > 0;

        } catch (Exception $e) {
            Logger::error('Failed to check blacklist', [
                'error' => $e->getMessage(),
                'token_hash' => hash('sha256', $token)
            ]);
            // In case of error, assume token is valid to prevent lockouts
            return false;
        }
    }

    private static function validateTokenWithoutBlacklistCheck(string $token): ?object {
        try {
            self::init();
            return FirebaseJWT::decode($token, new Key(self::$secret, self::$algorithm));
        } catch (Exception $e) {
            return null;
        }
    }

    public static function cleanupExpiredTokens(): int {
        try {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("DELETE FROM jwt_blacklist WHERE expires_at <= NOW()");
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            
            if ($deletedCount > 0) {
                Logger::info("Cleaned up expired JWT tokens", ['count' => $deletedCount]);
            }
            
            return $deletedCount;

        } catch (Exception $e) {
            Logger::error('Failed to cleanup expired tokens', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
