<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/StadiumService.php                *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/


namespace Hospitality\Services;

use Hospitality\Repositories\StadiumRepository;
use Hospitality\Repositories\UserRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class StadiumService {
    private StadiumRepository $stadiumRepository;
    private UserRepository $userRepository;

    public function __construct() {
        $this->stadiumRepository = new StadiumRepository();
        $this->userRepository = new UserRepository();
    }

    /**
     * Create new stadium with admin user
     */
    public function createStadium(array $stadiumData, array $adminData): array {
        // Validation stadium
        $errors = Validator::validateRequired($stadiumData, ['name']);
        
        if (!empty($stadiumData['contact_email']) && !Validator::validateEmail($stadiumData['contact_email'])) {
            $errors[] = 'Invalid stadium contact email';
        }

        // Validation admin user
        $adminErrors = Validator::validateRequired($adminData, ['username', 'email', 'password', 'full_name']);
        $errors = array_merge($errors, $adminErrors);

        if (!empty($adminData['email']) && !Validator::validateEmail($adminData['email'])) {
            $errors[] = 'Invalid admin email';
        }

        if (!empty($adminData['password'])) {
            $passwordErrors = Validator::validatePassword($adminData['password']);
            $errors = array_merge($errors, $passwordErrors);
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Check stadium name uniqueness
        if ($this->stadiumRepository->nameExists($stadiumData['name'])) {
            throw new Exception('Stadium name already exists');
        }

        // Check admin username uniqueness (across all stadiums)
        $existingUser = $this->userRepository->findByUsername($adminData['username']);
        if ($existingUser) {
            throw new Exception('Admin username already exists');
        }

        // Create stadium + admin
        $result = $this->stadiumRepository->create($stadiumData, $adminData);

        // Log operation
        LogService::log(
            'STADIUM_CREATE',
            'New stadium created with admin user',
            [
                'stadium_id' => $result['stadium_id'],
                'stadium_name' => $result['stadium_name'],
                'admin_username' => $result['admin_username']
            ],
            1, // Created by super_admin
            null,
            'stadiums',
            $result['stadium_id']
        );

        Logger::info('Stadium created successfully', [
            'stadium_id' => $result['stadium_id'],
            'stadium_name' => $result['stadium_name']
        ]);

        return $result;
    }

    /**
     * Get all stadiums (super_admin only)
     */
    public function getAllStadiums(bool $activeOnly = true): array {
        return $this->stadiumRepository->findAll($activeOnly);
    }

    /**
     * Get stadium details by ID
     */
    public function getStadiumById(int $id): array {
        $stadium = $this->stadiumRepository->findById($id);
        
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        return $stadium;
    }

    /**
     * Update stadium
     */
    public function updateStadium(int $id, array $data): bool {
        // Check stadium exists
        $stadium = $this->stadiumRepository->findById($id);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        // Validate email if provided
        if (!empty($data['contact_email']) && !Validator::validateEmail($data['contact_email'])) {
            throw new Exception('Invalid contact email');
        }

        // Check name uniqueness if changing name
        if (isset($data['name']) && $data['name'] !== $stadium['name']) {
            if ($this->stadiumRepository->nameExists($data['name'], $id)) {
                throw new Exception('Stadium name already exists');
            }
        }

        $updated = $this->stadiumRepository->update($id, $data);

        if ($updated) {
            LogService::log(
                'STADIUM_UPDATE',
                'Stadium details updated',
                ['changes' => array_keys($data)],
                1,
                $id,
                'stadiums',
                $id
            );
        }

        return $updated;
    }

    /**
     * Delete stadium (soft delete)
     */
    public function deleteStadium(int $id): bool {
        $stadium = $this->stadiumRepository->findById($id);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        $deleted = $this->stadiumRepository->delete($id);

        if ($deleted) {
            LogService::log(
                'STADIUM_DELETE',
                'Stadium deactivated',
                ['stadium_name' => $stadium['name']],
                1,
                $id,
                'stadiums',
                $id
            );
        }

        return $deleted;
    }

    /**
     * Get stadium statistics
     */
    public function getStadiumStatistics(int $id): array {
        $stadium = $this->stadiumRepository->findById($id);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        return $this->stadiumRepository->getStatistics($id);
    }
}