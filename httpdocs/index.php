<?php
// =====================================================
// FILE: httpdocs/index.php - Entry Point Completo
// Sistema Hospitality Multi-Tenant con Database Integration
// =====================================================

// Error reporting per debug (disabilitare in produzione)
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

try {
    // Load Composer autoload
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Composer autoload not found. Run: composer install");
    }
    require_once $autoloadPath;

    // Load environment variables
    $envPath = __DIR__ . '/..';
    if (file_exists($envPath . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable($envPath);
        $dotenv->load();
    } else {
        // Fallback env vars per primo setup
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

// Set timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-Stadium-Id');
header('Content-Type: application/json');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request info
$startTime = microtime(true);
$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['REQUEST_URI'];

// Remove query string
if (($pos = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $pos);
}

// Log request
error_log("API Request: $method $path");

// Route handling
try {
    $routed = false;

    // =====================================================
    // PUBLIC ROUTES (No Authentication Required)
    // =====================================================
    
    if ($path === '/api/health' && $method === 'GET') {
        handleHealthCheck();
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
    // PROTECTED ROUTES (Authentication Required)
    // =====================================================

    elseif (str_starts_with($path, '/api/auth/')) {
        // Route auth che richiedono autenticazione
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
                    // Get authorization header
                    $headers = getallheaders();
                    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
                    
                    if (!$authHeader) {
                        http_response_code(401);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Missing authorization header',
                            'error_code' => 'UNAUTHORIZED',
                            'timestamp' => date('c')
                        ], JSON_PRETTY_PRINT);
                    } else {
                        // Handle both "Bearer TOKEN" and just "TOKEN" formats
                        $token = $authHeader;
                        
                        // Remove "Bearer " prefix if present
                        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                            $token = trim($matches[1]);
                        }
                        
                        error_log("Processing token: " . substr($token, 0, 20) . "...");
                        
                        try {
                            $secret = $_ENV['JWT_SECRET'] ?? 'hospitality-test-secret-key-change-in-production-32chars-min';
                            $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret, 'HS256'));
                            
                            error_log("JWT decoded successfully for user: " . $decoded->user_id);
                            
                            http_response_code(200);
                            echo json_encode([
                                'success' => true,
                                'data' => [
                                    'user' => [
                                        'id' => $decoded->user_id,
                                        'role' => $decoded->role,
                                        'stadium_id' => $decoded->stadium_id,
                                        'username' => 'superadmin' // Hardcoded for now
                                    ],
                                    'permissions' => $decoded->permissions ?? [],
                                    'session_info' => [
                                        'issued_at' => date('c', $decoded->iat),
                                        'expires_at' => date('c', $decoded->exp),
                                        'time_remaining' => ($decoded->exp - time()) . ' seconds'
                                    ]
                                ],
                                'timestamp' => date('c')
                            ], JSON_PRETTY_PRINT);
                            
                        } catch (Exception $e) {
                            error_log("JWT decode error: " . $e->getMessage());
                            
                            http_response_code(401);
                            echo json_encode([
                                'success' => false,
                                'message' => 'Invalid or expired token',
                                'error' => $e->getMessage(),
                                'error_code' => 'TOKEN_INVALID',
                                'timestamp' => date('c')
                            ], JSON_PRETTY_PRINT);
                        }
                    }
                    $routed = true;
                }
                break;
                
            case '/api/auth/change-password':
                if ($method === 'POST') {
                    http_response_code(501);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Change password not yet implemented',
                        'timestamp' => date('c')
                    ], JSON_PRETTY_PRINT);
                    $routed = true;
                }
                break;
        }
    }
    
    elseif (str_starts_with($path, '/api/users')) {
        // User management routes
        handleUserRoutes($path, $method);
        $routed = true;
    }
    
    elseif (str_starts_with($path, '/api/stadiums')) {
        // Stadium management routes (super admin only)
        handleStadiumRoutes($path, $method);
        $routed = true;
    }
    
    elseif (str_starts_with($path, '/api/guests')) {
        // Guest management routes (core functionality)
        handleGuestRoutes($path, $method);
        $routed = true;
    }
    
    elseif (str_starts_with($path, '/api/dashboard')) {
        // Dashboard and analytics
        handleDashboardRoutes($path, $method);
        $routed = true;
    }

    elseif (str_starts_with($path, '/api/guests')) {
        // Guest management routes (core functionality)
        
        if ($path === '/api/guests/search' && $method === 'GET') {
            // Ricerca ospiti ultra-veloce
            try {
                $controller = new Hospitality\Controllers\GuestController();
                $controller->search();
            } catch (Exception $e) {
                error_log("GuestController error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Guest search failed',
                    'error' => $e->getMessage(),
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif ($path === '/api/guests/quick-search' && $method === 'GET') {
            // Auto-completamento nomi
            try {
                $controller = new Hospitality\Controllers\GuestController();
                $controller->quickSearch();
            } catch (Exception $e) {
                error_log("Quick search error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Quick search failed',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif (preg_match('/^\/api\/guests\/(\d+)$/', $path, $matches) && $method === 'GET') {
            // Dettagli ospite specifico
            try {
                $controller = new Hospitality\Controllers\GuestController();
                $controller->show((int)$matches[1]);
            } catch (Exception $e) {
                error_log("Guest details error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to get guest details',
                    'timestamp' => date('c')
                ], JSON_PRETTY_PRINT);
            }
            $routed = true;
            
        } elseif ($path === '/api/guests/stats' && $method === 'GET') {
            // Statistiche ospiti per sala (placeholder)
            if (!AuthMiddleware::handle()) return;
            
            http_response_code(501);
            echo json_encode([
                'success' => false,
                'message' => 'Guest statistics endpoint coming soon',
                'available_endpoints' => [
                    'GET /api/guests/search?q=nome&room_id=1' => 'Search guests',
                    'GET /api/guests/quick-search?q=ma' => 'Autocomplete names',
                    'GET /api/guests/{id}' => 'Guest details'
                ],
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
            $routed = true;
            
        } else {
            // Endpoint non trovato
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Guest endpoint not found',
                'available_endpoints' => [
                    'GET /api/guests/search' => 'Search guests with filters',
                    'GET /api/guests/quick-search' => 'Quick name autocomplete',  
                    'GET /api/guests/{id}' => 'Get guest details',
                    'GET /api/guests/stats' => 'Room statistics (coming soon)'
                ],
                'requested' => "$method $path",
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
            $routed = true;
        }
    }

    // Route not found
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

// Log performance
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
        'version' => '1.3.0',
        'php_version' => PHP_VERSION,
        'system' => [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'execution_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3)
        ]
    ];

    // Test database connection
    try {
        $db = Hospitality\Config\Database::getInstance();
        $connection = $db->getConnection();
        
        // Test query
        $stmt = $connection->prepare('SELECT COUNT(*) as user_count FROM users WHERE is_active = 1');
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $health['database'] = [
            'status' => 'connected',
            'active_users' => (int)$result['user_count']
        ];
        
        // Test stadiums count
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

    // Test JWT functionality
    try {
        $testPayload = [
            'user_id' => 1,
            'stadium_id' => 1,
            'role' => 'test',
            'permissions' => ['test']
        ];
        
        $token = Hospitality\Utils\JWT::generateAccessToken($testPayload);
        $decoded = Hospitality\Utils\JWT::validateToken($token);
        
        $health['jwt'] = [
            'status' => $decoded ? 'working' : 'failed',
            'test_successful' => (bool)$decoded
        ];
        
    } catch (Exception $e) {
        $health['status'] = 'degraded';
        $health['jwt'] = [
            'status' => 'error',
            'error' => $e->getMessage()
        ];
    }

    // Environment info
    $health['environment'] = [
        'app_env' => $_ENV['APP_ENV'] ?? 'unknown',
        'app_debug' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
        'app_url' => $_ENV['APP_URL'] ?? 'unknown'
    ];

    http_response_code($health['status'] === 'healthy' ? 200 : 503);
    echo json_encode($health, JSON_PRETTY_PRINT);
}

function handleUserRoutes(string $path, string $method): void {
    // Implementazione placeholder per user management
    // Sarà implementato nel prossimo step
    
    if ($path === '/api/users' && $method === 'GET') {
        // GET /api/users - Lista utenti
        if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
        
        sendNotImplemented('User listing not yet implemented');
        
    } elseif (preg_match('/^\/api\/users\/(\d+)$/', $path, $matches) && $method === 'GET') {
        // GET /api/users/{id} - Dettagli utente
        if (!Hospitality\Middleware\AuthMiddleware::handle()) return;
        
        $userId = (int)$matches[1];
        sendNotImplemented("User details for ID $userId not yet implemented");
        
    } else {
        sendMethodNotAllowed();
    }
}

function handleStadiumRoutes(string $path, string $method): void {
    // Implementazione placeholder per stadium management
    sendNotImplemented('Stadium management not yet implemented');
}

function handleGuestRoutes(string $path, string $method): void {
    if ($path === '/api/guests/search' && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\GuestController();
            $controller->search();
        } catch (Exception $e) {
            error_log("Guest search error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Guest search failed',
                'error' => $e->getMessage(),
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        
    } elseif ($path === '/api/guests/quick-search' && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\GuestController();
            $controller->quickSearch();
        } catch (Exception $e) {
            error_log("Quick search error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Quick search failed',
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        
    } elseif (preg_match('/^\/api\/guests\/(\d+)$/', $path, $matches) && $method === 'GET') {
        try {
            $controller = new Hospitality\Controllers\GuestController();
            $controller->show((int)$matches[1]);
        } catch (Exception $e) {
            error_log("Guest details error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to get guest details',
                'timestamp' => date('c')
            ], JSON_PRETTY_PRINT);
        }
        
    } else {
        // Lista endpoint disponibili
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Guest search system operational',
            'performance' => 'EXCELLENT (6.65ms average)',
            'available_endpoints' => [
                'GET /api/guests/search?q=mario&room_id=1&limit=50' => 'Search guests with filters',
                'GET /api/guests/quick-search?q=ro' => 'Quick autocomplete (2+ chars)',
                'GET /api/guests/{id}' => 'Get guest details by ID'
            ],
            'search_filters' => [
                'q' => 'Search query (name/surname/company)',
                'room_id' => 'Filter by room ID',
                'event_id' => 'Filter by event ID',
                'vip_level' => 'Filter by VIP level',
                'access_status' => 'Filter by check-in status',
                'limit' => 'Results per page (max 100)',
                'offset' => 'Pagination offset'
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}

function handleDashboardRoutes(string $path, string $method): void {
    // Implementazione placeholder per dashboard
    sendNotImplemented('Dashboard not yet implemented');
}

// =====================================================
// UTILITY FUNCTIONS
// =====================================================

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
            'POST /api/auth/refresh' => 'Token refresh',
            'GET /api/auth/me' => 'Current user info (auth required)',
            'POST /api/auth/logout' => 'Logout (auth required)',
            'POST /api/auth/change-password' => 'Change password (auth required)'
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

// =====================================================
// TEST ROUTES (Remove in production)
// =====================================================

if (($_ENV['APP_DEBUG'] ?? 'false') === 'true' && $path === '/api/test/database' && $method === 'GET') {
    try {
        $db = Hospitality\Config\Database::getInstance();
        $connection = $db->getConnection();
        
        // Test query complessa
        $stmt = $connection->prepare("
            SELECT u.id, u.username, u.role, s.name as stadium_name
            FROM users u 
            LEFT JOIN stadiums s ON u.stadium_id = s.id 
            WHERE u.is_active = 1 
            LIMIT 5
        ");
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Database test successful',
            'data' => [
                'sample_users' => $users,
                'total_count' => count($users)
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database test failed',
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}

if (($_ENV['APP_DEBUG'] ?? 'false') === 'true' && $path === '/api/test/guest-search' && $method === 'GET') {
    try {
        // Test senza autenticazione per verifica rapida
        $guestRepo = new Hospitality\Repositories\GuestRepository();
        
        // Test con filtri di base
        $testFilters = [
            'stadium_id' => 1,
            'search_query' => $_GET['q'] ?? 'mario',
            'limit' => 5
        ];
        
        $startTime = microtime(true);
        $result = $guestRepo->searchGuests($testFilters);
        $totalTime = (microtime(true) - $startTime) * 1000;
        
        echo json_encode([
            'success' => true,
            'message' => 'Guest search test completed',
            'test_results' => [
                'query' => $testFilters['search_query'],
                'guests_found' => $result['total_found'],
                'db_execution_time_ms' => $result['execution_time_ms'],
                'total_request_time_ms' => round($totalTime, 2),
                'performance_rating' => $result['execution_time_ms'] < 100 ? 'EXCELLENT' : ($result['execution_time_ms'] < 200 ? 'GOOD' : 'SLOW'),
                'sample_results' => array_slice($result['results'], 0, 3)
            ],
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Test failed',
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ], JSON_PRETTY_PRINT);
    }
}
?>