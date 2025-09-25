<?php
/*********************************************************
*                                                        *
*   FILE: src/Repositories/GuestRepository.php           *
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

class GuestRepository {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Ricerca ultra-veloce ospiti con filtri multipli
     * Target performance: <100ms su 1500+ record
     */
    public function searchGuests(array $filters = []): array {
        $startTime = microtime(true);

        // Base query ottimizzata con indici
        $sql = "
            SELECT 
                g.id,
                g.first_name,
                g.last_name,
                g.table_number,
                g.seat_number,
                g.vip_level,
                g.company_name,
                g.contact_email,
                g.contact_phone,
                g.notes,
                hr.name as room_name,
                hr.id as room_id,
                e.name as event_name,
                e.event_date,
                CASE 
                    WHEN ga.id IS NOT NULL THEN 'checked_in'
                    ELSE 'not_checked_in'
                END as access_status,
                ga.access_time as last_access_time,
                u.full_name as checked_in_by
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            LEFT JOIN users u ON ga.hostess_id = u.id
            WHERE g.is_active = 1
        ";

        $params = [];
        $conditions = [];

        // Filtro per stadio (sempre richiesto per multi-tenancy)
        if (!empty($filters['stadium_id'])) {
            $conditions[] = "g.stadium_id = :stadium_id";
            $params['stadium_id'] = $filters['stadium_id'];
        }

        // Filtro per sala (per hostess)
        if (!empty($filters['room_ids'])) {
            if (is_array($filters['room_ids'])) {
                $placeholders = [];
                foreach ($filters['room_ids'] as $i => $roomId) {
                    $placeholders[] = ":room_id_$i";
                    $params["room_id_$i"] = $roomId;
                }
                $conditions[] = "g.room_id IN (" . implode(',', $placeholders) . ")";
            } else {
                $conditions[] = "g.room_id = :room_id";
                $params['room_id'] = $filters['room_ids'];
            }
        }

        // Ricerca per nome/cognome (ottimizzata con indici prefix)
        if (!empty($filters['search_query'])) {
            $searchTerms = explode(' ', trim($filters['search_query']));
            $searchConditions = [];
            
            foreach ($searchTerms as $i => $term) {
                if (strlen($term) >= 2) {
                    $searchConditions[] = "(
                        g.last_name LIKE :search_term_{$i}_1 OR 
                        g.first_name LIKE :search_term_{$i}_2 OR 
                        g.company_name LIKE :search_term_{$i}_3
                    )";
                    $params["search_term_{$i}_1"] = $term . '%';
                    $params["search_term_{$i}_2"] = $term . '%';
                    $params["search_term_{$i}_3"] = '%' . $term . '%';
                }
            }
            
            if (!empty($searchConditions)) {
                $conditions[] = "(" . implode(' AND ', $searchConditions) . ")";
            }
        }

        // Filtro per evento
        if (!empty($filters['event_id'])) {
            $conditions[] = "g.event_id = :event_id";
            $params['event_id'] = $filters['event_id'];
        }

        // Filtro per stato accesso
        if (!empty($filters['access_status'])) {
            if ($filters['access_status'] === 'checked_in') {
                $conditions[] = "ga.id IS NOT NULL";
            } elseif ($filters['access_status'] === 'not_checked_in') {
                $conditions[] = "ga.id IS NULL";
            }
        }

        // Filtro per livello VIP
        if (!empty($filters['vip_level'])) {
            $conditions[] = "g.vip_level = :vip_level";
            $params['vip_level'] = $filters['vip_level'];
        }

        // Aggiungi condizioni WHERE
        if (!empty($conditions)) {
            $sql .= " AND " . implode(' AND ', $conditions);
        }

        // Ordinamento ottimizzato (usa indici)
        $sql .= " ORDER BY g.last_name, g.first_name";

        // Limite per performance
        $limit = min((int)($filters['limit'] ?? 100), 500); // Max 500 risultati
        $offset = max((int)($filters['offset'] ?? 0), 0);
        
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        try {
            $stmt = $this->db->prepare($sql);
            
            // Bind parameters con tipi corretti
            foreach ($params as $key => $value) {
                if ($key === 'limit' || $key === 'offset' || strpos($key, '_id') !== false) {
                    $stmt->bindValue(":$key", (int)$value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Performance logging
            $executionTime = (microtime(true) - $startTime) * 1000;
            error_log("Guest search completed in " . round($executionTime, 2) . "ms, found " . count($results) . " results");

            // Log slow queries per ottimizzazione
            if ($executionTime > 100) {
                error_log("SLOW QUERY ALERT: Guest search took " . round($executionTime, 2) . "ms");
                error_log("Query: " . $sql);
                error_log("Params: " . json_encode($params));
            }

            return [
                'results' => $results,
                'total_found' => count($results),
                'execution_time_ms' => round($executionTime, 2),
                'has_more' => count($results) === $limit
            ];

        } catch (Exception $e) {
            error_log("Guest search failed: " . $e->getMessage());
            throw new Exception("Search failed: " . $e->getMessage());
        }
    }

    /**
     * Trova ospite per ID con dettagli completi
     */
    public function findById(int $guestId, ?int $stadiumId = null): ?array {
        $sql = "
            SELECT 
                g.*,
                hr.name as room_name,
                hr.capacity as room_capacity,
                e.name as event_name,
                e.event_date,
                e.event_time,
                s.name as stadium_name
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
            JOIN stadiums s ON g.stadium_id = s.id
            WHERE g.id = :guest_id AND g.is_active = 1
        ";

        $params = ['guest_id' => $guestId];

        // Multi-tenant security
        if ($stadiumId !== null) {
            $sql .= " AND g.stadium_id = :stadium_id";
            $params['stadium_id'] = $stadiumId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $guest = $stmt->fetch(PDO::FETCH_ASSOC);
        return $guest ?: null;
    }

    /**
     * Ottieni statistiche ospiti per una sala
     */
    public function getRoomStats(int $roomId, ?int $eventId = null): array {
        $sql = "
            SELECT 
                COUNT(*) as total_guests,
                COUNT(CASE WHEN g.vip_level = 'ultra_vip' THEN 1 END) as ultra_vip_count,
                COUNT(CASE WHEN g.vip_level = 'vip' THEN 1 END) as vip_count,
                COUNT(CASE WHEN g.vip_level = 'premium' THEN 1 END) as premium_count,
                COUNT(CASE WHEN g.vip_level = 'standard' THEN 1 END) as standard_count,
                COUNT(ga.id) as checked_in_count,
                (COUNT(*) - COUNT(ga.id)) as not_checked_in_count
            FROM guests g
            LEFT JOIN guest_accesses ga ON g.id = ga.guest_id 
                AND ga.access_type = 'entry'
                AND ga.id = (
                    SELECT MAX(ga2.id) 
                    FROM guest_accesses ga2 
                    WHERE ga2.guest_id = g.id AND ga2.access_type = 'entry'
                )
            WHERE g.room_id = :room_id 
                AND g.is_active = 1
        ";

        $params = ['room_id' => $roomId];

        if ($eventId) {
            $sql .= " AND g.event_id = :event_id";
            $params['event_id'] = $eventId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ottieni lista ospiti per hostess (con sale assegnate)
     */
    public function getGuestsForHostess(int $hostessId, array $filters = []): array {
        // Prima ottieni le sale assegnate alla hostess
        $roomsQuery = "
            SELECT ura.room_id 
            FROM user_room_assignments ura 
            WHERE ura.user_id = :hostess_id AND ura.is_active = 1
        ";

        $stmt = $this->db->prepare($roomsQuery);
        $stmt->execute(['hostess_id' => $hostessId]);
        $assignedRooms = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($assignedRooms)) {
            return [
                'results' => [],
                'total_found' => 0,
                'execution_time_ms' => 0,
                'has_more' => false,
                'message' => 'No rooms assigned to this hostess'
            ];
        }

        // Aggiungi le sale ai filtri
        $filters['room_ids'] = $assignedRooms;

        return $this->searchGuests($filters);
    }

    /**
     * Ricerca rapida per auto-completamento (super veloce)
     */
    public function quickSearch(string $query, int $stadiumId, ?array $roomIds = null, int $limit = 10): array {
        $startTime = microtime(true);

        // Query super ottimizzata solo per i campi essenziali
        $sql = "
            SELECT 
                g.id,
                CONCAT(g.last_name, ', ', g.first_name) as full_name,
                g.table_number,
                hr.name as room_name
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            WHERE g.stadium_id = :stadium_id 
                AND g.is_active = 1
                AND (
                    g.last_name LIKE :query_prefix OR 
                    g.first_name LIKE :query_prefix
                )
        ";

        $params = [
            'stadium_id' => $stadiumId,
            'query_prefix' => $query . '%'
        ];

        // Filtro sale se specificato
        if ($roomIds && !empty($roomIds)) {
            $placeholders = [];
            foreach ($roomIds as $i => $roomId) {
                $placeholders[] = ":room_id_$i";
                $params["room_id_$i"] = $roomId;
            }
            $sql .= " AND g.room_id IN (" . implode(',', $placeholders) . ")";
        }

        $sql .= " ORDER BY g.last_name, g.first_name LIMIT :limit";
        $params['limit'] = $limit;

        $stmt = $this->db->prepare($sql);
        
        // Bind con tipi corretti
        foreach ($params as $key => $value) {
            if ($key === 'limit' || $key === 'stadium_id' || strpos($key, 'room_id_') === 0) {
                $stmt->bindValue(":$key", (int)$value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $executionTime = (microtime(true) - $startTime) * 1000;
        
        return [
            'suggestions' => $results,
            'execution_time_ms' => round($executionTime, 2)
        ];
    }

    /**
     * Conta ospiti totali per filtri
     */
    public function countGuests(array $filters = []): int {
        // Usa la stessa logica di searchGuests ma con COUNT
        $sql = "
            SELECT COUNT(DISTINCT g.id)
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
            WHERE g.is_active = 1
        ";

        $params = [];
        $conditions = [];

        // Applica gli stessi filtri di searchGuests
        if (!empty($filters['stadium_id'])) {
            $conditions[] = "g.stadium_id = :stadium_id";
            $params['stadium_id'] = $filters['stadium_id'];
        }

        if (!empty($filters['room_ids'])) {
            if (is_array($filters['room_ids'])) {
                $placeholders = [];
                foreach ($filters['room_ids'] as $i => $roomId) {
                    $placeholders[] = ":room_id_$i";
                    $params["room_id_$i"] = $roomId;
                }
                $conditions[] = "g.room_id IN (" . implode(',', $placeholders) . ")";
            } else {
                $conditions[] = "g.room_id = :room_id";
                $params['room_id'] = $filters['room_ids'];
            }
        }

        if (!empty($filters['search_query'])) {
            $searchTerms = explode(' ', trim($filters['search_query']));
            $searchConditions = [];
            
            foreach ($searchTerms as $i => $term) {
                if (strlen($term) >= 2) {
                    $searchConditions[] = "(
                        g.last_name LIKE :search_term_{$i}_1 OR 
                        g.first_name LIKE :search_term_{$i}_2 OR 
                        g.company_name LIKE :search_term_{$i}_3
                    )";
                    $params["search_term_{$i}_1"] = $term . '%';
                    $params["search_term_{$i}_2"] = $term . '%';
                    $params["search_term_{$i}_3"] = '%' . $term . '%';
                }
            }
            
            if (!empty($searchConditions)) {
                $conditions[] = "(" . implode(' AND ', $searchConditions) . ")";
            }
        }

        if (!empty($conditions)) {
            $sql .= " AND " . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
}
