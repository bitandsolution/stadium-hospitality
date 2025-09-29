<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/EventServices.php                 *
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
use Hospitality\Repositories\StadiumRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class EventService {
    private EventRepository $eventRepository;
    private StadiumRepository $stadiumRepository;

    public function __construct() {
        $this->eventRepository = new EventRepository();
        $this->stadiumRepository = new StadiumRepository();
    }

    public function createEvent(array $eventData): array {
        $errors = Validator::validateRequired($eventData, ['stadium_id', 'name', 'event_date']);

        if (!empty($eventData['stadium_id']) && !Validator::validateId($eventData['stadium_id'])) {
            $errors[] = 'Invalid stadium ID';
        }

        if (!empty($eventData['event_date'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventData['event_date'])) {
                $errors[] = 'Invalid date format (use YYYY-MM-DD)';
            }
        }

        if (!empty($eventData['event_time'])) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventData['event_time'])) {
                $errors[] = 'Invalid time format (use HH:MM or HH:MM:SS)';
            }
        }

        if (!empty($eventData['capacity']) && (!is_numeric($eventData['capacity']) || $eventData['capacity'] < 1)) {
            $errors[] = 'Capacity must be a positive number';
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        $stadium = $this->stadiumRepository->findById($eventData['stadium_id']);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        if ($this->eventRepository->nameExistsInStadium(
            $eventData['name'], 
            $eventData['stadium_id'],
            $eventData['event_date']
        )) {
            throw new Exception('Event with same name already exists on this date in this stadium');
        }

        $eventId = $this->eventRepository->create($eventData);

        LogService::log(
            'EVENT_CREATE',
            'Event created',
            [
                'event_id' => $eventId,
                'event_name' => $eventData['name'],
                'event_date' => $eventData['event_date'],
                'stadium_id' => $eventData['stadium_id']
            ],
            $GLOBALS['current_user']['id'] ?? null,
            $eventData['stadium_id'],
            'events',
            $eventId
        );

        Logger::info('Event created successfully', [
            'event_id' => $eventId,
            'name' => $eventData['name']
        ]);

        return $this->eventRepository->findById($eventId);
    }

    public function getEventsByStadium(int $stadiumId, array $filters = []): array {
        $filters['active_only'] = !isset($filters['include_inactive']);
        return $this->eventRepository->findByStadium($stadiumId, $filters);
    }

    public function getEventById(int $id): array {
        $event = $this->eventRepository->findById($id);
        
        if (!$event) {
            throw new Exception('Event not found');
        }

        return $event;
    }

    public function updateEvent(int $id, array $data): bool {
        $event = $this->eventRepository->findById($id);
        if (!$event) {
            throw new Exception('Event not found');
        }

        if (isset($data['event_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['event_date'])) {
            throw new Exception('Invalid date format (use YYYY-MM-DD)');
        }

        if (isset($data['event_time']) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $data['event_time'])) {
            throw new Exception('Invalid time format (use HH:MM or HH:MM:SS)');
        }

        if (isset($data['capacity']) && (!is_numeric($data['capacity']) || $data['capacity'] < 1)) {
            throw new Exception('Capacity must be a positive number');
        }

        if (isset($data['name']) && isset($data['event_date'])) {
            $checkDate = $data['event_date'];
        } elseif (isset($data['name'])) {
            $checkDate = $event['event_date'];
        } else {
            $checkDate = null;
        }

        if (isset($data['name']) && $checkDate && $data['name'] !== $event['name']) {
            if ($this->eventRepository->nameExistsInStadium(
                $data['name'], 
                $event['stadium_id'],
                $checkDate,
                $id
            )) {
                throw new Exception('Event with same name already exists on this date');
            }
        }

        $updated = $this->eventRepository->update($id, $data);

        if ($updated) {
            LogService::log(
                'EVENT_UPDATE',
                'Event details updated',
                ['changes' => array_keys($data)],
                $GLOBALS['current_user']['id'] ?? null,
                $event['stadium_id'],
                'events',
                $id
            );
        }

        return $updated;
    }

    public function deleteEvent(int $id): bool {
        $event = $this->eventRepository->findById($id);
        if (!$event) {
            throw new Exception('Event not found');
        }

        if ($event['total_guests'] > 0) {
            throw new Exception('Cannot delete event with existing guests. Please delete or reassign guests first.');
        }

        $deleted = $this->eventRepository->delete($id);

        if ($deleted) {
            LogService::log(
                'EVENT_DELETE',
                'Event deactivated',
                ['event_name' => $event['name'], 'event_date' => $event['event_date']],
                $GLOBALS['current_user']['id'] ?? null,
                $event['stadium_id'],
                'events',
                $id
            );
        }

        return $deleted;
    }

    public function getEventWithStatistics(int $id): array {
        $event = $this->getEventById($id);
        $stats = $this->eventRepository->getEventStatistics($id);

        return [
            'event' => $event,
            'statistics' => $stats
        ];
    }

    public function getUpcomingEvents(int $stadiumId, int $limit = 5): array {
        return $this->eventRepository->getUpcomingEvents($stadiumId, $limit);
    }
}