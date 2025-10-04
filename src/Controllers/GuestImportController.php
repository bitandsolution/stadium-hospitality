<?php
/*********************************************************
*                                                        *
*   FILE: src/Controller/GuestImportController.php       *
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
        // Path assoluto dentro la directory consentita
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

            // Get POST parameters
            $eventId = $_POST['event_id'] ?? null;
            $stadiumId = $_POST['stadium_id'] ?? null;
            $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';

            if (!$eventId || !is_numeric($eventId)) {
                $this->sendError('event_id is required', [], 422);
                return;
            }

            if (!$stadiumId || !is_numeric($eventId)) {
                $this->sendError('stadium_id is required', [], 400);
                return;
            }

            // Validate event exists and belongs to user's stadium
            $eventStmt = $this->importService->db->prepare("
                SELECT stadium_id FROM events WHERE id = ? AND is_active = 1
            ");
            $eventStmt->execute([$eventId]);
            $event = $eventStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$event) {
                $this->sendError('Event not found', [], 404);
                return;
            }

            if (!TenantMiddleware::validateStadiumAccess($event['stadium_id'])) return;

            // Validate file upload
            if (!isset($_FILES['file'])) {
                $this->sendError('No file uploaded', [], 422);
                return;
            }

            $file = $_FILES['file'];

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
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
                'application/vnd.ms-excel' // xls
            ];
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

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

            try {
                // Process import
                $result = $this->importService->importFromExcel(
                    $filepath,
                    (int)$eventId,
                    $event['stadium_id'],
                    $dryRun
                );

                // Delete temporary file if not dry run
                if (!$dryRun && file_exists($filepath)) {
                    unlink($filepath);
                }

                $this->sendSuccess($result);

            } catch (Exception $e) {
                // Clean up file on error
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                throw $e;
            }

        } catch (Exception $e) {
            Logger::error('Guest import failed', [
                'error' => $e->getMessage(),
                'user_id' => $decoded->user_id ?? null
            ]);

            $this->sendError('Import failed', $e->getMessage(), 400);
        }
    }

    /**
     * GET /api/admin/guests/import/template
     * Download Excel template for import
     */
    public function downloadTemplate(): void {
        try {
            
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            // Create simple Excel template
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set headers
            // Headers - ordine ottimizzato per data entry
            $headers = ['SALA', 'TAVOLO', 'COGNOME', 'NOME', 'POSTO', 'RAGIONE SOCIALE', 'TELEFONO', 'EMAIL'];
            $sheet->fromArray([$headers], null, 'A1');

            // Add sample data
            $sampleData = [
                ['HOSPITALITY 1', '5', 'Rossi', 'Mario', '1', 'Acme Corp', '+39 333 1234567', 'mario.rossi@acme.it'],
                ['HOSPITALITY 2', '12', 'Bianchi', 'Laura', '7', 'Tech Solutions', '+39 333 7654321', 'laura@tech.it',  ]
            ];
            $sheet->fromArray($sampleData, null, 'A2');

            // Style headers
            $headerStyle = $sheet->getStyle('A1:H1');
            $headerStyle->getFont()->setBold(true);
            $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $headerStyle->getFill()->getStartColor()->setRGB('4472C4');
            $headerStyle->getFont()->getColor()->setRGB('FFFFFF');

            // Auto-size columns
            foreach (range('A', 'H') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Output file
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="hospitality_import_template.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;

        } catch (Exception $e) {
            Logger::error('Template download failed', ['error' => $e->getMessage()]);
            
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to generate template',
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