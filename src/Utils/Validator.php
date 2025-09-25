<?php
/******************************************************************
*                                                                 *
*   FILE: src/Utils/Validator.php                                 *
*                                                                 *
*   Author: Antonio Tartaglia - bitAND solution                   *
*   website: https://www.bitandsolution.it                        *
*   email:   info@bitandsolution.it                               *
*                                                                 *
*   Owner: bitAND solution                                        *
*                                                                 *
*   This is proprietary software                                  *
*   developed by bitAND solution for bitAND solution              *
*                                                                 *
******************************************************************/

namespace Hospitality\Utils;

class Validator {
    
    /**
     * Valida campi richiesti
     */
    public static function validateRequired(array $data, array $requiredFields): array {
        $errors = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty(trim((string)$data[$field]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        return $errors;
    }

    /**
     * Valida stringa con lunghezza
     */
    public static function validateString(string $value, int $minLength = 1, int $maxLength = 255): bool {
        $length = strlen(trim($value));
        return $length >= $minLength && $length <= $maxLength;
    }

    /**
     * Valida email
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Valida telefono
     */
    public static function validatePhone(string $phone): bool {
        $cleanPhone = preg_replace('/[\s\-\(\)]/', '', $phone);
        return preg_match('/^\+?[1-9]\d{1,14}$/', $cleanPhone);
    }

    /**
     * Valida password con criteri sicurezza
     */
    public static function validatePassword(string $password): array {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        if (strlen($password) > 128) {
            $errors[] = 'Password must be less than 128 characters';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return $errors;
    }

    /**
     * Valida ruolo utente
     */
    public static function validateRole(string $role): bool {
        return in_array($role, ['super_admin', 'stadium_admin', 'hostess']);
    }

    /**
     * Valida stadium_id per ruolo
     */
    public static function validateStadiumId(?int $stadiumId, string $userRole): bool {
        if ($userRole === 'super_admin') {
            return true; // Super admin può avere null stadium_id
        }
        
        return $stadiumId !== null && $stadiumId > 0;
    }

    /**
     * Valida query di ricerca
     */
    public static function validateSearchQuery(string $query): bool {
        // Minimo 2 caratteri, no caratteri speciali pericolosi
        $trimmed = trim($query);
        return strlen($trimmed) >= 2 && 
               strlen($trimmed) <= 100 &&
               preg_match('/^[a-zA-ZÀ-ÿ0-9\s\-\'\.]+$/u', $trimmed);
    }

    /**
     * Sanifica stringa di input
     */
    public static function sanitizeString(string $input): string {
        return trim(strip_tags($input));
    }

    /**
     * Valida ID numerico positivo
     */
    public static function validateId($id): bool {
        return is_numeric($id) && (int)$id > 0;
    }
}