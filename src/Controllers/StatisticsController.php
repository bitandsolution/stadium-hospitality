<?php
/*********************************************************
*                                                        *
*   FILE: src/Controllers/StatisticsController.php       *
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

namespace Hospitality\Controllers;

use Hospitality\Repositories\StatisticsRepository;
use Hospitality\Middleware\AuthMiddleware;
use Hospitality\Middleware\TenantMiddleware;
use Hospitality\Middleware\RoleMiddleware;
use Hospitality\Utils\Logger;
use Hospitality\Services\LogService;
use Exception;

class StatisticsController {
    private StatisticsRepository $statsRepository;

    public function __construct() {
        $this->statsRepository = new StatisticsRepository();
    }

    /**
     * GET /api/statistics/access-by-event
     * Statistiche accessi per evento (per grafici)
     */
    public function accessByEvent(): void {
        try {
            // Require authentication with at least stadium_admin role
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            // Get stadium ID
            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Get date range from query params
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));

            // Get statistics
            $stats = $this->statsRepository->getAccessByEvent($stadiumId, $dateFrom, $dateTo);

            // Log the access
            LogService::log(
                'STATS_VIEW',
                'Statistics accessed by event',
                ['date_range' => [$dateFrom, $dateTo]],
                $decoded->user_id,
                $decoded->stadium_id
            );

            $this->sendSuccess([
                'statistics' => $stats,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'total_events' => count($stats)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get access by event statistics', [
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve statistics', [], 500);
        }
    }

    /**
     * GET /api/statistics/access-by-room
     * Statistiche accessi per sala hospitality
     */
    public function accessByRoom(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Get optional event filter
            $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;

            // Get date range
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));

            $stats = $this->statsRepository->getAccessByRoom($stadiumId, $eventId, $dateFrom, $dateTo);

            LogService::log(
                'STATS_VIEW',
                'Statistics accessed by room',
                ['event_id' => $eventId, 'date_range' => [$dateFrom, $dateTo]],
                $decoded->user_id,
                $decoded->stadium_id
            );

            $this->sendSuccess([
                'statistics' => $stats,
                'event_id' => $eventId,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'total_rooms' => count($stats)
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get access by room statistics', [
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve statistics', [], 500);
        }
    }

    /**
     * GET /api/statistics/export-excel
     * Export dettagliato in formato Excel
     */
    public function exportExcel(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            // Get filters
            $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));

            // Get detailed data
            $data = $this->statsRepository->getDetailedAccessData(
                $stadiumId, 
                $eventId, 
                $dateFrom, 
                $dateTo
            );

            if (empty($data)) {
                $this->sendError('No data available for export', [], 404);
                return;
            }

            // Generate Excel file
            $filename = $this->generateExcelFile($data, $stadiumId);

            // Log the export
            LogService::log(
                'STATS_EXPORT',
                'Statistics exported to Excel',
                [
                    'event_id' => $eventId,
                    'date_range' => [$dateFrom, $dateTo],
                    'record_count' => count($data),
                    'filename' => $filename
                ],
                $decoded->user_id,
                $decoded->stadium_id
            );

            // Return file info
            $this->sendSuccess([
                'file' => $filename,
                'download_url' => '/api/statistics/download/' . basename($filename),
                'record_count' => count($data),
                'generated_at' => date('c')
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to export statistics to Excel', [
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to export data', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/statistics/download/{filename}
     * Download del file Excel generato
     */
    public function downloadExcel(string $filename): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            // Sanitize filename
            $filename = basename($filename);
            $filepath = sys_get_temp_dir() . '/hospitality_exports/' . $filename;

            if (!file_exists($filepath)) {
                $this->sendError('File not found or expired', [], 404);
                return;
            }

            // Security check: verify file was created recently (within 1 hour)
            if (time() - filemtime($filepath) > 3600) {
                unlink($filepath);
                $this->sendError('File expired', [], 410);
                return;
            }

            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            // Output file
            readfile($filepath);

            // Delete file after download
            unlink($filepath);

            exit;

        } catch (Exception $e) {
            Logger::error('Failed to download Excel file', [
                'error' => $e->getMessage(),
                'filename' => $filename ?? 'unknown'
            ]);
            $this->sendError('Failed to download file', [], 500);
        }
    }

    /**
     * GET /api/statistics/summary
     * Riepilogo generale statistiche
     */
    public function summary(): void {
        try {
            $decoded = AuthMiddleware::handle();
            if (!$decoded) return;

            if (!RoleMiddleware::requireRole('stadium_admin')) return;

            $stadiumId = TenantMiddleware::getStadiumIdForQuery();
            
            if (!$stadiumId) {
                $this->sendError('Stadium context required', [], 400);
                return;
            }

            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));

            $summary = $this->statsRepository->getSummary($stadiumId, $dateFrom, $dateTo);

            $this->sendSuccess([
                'summary' => $summary,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Failed to get statistics summary', [
                'error' => $e->getMessage()
            ]);
            $this->sendError('Failed to retrieve summary', [], 500);
        }
    }

    // =====================================================
    // PRIVATE METHODS
    // =====================================================

    private function generateExcelFile(array $data, int $stadiumId): string {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set headers
        $headers = ['Evento', 'Cognome', 'Nome', 'Sala Hospitality', 'Stato'];
        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

        // Add data
        $row = 2;
        foreach ($data as $record) {
            $sheet->setCellValue('A' . $row, $record['event_name']);
            $sheet->setCellValue('B' . $row, $record['last_name']);
            $sheet->setCellValue('C' . $row, $record['first_name']);
            $sheet->setCellValue('D' . $row, $record['room_name']);
            $sheet->setCellValue('E' . $row, $record['status']);

            // Color code status
            if ($record['status'] === 'Entrato') {
                $sheet->getStyle('E' . $row)->getFont()->getColor()->setRGB('10B981');
            } else {
                $sheet->getStyle('E' . $row)->getFont()->getColor()->setRGB('EF4444');
            }

            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Generate filename
        $timestamp = date('Y-m-d_His');
        $filename = "statistiche_accessi_{$stadiumId}_{$timestamp}.xlsx";

        // Create export directory if not exists
        $exportDir = sys_get_temp_dir() . '/hospitality_exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename;

        // Save file
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filepath);

        return $filepath;
    }

    // =====================================================
    // UTILITY METHODS
    // =====================================================

    private function sendSuccess(array $data): void {
        http_response_code(200);
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