<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/GuestImportServices.php           *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Services;

use Hospitality\Repositories\GuestRepository;
use Hospitality\Config\Database;
use Hospitality\Utils\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Exception;
use PDO;

class GuestImportService {
    public PDO $db;
    private array $roomCache = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Import guests from Excel file
     * Expected columns: COGNOME, NOME, RAGIONE SOCIALE, TELEFONO, EMAIL, SALA, POSTO
     */
    public function importFromExcel(
        string $filePath, 
        int $eventId, 
        int $stadiumId,
        bool $dryRun = false
    ): array {
        $startTime = microtime(true);

        try {
            // Validate file exists
            if (!file_exists($filePath)) {
                throw new Exception('File not found');
            }

            // Load Excel file
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                throw new Exception('Excel file is empty');
            }

            // Load room mappings for this stadium
            $this->loadRoomCache($stadiumId);

            // Parse and validate
            $results = $this->parseAndValidateRows($rows, $eventId, $stadiumId);

            // If dry run, return validation results without inserting
            if ($dryRun) {
                return [
                    'success' => true,
                    'dry_run' => true,
                    'valid_rows' => count($results['valid']),
                    'invalid_rows' => count($results['invalid']),
                    'errors' => $results['invalid'],
                    'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
                ];
            }

            // Insert valid rows
            $insertResults = $this->insertGuests($results['valid'], $eventId, $stadiumId);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Log import operation
            LogService::log(
                'GUEST_IMPORT',
                'Guests imported from Excel',
                [
                    'event_id' => $eventId,
                    'total_rows' => count($rows) - 1,
                    'imported' => $insertResults['inserted'],
                    'skipped' => $insertResults['skipped'],
                    'errors' => count($results['invalid']),
                    'execution_time_ms' => $executionTime
                ],
                $GLOBALS['current_user']['id'] ?? null,
                $stadiumId
            );

            return [
                'success' => true,
                'imported' => $insertResults['inserted'],
                'skipped' => $insertResults['skipped'],
                'errors' => $results['invalid'],
                'total_processed' => count($rows) - 1,
                'execution_time_ms' => $executionTime
            ];

        } catch (Exception $e) {
            Logger::error('Excel import failed', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            throw $e;
        }
    }

    private function parseAndValidateRows(array $rows, int $eventId, int $stadiumId): array {
        $valid = [];
        $invalid = [];

        // First row should be headers
        $headers = array_map('strtoupper', array_map('trim', $rows[0]));
        
        // Map headers to indices
        $colMap = [
            'last_name' => $this->findColumnIndex($headers, ['COGNOME']),
            'first_name' => $this->findColumnIndex($headers, ['NOME']),
            'company' => $this->findColumnIndex($headers, ['RAGIONE SOCIALE', 'AZIENDA']),
            'phone' => $this->findColumnIndex($headers, ['TELEFONO', 'TEL']),
            'email' => $this->findColumnIndex($headers, ['EMAIL', 'E-MAIL']),
            'room' => $this->findColumnIndex($headers, ['SALA', 'ROOM']),
            'table' => $this->findColumnIndex($headers, ['POSTO', 'TAVOLO', 'TABLE'])
        ];

        // Validate required columns exist
        if ($colMap['last_name'] === false || $colMap['first_name'] === false || 
            $colMap['room'] === false || $colMap['table'] === false) {
            throw new Exception('Required columns missing (COGNOME, NOME, SALA, POSTO)');
        }

        // Process data rows (skip header)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $rowNum = $i + 1;

            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $errors = [];
            $guestData = [];

            // Extract and validate COGNOME (required)
            $lastName = trim($row[$colMap['last_name']] ?? '');
            if (empty($lastName)) {
                $errors[] = "COGNOME missing";
            } else {
                $guestData['last_name'] = $lastName;
            }

            // Extract and validate NOME (required)
            $firstName = trim($row[$colMap['first_name']] ?? '');
            if (empty($firstName)) {
                $errors[] = "NOME missing";
            } else {
                $guestData['first_name'] = $firstName;
            }

            // Extract SALA (required) and map to room_id
            $roomName = trim($row[$colMap['room']] ?? '');
            if (empty($roomName)) {
                $errors[] = "SALA missing";
            } else {
                $roomId = $this->getRoomIdByName($roomName, $stadiumId);
                if (!$roomId) {
                    $errors[] = "SALA '{$roomName}' not found";
                } else {
                    $guestData['room_id'] = $roomId;
                }
            }

            // Extract POSTO (required)
            $tableNumber = trim($row[$colMap['table']] ?? '');
            if (empty($tableNumber)) {
                $errors[] = "POSTO missing";
            } else {
                $guestData['table_number'] = $tableNumber;
            }

            // Optional fields
            if ($colMap['company'] !== false) {
                $guestData['company_name'] = trim($row[$colMap['company']] ?? '');
            }

            if ($colMap['phone'] !== false) {
                $guestData['contact_phone'] = trim($row[$colMap['phone']] ?? '');
            }

            if ($colMap['email'] !== false) {
                $email = trim($row[$colMap['email']] ?? '');
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email format";
                } else {
                    $guestData['contact_email'] = $email;
                }
            }

            if (empty($errors)) {
                $valid[] = $guestData;
            } else {
                $invalid[] = [
                    'row' => $rowNum,
                    'data' => $row,
                    'errors' => $errors
                ];
            }
        }

        return ['valid' => $valid, 'invalid' => $invalid];
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
                    $stmt->execute([
                        $stadiumId,
                        $eventId,
                        $guest['room_id'],
                        $guest['first_name'],
                        $guest['last_name'],
                        $guest['company_name'] ?? null,
                        $guest['table_number'],
                        $guest['contact_phone'] ?? null,
                        $guest['contact_email'] ?? null
                    ]);
                    $inserted++;
                } catch (Exception $e) {
                    $skipped++;
                    Logger::warning('Guest insert skipped', [
                        'guest' => $guest['first_name'] . ' ' . $guest['last_name'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->db->commit();

            return ['inserted' => $inserted, 'skipped' => $skipped];

        } catch (Exception $e) {
            $this->db->rollBack();
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
            // Store both original and normalized versions
            $this->roomCache[strtoupper(trim($room['name']))] = $room['id'];
        }
    }

    private function getRoomIdByName(string $roomName, int $stadiumId): ?int {
        $normalized = strtoupper(trim($roomName));
        return $this->roomCache[$normalized] ?? null;
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