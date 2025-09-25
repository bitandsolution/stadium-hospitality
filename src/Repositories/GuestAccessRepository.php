<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/GuestAccessRepository.php     *
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

namespace Hospitality\Repositories;

use Hospitality\Config\Database;
use PDO;
use Exception;

class GuestAccessRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Ottieni stato attuale ospite (ultimo accesso)
     */
    public function getGuestCurrentStatus(int $guestId, int $stadiumId): ?array {
        $sql = "
            SELECT 
                ga.id,
                ga.access_type,
                ga.access_time,
                ga.companions,
                ga.notes,
                u.full_name as hostess_name,
                CASE 
                    WHEN ga.access_type = 'entry' THEN 'checked_in'
                    WHEN ga.access_type = 'exit' THEN 'checked_out'
                    ELSE 'never_accessed'
                END as current_status
            FROM guest_accesses ga
            JOIN users u ON ga.hostess_id = u.id
            WHERE ga.guest_id = ? AND ga.stadium_id = ?
            ORDER BY ga.access_time DESC, ga.id DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$guestId, $stadiumId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Verifica se hostess può accedere alla sala dell'ospite
     * Super admin può accedere a tutte le sale
     */
    public function canHostessAccessGuest(int $hostessId, int $guestId, int $stadiumId): bool {
        // Prima verifica il ruolo dell'utente
        $userSql = "SELECT role FROM users WHERE id = ?";
        $userStmt = $this->db->prepare($userSql);
        $userStmt->execute([$hostessId]);
        $userRole = $userStmt->fetchColumn();
        
        // Super admin può accedere a tutti gli ospiti del proprio stadio
        if ($userRole === 'super_admin') {
            $sql = "
                SELECT 1
                FROM guests g
                WHERE g.id = ? 
                    AND g.stadium_id = ?
                    AND g.is_active = 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$guestId, $stadiumId]);
            
            return $stmt->rowCount() > 0;
        }
        
        // Per hostess, verifica assegnazione sala
        $sql = "
            SELECT 1
            FROM guests g
            JOIN user_room_assignments ura ON g.room_id = ura.room_id
            WHERE g.id = ? 
                AND g.stadium_id = ?
                AND ura.user_id = ? 
                AND ura.is_active = 1
                AND g.is_active = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$guestId, $stadiumId, $hostessId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Registra check-in ospite
     */
    public function recordCheckin(int $guestId, int $hostessId, int $stadiumId, array $data = []): array {
        try {
            $this->db->beginTransaction();

            // 1. Verifica stato attuale
            $currentStatus = $this->getGuestCurrentStatus($guestId, $stadiumId);
            
            if ($currentStatus && $currentStatus['access_type'] === 'entry') {
                throw new Exception('Guest is already checked in');
            }

            // 2. Ottieni info ospite
            $guestInfo = $this->getGuestInfo($guestId, $stadiumId);
            if (!$guestInfo) {
                throw new Exception('Guest not found or inactive');
            }

            // 3. Verifica accesso hostess
            if (!$this->canHostessAccessGuest($hostessId, $guestId, $stadiumId)) {
                throw new Exception('Hostess does not have access to this guest\'s room');
            }

            // 4. Registra l'accesso - SENZA created_at
            $deviceInfo = $this->collectDeviceInfo();
            
            $stmt = $this->db->prepare("
                INSERT INTO guest_accesses (
                    stadium_id, guest_id, hostess_id, access_type, 
                    access_time, notes, device_info
                ) VALUES (?, ?, ?, 'entry', NOW(), ?, ?)
            ");

            $stmt->execute([
                $stadiumId,
                $guestId,
                $hostessId,
                $data['notes'] ?? null,
                json_encode($deviceInfo)
            ]);

            $accessId = $this->db->lastInsertId();

            // 5. Ottieni dati completi per response
            $result = $this->getAccessRecord($accessId);

            $this->db->commit();

            return [
                'access_id' => (int)$accessId,
                'guest_id' => $guestId,
                'guest_name' => $guestInfo['full_name'],
                'room_name' => $guestInfo['room_name'],
                'table_number' => $guestInfo['table_number'],
                'checkin_time' => $result['access_time'],
                'hostess_name' => $result['hostess_name'],
                'previous_status' => $currentStatus ? $currentStatus['current_status'] : 'never_accessed',
                'companions' => 0, // Sempre 0 per ora (fino a quando non aggiungiamo la colonna)
                'notes' => $data['notes'] ?? null
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Registra check-out ospite
     */
    public function recordCheckout(int $guestId, int $hostessId, int $stadiumId, array $data = []): array {
        try {
            $this->db->beginTransaction();

            // 1. Verifica stato attuale
            $currentStatus = $this->getGuestCurrentStatus($guestId, $stadiumId);
            
            if (!$currentStatus || $currentStatus['access_type'] !== 'entry') {
                throw new Exception('Guest is not currently checked in');
            }

            // 2. Ottieni info ospite
            $guestInfo = $this->getGuestInfo($guestId, $stadiumId);
            if (!$guestInfo) {
                throw new Exception('Guest not found or inactive');
            }

            // 3. Verifica accesso hostess
            if (!$this->canHostessAccessGuest($hostessId, $guestId, $stadiumId)) {
                throw new Exception('Hostess does not have access to this guest\'s room');
            }

            // 4. Registra l'uscita - SENZA created_at
            $deviceInfo = $this->collectDeviceInfo();
            
            $stmt = $this->db->prepare("
                INSERT INTO guest_accesses (
                    stadium_id, guest_id, hostess_id, access_type, 
                    access_time, notes, device_info
                ) VALUES (?, ?, ?, 'exit', NOW(), ?, ?)
            ");

            $stmt->execute([
                $stadiumId,
                $guestId,
                $hostessId,
                $data['notes'] ?? null,
                json_encode($deviceInfo)
            ]);

            $accessId = $this->db->lastInsertId();

            // 5. Calcola durata permanenza
            $checkinTime = new \DateTime($currentStatus['access_time']);
            $checkoutTime = new \DateTime();
            $duration = $checkoutTime->diff($checkinTime);
            $durationMinutes = ($duration->h * 60) + $duration->i;

            // 6. Ottieni dati completi per response
            $result = $this->getAccessRecord($accessId);

            $this->db->commit();

            return [
                'access_id' => (int)$accessId,
                'guest_id' => $guestId,
                'guest_name' => $guestInfo['full_name'],
                'room_name' => $guestInfo['room_name'],
                'checkout_time' => $result['access_time'],
                'checkin_time' => $currentStatus['access_time'],
                'duration_minutes' => $durationMinutes,
                'hostess_name' => $result['hostess_name'],
                'notes' => $data['notes'] ?? null
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Ottieni storico completo accessi ospite
     */
    public function getGuestAccessHistory(int $guestId, int $stadiumId): array {
        // Info base ospite
        $guestInfo = $this->getGuestInfo($guestId, $stadiumId);
        if (!$guestInfo) {
            throw new Exception('Guest not found');
        }

        // Storico accessi
        $sql = "
            SELECT 
                ga.id,
                ga.access_type,
                ga.access_time,
                ga.companions,
                ga.notes,
                u.full_name as hostess_name,
                ga.device_info
            FROM guest_accesses ga
            JOIN users u ON ga.hostess_id = u.id
            WHERE ga.guest_id = ? AND ga.stadium_id = ?
            ORDER BY ga.access_time DESC, ga.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$guestId, $stadiumId]);
        $accessHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcola statistiche
        $totalVisits = 0;
        $totalDurationMinutes = 0;
        $currentStatus = 'never_accessed';

        if (!empty($accessHistory)) {
            $currentStatus = $accessHistory[0]['access_type'] === 'entry' ? 'checked_in' : 'checked_out';
            
            // Calcola visite complete (entry seguita da exit)
            for ($i = 0; $i < count($accessHistory) - 1; $i += 2) {
                if ($accessHistory[$i]['access_type'] === 'exit' && 
                    $accessHistory[$i + 1]['access_type'] === 'entry') {
                    
                    $totalVisits++;
                    
                    // Calcola durata
                    $entryTime = new \DateTime($accessHistory[$i + 1]['access_time']);
                    $exitTime = new \DateTime($accessHistory[$i]['access_time']);
                    $duration = $exitTime->diff($entryTime);
                    $totalDurationMinutes += ($duration->h * 60) + $duration->i;
                }
            }
        }

        return [
            'guest' => $guestInfo,
            'access_history' => $accessHistory,
            'current_status' => $currentStatus,
            'total_visits' => $totalVisits,
            'total_duration_minutes' => $totalDurationMinutes
        ];
    }

    /**
     * Ottieni info ospite per check-in/out
     */
    private function getGuestInfo(int $guestId, int $stadiumId): ?array {
        $sql = "
            SELECT 
                g.id,
                CONCAT(g.last_name, ', ', g.first_name) as full_name,
                g.table_number,
                g.vip_level,
                hr.name as room_name,
                e.name as event_name,
                e.event_date
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
            WHERE g.id = ? AND g.stadium_id = ? AND g.is_active = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$guestId, $stadiumId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ottieni record accesso specifico
     */
    private function getAccessRecord(int $accessId): array {
        $sql = "
            SELECT 
                ga.access_time,
                u.full_name as hostess_name
            FROM guest_accesses ga
            JOIN users u ON ga.hostess_id = u.id
            WHERE ga.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$accessId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Raccogli info dispositivo per audit trail
     */
    private function collectDeviceInfo(): array {
        return [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => date('c'),
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
    }

    /**
     * Ottieni statistiche accessi per periodo
     */
    public function getAccessStats(int $stadiumId, ?int $roomId = null, ?string $date = null): array {
        $sql = "
            SELECT 
                COUNT(CASE WHEN access_type = 'entry' THEN 1 END) as total_checkins,
                COUNT(CASE WHEN access_type = 'exit' THEN 1 END) as total_checkouts,
                COUNT(DISTINCT guest_id) as unique_guests,
                COUNT(DISTINCT hostess_id) as active_hostesses
            FROM guest_accesses
            WHERE stadium_id = ?
        ";

        $params = [$stadiumId];

        if ($roomId) {
            $sql .= " AND guest_id IN (
                SELECT id FROM guests WHERE room_id = ? AND stadium_id = ?
            )";
            $params[] = $roomId;
            $params[] = $stadiumId;
        }

        if ($date) {
            $sql .= " AND DATE(access_time) = ?";
            $params[] = $date;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}