<?php
/*********************************************************
*                                                        *
*   FILE: src/Services/UserService.php                   *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*********************************************************/


namespace Hospitality\Services;

use Hospitality\Repositories\UserRepository;
use Hospitality\Repositories\StadiumRepository;
use Hospitality\Utils\Validator;
use Hospitality\Utils\Logger;
use Exception;

class UserService {
    private UserRepository $userRepository;
    private StadiumRepository $stadiumRepository;

    public function __construct() {
        $this->userRepository = new UserRepository();
        $this->stadiumRepository = new StadiumRepository();
    }

    public function createUser(array $userData): array {
        // Validation
        $errors = Validator::validateRequired($userData, [
            'stadium_id', 'username', 'email', 'password', 'full_name', 'role'
        ]);

        if (!empty($userData['email']) && !Validator::validateEmail($userData['email'])) {
            $errors[] = 'Invalid email format';
        }

        if (!empty($userData['role']) && !Validator::validateRole($userData['role'])) {
            $errors[] = 'Invalid role';
        }

        if (!empty($userData['password'])) {
            $passwordErrors = Validator::validatePassword($userData['password']);
            $errors = array_merge($errors, $passwordErrors);
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Check stadium exists
        $stadium = $this->stadiumRepository->findById($userData['stadium_id']);
        if (!$stadium) {
            throw new Exception('Stadium not found');
        }

        // Check username uniqueness
        if ($this->userRepository->usernameExists($userData['username'], $userData['stadium_id'])) {
            throw new Exception('Username already exists in this stadium');
        }

        // Check email uniqueness
        if ($this->userRepository->emailExists($userData['email'], $userData['stadium_id'])) {
            throw new Exception('Email already exists in this stadium');
        }

        // Create user
        $userId = $this->userRepository->create([
            'stadium_id' => $userData['stadium_id'],
            'username' => $userData['username'],
            'email' => $userData['email'],
            'password' => $userData['password'],
            'role' => $userData['role'],
            'full_name' => $userData['full_name'],
            'phone' => $userData['phone'] ?? null,
            'language' => $userData['language'] ?? 'it',
            'created_by' => $GLOBALS['current_user']['id'] ?? 1
        ]);

        LogService::log(
            'USER_CREATE',
            'New user created',
            [
                'user_id' => $userId,
                'username' => $userData['username'],
                'role' => $userData['role']
            ],
            $GLOBALS['current_user']['id'] ?? null,
            $userData['stadium_id'],
            'users',
            $userId
        );

        Logger::info('User created successfully', [
            'user_id' => $userId,
            'username' => $userData['username'],
            'role' => $userData['role']
        ]);

        return $this->userRepository->findById($userId);
    }

    public function getUsersByStadium(int $stadiumId, ?string $role = null): array {
        return $this->userRepository->findByStadium($stadiumId, $role);
    }

    public function getUserById(int $id): array {
        $user = $this->userRepository->findById($id);
        
        if (!$user) {
            throw new Exception('User not found');
        }

        // Remove sensitive data
        unset($user['password_hash']);

        return $user;
    }

    public function updateUser(int $id, array $data): bool {
        $user = $this->userRepository->findById($id);
        if (!$user) {
            throw new Exception('User not found');
        }

        // Validate email if changing
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            if (!Validator::validateEmail($data['email'])) {
                throw new Exception('Invalid email format');
            }
            if ($this->userRepository->emailExists($data['email'], $user['stadium_id'], $id)) {
                throw new Exception('Email already exists');
            }
        }

        // Validate username if changing
        if (isset($data['username']) && $data['username'] !== $user['username']) {
            if ($this->userRepository->usernameExists($data['username'], $user['stadium_id'], $id)) {
                throw new Exception('Username already exists');
            }
        }

        $updated = $this->userRepository->update($id, $data);

        if ($updated) {
            LogService::log(
                'USER_UPDATE',
                'User details updated',
                ['changes' => array_keys($data)],
                $GLOBALS['current_user']['id'] ?? null,
                $user['stadium_id'],
                'users',
                $id
            );
        }

        return $updated;
    }

    public function deactivateUser(int $id): bool {
        $user = $this->userRepository->findById($id);
        if (!$user) {
            throw new Exception('User not found');
        }

        $deactivated = $this->userRepository->deactivate($id);

        if ($deactivated) {
            LogService::log(
                'USER_DEACTIVATE',
                'User deactivated',
                ['username' => $user['username']],
                $GLOBALS['current_user']['id'] ?? null,
                $user['stadium_id'],
                'users',
                $id
            );
        }

        return $deactivated;
    }

    public function getHostessWithRooms(int $stadiumId): array {
        return $this->userRepository->getHostessWithRooms($stadiumId);
    }
}