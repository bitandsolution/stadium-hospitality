<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/CheckinService.php                *
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

namespace Hospitality\Services;

use Hospitality\Repositories\GuestAccessRepository;
use Hospitality\Repositories\GuestRepository;
use Hospitality\Utils\Logger;
use Hospitality\Utils\Validator;
use Exception;

class CheckinService {
    private GuestAccessRepository $guestAccessRepository;
    private GuestRepository $guestRepository;

    public function __construct() {
        $this->guestAccessRepository = new GuestAccessRepository();
        $this->guestRepository = new GuestRepository();
    }

    /**
     * Esegui check-in ospite con validazioni complete
     */
    public function checkinGuest(int $guestId, int $hostessId, int $stadiumId, array $data = []): array {
        $startTime = microtime(true);

        try {
            // Validazioni input
            $errors = $this->validateCheckinData($data);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }

            // Verifica che l'ospite esista
            $guest = $this->guestRepository->findById($guestId, $stadiumId);
            if (!$guest) {
                throw new Exception('Guest not found or inactive');
            }

            // Verifica che l'evento sia ancora attivo/futuro
            $eventDate = new \DateTime($guest['event_date']);
            $today = new \DateTime();
            
            if ($eventDate < $today->modify('-1 day')) {
                throw new Exception('Cannot check-in guest for past events');
            }

            // Esegui il check-in
            $result = $this->guestAccessRepository->recordCheckin(
                $guestId, 
                $hostessId, 
                $stadiumId, 
                $data
            );

            // Log dell'operazione
            LogService::log(
                'GUEST_CHECKIN',
                'Guest checked in successfully',
                [
                    'guest_id' => $guestId,
                    'guest_name' => $result['guest_name'],
                    'room_name' => $result['room_name'],
                    'companions' => $result['companions'],
                    'previous_status' => $result['previous_status']
                ],
                $hostessId,
                $stadiumId,
                'guest_accesses',
                $result['access_id']
            );

            $executionTime = (microtime(true) - $startTime) * 1000;
            
            // Performance monitoring
            if ($executionTime > 500) {
                Logger::warning('Slow check-in detected', [
                    'execution_time_ms' => round($executionTime, 2),
                    'guest_id' => $guestId,
                    'hostess_id' => $hostessId
                ]);
            }

            Logger::info('Guest check-in completed', [
                'guest_id' => $guestId,
                'hostess_id' => $hostessId,
                'execution_time_ms' => round($executionTime, 2)
            ]);

            return $result;

        } catch (Exception $e) {
            Logger::error('Guest check-in failed', [
                'guest_id' => $guestId,
                'hostess_id' => $hostessId,
                'stadium_id' => $stadiumId,
                'error' => $e->getMessage(),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            throw $e;
        }
    }

    /**
     * Esegui check-out ospite con validazioni
     */
    public function checkoutGuest(int $guestId, int $hostessId, int $stadiumId, array $data = []): array {
        $startTime = microtime(true);

        try {
            // Validazioni input
            $errors = $this->validateCheckoutData($data);
            if (!empty($errors)) {
                throw new Exception('Validation failed: ' . implode(', ', $errors));
            }

            // Verifica che l'ospite esista
            $guest = $this->guestRepository->findById($guestId, $stadiumId);
            if (!$guest) {
                throw new Exception('Guest not found or inactive');
            }

            // Esegui il check-out
            $result = $this->guestAccessRepository->recordCheckout(
                $guestId, 
                $hostessId, 
                $stadiumId, 
                $data
            );

            // Log dell'operazione
            LogService::log(
                'GUEST_CHECKOUT',
                'Guest checked out successfully',
                [
                    'guest_id' => $guestId,
                    'guest_name' => $result['guest_name'],
                    'room_name' => $result['room_name'],
                    'duration_minutes' => $result['duration_minutes'],
                    'checkin_time' => $result['checkin_time']
                ],
                $hostessId,
                $stadiumId,
                'guest_accesses',
                $result['access_id']
            );

            $executionTime = (microtime(true) - $startTime) * 1000;
            
            // Performance monitoring
            if ($executionTime > 300) {
                Logger::warning('Slow check-out detected', [
                    'execution_time_ms' => round($executionTime, 2),
                    'guest_id' => $guestId,
                    'hostess_id' => $hostessId
                ]);
            }

            Logger::info('Guest check-out completed', [
                'guest_id' => $guestId,
                'hostess_id' => $hostessId,
                'duration_minutes' => $result['duration_minutes'],
                'execution_time_ms' => round($executionTime, 2)
            ]);

            return $result;

        } catch (Exception $e) {
            Logger::error('Guest check-out failed', [
                'guest_id' => $guestId,
                'hostess_id' => $hostessId,
                'stadium_id' => $stadiumId,
                'error' => $e->getMessage(),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            throw $e;
        }
    }

    /**
     * Ottieni storico accessi ospite
     */
    public function getGuestAccessHistory(int $guestId, int $hostessId, int $stadiumId): array {
        try {
            // Per hostess, verifica accesso alla sala dell'ospite
            $currentUser = $GLOBALS['current_user'] ?? null;
            
            if ($currentUser && $currentUser['role'] === 'hostess') {
                if (!$this->guestAccessRepository->canHostessAccessGuest($hostessId, $guestId, $stadiumId)) {
                    throw new Exception('Access denied: hostess cannot view this guest\'s history');
                }
            }

            $result = $this->guestAccessRepository->getGuestAccessHistory($guestId, $stadiumId);

            // Log della consultazione
            LogService::log(
                'GUEST_HISTORY_VIEW',
                'Guest access history viewed',
                [
                    'guest_id' => $guestId,
                    'guest_name' => $result['guest']['full_name'],
                    'total_visits' => $result['total_visits'],
                    'current_status' => $result['current_status']
                ],
                $hostessId,
                $stadiumId
            );

            return $result;

        } catch (Exception $e) {
            Logger::error('Failed to get guest access history', [
                'guest_id' => $guestId,
                'hostess_id' => $hostessId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Ottieni stato attuale ospite (per UI)
     */
    public function getGuestCurrentStatus(int $guestId, int $stadiumId): array {
        try {
            $status = $this->guestAccessRepository->getGuestCurrentStatus($guestId, $stadiumId);
            $guest = $this->guestRepository->findById($guestId, $stadiumId);

            if (!$guest) {
                throw new Exception('Guest not found');
            }

            return [
                'guest_id' => $guestId,
                'guest_name' => $guest['first_name'] . ' ' . $guest['last_name'],
                'room_name' => $guest['room_name'],
                'table_number' => $guest['table_number'],
                'vip_level' => $guest['vip_level'],
                'current_status' => $status ? $status['current_status'] : 'never_accessed',
                'last_access' => $status ? [
                    'type' => $status['access_type'],
                    'time' => $status['access_time'],
                    'hostess' => $status['hostess_name'],
                    'companions' => $status['companions']
                ] : null
            ];

        } catch (Exception $e) {
            Logger::error('Failed to get guest current status', [
                'guest_id' => $guestId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Valida dati check-in
     */
    private function validateCheckinData(array $data): array {
        $errors = [];

        if (isset($data['companions']) && 
            (!is_numeric($data['companions']) || (int)$data['companions'] < 0 || (int)$data['companions'] > 20)) {
            $errors[] = 'Companions must be a number between 0 and 20';
        }

        if (isset($data['notes']) && strlen($data['notes']) > 500) {
            $errors[] = 'Notes cannot exceed 500 characters';
        }

        return $errors;
    }

    /**
     * Valida dati check-out
     */
    private function validateCheckoutData(array $data): array {
        $errors = [];

        if (isset($data['notes']) && strlen($data['notes']) > 500) {
            $errors[] = 'Notes cannot exceed 500 characters';
        }

        return $errors;
    }

    /**
     * Ottieni statistiche check-in per hostess/sala
     */
    public function getCheckinStats(int $stadiumId, ?int $roomId = null, ?string $date = null): array {
        try {
            return $this->guestAccessRepository->getAccessStats($stadiumId, $roomId, $date);
        } catch (Exception $e) {
            Logger::error('Failed to get check-in stats', [
                'stadium_id' => $stadiumId,
                'room_id' => $roomId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}