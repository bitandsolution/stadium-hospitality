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
     * Create new stadium with initial admin user
     */
    public function createStadium(array $stadiumData, array $adminData): array {
        // Validate stadium data
        $errors = $this->validateStadiumData($stadiumData);
        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Validate admin data
        $adminErrors = $this->validateAdminData($adminData);
        if (!empty($adminErrors)) {
            throw new Exception('Admin validation failed: ' . implode(', ', $adminErrors));
        }

        // Check stadium name uniqueness
        if ($this->stadiumRepository->nameExists($stadiumData['name'])) {
            throw new Exception('Stadium name already exists');
        }

        // Check admin username uniqueness
        $existingUser = $this->userRepository->findByUsername($adminData['username']);
        if ($existingUser) {
            throw new Exception('Admin username already exists');
        }

        // Check admin email uniqueness
        $existingEmail = $this->userRepository->findByEmail($adminData['email']);
        if ($existingEmail) {
            throw new Exception('Admin email already exists');
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
     * Get all stadiums
     */
    public function getAllStadiums(bool $activeOnly = true): array {
        return $this->stadiumRepository->findAll($activeOnly);
    }

    /**
     * Get stadium by ID
     */
    public function getStadiumById(int $id): array {
        $stadium = $this->stadiumRepository->findById($id);
        
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        return $stadium;
    }

    /**
     * Get stadium statistics
     */
    public function getStadiumStatistics(int $id): array {
        return $this->stadiumRepository->getStatistics($id);
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

        // Validate colors if provided
        if (isset($data['primary_color']) && !$this->isValidHexColor($data['primary_color'])) {
            throw new Exception('Invalid primary color format');
        }

        if (isset($data['secondary_color']) && !$this->isValidHexColor($data['secondary_color'])) {
            throw new Exception('Invalid secondary color format');
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
                'Stadium information updated',
                ['stadium_id' => $id, 'changes' => array_keys($data)],
                null,
                null,
                'stadiums',
                $id
            );

            Logger::info('Stadium updated', ['stadium_id' => $id]);
        }

        return $updated;
    }

    /**
     * Delete (deactivate) stadium
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
                ['stadium_id' => $id, 'stadium_name' => $stadium['name']],
                null,
                null,
                'stadiums',
                $id
            );

            Logger::info('Stadium deleted', ['stadium_id' => $id]);
        }

        return $deleted;
    }

    /**
     * Upload stadium logo
     */
    public function uploadLogo(int $stadiumId, array $file): array {
        // Validate stadium exists
        $stadium = $this->stadiumRepository->findById($stadiumId);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        // Validate file
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type. Allowed: PNG, JPG, SVG');
        }

        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            throw new Exception('File too large. Maximum size: 2MB');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }

        // Create uploads directory if not exists
        $uploadDir = __DIR__ . '/../../httpdocs/uploads/logos';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'stadium_' . $stadiumId . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to save uploaded file');
        }

        // Delete old logo if exists
        if (!empty($stadium['logo_url'])) {
            $oldFile = __DIR__ . '/../../httpdocs' . parse_url($stadium['logo_url'], PHP_URL_PATH);
            if (file_exists($oldFile) && is_file($oldFile)) {
                unlink($oldFile);
            }
        }

        // Update stadium with new logo URL
        $logoUrl = '/uploads/logos/' . $filename;
        $this->stadiumRepository->update($stadiumId, ['logo_url' => $logoUrl]);

        Logger::info('Stadium logo uploaded', [
            'stadium_id' => $stadiumId,
            'filename' => $filename
        ]);

        return [
            'logo_url' => $logoUrl,
            'filename' => $filename
        ];
    }

    /**
     * Delete stadium logo
     */
    public function deleteLogo(int $stadiumId): bool {
        $stadium = $this->stadiumRepository->findById($stadiumId);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        if (empty($stadium['logo_url'])) {
            return true; // No logo to delete
        }

        // Delete file
        $filepath = __DIR__ . '/../../httpdocs' . parse_url($stadium['logo_url'], PHP_URL_PATH);
        if (file_exists($filepath) && is_file($filepath)) {
            unlink($filepath);
        }

        // Remove logo URL from database
        $this->stadiumRepository->update($stadiumId, ['logo_url' => null]);

        Logger::info('Stadium logo deleted', ['stadium_id' => $stadiumId]);

        return true;
    }

    // =====================================================
    // PRIVATE VALIDATION METHODS
    // =====================================================

    /**
     * Validate stadium data
     */
    private function validateStadiumData(array $data): array {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Stadium name is required';
        }

        if (empty($data['city'])) {
            $errors[] = 'City is required';
        }

        if (!empty($data['contact_email']) && !Validator::validateEmail($data['contact_email'])) {
            $errors[] = 'Invalid contact email format';
        }

        if (isset($data['capacity']) && !is_numeric($data['capacity'])) {
            $errors[] = 'Capacity must be a number';
        }

        if (isset($data['primary_color']) && !$this->isValidHexColor($data['primary_color'])) {
            $errors[] = 'Invalid primary color format';
        }

        if (isset($data['secondary_color']) && !$this->isValidHexColor($data['secondary_color'])) {
            $errors[] = 'Invalid secondary color format';
        }

        return $errors;
    }

    /**
     * Validate admin user data
     */
    private function validateAdminData(array $data): array {
        $errors = [];

        if (empty($data['username'])) {
            $errors[] = 'Admin username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors[] = 'Admin username must be at least 3 characters';
        }

        if (empty($data['email'])) {
            $errors[] = 'Admin email is required';
        } elseif (!Validator::validateEmail($data['email'])) {
            $errors[] = 'Invalid admin email format';
        }

        if (empty($data['password'])) {
            $errors[] = 'Admin password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors[] = 'Admin password must be at least 8 characters';
        }

        if (empty($data['full_name'])) {
            $errors[] = 'Admin full name is required';
        }

        return $errors;
    }

    /**
     * Validate hex color format
     */
    private function isValidHexColor(string $color): bool {
        return preg_match('/^#[0-9A-F]{6}$/i', $color) === 1;
    }
}