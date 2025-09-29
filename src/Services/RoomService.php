<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/RoomService.php                   *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Services;

use Hospitality\Repositories\RoomRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Hospitality\Services\LogService;
use Exception;

class RoomService {
    private RoomRepository $roomRepository;

    public function __construct() {
        $this->roomRepository = new RoomRepository();
    }

    public function createRoom(array $data): array {
        // Validation - only name and stadium_id are required
        $errors = Validator::validateRequired($data, ['stadium_id', 'name']);
        
        if (!empty($errors)) {
            throw new Exception(implode(', ', $errors));
        }

        // Set default capacity if not provided or invalid
        if (!isset($data['capacity']) || $data['capacity'] < 1) {
            $data['capacity'] = 500;
            Logger::info('Using default capacity for new room', [
                'room_name' => $data['name'],
                'default_capacity' => 500
            ]);
        }

        if (!Validator::validateId($data['stadium_id'])) {
            throw new Exception('Invalid stadium_id');
        }

        if (!Validator::validateString($data['name'], 2, 100)) {
            throw new Exception('Room name must be between 2 and 100 characters');
        }

        if (isset($data['capacity']) && $data['capacity'] < 1) {
            throw new Exception('Capacity must be at least 1');
        }

        if (isset($data['room_type']) && !in_array($data['room_type'], ['standard', 'vip', 'premium', 'buffet'])) {
            throw new Exception('Invalid room type');
        }

        $currentUser = $GLOBALS['current_user'] ?? null;
        $data['created_by'] = $currentUser['id'] ?? null;

        $roomId = $this->roomRepository->create($data);

        LogService::log(
            'ROOM_CREATED',
            "New room created: {$data['name']}",
            [
                'room_id' => $roomId,
                'stadium_id' => $data['stadium_id'],
                'capacity' => $data['capacity'],
                'room_type' => $data['room_type'] ?? 'standard'
            ],
            $currentUser['id'] ?? null,
            $data['stadium_id'],
            'hospitality_rooms',
            $roomId
        );

        return $this->roomRepository->findById($roomId);
    }

    public function getRoomsByStadium(int $stadiumId, bool $activeOnly = true): array {
        if (!Validator::validateId($stadiumId)) {
            throw new Exception('Invalid stadium_id');
        }

        // Use the method with stats for better UX
        return $this->roomRepository->findByStadiumWithStats($stadiumId, $activeOnly);
    }

    public function getRoomById(int $id): array {
        $room = $this->roomRepository->findById($id);
        
        if (!$room) {
            throw new Exception('Room not found');
        }

        return $room;
    }

    public function getRoomWithStats(int $id): array {
        $room = $this->getRoomById($id);
        $stats = $this->roomRepository->getRoomStats($id);
        
        $assignedHostess = $this->roomRepository->getAssignedHostess($id);

        return [
            'room' => $room,
            'statistics' => $stats,
            'assigned_hostess' => $assignedHostess
        ];
    }

    public function updateRoom(int $id, array $data): bool {
        $room = $this->getRoomById($id);

        if (isset($data['name']) && !Validator::validateString($data['name'], 2, 100)) {
            throw new Exception('Room name must be between 2 and 100 characters');
        }

        // Handle capacity update with default
        if (isset($data['capacity'])) {
            if ($data['capacity'] < 1) {
                $data['capacity'] = 500;
                Logger::info('Using default capacity for room update', [
                    'room_id' => $id,
                    'default_capacity' => 500
                ]);
            }
        }

        if (isset($data['room_type']) && !in_array($data['room_type'], ['standard', 'vip', 'premium', 'buffet'])) {
            throw new Exception('Invalid room type');
        }

        $currentUser = $GLOBALS['current_user'] ?? null;
        
        $updated = $this->roomRepository->update($id, $data);

        if ($updated) {
            $changes = [];
            foreach ($data as $key => $value) {
                if (isset($room[$key]) && $room[$key] != $value) {
                    $changes[$key] = [
                        'old' => $room[$key],
                        'new' => $value
                    ];
                }
            }

            LogService::log(
                'ROOM_UPDATED',
                "Room updated: {$room['name']}",
                [
                    'room_id' => $id,
                    'changes' => $changes
                ],
                $currentUser['id'] ?? null,
                $room['stadium_id'],
                'hospitality_rooms',
                $id
            );
        }

        return $updated;
    }

    public function deleteRoom(int $id): bool {
        $room = $this->getRoomById($id);
        
        $currentUser = $GLOBALS['current_user'] ?? null;

        $deleted = $this->roomRepository->delete($id);

        if ($deleted) {
            LogService::log(
                'ROOM_DELETED',
                "Room deactivated: {$room['name']}",
                ['room_id' => $id],
                $currentUser['id'] ?? null,
                $room['stadium_id'],
                'hospitality_rooms',
                $id
            );
        }

        return $deleted;
    }
}