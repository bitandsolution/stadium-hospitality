<?php
/**
 * Bootstrap Super Admin - Versione WEB
 * ELIMINARE IMMEDIATAMENTE DOPO L'USO!
 */

// Token di sicurezza - CAMBIALO!
define('BOOTSTRAP_TOKEN', 'dfdhfdghersz2353');

// Verifica token nella query string
if (!isset($_GET['token']) || $_GET['token'] !== BOOTSTRAP_TOKEN) {
    die('❌ Access Denied - Invalid Token');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carica autoload
require_once __DIR__ . '/../../vendor/autoload.php';

// Carica .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bootstrap Super Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8">
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                <p class="text-red-700 font-bold">⚠️ ATTENZIONE</p>
                <p class="text-sm text-red-600">Elimina questo file immediatamente dopo l'uso!</p>
            </div>

            <h1 class="text-2xl font-bold text-gray-800 mb-6">Crea Super Admin</h1>

            <?php
            // Verifica se esistono già super admin
            try {
                $db = \Hospitality\Config\Database::getInstance()->getConnection();
                $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'super_admin' AND is_active = 1");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    echo '<div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">';
                    echo '<p class="text-yellow-700">Esistono già ' . $result['count'] . ' super admin attivi.</p>';
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">';
                echo '<p class="text-red-700">Errore database: ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }

            // Gestione form submit
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $username = trim($_POST['username'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $passwordConfirm = $_POST['password_confirm'] ?? '';
                    $fullName = trim($_POST['full_name'] ?? '');

                    // Validazioni
                    $errors = [];

                    if (empty($username)) $errors[] = "Username obbligatorio";
                    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email non valida";
                    if (empty($fullName)) $errors[] = "Nome completo obbligatorio";
                    if (strlen($password) < 8) $errors[] = "Password minimo 8 caratteri";
                    if (!preg_match('/[A-Z]/', $password)) $errors[] = "Password deve contenere una maiuscola";
                    if (!preg_match('/[a-z]/', $password)) $errors[] = "Password deve contenere una minuscola";
                    if (!preg_match('/[0-9]/', $password)) $errors[] = "Password deve contenere un numero";
                    if ($password !== $passwordConfirm) $errors[] = "Le password non coincidono";

                    if (!empty($errors)) {
                        echo '<div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">';
                        echo '<p class="text-red-700 font-bold">Errori di validazione:</p>';
                        echo '<ul class="list-disc list-inside text-sm text-red-600 mt-2">';
                        foreach ($errors as $error) {
                            echo '<li>' . htmlspecialchars($error) . '</li>';
                        }
                        echo '</ul></div>';
                    } else {
                        // Verifica duplicati
                        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                        $stmt->execute([$username, $email]);
                        
                        if ($stmt->rowCount() > 0) {
                            echo '<div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">';
                            echo '<p class="text-red-700">Username o email già esistenti</p>';
                            echo '</div>';
                        } else {
                            // Crea super admin
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
                            
                            $stmt = $db->prepare("
                                INSERT INTO users (
                                    stadium_id, username, email, password_hash, role, 
                                    full_name, is_active, created_at, created_by
                                ) VALUES (
                                    NULL, ?, ?, ?, 'super_admin', ?, 1, NOW(), NULL
                                )
                            ");
                            
                            $stmt->execute([$username, $email, $passwordHash, $fullName]);
                            $userId = $db->lastInsertId();

                            // Log
                            try {
                                \Hospitality\Utils\Logger::info('Bootstrap super admin created via web', [
                                    'user_id' => $userId,
                                    'username' => $username,
                                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                                ]);
                            } catch (Exception $e) {
                                // Log non bloccante
                            }

                            echo '<div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">';
                            echo '<p class="text-green-700 font-bold">✅ Super Admin creato con successo!</p>';
                            echo '<p class="text-sm text-green-600 mt-2">ID: ' . $userId . '</p>';
                            echo '<p class="text-sm text-green-600">Username: ' . htmlspecialchars($username) . '</p>';
                            echo '</div>';

                            echo '<div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">';
                            echo '<p class="text-yellow-700 font-bold">⚠️ IMPORTANTE</p>';
                            echo '<p class="text-sm text-yellow-600">Elimina IMMEDIATAMENTE questo file per sicurezza:</p>';
                            echo '<code class="text-xs bg-gray-200 px-2 py-1 rounded block mt-2">rm /var/www/vhosts/checkindigitale.cloud/httpdocs/scripts/bootstrap_web.php</code>';
                            echo '</div>';

                            echo '<a href="../login.html" class="block w-full text-center bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700 transition">Vai al Login</a>';
                            exit;
                        }
                    }
                } catch (Exception $e) {
                    echo '<div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">';
                    echo '<p class="text-red-700 font-bold">Errore:</p>';
                    echo '<p class="text-sm text-red-600">' . htmlspecialchars($e->getMessage()) . '</p>';
                    echo '</div>';
                }
            }
            ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="username" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome Completo</label>
                    <input type="text" name="full_name" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <p class="text-xs text-gray-500 mt-1">Min 8 caratteri, maiuscola, minuscola, numero</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Conferma Password</label>
                    <input type="password" name="password_confirm" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>

                <button type="submit" 
                        class="w-full bg-indigo-600 text-white py-2 rounded-lg hover:bg-indigo-700 transition font-medium">
                    Crea Super Admin
                </button>
            </form>
        </div>
    </div>
</body>
</html>