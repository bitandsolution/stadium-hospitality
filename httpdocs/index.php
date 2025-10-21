<?php
// =====================================================
// FILE: httpdocs/index.php - Entry Point Complete Fixed
// =====================================================

if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

try {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Composer autoload not found. Run: composer install");
    }
    require_once $autoloadPath;

    $envPath = __DIR__ . '/..';
    if (file_exists($envPath . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->load();
    } else {
        $_ENV['APP_TIMEZONE'] = 'Europe/Rome';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['JWT_SECRET'] = 'fallback-secret-change-in-production';
    }

} catch (Exception $e) {
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Bootstrap error: ' . $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT));
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Stadium-Id');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$startTime = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

if (($pos = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $pos);
}

error_log("API Request: $method $path");

try {
    $routed = false;

    // =====================================================
    // HOMEPAGE PWA
    // =====================================================
    
    if ($path === '/' && $method === 'GET') {
        servePWAHomepage();
        exit;
    }

    // =====================================================
    // PUBLIC ROUTES
    // =====================================================
    
    if ($path === '/api/health' && $method === 'GET') {
        handleHealthCheck();
        $routed = true;
    }

    elseif ($path === '/api/admin/guests/import/template' && $method === 'GET') {
        // Template download
        $controller = new Hospitality\Controllers\GuestImportController();
        $controller->downloadTemplate();
        $routed = true;
    }

    elseif ($path === '/api/auth/login' && $method === 'POST') {
        $controller = new Hospitality\Controllers\AuthController();
        $controller->login();
        $routed = true;
    }
    
    elseif ($path === '/api/auth/login' && $method === 'POST') {
        $controller = new Hospitality\Controllers\AuthController();
        $controller->login();
        $routed = true;
    }
    
    elseif ($path === '/api/auth/refresh' && $method === 'POST') {
        $controller = new Hospitality\Controllers\AuthController();
        $controller->refresh();
        $routed = true;
    }

    // =====================================================
    // PROTECTED AUTH ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/auth/')) {
        $controller = new Hospitality\Controllers\AuthController();
        
        switch ($path) {
            case '/api/auth/logout':
                if ($method === 'POST') {
                    $controller->logout();
                    $routed = true;
                }
                break;
                
            case '/api/auth/me':
                if ($method === 'GET') {
                    $controller->me();
                    $routed = true;
                }
                break;
                
            case '/api/auth/change-password':
                if ($method === 'POST') {
                    $controller->changePassword();
                    $routed = true;
                }
                break;
        }
    }


    elseif ($path === '/api/debug/user-context' && $method === 'GET') {
        // 🔍 DEBUG ENDPOINT - Rimuovere in produzione
        if (($_ENV['APP_DEBUG'] ?? 'false') !== 'true') {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        
        try {
            $decoded = Hospitality\Middleware\AuthMiddleware::handle();
            if (!$decoded) exit;
            
            $db = Hospitality\Config\Database::getInstance()->getConnection();
            
            // Get user full data
            $stmt = $db->prepare("
                SELECT u.*, s.name as stadium_name
                FROM users u
                LEFT JOIN stadiums s ON u.stadium_id = s.id
                WHERE u.id = ?
            ");
            $stmt->execute([$decoded->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            unset($user['password_hash']);
            
            // Get room assignments if hostess
            $roomAssignments = [];
            if ($user['role'] === 'hostess') {
                $stmt = $db->prepare("
                    SELECT 
                        hr.id, hr.name, hr.capacity, hr.vip_level,
                        COUNT(DISTINCT g.id) as guest_count
                    FROM user_room_assignments ura
                    JOIN hospitality_rooms hr ON ura.room_id = hr.id
                    LEFT JOIN guests g ON g.room_id = hr.id AND g.is_active = 1
                    WHERE ura.user_id = ? AND ura.is_active = 1
                    GROUP BY hr.id, hr.name, hr.capacity, hr.vip_level
                ");
                $stmt->execute([$decoded->user_id]);
                $roomAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'debug_data' => [
                    'user' => $user,
                    'jwt_decoded' => [
                        'user_id' => $decoded->user_id,
                        'stadium_id' => $decoded->stadium_id,
                        'role' => $decoded->role,
                        'permissions' => $decoded->permissions ?? []
                    ],
                    'room_assignments' => $roomAssignments,
                    'globals_user' => $GLOBALS['current_user'] ?? null
                ],
                'recommendations' => [
                    'expected_view' => $user['role'] === 'hostess' ? 'Check-in/Check-out Interface' : 'Admin Dashboard',
                    'has_room_access' => count($roomAssignments) > 0,
                    'should_see_guests' => $user['role'] === 'hostess' && count($roomAssignments) > 0
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], JSON_PRETTY_PRINT);
        }
        $routed = true;
    }

    // =====================================================
    // ADMIN EVENT ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/events')) {
        $controller = new Hospitality\Controllers\EventController();
        
        if ($path === '/api/admin/events/upcoming' && $method === 'GET') {
            $controller->upcoming();
            $routed = true;
        }
        elseif ($path === '/api/admin/events' && $method === 'POST') {
            $controller->create();
            $routed = true;
        }
        elseif ($path === '/api/admin/events' && $method === 'GET') {
            $controller->index();
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/events\/(\d+)$/', $path, $matches)) {
            $eventId = (int)$matches[1];
            
            if ($method === 'GET') {
                $controller->show($eventId);
                $routed = true;
            }
            elseif ($method === 'PUT') {
                $controller->update($eventId);
                $routed = true;
            }
            elseif ($method === 'DELETE') {
                $controller->delete($eventId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // ADMIN STADIUM ROUTES
    // =====================================================
    
    elseif (str_starts_with($path, '/api/admin/stadiums')) {
        $controller = new Hospitality\Controllers\StadiumController();
        
        if ($path === '/api/admin/stadiums' && $method === 'POST') {
            $controller->create();
            $routed = true;
        }
        elseif ($path === '/api/admin/stadiums' && $method === 'GET') {
            $controller->index();
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/stadiums\/(\d+)$/', $path, $matches)) {
            $stadiumId = (int)$matches[1];
            
            if ($method === 'GET') {
                $controller->show($stadiumId);
                $routed = true;
            }
            elseif ($method === 'PUT') {
                $controller->update($stadiumId);
                $routed = true;
            }
            elseif ($method === 'DELETE') {
                $controller->delete($stadiumId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // ADMIN ROOM ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/rooms')) {
        $controller = new Hospitality\Controllers\RoomController();
        
        if ($path === '/api/admin/rooms' && $method === 'POST') {
            $controller->create();
            $routed = true;
        }
        elseif ($path === '/api/admin/rooms' && $method === 'GET') {
            $controller->index();
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/rooms\/(\d+)$/', $path, $matches)) {
            $roomId = (int)$matches[1];
            
            if ($method === 'GET') {
                $controller->show($roomId);
                $routed = true;
            }
            elseif ($method === 'PUT') {
                $controller->update($roomId);
                $routed = true;
            }
            elseif ($method === 'DELETE') {
                $controller->delete($roomId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // ADMIN USER ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/users')) {
        
        // Room assignment routes - must come BEFORE generic user routes
        if (preg_match('/^\/api\/admin\/users\/(\d+)\/rooms\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
            $controller = new Hospitality\Controllers\RoomAssignmentController();
            $controller->removeRoomAssignment((int)$matches[1], (int)$matches[2]);
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/users\/(\d+)\/rooms$/', $path, $matches)) {
            $controller = new Hospitality\Controllers\RoomAssignmentController();
            
            if ($method === 'POST') {
                $controller->assignRooms((int)$matches[1]);
                $routed = true;
            }
            elseif ($method === 'GET') {
                $controller->getAssignedRooms((int)$matches[1]);
                $routed = true;
            }
        }
        // Standard user CRUD routes
        elseif ($path === '/api/admin/users' && $method === 'POST') {
            $controller = new Hospitality\Controllers\UserController();
            $controller->create();
            $routed = true;
        }
        elseif ($path === '/api/admin/users' && $method === 'GET') {
            $controller = new Hospitality\Controllers\UserController();
            $controller->index();
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches)) {
            $controller = new Hospitality\Controllers\UserController();
            $userId = (int)$matches[1];
            
            if ($method === 'GET') {
                $controller->show($userId);
                $routed = true;
            }
            elseif ($method === 'PUT') {
                $controller->update($userId);
                $routed = true;
            }
            elseif ($method === 'DELETE') {
                $controller->delete($userId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // ADMIN GUEST IMPORT ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/guests/import')) {
        
        if ($path === '/api/admin/guests/import/template' && $method === 'GET') {
            $controller = new Hospitality\Controllers\GuestImportController();
            $controller->downloadTemplate();
            $routed = true;
        }
        elseif ($path === '/api/admin/guests/import' && $method === 'POST') {
            $controller = new Hospitality\Controllers\GuestImportController();
            $controller->import();
            $routed = true;
        }
    }

    // =====================================================
    // ADMIN GUEST CRUD ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/guests') && !str_contains($path, '/import')) {
        $controller = new Hospitality\Controllers\GuestCrudController();
        
        if ($path === '/api/admin/guests' && $method === 'POST') {
            $controller->create();
            $routed = true;
        }
        elseif ($path === '/api/admin/guests' && $method === 'GET') {
            $controller->list();
            $routed = true;
        }
        elseif (preg_match('/^\/api\/admin\/guests\/(\d+)$/', $path, $matches)) {
            $guestId = (int)$matches[1];
            
            if ($method === 'PUT') {
                $controller->update($guestId);
                $routed = true;
            }
            elseif ($method === 'DELETE') {
                $controller->delete($guestId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // ADMIN UTILITY ROUTES (Solo Super Admin)
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/utilities')) {
        $controller = new Hospitality\Controllers\UtilityController();
        
        if ($path === '/api/admin/utilities/generate-password-hash' && $method === 'POST') {
            $controller->generatePasswordHash();
            $routed = true;
        }
    }

    // =====================================================
    // ADMIN STADIUM ROUTES
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/stadiums')) {
        $controller = new Hospitality\Controllers\StadiumController();
        
        // POST /api/admin/stadiums - Create new stadium
        if ($path === '/api/admin/stadiums' && $method === 'POST') {
            $controller->create();
            $routed = true;
        }
        // GET /api/admin/stadiums - List all stadiums
        elseif ($path === '/api/admin/stadiums' && $method === 'GET') {
            $controller->index();
            $routed = true;
        }
        // GET /api/admin/stadiums/{id} - Get stadium details
        elseif (preg_match('/^\/api\/admin\/stadiums\/(\d+)$/', $path, $matches)) {
            $stadiumId = (int)$matches[1];
            
            if ($method === 'GET') {
                $controller->show($stadiumId);
                $routed = true;
            }
            // PUT /api/admin/stadiums/{id} - Update stadium
            elseif ($method === 'PUT') {
                $controller->update($stadiumId);
                $routed = true;
            }
            // DELETE /api/admin/stadiums/{id} - Delete stadium
            elseif ($method === 'DELETE') {
                $controller->delete($stadiumId);
                $routed = true;
            }
        }
        // POST /api/admin/stadiums/{id}/logo - Upload logo
        elseif (preg_match('/^\/api\/admin\/stadiums\/(\d+)\/logo$/', $path, $matches)) {
            $stadiumId = (int)$matches[1];
            
            if ($method === 'POST') {
                $controller->uploadLogo($stadiumId);
                $routed = true;
            }
            // DELETE /api/admin/stadiums/{id}/logo - Delete logo
            elseif ($method === 'DELETE') {
                $controller->deleteLogo($stadiumId);
                $routed = true;
            }
        }
    }

    // =====================================================
    // EVENT ROUTES 
    // =====================================================

    elseif (str_starts_with($path, '/api/admin/events')) {
        // Event management routes
        
        if ($path === '/api/admin/events' && $method === 'POST') {
            // Create event
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->create();
            } catch (Exception $e) {
                error_log("Event creation error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Event creation failed',
                    'error' => $e->getMessage(),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif ($path === '/api/admin/events' && $method === 'GET') {
            // List events
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->index();
            } catch (Exception $e) {
                error_log("Event list error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to retrieve events',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif ($path === '/api/admin/events/upcoming' && $method === 'GET') {
            // Get upcoming events
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->upcoming();
            } catch (Exception $e) {
                error_log("Upcoming events error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to retrieve upcoming events',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/events\/(\d+)$/', $path, $matches) && $method === 'GET') {
            // Get event details
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->show((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Event details error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to get event details',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/events\/(\d+)$/', $path, $matches) && $method === 'PUT') {
            // Update event
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->update((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Event update error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update event',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/events\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
            // Delete event
            try {
                $controller = new Hospitality\Controllers\EventController();
                $controller->delete((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Event deletion error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to delete event',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } else {
            // Event endpoint not found
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Event endpoint not found',
                'available_endpoints' => [
                    'POST /api/admin/events' => 'Create event',
                    'GET /api/admin/events?stadium_id=X' => 'List events',
                    'GET /api/admin/events/upcoming?stadium_id=X' => 'Upcoming events',
                    'GET /api/admin/events/{id}' => 'Event details',
                    'PUT /api/admin/events/{id}' => 'Update event',
                    'DELETE /api/admin/events/{id}' => 'Delete event'
                ],
                'requested' => "$method $path",
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
            $routed = true;
        }
    }

    // =====================================================
    // USERS ROUTES 
    // =====================================================
    elseif (str_starts_with($path, '/api/admin/users')) {
        // User management routes
        
        if ($path === '/api/admin/users' && $method === 'POST') {
            // Create user
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->create();
            } catch (Exception $e) {
                error_log("User creation error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'User creation failed',
                    'error' => $e->getMessage(),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif ($path === '/api/admin/users' && $method === 'GET') {
            // List users
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->index();
            } catch (Exception $e) {
                error_log("User list error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to retrieve users',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)\/rooms$/', $path, $matches) && $method === 'GET') {
            // Get user's assigned rooms
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->getRooms((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Get user rooms error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to retrieve rooms',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)\/rooms$/', $path, $matches) && $method === 'POST') {
            // Assign rooms to user
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->assignRooms((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Assign rooms error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to assign rooms',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)\/rooms\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
            // Remove single room assignment
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->removeRoom((int)$matches[1], (int)$matches[2]);
            } catch (Exception $e) {
                error_log("Remove room error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to remove room',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches) && $method === 'GET') {
            // Get user details
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->show((int)$matches[1]);
            } catch (Exception $e) {
                error_log("User details error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to get user details',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches) && $method === 'PUT') {
            // Update user
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->update((int)$matches[1]);
            } catch (Exception $e) {
                error_log("User update error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to update user',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/admin\/users\/(\d+)$/', $path, $matches) && $method === 'DELETE') {
            // Delete user
            try {
                $controller = new Hospitality\Controllers\UserController();
                $controller->delete((int)$matches[1]);
            } catch (Exception $e) {
                error_log("User deletion error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to delete user',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } else {
            // User endpoint not found
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'User endpoint not found',
                'available_endpoints' => [
                    'POST /api/admin/users' => 'Create user',
                    'GET /api/admin/users?stadium_id=X&role=hostess' => 'List users',
                    'GET /api/admin/users/{id}' => 'User details',
                    'PUT /api/admin/users/{id}' => 'Update user',
                    'DELETE /api/admin/users/{id}' => 'Delete user',
                    'GET /api/admin/users/{id}/rooms' => 'Get assigned rooms',
                    'POST /api/admin/users/{id}/rooms' => 'Assign rooms',
                    'DELETE /api/admin/users/{id}/rooms/{roomId}' => 'Remove room'
                ],
                'requested' => "$method $path",
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
            $routed = true;
        }
    }

    // =====================================================
    // GUEST ACCESS ROUTES (Check-in/Check-out)
    // =====================================================

    elseif (preg_match('/^\/api\/guests\/(\d+)\/checkin$/', $path, $matches) && $method === 'POST') {
        try {
            $controller = new Hospitality\Controllers\GuestAccessController();
            $controller->checkin((int)$matches[1]);
        } catch (Exception $e) {
            error_log("Check-in error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Check-in failed',
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        $routed = true;
    }

    elseif (preg_match('/^\/api\/guests\/(\d+)\/checkout$/', $path, $matches) && $method === 'POST') {
        try {
            $controller = new Hospitality\Controllers\GuestAccessController();
            $controller->checkout((int)$matches[1]);
        } catch (Exception $e) {
            error_log("Check-out error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Check-out failed',
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        $routed = true;
    }

    elseif (preg_match('/^\/api\/guests\/(\d+)\/access-history$/', $path, $matches) && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\GuestAccessController();
            $controller->getAccessHistory((int)$matches[1]);
        } catch (Exception $e) {
            error_log("Access history error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to get access history',
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        $routed = true;
    }
    
    // =====================================================
    // GUEST ROUTES (Search, Details, Update)
    // =====================================================
    
    elseif (str_starts_with($path, '/api/guests')) {
        $controller = new Hospitality\Controllers\GuestController();
        
        // Search guests with filters
        if ($path === '/api/guests/search' && $method === 'GET') {
            $controller->search();
            $routed = true;
        }
        // Quick search autocomplete
        elseif ($path === '/api/guests/quick-search' && $method === 'GET') {
            $controller->quickSearch();
            $routed = true;
        }
        // Get guest details
        elseif (preg_match('/^\/api\/guests\/(\d+)$/', $path, $matches) && $method === 'GET') {
            $controller->show((int)$matches[1]);
            $routed = true;
        }
        // Update guest (hostess full edit with email notification)
        elseif (preg_match('/^\/api\/guests\/(\d+)$/', $path, $matches) && $method === 'PUT') {
            if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
            $controller->update((int)$matches[1]);
            $routed = true;
        }
    }

    // =====================================================
    // ROOM ACCESS ROUTES
    // =====================================================
    
    elseif (str_starts_with($path, '/api/rooms/') && str_contains($path, '/current-guests')) {
        // Current guests in room
        if (preg_match('/^\/api\/rooms\/(\d+)\/current-guests$/', $path, $matches) && $method === 'GET') {
            try {
                $controller = new Hospitality\Controllers\GuestAccessController();
                $controller->getCurrentGuestsInRoom((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Current guests error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to get current guests',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
        }
    }

    // =====================================================
    // STATISTICS DASHBOARD
    // =====================================================
    elseif ($path === '/api/dashboard/stats' && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\DashboardController();
            $controller->stats();
        } catch (Exception $e) {
            error_log("Dashboard stats error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load stats',
                'timestamp' => date('c')
            ]);
        }
        $routed = true;
    }

    elseif (str_starts_with($path, '/api/dashboard')) {
        // Dashboard and analytics
        handleDashboardRoutes($path, $method);
        $routed = true;
    }
    
    elseif (str_starts_with($path, '/api/statistics')) {
        // Statistics routes
        handleStatisticsRoutes($path, $method);
        $routed = true;
    }

    // =====================================================
    // UPCOMING EVENTS
    // =====================================================
    elseif ($path === '/api/dashboard/upcoming-events' && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\DashboardController();
            $controller->upcomingEvents();
        } catch (Exception $e) {
            error_log("Dashboard events error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load events',
                'timestamp' => date('c')
            ]);
        }
        $routed = true;
    }

    // =====================================================
    // USER ROUTES (placeholder)
    // =====================================================
    
    elseif (str_starts_with($path, '/api/users')) {
        handleUserRoutes($path, $method);
        $routed = true;
    }

    // =====================================================
    // 404 - Route not found
    // =====================================================
    
    if (!$routed) {
        sendNotFound($path, $method);
    }

} catch (Exception $e) {
    error_log('Request handling error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? $e->getMessage() : 'Internal server error',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}

$executionTime = (microtime(true) - $startTime) * 1000;
if ($executionTime > 1000) {
    error_log("Slow request: $method $path took " . round($executionTime, 2) . "ms");
}

// =====================================================
// ROUTE HANDLERS
// =====================================================

function handleHealthCheck(): void {
    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'version' => '1.5.0',
        'php_version' => PHP_VERSION,
        'system' => [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]
    ];

    try {
        $db = Hospitality\Config\Database::getInstance();
        $connection = $db->getConnection();
        
        $stmt = $connection->prepare('SELECT COUNT(*) as user_count FROM users WHERE is_active = 1');
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $health['database'] = [
            'status' => 'connected',
            'active_users' => (int)$result['user_count']
        ];
        
        $stmt = $connection->prepare('SELECT COUNT(*) as stadium_count FROM stadiums WHERE is_active = 1');
        $stmt->execute();
        $stadiumResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $health['database']['active_stadiums'] = (int)$stadiumResult['stadium_count'];
        
    } catch (Exception $e) {
        $health['status'] = 'degraded';
        $health['database'] = [
            'status' => 'connection_failed',
            'error' => $e->getMessage()
        ];
    }

    http_response_code($health['status'] === 'healthy' ? 200 : 503);
    echo json_encode($health, JSON_PRETTY_PRINT);
}

function handleUserRoutes(string $path, string $method): void {
    if ($path === '/api/users' && $method === 'GET') {
        if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
        sendNotImplemented('User listing not yet implemented');
    } 
    elseif (preg_match('/^\/api\/users\/(\d+)$/', $path, $matches) && $method === 'GET') {
        if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
        sendNotImplemented("User details for ID {$matches[1]} not yet implemented");
    } 
    else {
        sendMethodNotAllowed();
    }
}

function sendNotFound(string $path, string $method): void {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Endpoint not found',
        'requested_path' => $path,
        'method' => $method,
        'available_endpoints' => [
            'GET /api/health' => 'System health check',
            'POST /api/auth/login' => 'User authentication',
            'GET /api/auth/me' => 'Current user info',
            'POST /api/admin/stadiums' => 'Create stadium',
            'GET /api/admin/stadiums' => 'List stadiums',
            'POST /api/admin/events' => 'Create event',
            'GET /api/admin/events' => 'List events',
            'POST /api/admin/users' => 'Create user',
            'GET /api/guests/search' => 'Search guests',
            'PUT /api/guests/{id}' => 'Update guest',
            'POST /api/guests/{id}/checkin' => 'Check-in guest',
            'POST /api/guests/{id}/checkout' => 'Check-out guest',
            'GET /api/guests/{id}/access-history' => 'Guest history',
            'GET /api/rooms/{id}/current-guests' => 'Current guests'
        ],
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}

function sendMethodNotAllowed(): void {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed for this endpoint',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}

function sendNotImplemented(string $message = 'Endpoint not yet implemented'): void {
    http_response_code(501);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'status' => 'coming_soon',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
}

function servePWAHomepage(): void {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#2563eb">
        <title>Hospitality Manager</title>
        <link rel="manifest" href="/manifest.json">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                padding: 20px;
            }
            .container {
                text-align: center;
                max-width: 600px;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 20px;
                padding: 40px;
                backdrop-filter: blur(10px);
            }
            .title { font-size: 2.5rem; margin: 20px 0; }
            .btn {
                padding: 12px 24px;
                margin: 10px;
                background: white;
                color: #2563eb;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div style="font-size: 4rem;">🏟️</div>
            <h1 class="title">Hospitality Manager</h1>
            <p style="opacity: 0.9; margin-bottom: 30px;">Sistema Check-in per Stadi</p>
            <a href="/api/health" class="btn">🔧 System Status</a>
        </div>
    </body>
    </html>
    <?php
}
function handleStatisticsRoutes(string $path, string $method): void {
    // All statistics routes require authentication
    if (!Hospitality\Middleware\AuthMiddleware::handle()) return;

    $controller = new Hospitality\Controllers\StatisticsController();
    
    if ($path === '/api/statistics/summary' && $method === 'GET') {
        $controller->summary();
        
    } elseif ($path === '/api/statistics/access-by-event' && $method === 'GET') {
        $controller->accessByEvent();
        
    } elseif ($path === '/api/statistics/access-by-room' && $method === 'GET') {
        $controller->accessByRoom();
        
    } elseif ($path === '/api/statistics/export-excel' && $method === 'GET') {
        $controller->exportExcel();
        
    } elseif (preg_match('/^\/api\/statistics\/download\/(.+)$/', $path, $matches) && $method === 'GET') {
        $filename = $matches[1];
        $controller->downloadExcel($filename);
        
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Statistics endpoint not found',
            'available_endpoints' => [
                'GET /api/statistics/summary' => 'Get statistics summary',
                'GET /api/statistics/access-by-event' => 'Access statistics by event',
                'GET /api/statistics/access-by-room' => 'Access statistics by room',
                'GET /api/statistics/export-excel' => 'Export detailed Excel report',
                'GET /api/statistics/download/{filename}' => 'Download generated Excel file'
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}

function handleDashboardRoutes(string $path, string $method): void {
    // All dashboard routes require authentication
    if (!Hospitality\Middleware\AuthMiddleware::handle()) return;

    $controller = new Hospitality\Controllers\DashboardController();
    
    if ($path === '/api/dashboard/stats' && $method === 'GET') {
        // Get dashboard statistics
        try {
            $controller->stats();
        } catch (Exception $e) {
            error_log("Dashboard stats error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load statistics',
                'error' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : null,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        
    } elseif ($path === '/api/dashboard/upcoming-events' && $method === 'GET') {
        // Get upcoming events for dashboard
        try {
            $controller->upcomingEvents();
        } catch (Exception $e) {
            error_log("Dashboard upcoming events error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load upcoming events',
                'error' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : null,
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        
    } elseif ($path === '/api/dashboard/recent-activity' && $method === 'GET') {
        // Get recent activity (placeholder for future implementation)
        if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
        
        http_response_code(501);
        echo json_encode([
            'success' => false,
            'message' => 'Recent activity endpoint coming soon',
            'status' => 'not_implemented',
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        
    } else {
        // Dashboard endpoint not found
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Dashboard endpoint not found',
            'available_endpoints' => [
                'GET /api/dashboard/stats?stadium_id=X' => 'Get dashboard statistics',
                'GET /api/dashboard/upcoming-events?stadium_id=X' => 'Get upcoming events',
                'GET /api/dashboard/recent-activity?stadium_id=X' => 'Get recent activity (coming soon)'
            ],
            'requested' => "$method $path",
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}


?>