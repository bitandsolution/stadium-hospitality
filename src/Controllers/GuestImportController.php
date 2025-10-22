<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/GuestImportController.php      *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Controllers;

use Hospitality\Services\GuestImportService;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Utils\Logger;
use Exception;

class GuestImportController {
    private GuestImportService $importService;
    private string $uploadDir;
    private int $maxFileSize = 10485760; // 10MB

    public function __construct() {
        $this->importService = new GuestImportService();
        $this->uploadDir = '/var/www/vhosts/checkindigitale.cloud/uploads/imports';
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * POST /api/admin/guests/import
     * Upload and import Excel file with guests
     */
    public function import(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            Logger::info('Import request received', [
                'user_id' => $decoded->user_id,
                'POST' => $_POST,
                'FILES' => array_keys($_FILES)
            ]);

            // Get POST parameters
            $eventId = $_POST['event_id'] ?? null;
            $stadiumId = $_POST['stadium_id'] ?? null;
            $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';

            if (!$eventId || !is_numeric($eventId)) {
                $this->sendError('event_id is required and must be numeric', [], 422);
                return;
            }

            if (!$stadiumId || !is_numeric($stadiumId)) {
                $this->sendError('stadium_id is required and must be numeric', [], 422);
                return;
            }

            Logger::info('Import parameters', [
                'event_id' => $eventId,
                'stadium_id' => $stadiumId,
                'dry_run' => $dryRun
            ]);

            // Validate event exists and belongs to user's stadium
            $eventStmt = $this->importService->db->prepare("
                SELECT stadium_id, name FROM events WHERE id = ? AND is_active = 1
            ");
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$event) {
                $this->sendError('Event not found', [], 404);
                return;
            }

            Logger::info('Event found', ['event' => $event]);

            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            // Validate file upload
            if (!isset($_FILES['file'])) {
                $this->sendError('No file uploaded', [], 422);
                return;
            }

            $file = $_FILES['file'];

            Logger::info('File received', [
                'name' => $file['name'],
                'type' => $file['type'],
                'size' => $file['size'],
                'error' => $file['error']
            ]);

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->sendError('File upload error: ' . $this->getUploadErrorMessage($file['error']), [], 400);
                return;
            }

            // Validate file size
            if ($file['size'] > $this->maxFileSize) {
                $this->sendError('File too large. Maximum size: 10MB', [], 422);
                return;
            }

            // Validate file type
            $allowedExtensions = ['xlsx', 'xls'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                $this->sendError('Invalid file type. Only .xlsx and .xls files are allowed', [], 422);
                return;
            }

            // Validate MIME type
            $allowedMimeTypes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel'
            ];
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            Logger::info('File validation', [
                'extension' => $fileExtension,
                'mime_type' => $mimeType
            ]);

            if (!in_array($mimeType, $allowedMimeTypes)) {
                $this->sendError('Invalid file type detected', [], 422);
                return;
            }

            // Generate unique filename
            $filename = 'import_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $filepath = $this->uploadDir . '/' . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->sendError('Failed to save uploaded file', [], 500);
                return;
            }

            Logger::info('File saved', ['filepath' => $filepath]);

            try {
                // Process import
                $result = $this->importService->importFromExcel(
                    $filepath,
                    (int)$eventId,
                    (int)$event['stadium_id'],
                    $dryRun
                );

                Logger::info('Import completed', ['result' => $result]);

                // Delete temporary file if not dry run
                if (!$dryRun && file_exists($filepath)) {
                    unlink($filepath);
                    Logger::info('Temporary file deleted', ['filepath' => $filepath]);
                }

                // Format response for frontend
                $responseData = [
                    'imported_count' => $result['imported'] ?? 0,
                    'skipped_count' => $result['skipped'] ?? 0,
                    'errors_count' => count($result['errors'] ?? []),
                    'total_processed' => $result['total_processed'] ?? 0,
                    'execution_time_ms' => $result['execution_time_ms'] ?? 0,
                    'errors' => $result['errors'] ?? [],
                    'summary' => [
                        'successful' => $result['imported'] ?? 0,
                        'failed' => $result['skipped'] ?? 0,
                        'total' => $result['total_processed'] ?? 0
                    ]
                ];

                $this->sendSuccess($responseData);

            } catch (Exception $e) {
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                
                Logger::error('Import processing failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Guest import failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            $this->sendError('Import failed: ' . $e->getMessage(), [
                'details' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ], 400);
        }
    }

    /**
     * GET /api/admin/guests/import/template
     */
    public function downloadTemplate(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            Logger::info('Template download requested', ['user_id' => $decoded->user_id]);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $headers = ['SALA', 'COGNOME', 'NOME', 'TAVOLO', 'RAGIONE SOCIALE', 'TELEFONO', 'EMAIL'];
            $sheet->fromArray([$headers], null, 'A1');

            $sampleData = [
                ['HOSPITALITY 1', 'Rossi', 'Mario', '5', 'Acme Corp', '+39 333 1234567', 'mario.rossi@acme.it'],
                ['HOSPITALITY 2', 'Bianchi', 'Laura', '', 'Tech Solutions', '+39 333 7654321', 'laura@tech.it'],
                ['HOSPITALITY 1', 'Verdi', 'Giuseppe', '12', '', '', '']
            ];
            $sheet->fromArray($sampleData, null, 'A2');

            $headerStyle = $sheet->getStyle('A1:G1');
            $headerStyle->getFont()->setBold(true);
            $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $headerStyle->getFill()->getStartColor()->setRGB('4472C4');
            $headerStyle->getFont()->getColor()->setRGB('FFFFFF');

            $sheet->setCellValue('A5', 'NOTA: I campi SALA, COGNOME e NOME sono OBBLIGATORI. TAVOLO è opzionale.');
            $sheet->getStyle('A5')->getFont()->setBold(true)->setItalic(true);
            $sheet->getStyle('A5')->getFont()->getColor()->setRGB('FF0000');

            foreach (range('A', 'G') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="hospitality_import_template.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            
            Logger::info('Template downloaded successfully', ['user_id' => $decoded->user_id]);
            exit;

        } catch (Exception $e) {
            Logger::error('Template download failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to generate template',
                'details' => $e->getMessage(),
                'timestamp' => date('c')
            ]);
            exit;
        }
    }

    private function getUploadErrorMessage(int $errorCode): string {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        return $errors[$errorCode] ?? 'Unknown upload error';
    }

    private function sendSuccess(array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }

    private function sendError(string $message, mixed $details = [], int $code = 400): void {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'details' => $details,
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}