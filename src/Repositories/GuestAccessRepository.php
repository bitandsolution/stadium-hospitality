<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/GuestAccessRepository.php     *
*   Data Access Layer per accessi ospiti                 *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
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
     * Crea nuovo record di accesso (entry o exit)
     */
    public function createAccess(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO guest_accesses (
                guest_id, hostess_id, stadium_id, room_id, event_id,
                access_type, access_time, device_type, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
        ");

        $stmt->execute([
            $data['guest_id'],
            $data['hostess_id'],
            $data['stadium_id'],
            $data['room_id'],
            $data['event_id'],
            $data['access_type'], // 'entry' or 'exit'
            $data['device_type'] ?? 'web'
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Ottieni ultimo accesso per un ospite
     */
    public function getLastAccess(int $guestId): ?array {
        $stmt = $this->db->prepare("
            SELECT 
                ga.*,
                u.full_name as hostess_name,
                u.username as hostess_username
            FROM guest_accesses ga
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE ga.guest_id = ?
            ORDER BY ga.access_time DESC
            LIMIT 1
        ");

        $stmt->execute([$guestId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Ottieni storico accessi completo per ospite
     */
    public function getGuestAccessHistory(int $guestId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT 
                ga.id,
                ga.access_type,
                ga.access_time,
                ga.device_type,
                u.full_name as hostess_name,
                hr.name as room_name,
                e.name as event_name
            FROM guest_accesses ga
            LEFT JOIN users u ON ga.hostess_id = u.id
            LEFT JOIN hospitality_rooms hr ON ga.room_id = hr.id
            LEFT JOIN events e ON ga.event_id = e.id
            WHERE ga.guest_id = ?
            ORDER BY ga.access_time DESC
            LIMIT ?
        ");

        $stmt->bindValue(1, $guestId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ottieni ospiti attualmente presenti in una sala
     * (ultimo accesso = entry senza exit successivo)
     */
    public function getCurrentGuestsInRoom(int $roomId): array {
        $stmt = $this->db->prepare("
            SELECT 
                g.id as guest_id,
                g.first_name,
                g.last_name,
                g.vip_level,
                g.table_number,
                ga.access_time as checkin_time,
                u.full_name as checked_in_by,
                TIMESTAMPDIFF(MINUTE, ga.access_time, NOW()) as minutes_in_room
            FROM guests g
            INNER JOIN guest_accesses ga ON g.id = ga.guest_id
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE g.room_id = ?
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id)
                    FROM guest_accesses ga2
                    WHERE ga2.guest_id = g.id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM guest_accesses ga3
                    WHERE ga3.guest_id = g.id
                        AND ga3.access_type = 'exit'
                        AND ga3.access_time > ga.access_time
                )
            ORDER BY ga.access_time DESC
        ");

        $stmt->execute([$roomId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se una hostess può accedere a una specifica sala
     */
    public function hostessCanAccessRoom(int $hostessId, int $roomId): bool {
        $stmt = $this->db->prepare("
            SELECT 1 
            FROM user_room_assignments 
            WHERE user_id = ? AND room_id = ? AND is_active = 1
        ");

        $stmt->execute([$hostessId, $roomId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Statistiche accessi per evento
     */
    public function getEventAccessStats(int $eventId): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN ga.access_type = 'entry' THEN ga.guest_id END) as total_checkins,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'exit' THEN ga.guest_id END) as total_checkouts,
                COUNT(DISTINCT ga.guest_id) as unique_guests,
                MIN(ga.access_time) as first_access,
                MAX(ga.access_time) as last_access,
                AVG(TIMESTAMPDIFF(MINUTE, 
                    (SELECT ga2.access_time FROM guest_accesses ga2 
                     WHERE ga2.guest_id = ga.guest_id AND ga2.access_type = 'entry' 
                     ORDER BY ga2.access_time DESC LIMIT 1),
                    (SELECT ga3.access_time FROM guest_accesses ga3 
                     WHERE ga3.guest_id = ga.guest_id AND ga3.access_type = 'exit' 
                     ORDER BY ga3.access_time DESC LIMIT 1)
                )) as avg_duration_minutes
            FROM guest_accesses ga
            WHERE ga.event_id = ?
        ");

        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Statistiche accessi per sala
     */
    public function getRoomAccessStats(int $roomId, ?int $eventId = null): array {
        $sql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN ga.access_type = 'entry' THEN ga.guest_id END) as total_checkins,
                COUNT(DISTINCT CASE WHEN ga.access_type = 'exit' THEN ga.guest_id END) as total_checkouts,
                COUNT(DISTINCT ga.hostess_id) as hostesses_involved,
                DATE(ga.access_time) as access_date,
                HOUR(ga.access_time) as access_hour,
                COUNT(*) as hourly_count
            FROM guest_accesses ga
            WHERE ga.room_id = ?
        ";

        $params = [$roomId];

        if ($eventId) {
            $sql .= " AND ga.event_id = ?";
            $params[] = $eventId;
        }

        $sql .= " GROUP BY DATE(ga.access_time), HOUR(ga.access_time)
                  ORDER BY access_date DESC, access_hour DESC
                  LIMIT 24";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Performance hostess - conteggio check-in per periodo
     */
    public function getHostessPerformance(int $hostessId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT 
                DATE(ga.access_time) as date,
                COUNT(CASE WHEN ga.access_type = 'entry' THEN 1 END) as checkins,
                COUNT(CASE WHEN ga.access_type = 'exit' THEN 1 END) as checkouts,
                COUNT(DISTINCT ga.guest_id) as unique_guests,
                COUNT(DISTINCT ga.room_id) as rooms_worked
            FROM guest_accesses ga
            WHERE ga.hostess_id = ?
                AND DATE(ga.access_time) BETWEEN ? AND ?
            GROUP BY DATE(ga.access_time)
            ORDER BY date DESC
        ");

        $stmt->execute([$hostessId, $startDate, $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Accessi in tempo reale per dashboard
     */
    public function getRecentAccesses(int $stadiumId, int $minutes = 30, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT 
                ga.id,
                ga.access_type,
                ga.access_time,
                g.first_name,
                g.last_name,
                g.vip_level,
                hr.name as room_name,
                u.full_name as hostess_name,
                TIMESTAMPDIFF(SECOND, ga.access_time, NOW()) as seconds_ago
            FROM guest_accesses ga
            INNER JOIN guests g ON ga.guest_id = g.id
            LEFT JOIN hospitality_rooms hr ON ga.room_id = hr.id
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE ga.stadium_id = ?
                AND ga.access_time >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY ga.access_time DESC
            LIMIT ?
        ");

        $stmt->bindValue(1, $stadiumId, PDO::PARAM_INT);
        $stmt->bindValue(2, $minutes, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Conta accessi totali per guest
     */
    public function getGuestAccessCount(int $guestId): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(CASE WHEN access_type = 'entry' THEN 1 END) as total_entries,
                COUNT(CASE WHEN access_type = 'exit' THEN 1 END) as total_exits,
                MIN(access_time) as first_access,
                MAX(access_time) as last_access
            FROM guest_accesses
            WHERE guest_id = ?
        ");

        $stmt->execute([$guestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Export accessi per periodo (per report)
     */
    public function exportAccessesForPeriod(
        int $stadiumId,
        string $startDate,
        string $endDate,
        ?int $roomId = null,
        ?int $eventId = null
    ): array {
        $sql = "
            SELECT 
                ga.id,
                ga.access_time,
                ga.access_type,
                g.first_name as guest_first_name,
                g.last_name as guest_last_name,
                g.company_name,
                g.vip_level,
                g.table_number,
                hr.name as room_name,
                e.name as event_name,
                e.event_date,
                u.full_name as hostess_name,
                ga.device_type
            FROM guest_accesses ga
            INNER JOIN guests g ON ga.guest_id = g.id
            LEFT JOIN hospitality_rooms hr ON ga.room_id = hr.id
            LEFT JOIN events e ON ga.event_id = e.id
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE ga.stadium_id = ?
                AND DATE(ga.access_time) BETWEEN ? AND ?
        ";

        $params = [$stadiumId, $startDate, $endDate];

        if ($roomId) {
            $sql .= " AND ga.room_id = ?";
            $params[] = $roomId;
        }

        if ($eventId) {
            $sql .= " AND ga.event_id = ?";
            $params[] = $eventId;
        }

        $sql .= " ORDER BY ga.access_time DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}