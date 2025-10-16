<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/StatisticsRepository.php      *
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

class StatisticsRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Statistiche accessi per evento
     */
    public function getAccessByEvent(int $stadiumId, string $dateFrom, string $dateTo): array {
        $sql = "
            SELECT 
                e.id as event_id,
                e.name as event_name,
                e.event_date,
                e.opponent_team,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NOT NULL THEN g.id 
                END) as checked_in,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NULL THEN g.id 
                END) as not_checked_in,
                ROUND(
                    (COUNT(DISTINCT CASE WHEN ga.id IS NOT NULL THEN g.id END) * 100.0) / 
                    NULLIF(COUNT(DISTINCT g.id), 0), 
                    1
                ) as check_in_percentage
            FROM events e
            LEFT JOIN guests g ON e.id = g.event_id AND g.is_active = 1
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE e.stadium_id = :stadium_id
                AND e.is_active = 1
                AND e.event_date BETWEEN :date_from AND :date_to
            GROUP BY e.id, e.name, e.event_date, e.opponent_team
            ORDER BY e.event_date ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'stadium_id' => $stadiumId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Statistiche accessi per sala hospitality - FIXED VERSION
     */
    public function getAccessByRoom(int $stadiumId, ?int $eventId, string $dateFrom, string $dateTo): array {
        $sql = "
            SELECT 
                hr.id as room_id,
                hr.name as room_name,
                hr.capacity,
                hr.floor,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NOT NULL THEN g.id 
                END) as checked_in,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NULL THEN g.id 
                END) as not_checked_in,
                ROUND(
                    COALESCE(
                        (COUNT(DISTINCT CASE WHEN ga.id IS NOT NULL THEN g.id END) * 100.0) / 
                        NULLIF(COUNT(DISTINCT g.id), 0),
                        0
                    ),
                    1
                ) as check_in_percentage,
                ROUND(
                    COALESCE(
                        (COUNT(DISTINCT g.id) * 100.0) / NULLIF(hr.capacity, 0),
                        0
                    ),
                    1
                ) as occupancy_percentage
            FROM hospitality_rooms hr
            LEFT JOIN guests g ON hr.id = g.room_id 
                AND g.is_active = 1
                AND g.stadium_id = :stadium_id_guests
                AND g.event_id IN (
                    SELECT id FROM events 
                    WHERE stadium_id = :stadium_id_events
                    AND event_date BETWEEN :date_from AND :date_to
                    AND is_active = 1
                    " . ($eventId ? "AND id = :event_id" : "") . "
                )
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE hr.stadium_id = :stadium_id_rooms
                AND hr.is_active = 1
            GROUP BY hr.id, hr.name, hr.capacity, hr.floor
            ORDER BY hr.name ASC
        ";

        $params = [
            'stadium_id_guests' => $stadiumId,
            'stadium_id_events' => $stadiumId,
            'stadium_id_rooms' => $stadiumId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];

        if ($eventId) {
            $params['event_id'] = $eventId;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log('[StatisticsRepository] getAccessByRoom returned ' . count($results) . ' rooms');
            
            return $results;
            
        } catch (\Exception $e) {
            error_log('[StatisticsRepository] getAccessByRoom error: ' . $e->getMessage());
            error_log('[StatisticsRepository] Params: ' . json_encode($params));
            throw $e;
        }
    }

    /**
     * Dati dettagliati per export Excel
     */
    public function getDetailedAccessData(int $stadiumId, ?int $eventId, string $dateFrom, string $dateTo): array {
        $sql = "
            SELECT 
                e.name as event_name,
                e.event_date,
                g.last_name,
                g.first_name,
                hr.name as room_name,
                CASE 
                    WHEN ga.id IS NOT NULL THEN 'Entrato'
                    ELSE 'Non entrato'
                END as status,
                ga.access_time,
                u.full_name as checked_by
            FROM guests g
            JOIN events e ON g.event_id = e.id
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE g.stadium_id = :stadium_id
                AND g.is_active = 1
                AND e.event_date BETWEEN :date_from AND :date_to
                AND e.is_active = 1
                " . ($eventId ? "AND e.id = :event_id" : "") . "
            ORDER BY e.event_date DESC, g.last_name ASC, g.first_name ASC
        ";

        $params = [
            'stadium_id' => $stadiumId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];

        if ($eventId) {
            $params['event_id'] = $eventId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Riepilogo generale statistiche
     */
    public function getSummary(int $stadiumId, string $dateFrom, string $dateTo): array {
        $sql = "
            SELECT 
                COUNT(DISTINCT e.id) as total_events,
                COUNT(DISTINCT g.id) as total_guests,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NOT NULL THEN g.id 
                END) as total_checked_in,
                COUNT(DISTINCT CASE 
                    WHEN ga.id IS NULL THEN g.id 
                END) as total_not_checked_in,
                COUNT(DISTINCT hr.id) as total_rooms_used,
                COUNT(DISTINCT ga.hostess_id) as total_hostesses_active,
                ROUND(
                    (COUNT(DISTINCT CASE WHEN ga.id IS NOT NULL THEN g.id END) * 100.0) / 
                    NULLIF(COUNT(DISTINCT g.id), 0), 
                    1
                ) as overall_check_in_rate,
                COUNT(DISTINCT CASE 
                    WHEN g.vip_level = 'ultra_vip' THEN g.id 
                END) as ultra_vip_count,
                COUNT(DISTINCT CASE 
                    WHEN g.vip_level = 'vip' THEN g.id 
                END) as vip_count,
                COUNT(DISTINCT CASE 
                    WHEN g.vip_level = 'premium' THEN g.id 
                END) as premium_count,
                COUNT(DISTINCT CASE 
                    WHEN g.vip_level = 'standard' THEN g.id 
                END) as standard_count
            FROM events e
            LEFT JOIN guests g ON e.id = g.event_id AND g.is_active = 1
            LEFT JOIN hospitality_rooms hr ON g.room_id = hr.id
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE e.stadium_id = :stadium_id
                AND e.is_active = 1
                AND e.event_date BETWEEN :date_from AND :date_to
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'stadium_id' => $stadiumId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}