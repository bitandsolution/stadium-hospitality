<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/EventService.php                  *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/

namespace Hospitality\Services;

use Hospitality\Repositories\EventRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Hospitality\Services\LogService;
use Exception;

class EventService {
    private EventRepository $eventRepository;

    public function __construct() {
        $this->eventRepository = new EventRepository();
    }

    /**
     * Create new event
     */
    public function createEvent(array $data): array {
        // Validation
        $errors = Validator::validateRequired($data, ['stadium_id', 'name', 'event_date']);
        
        if (!empty($errors)) {
            throw new Exception(implode(', ', $errors));
        }

        if (!Validator::validateId($data['stadium_id'])) {
            throw new Exception('Invalid stadium_id');
        }

        if (!Validator::validateString($data['name'], 2, 200)) {
            throw new Exception('Event name must be between 2 and 200 characters');
        }

        // Validate date format
        $date = \DateTime::createFromFormat('Y-m-d', $data['event_date']);
        if (!$date || $date->format('Y-m-d') !== $data['event_date']) {
            throw new Exception('Invalid event_date format. Use YYYY-MM-DD');
        }

        // Validate time if provided
        if (!empty($data['event_time'])) {
            $time = \DateTime::createFromFormat('H:i:s', $data['event_time']);
            if (!$time) {
                // Try H:i format
                $time = \DateTime::createFromFormat('H:i', $data['event_time']);
                if ($time) {
                    $data['event_time'] = $time->format('H:i:s');
                } else {
                    throw new Exception('Invalid event_time format. Use HH:MM or HH:MM:SS');
                }
            }
        }

        // Check for duplicate event name on same date
        if ($this->eventRepository->nameExistsForDate(
            $data['name'], 
            $data['event_date'], 
            $data['stadium_id']
        )) {
            throw new Exception('An event with this name already exists for this date');
        }

        $currentUser = $GLOBALS['current_user'] ?? null;

        $eventId = $this->eventRepository->create($data);

        LogService::log(
            'EVENT_CREATED',
            "New event created: {$data['name']}",
            [
                'event_id' => $eventId,
                'stadium_id' => $data['stadium_id'],
                'event_date' => $data['event_date'],
                'opponent_team' => $data['opponent_team'] ?? null,
                'competition' => $data['competition'] ?? null
            ],
            $currentUser['id'] ?? null,
            $data['stadium_id'],
            'events',
            $eventId
        );

        return $this->eventRepository->findById($eventId);
    }

    /**
     * Get events by stadium
     */
    public function getEventsByStadium(int $stadiumId, bool $activeOnly = true): array {
        if (!Validator::validateId($stadiumId)) {
            throw new Exception('Invalid stadium_id');
        }

        // Use the method with stats for better UX
        return $this->eventRepository->findByStadiumWithStats($stadiumId, $activeOnly);
    }

    /**
     * Get upcoming events
     */
    public function getUpcomingEvents(int $stadiumId, int $limit = 10): array {
        if (!Validator::validateId($stadiumId)) {
            throw new Exception('Invalid stadium_id');
        }

        return $this->eventRepository->findUpcoming($stadiumId, $limit);
    }

    /**
     * Get past events
     */
    public function getPastEvents(int $stadiumId, int $limit = 10): array {
        if (!Validator::validateId($stadiumId)) {
            throw new Exception('Invalid stadium_id');
        }

        return $this->eventRepository->findPast($stadiumId, $limit);
    }

    /**
     * Get event by ID
     */
    public function getEventById(int $id): array {
        $event = $this->eventRepository->findById($id);
        
        if (!$event) {
            throw new Exception('Event not found');
        }

        return $event;
    }

    /**
     * Get event with detailed statistics
     */
    public function getEventWithStats(int $id): array {
        $event = $this->getEventById($id);
        $stats = $this->eventRepository->getEventStats($id);
        $rooms = $this->eventRepository->getEventRooms($id);

        return [
            'event' => $event,
            'statistics' => $stats,
            'rooms' => $rooms
        ];
    }

    /**
     * Update event
     */
    public function updateEvent(int $id, array $data): bool {
        $event = $this->getEventById($id);

        if (isset($data['name']) && !Validator::validateString($data['name'], 2, 200)) {
            throw new Exception('Event name must be between 2 and 200 characters');
        }

        // Validate date if provided
        if (isset($data['event_date'])) {
            $date = \DateTime::createFromFormat('Y-m-d', $data['event_date']);
            if (!$date || $date->format('Y-m-d') !== $data['event_date']) {
                throw new Exception('Invalid event_date format. Use YYYY-MM-DD');
            }

            // Check for duplicate on new date
            if (isset($data['name'])) {
                $name = $data['name'];
            } else {
                $name = $event['name'];
            }

            if ($this->eventRepository->nameExistsForDate(
                $name, 
                $data['event_date'], 
                $event['stadium_id'],
                $id
            )) {
                throw new Exception('An event with this name already exists for this date');
            }
        }

        // Validate time if provided
        if (isset($data['event_time']) && !empty($data['event_time'])) {
            $time = \DateTime::createFromFormat('H:i:s', $data['event_time']);
            if (!$time) {
                $time = \DateTime::createFromFormat('H:i', $data['event_time']);
                if ($time) {
                    $data['event_time'] = $time->format('H:i:s');
                } else {
                    throw new Exception('Invalid event_time format. Use HH:MM or HH:MM:SS');
                }
            }
        }

        $currentUser = $GLOBALS['current_user'] ?? null;
        
        $updated = $this->eventRepository->update($id, $data);

        if ($updated) {
            $changes = [];
            foreach ($data as $key => $value) {
                if (isset($event[$key]) && $event[$key] != $value) {
                    $changes[$key] = [
                        'old' => $event[$key],
                        'new' => $value
                    ];
                }
            }

            LogService::log(
                'EVENT_UPDATED',
                "Event updated: {$event['name']}",
                [
                    'event_id' => $id,
                    'changes' => $changes
                ],
                $currentUser['id'] ?? null,
                $event['stadium_id'],
                'events',
                $id
            );
        }

        return $updated;
    }

    /**
     * Delete event (soft delete)
     */
    public function deleteEvent(int $id): bool {
        $event = $this->getEventById($id);
        
        $currentUser = $GLOBALS['current_user'] ?? null;

        $deleted = $this->eventRepository->delete($id);

        if ($deleted) {
            LogService::log(
                'EVENT_DELETED',
                "Event deactivated: {$event['name']}",
                ['event_id' => $id],
                $currentUser['id'] ?? null,
                $event['stadium_id'],
                'events',
                $id
            );
        }

        return $deleted;
    }
}