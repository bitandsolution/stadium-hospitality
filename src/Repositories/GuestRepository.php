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
                g.updated_at,
                hr.name as room_name,
                hr.id as room_id,
                e.name as event_name,
                e.event_date,
                CASE 
                    WHEN (
                        SELECT access_type 
                        FROM guest_accesses 
                        WHERE guest_id = g.id 
                        ORDER BY access_time DESC, id DESC 
                        LIMIT 1
                    ) = 'entry' THEN 'checked_in'
                    ELSE 'not_checked_in'
                END as access_status,
                (
                    SELECT access_time 
                    FROM guest_accesses 
                    WHERE guest_id = g.id AND access_type = 'entry'
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) as last_access_time,
                (
                    SELECT u.full_name 
                    FROM guest_accesses ga
                    JOIN users u ON ga.hostess_id = u.id
                    WHERE ga.guest_id = g.id AND ga.access_type = 'entry'
                    ORDER BY ga.access_time DESC, ga.id DESC 
                    LIMIT 1
                ) as checked_in_by
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
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
                $conditions[] = "(
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) = 'entry'";
            } elseif ($filters['access_status'] === 'not_checked_in') {
                $conditions[] = "(
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) IS NULL OR (
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) = 'exit'";
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
     * Get assigned room IDs for a hostess
     */
    public function getHostessAssignedRooms(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT room_id FROM user_room_assignments 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Trova ospite per ID con dettagli completi
     */
    public function findById(int $guestId, ?int $stadiumId = null): ?array {
        $sql = "
            SELECT 
                g.*,
                hr.name as room_name,
                e.name as event_name,
                e.event_date,
                s.name as stadium_name,
                CASE 
                    WHEN (
                        SELECT access_type 
                        FROM guest_accesses 
                        WHERE guest_id = g.id 
                        ORDER BY access_time DESC, id DESC 
                        LIMIT 1
                    ) = 'entry' THEN 'checked_in'
                    ELSE 'not_checked_in'
                END as access_status,
                -- Prendi l'ora dell'ultimo entry
                (
                    SELECT access_time 
                    FROM guest_accesses 
                    WHERE guest_id = g.id AND access_type = 'entry'
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) as last_access_time
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
        $startTime = microtime(true);

        // Get assigned room IDs for hostess
        $roomStmt = $this->db->prepare("
            SELECT room_id 
            FROM user_room_assignments 
            WHERE user_id = ? AND is_active = 1
        ");
        $roomStmt->execute([$hostessId]);
        $roomIds = $roomStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($roomIds)) {
            return [
                'results' => [],
                'total_found' => 0,
                'execution_time_ms' => 0,
                'has_more' => false
            ];
        }

        // Add room filter
        $filters['room_ids'] = $roomIds;

        // Prima calcola il TOTALE senza LIMIT
        $countFilters = $filters;
        unset($countFilters['limit']);
        unset($countFilters['offset']);

        $totalCount = $this->countGuests($countFilters);
        
        // Poi usa searchGuests con limit per i risultati paginati
        $searchResult = $this->searchGuests($filters);

        // Sovrascrivi total_found con il conteggio corretto
        $searchResult['total_found'] = $totalCount;
        $searchResult['has_more'] = ($filters['offset'] ?? 0) + count($searchResult['results']) < $totalCount;
        
        $executionTime = (microtime(true) - $startTime) * 1000;
        $searchResult['execution_time_ms'] = round($executionTime, 2);
        
        // Use standard search with room filter
        return $searchResult;
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
            WHERE g.stadium_id = ? 
                AND g.is_active = 1
                AND (
                    g.last_name LIKE ? OR 
                    g.first_name LIKE ?
                )
        ";

        // Array dei parametri nell'ordine corretto
        $params = [
            $stadiumId,
            $query . '%',
            $query . '%'
        ];

        // Filtro sale se specificato
        if ($roomIds && !empty($roomIds)) {
            $placeholders = str_repeat('?,', count($roomIds) - 1) . '?';
            $sql .= " AND g.room_id IN ($placeholders)";
            $params = array_merge($params, $roomIds);
        }

        $sql .= " ORDER BY g.last_name, g.first_name LIMIT ?";
        $params[] = $limit;

        try {
            $stmt = $this->db->prepare($sql);
            
            // DEBUG: Log the SQL and parameters
            error_log("QuickSearch SQL: " . $sql);
            error_log("QuickSearch Params: " . json_encode($params));
            error_log("Param count: " . count($params));
            
            // Esegui con i parametri - SEMPLICE E PULITO
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $executionTime = (microtime(true) - $startTime) * 1000;
            
            error_log("QuickSearch completed: " . count($results) . " results in " . round($executionTime, 2) . "ms");
            
            return [
                'suggestions' => $results,
                'execution_time_ms' => round($executionTime, 2)
            ];
            
        } catch (Exception $e) {
            error_log("QuickSearch SQL Error: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . json_encode($params));
            throw new Exception("Quick search failed: " . $e->getMessage());
        }
    }

    /**
     * Conta ospiti senza LIMIT 
     */

    private function countGuests(array $filters = []): int {
        $sql = "
            SELECT COUNT(DISTINCT g.id) as total
            FROM guests g
            JOIN hospitality_rooms hr ON g.room_id = hr.id
            JOIN events e ON g.event_id = e.id
            WHERE g.is_active = 1
        ";

        $params = [];
        $conditions = [];

        // Filtro per stadio
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

        // Ricerca per nome/cognome
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
                $conditions[] = "(
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) = 'entry'";
            } elseif ($filters['access_status'] === 'not_checked_in') {
                $conditions[] = "(
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) IS NULL OR (
                    SELECT access_type 
                    FROM guest_accesses 
                    WHERE guest_id = g.id 
                    ORDER BY access_time DESC, id DESC 
                    LIMIT 1
                ) = 'exit'";
            }
        }

        // Filtro per livello VIP
        if (!empty($filters['vip_level'])) {
            $conditions[] = "g.vip_level = :vip_level";
            $params['vip_level'] = $filters['vip_level'];
        }

        if (!empty($conditions)) {
            $sql .= " AND " . implode(' AND ', $conditions);
        }

        try {
            $stmt = $this->db->prepare($sql);
            
            foreach ($params as $key => $value) {
                if (strpos($key, '_id') !== false && $key !== 'stadium_id' && $key !== 'event_id') {
                    $stmt->bindValue(":$key", (int)$value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue(":$key", $value, PDO::PARAM_STR);
                }
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)($result['total'] ?? 0);
            
        } catch (Exception $e) {
            error_log("Guest count failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Metodo per ottenere updated_at corrente per lock ottimistico
     */
    public function getGuestUpdatedAt(int $guestId, ?int $stadiumId = null): ?string {
        $sql = "SELECT updated_at FROM guests WHERE id = ?";
        $params = [$guestId];

        if ($stadiumId !== null) {
            $sql .= " AND stadium_id = ?";
            $params[] = $stadiumId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['updated_at'] : null;
    }

    /**
     * Create new guest
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO guests (
                stadium_id, event_id, room_id,
                first_name, last_name, company_name,
                contact_email, contact_phone,
                table_number, seat_number,
                vip_level, notes,
                is_active, created_at
            ) VALUES (
                :stadium_id, :event_id, :room_id,
                :first_name, :last_name, :company_name,
                :contact_email, :contact_phone,
                :table_number, :seat_number,
                :vip_level, :notes,
                1, NOW()
            )
        ");

        $stmt->execute([
            'stadium_id' => $data['stadium_id'],
            'event_id' => $data['event_id'],
            'room_id' => $data['room_id'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'company_name' => $data['company_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'table_number' => $data['table_number'] ?? null,
            'seat_number' => $data['seat_number'] ?? null,
            'vip_level' => $data['vip_level'] ?? 'standard',
            'notes' => $data['notes'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update guest data con OPTIMISTIC LOCKING per gestione concorrenza
     * Previene modifiche concorrenti da multiple hostess
     * 
     * @param int $guestId ID ospite
     * @param array $data Dati da aggiornare
     * @param int|null $stadiumId ID stadio per security multi-tenant
     * @param string|null $expectedUpdatedAt Timestamp atteso per lock ottimistico
     * @return bool
     * @throws Exception Se c'è conflitto (record modificato da altra hostess)
     */
    public function update(int $guestId, array $data, ?int $stadiumId = null, ?string $expectedUpdatedAt = null): bool {
        try {
            $fields = [];
            $params = [];

            // Campi aggiornabili
            $allowedFields = [
                'first_name', 'last_name', 'company_name', 
                'contact_email', 'contact_phone', 
                'vip_level', 'table_number', 'seat_number', 
                'room_id', 'notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            // Add updated_at timestamp
            $fields[] = "updated_at = NOW()";

            // Build SQL with optimistic locking
            $sql = "UPDATE guests SET " . implode(', ', $fields) . " WHERE id = ?";
            $params[] = $guestId;

            // Add stadium filter for multi-tenant security
            if ($stadiumId !== null) {
                $sql .= " AND stadium_id = ?";
                $params[] = $stadiumId;
            }

            // Verifica che updated_at non sia cambiato
            // Se un'altra hostess ha modificato il record, la query non aggiornerà nulla
            if ($expectedUpdatedAt !== null) {
                $sql .= " AND updated_at = ?";
                $params[] = $expectedUpdatedAt;
            }

            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute($params);
            
            // Controlla se è stata aggiornata qualche riga
            // Se rowCount === 0 e abbiamo passato expectedUpdatedAt, significa CONFLITTO
            if ($success && $stmt->rowCount() === 0 && $expectedUpdatedAt !== null) {
                throw new Exception("CONFLICT: Record was modified by another user");
            }

            return $success;

        } catch (Exception $e) {
            error_log("Guest update failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get guest data for diff comparison
     */
    public function getGuestDataForDiff(int $guestId, ?int $stadiumId = null): ?array {
        $sql = "
            SELECT 
                g.id,
                g.first_name,
                g.last_name,
                g.company_name,
                g.contact_email,
                g.contact_phone,
                g.vip_level,
                g.table_number,
                g.seat_number,
                g.room_id,
                g.notes,
                g.updated_at,
                hr.name as room_name
            FROM guests g
            LEFT JOIN hospitality_rooms hr ON g.room_id = hr.id
            WHERE g.id = ? AND g.is_active = 1
        ";
        
        $params = [$guestId];

        if ($stadiumId !== null) {
            $sql .= " AND g.stadium_id = ?";
            $params[] = $stadiumId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Validate hostess can edit guest (check room assignment)
     */
    public function canHostessEditGuest(int $guestId, int $hostessId, int $stadiumId): bool {
        try {
            $sql = "
                SELECT 1 
                FROM guests g
                INNER JOIN user_room_assignments ura 
                    ON g.room_id = ura.room_id 
                    AND ura.user_id = ? 
                    AND ura.is_active = 1
                WHERE g.id = ? 
                    AND g.stadium_id = ? 
                    AND g.is_active = 1
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$hostessId, $guestId, $stadiumId]);

            return $stmt->rowCount() > 0;

        } catch (\Exception $e) {
            error_log("Hostess permission check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Conta TUTTI gli ospiti assegnati a una hostess
     * Funzione PUBBLICA chiamata dal Controller
     */
    public function countGuestsForHostess(int $hostessId, array $filters = []): int {
        // Get assigned room IDs for hostess
        $roomStmt = $this->db->prepare("
            SELECT room_id 
            FROM user_room_assignments 
            WHERE user_id = ? AND is_active = 1
        ");
        $roomStmt->execute([$hostessId]);
        $roomIds = $roomStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($roomIds)) {
            return 0;
        }

        // Add room filter
        $filters['room_ids'] = $roomIds;
        
        // Usa la funzione privata countGuests
        return $this->countGuests($filters);
    }

    /**
     * Ottieni la capacità totale delle sale assegnate a una hostess
     * 
     * Questa funzione somma le capacità di tutte le sale assegnate
     * alla hostess per determinare il limite massimo di ospiti caricabili.
     * 
     * @param int $hostessId ID dell'hostess
     * @return int Capacità totale (somma delle capacità di tutte le sale)
     */
    public function getTotalCapacityForHostess(int $hostessId): int {
        try {
            $sql = "
                SELECT COALESCE(SUM(hr.capacity), 0) as total_capacity
                FROM user_room_assignments ura
                INNER JOIN hospitality_rooms hr ON ura.room_id = hr.id
                WHERE ura.user_id = :user_id 
                AND ura.is_active = 1
                AND hr.is_active = 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $hostessId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $capacity = (int)($result['total_capacity'] ?? 0);
            
            // Log per debug
            error_log(sprintf(
                "Hostess %d - Total room capacity: %d",
                $hostessId,
                $capacity
            ));
            
            return $capacity;
            
        } catch (Exception $e) {
            error_log("Error getting hostess room capacity: " . $e->getMessage());
            return 0;
        }
    }

}
