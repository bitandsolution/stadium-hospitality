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
use Hospitality\Repositories\StadiumRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class RoomService {
    private RoomRepository $roomRepository;
    private StadiumRepository $stadiumRepository;

    public function __construct() {
        $this->roomRepository = new RoomRepository();
        $this->stadiumRepository = new StadiumRepository();
    }

    public function createRoom(array $roomData): array {
        $errors = Validator::validateRequired($roomData, ['stadium_id', 'name']);

        if (!empty($roomData['stadium_id']) && !Validator::validateId($roomData['stadium_id'])) {
            $errors[] = 'Invalid stadium ID';
        }

        if (!empty($roomData['capacity']) && (!is_numeric($roomData['capacity']) || $roomData['capacity'] < 1)) {
            $errors[] = 'Capacity must be a positive number';
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        $stadium = $this->stadiumRepository->findById($roomData['stadium_id']);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        if ($this->roomRepository->nameExistsInStadium($roomData['name'], $roomData['stadium_id'])) {
            throw new Exception('Room name already exists in this stadium');
        }

        $roomId = $this->roomRepository->create($roomData);

        LogService::log(
            'ROOM_CREATE',
            'Hospitality room created',
            [
                'room_id' => $roomId,
                'room_name' => $roomData['name'],
                'stadium_id' => $roomData['stadium_id']
            ],
            $GLOBALS['current_user']['id'] ?? null,
            $roomData['stadium_id'],
            'hospitality_rooms',
            $roomId
        );

        Logger::info('Room created successfully', [
            'room_id' => $roomId,
            'name' => $roomData['name']
        ]);

        return $this->roomRepository->findById($roomId);
    }

    public function getRoomsByStadium(int $stadiumId, bool $activeOnly = true): array {
        return $this->roomRepository->findByStadium($stadiumId, $activeOnly);
    }

    public function getRoomById(int $id): array {
        $room = $this->roomRepository->findById($id);
        
        if (!$room) {
            throw new Exception('Room not found');
        }

        return $room;
    }

    public function updateRoom(int $id, array $data): bool {
        $room = $this->roomRepository->findById($id);
        if (!$room) {
            throw new Exception('Room not found');
        }

        if (isset($data['capacity']) && (!is_numeric($data['capacity']) || $data['capacity'] < 1)) {
            throw new Exception('Capacity must be a positive number');
        }

        if (isset($data['name']) && $data['name'] !== $room['name']) {
            if ($this->roomRepository->nameExistsInStadium($data['name'], $room['stadium_id'], $id)) {
                throw new Exception('Room name already exists in this stadium');
            }
        }

        $updated = $this->roomRepository->update($id, $data);

        if ($updated) {
            LogService::log(
                'ROOM_UPDATE',
                'Room details updated',
                ['changes' => array_keys($data)],
                $GLOBALS['current_user']['id'] ?? null,
                $room['stadium_id'],
                'hospitality_rooms',
                $id
            );
        }

        return $updated;
    }

    public function deleteRoom(int $id): bool {
        $room = $this->roomRepository->findById($id);
        if (!$room) {
            throw new Exception('Room not found');
        }

        $deleted = $this->roomRepository->delete($id);

        if ($deleted) {
            LogService::log(
                'ROOM_DELETE',
                'Room deactivated',
                ['room_name' => $room['name']],
                $GLOBALS['current_user']['id'] ?? null,
                $room['stadium_id'],
                'hospitality_rooms',
                $id
            );
        }

        return $deleted;
    }

    public function getRoomWithStats(int $id): array {
        $room = $this->getRoomById($id);
        $stats = $this->roomRepository->getStatistics($id);
        $hostesses = $this->roomRepository->getAssignedHostess($id);

        return [
            'room' => $room,
            'statistics' => $stats,
            'assigned_hostesses' => $hostesses
        ];
    }
}