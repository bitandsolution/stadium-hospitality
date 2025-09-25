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

namespace Hospitality\Services;

use Hospitality\Config\Database;
use Hospitality\Utils\Logger;

class LogService {
    
    public static function log(
        string $operationType,
        string $description,
        array $additionalData = [],
        ?int $userId = null,
        ?int $stadiumId = null,
        ?string $tableAffected = null,
        ?int $recordId = null
    ): void {
        try {
            $db = Database::getInstance()->getConnection();

            // Get current user context if not provided
            if (!$userId && isset($GLOBALS['current_user'])) {
                $userId = $GLOBALS['current_user']['id'];
                $stadiumId = $stadiumId ?? $GLOBALS['current_user']['stadium_id'];
            }

            // Collect request data
            $requestData = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'additional' => $additionalData
            ];

            $stmt = $db->prepare("
                INSERT INTO system_logs (
                    stadium_id, user_id, operation_type, operation_description,
                    table_affected, record_id, ip_address, user_agent,
                    device_type, request_data, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $deviceType = self::detectDeviceType();
            
            $stmt->execute([
                $stadiumId,
                $userId,
                $operationType,
                $description,
                $tableAffected,
                $recordId,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $deviceType,
                json_encode($requestData)
            ]);

        } catch (\Exception $e) {
            // Non bloccare l'applicazione se il logging fallisce
            Logger::error('Failed to write system log', [
                'operation' => $operationType,
                'error' => $e->getMessage()
            ]);
        }
    }

    private static function detectDeviceType(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (stripos($userAgent, 'mobile') !== false || 
            stripos($userAgent, 'android') !== false ||
            stripos($userAgent, 'iphone') !== false) {
            return 'mobile';
        }
        
        if (stripos($userAgent, 'hospitality-pwa') !== false) {
            return 'pwa';
        }
        
        return 'web';
    }
}