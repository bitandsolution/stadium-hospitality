<?php
/*********************************************************
*                                                        *
*   FILE: src/Config/Database.php - Database Connection  *
*                                                        *
*   Author: Antonio Tartaglia - bitAND solution          *
*   website: https://www.bitandsolution.it               *
*   email:   info@bitandsolution.it                      *
*                                                        *
*   Owner: bitAND solution                               *
*                                                        *
*   This is proprietary software                         *
*   developed by bitAND solution for bitAND solution     *
*                                                        *
*********************************************************/

namespace Hospitality\Config;

use PDO;
use PDOException;
use Exception;

class Database {
    private static ?Database $instance = null;
    private PDO $connection;
    private int $connectionAttempts = 0;
    private int $maxConnectionAttempts = 3;

    private function __construct() {
        $this->connect();
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    private function connect(): void {
        $this->connectionAttempts++;

        try {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $dbname = $_ENV['DB_NAME'] ?? 'db_stadiumhm';
            $username = $_ENV['DB_USER'] ?? '';
            $password = $_ENV['DB_PASS'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8';

            if (empty($username) || empty($dbname)) {
                throw new PDOException('Database credentials not configured in .env file');
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
                PDO::ATTR_TIMEOUT => 30,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
            ];

            $this->connection = new PDO($dsn, $username, $password, $options);
            
            // Test connection with a simple query
            $this->connection->query('SELECT 1');
            
            error_log("Database connected successfully to {$host}:{$port}/{$dbname}");
            
        } catch (PDOException $e) {
            error_log("Database connection failed (attempt {$this->connectionAttempts}): " . $e->getMessage());
            
            if ($this->connectionAttempts < $this->maxConnectionAttempts) {
                sleep(1); // Wait 1 second before retry
                $this->connect();
            } else {
                throw new Exception('Database connection failed after ' . $this->maxConnectionAttempts . ' attempts: ' . $e->getMessage());
            }
        }
    }

    public function getConnection(): PDO {
        // Health check - test if connection is still alive
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            error_log('Database connection lost, reconnecting...');
            $this->connectionAttempts = 0; // Reset counter
            $this->connect();
        }
        
        return $this->connection;
    }

    public function beginTransaction(): bool {
        return $this->connection->beginTransaction();
    }

    public function commit(): bool {
        return $this->connection->commit();
    }

    public function rollback(): bool {
        return $this->connection->rollBack();
    }

    public function inTransaction(): bool {
        return $this->connection->inTransaction();
    }

    /**
     * Execute query with automatic retry on connection failure
     */
    public function executeQuery(string $sql, array $params = []): array {
        $maxRetries = 2;
        $retryCount = 0;

        while ($retryCount <= $maxRetries) {
            try {
                $stmt = $this->connection->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (PDOException $e) {
                $retryCount++;
                
                if ($retryCount > $maxRetries) {
                    throw $e;
                }
                
                // If connection was lost, reconnect and retry
                if (stripos($e->getMessage(), 'gone away') !== false || 
                    stripos($e->getMessage(), 'connection') !== false) {
                    
                    error_log("Database connection lost during query, reconnecting... (retry {$retryCount})");
                    $this->connect();
                } else {
                    throw $e; // Non-connection error, don't retry
                }
            }
        }

        return [];
    }

    // Prevent cloning and serialization
    private function __clone() {}
    
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton Database instance");
    }
}
