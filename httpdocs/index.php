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
        
        if ($path === '/api/auth/logout' && $method === 'POST') {
            $controller->logout();
            $routed = true;
        }
        elseif ($path === '/api/auth/me' && $method === 'GET') {
            $controller->me();
            $routed = true;
        }
        elseif ($path === '/api/auth/change-password' && $method === 'POST') {
            $controller->changePassword();
            $routed = true;
        }
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
            'PUT /api/guests/{id}' => 'Update guest (hostess)'
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
?>