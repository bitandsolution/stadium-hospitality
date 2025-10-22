<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/GuestImportService.php            *
*   MODIFIED: Report dettagliato errori import           *
*                                                        *
*********************************************************/

namespace Hospitality\Services;

use Hospitality\Config\Database;
use Hospitality\Utils\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Exception;
use PDO;

class GuestImportService {
    public PDO $db;
    private array $roomCache = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function importFromExcel(
        string $filePath, 
        int $eventId, 
        int $stadiumId,
        bool $dryRun = false
    ): array {
        $startTime = microtime(true);

        try {
            if (!file_exists($filePath)) {
                throw new Exception('File not found');
            }

            Logger::info('Starting Excel import', [
                'file' => $filePath,
                'event_id' => $eventId,
                'stadium_id' => $stadiumId,
                'dry_run' => $dryRun
            ]);

            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }

            Logger::info('Excel file loaded', ['total_rows' => count($rows)]);

            $this->loadRoomCache($stadiumId);
            Logger::info('Room cache loaded', [
                'rooms_count' => count($this->roomCache),
                'rooms' => array_keys($this->roomCache)
            ]);

            $results = $this->parseAndValidateRows($rows, $eventId, $stadiumId);

            Logger::info('Parsing completed', [
                'valid' => count($results['valid']),
                'invalid' => count($results['invalid']),
                'empty_rows' => $results['empty_rows']
            ]);

            if ($dryRun) {
                return [
                    'success' => true,
                    'dry_run' => true,
                    'valid_rows' => count($results['valid']),
                    'invalid_rows' => count($results['invalid']),
                    'empty_rows' => $results['empty_rows'],
                    'errors' => $results['invalid'],
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }

            $insertResults = $this->insertGuests($results['valid'], $eventId, $stadiumId);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info('Import completed', [
                'inserted' => $insertResults['inserted'],
                'skipped' => $insertResults['skipped']
            ]);

            if (class_exists('\Hospitality\Services\LogService')) {
                \Hospitality\Services\LogService::log(
                    'GUEST_IMPORT',
                    'Guests imported from Excel',
                    [
                        'event_id' => $eventId,
                        'total_rows' => count($rows) - 1,
                        'imported' => $insertResults['inserted'],
                        'skipped' => $insertResults['skipped'],
                        'errors' => count($results['invalid']),
                        'empty_rows' => $results['empty_rows'],
                        'execution_time_ms' => $executionTime
                    ],
                    $GLOBALS['current_user']['id'] ?? null,
                    $stadiumId
                );
            }

            return [
                'success' => true,
                'imported' => $insertResults['inserted'],
                'skipped' => $insertResults['skipped'],
                'errors' => $results['invalid'],
                'empty_rows' => $results['empty_rows'],
                'total_processed' => count($rows) - 1,
                'execution_time_ms' => $executionTime,
                'summary' => [
                    'total_rows_in_file' => count($rows) - 1,
                    'valid_rows' => count($results['valid']),
                    'invalid_rows' => count($results['invalid']),
                    'empty_rows' => $results['empty_rows'],
                    'successfully_imported' => $insertResults['inserted'],
                    'skipped_duplicates' => $insertResults['skipped']
                ]
            ];

        } catch (Exception $e) {
            Logger::error('Excel import failed', [
                'error' => $e->getMessage(),
                'file' => $filePath,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function parseAndValidateRows(array $rows, int $eventId, int $stadiumId): array {
        $valid = [];
        $invalid = [];
        $emptyRowsCount = 0;

        $headers = array_map('strtoupper', array_map('trim', $rows[0]));
        Logger::info('Excel headers found', ['headers' => implode(', ', $headers)]);
        
        $colMap = [
            'last_name' => $this->findColumnIndex($headers, ['COGNOME']),
            'first_name' => $this->findColumnIndex($headers, ['NOME']),
            'company' => $this->findColumnIndex($headers, ['RAGIONE SOCIALE', 'AZIENDA', 'COMPANY']),
            'phone' => $this->findColumnIndex($headers, ['TELEFONO', 'TEL', 'PHONE']),
            'email' => $this->findColumnIndex($headers, ['EMAIL', 'E-MAIL', 'MAIL']),
            'room' => $this->findColumnIndex($headers, ['SALA', 'ROOM']),
            'table' => $this->findColumnIndex($headers, ['TAVOLO', 'POSTO', 'TABLE', 'SEAT'])
        ];

        Logger::info('Column mapping', ['mapping' => json_encode($colMap)]);

        // Solo COGNOME e SALA sono obbligatori - NOME è opzionale
        if ($colMap['last_name'] === false || $colMap['room'] === false) {
            throw new Exception('Required columns missing: COGNOME and SALA must be present');
        }

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $rowNum = $i + 1;

            if ($this->isEmptyRow($row)) {
                $emptyRowsCount++;
                Logger::debug("Row {$rowNum} is empty, skipping");
                continue;
            }

            $errors = [];
            $guestData = [];

            $lastName = trim($row[$colMap['last_name']] ?? '');
            if (empty($lastName)) {
                $errors[] = "COGNOME missing";
            } else {
                $guestData['last_name'] = $lastName;
            }

            // NOME è opzionale - se vuoto usiamo "-"
            $firstName = trim($row[$colMap['first_name']] ?? '');
            $guestData['first_name'] = !empty($firstName) ? $firstName : '-';

            $roomName = trim($row[$colMap['room']] ?? '');
            if (empty($roomName)) {
                $errors[] = "SALA missing";
            } else {
                $roomId = $this->getRoomIdByName($roomName, $stadiumId);
                if (!$roomId) {
                    $errors[] = "SALA '{$roomName}' not found in database. Available rooms: " . implode(', ', array_keys($this->roomCache));
                } else {
                    $guestData['room_id'] = $roomId;
                }
            }

            if ($colMap['table'] !== false) {
                $tableNumber = trim($row[$colMap['table']] ?? '');
                $guestData['table_number'] = !empty($tableNumber) ? $tableNumber : null;
            } else {
                $guestData['table_number'] = null;
            }

            if ($colMap['company'] !== false) {
                $company = trim($row[$colMap['company']] ?? '');
                $guestData['company_name'] = !empty($company) ? $company : null;
            }

            if ($colMap['phone'] !== false) {
                $phone = trim($row[$colMap['phone']] ?? '');
                $guestData['contact_phone'] = !empty($phone) ? $phone : null;
            }

            if ($colMap['email'] !== false) {
                $email = trim($row[$colMap['email']] ?? '');
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email format: {$email}";
                } else {
                    $guestData['contact_email'] = !empty($email) ? $email : null;
                }
            }

            if (empty($errors)) {
                $valid[] = $guestData;
                Logger::debug("Row {$rowNum} valid", ['data' => $guestData]);
            } else {
                $invalid[] = [
                    'row' => $rowNum,
                    'data' => array_slice($row, 0, 8),
                    'errors' => $errors
                ];
                Logger::warning("Row {$rowNum} invalid", [
                    'errors' => $errors, 
                    'data' => array_slice($row, 0, 8)
                ]);
            }
        }

        return [
            'valid' => $valid, 
            'invalid' => $invalid,
            'empty_rows' => $emptyRowsCount
        ];
    }

    private function insertGuests(array $validGuests, int $eventId, int $stadiumId): array {
        $inserted = 0;
        $skipped = 0;

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO guests (
                    stadium_id, event_id, room_id, first_name, last_name,
                    company_name, table_number, contact_phone, contact_email,
                    vip_level, is_active, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'standard', 1, NOW())
            ");

            foreach ($validGuests as $guest) {
                try {
                    $result = $stmt->execute([
                        $stadiumId,
                        $eventId,
                        $guest['room_id'],
                        $guest['first_name'],
                        $guest['last_name'],
                        $guest['company_name'] ?? null,
                        $guest['table_number'] ?? null,
                        $guest['contact_phone'] ?? null,
                        $guest['contact_email'] ?? null
                    ]);
                    
                    if ($result) {
                        $inserted++;
                        Logger::debug('Guest inserted', [
                            'name' => $guest['first_name'] . ' ' . $guest['last_name'],
                            'room_id' => $guest['room_id']
                        ]);
                    } else {
                        $skipped++;
                        Logger::warning('Guest insert returned false', ['guest' => $guest]);
                    }
                } catch (Exception $e) {
                    $skipped++;
                    Logger::warning('Guest insert skipped', [
                        'guest' => $guest['first_name'] . ' ' . $guest['last_name'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->db->commit();

            Logger::info('Guest insertion completed', [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'total' => count($validGuests)
            ]);

            return ['inserted' => $inserted, 'skipped' => $skipped];

        } catch (Exception $e) {
            $this->db->rollBack();
            Logger::error('Guest insertion failed, rollback', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function loadRoomCache(int $stadiumId): void {
        $stmt = $this->db->prepare("
            SELECT id, name 
            FROM hospitality_rooms 
            WHERE stadium_id = ? AND is_active = 1
        ");
        $stmt->execute([$stadiumId]);
        
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rooms as $room) {
            $normalized = strtoupper(trim($room['name']));
            $this->roomCache[$normalized] = $room['id'];
            
            Logger::debug('Room cached', [
                'name' => $room['name'],
                'normalized' => $normalized,
                'id' => $room['id']
            ]);
        }
    }

    private function getRoomIdByName(string $roomName, int $stadiumId): ?int {
        $normalized = strtoupper(trim($roomName));
        $roomId = $this->roomCache[$normalized] ?? null;
        
        if (!$roomId) {
            Logger::warning('Room not found in cache', [
                'room_name' => $roomName,
                'normalized' => $normalized,
                'available_rooms' => array_keys($this->roomCache)
            ]);
        }
        
        return $roomId;
    }

    private function findColumnIndex(array $headers, array $possibleNames): int|false {
        foreach ($possibleNames as $name) {
            $index = array_search($name, $headers);
            if ($index !== false) {
                return $index;
            }
        }
        return false;
    }

    private function isEmptyRow(array $row): bool {
        foreach ($row as $cell) {
            if (!empty(trim($cell))) {
                return false;
            }
        }
        return true;
    }
}