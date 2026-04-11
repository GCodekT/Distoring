<?php
/**
 * REST API для distoring.ru
 * ИСПРАВЛЕННАЯ ВЕРСИЯ с правильной работой сессий
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = Database::getInstance()->getConnection();

// Логирование для отладки
function logDebug($message) {
    error_log('[API] ' . $message);
}

// Получение текущего пользователя (ИСПРАВЛЕНО)
function getCurrentUser($db) {
    $token = null;
    
    // Проверяем заголовок Authorization
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $token = str_replace('Bearer ', '', $headers['Authorization']);
        }
    }
    
    // Fallback для nginx
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    }
    
    // Cookie fallback
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    logDebug('Token: ' . ($token ? substr($token, 0, 10) . '...' : 'none'));
    
    if (!$token) {
        logDebug('No token found');
        return null;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT u.* FROM users u
            JOIN sessions s ON u.id = s.user_id
            WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            logDebug('User found: ' . $user['email']);
        } else {
            logDebug('User not found or session expired');
        }
        
        return $user;
    } catch (Exception $e) {
        logDebug('Get user error: ' . $e->getMessage());
        return null;
    }
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ========== ПУБЛИЧНЫЕ ENDPOINTS ==========

// Получить все датчики
if ($action === 'sensors') {
    try {
        $stmt = $db->query("
            SELECT 
                s.id, s.device_id, s.name, s.latitude, s.longitude,
                s.is_precise_location, s.first_seen, s.last_seen,
                sd.voltage, sd.charge_percent, sd.roll_angle, sd.pitch_angle, sd.temperature,
                sd.status, sd.timestamp as last_data_time
            FROM sensors s
            LEFT JOIN sensor_data sd ON s.id = sd.sensor_id
            WHERE s.is_active = 1
            AND s.latitude IS NOT NULL 
            AND s.longitude IS NOT NULL
            AND sd.id = (
                SELECT id FROM sensor_data 
                WHERE sensor_id = s.id 
                ORDER BY timestamp DESC 
                LIMIT 1
            )
            ORDER BY s.last_seen DESC
        ");
        
        jsonResponse(['success' => true, 'sensors' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Получить конкретный датчик
if ($action === 'sensor') {
    $sensorId = $_GET['id'] ?? null;
    if (!$sensorId) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM sensors WHERE id = ? AND is_active = 1");
        $stmt->execute([$sensorId]);
        $sensor = $stmt->fetch();
        
        if (!$sensor) {
            jsonResponse(['success' => false, 'error' => 'Sensor not found'], 404);
        }
        
        $hours = $_GET['hours'] ?? 24;
        $stmt = $db->prepare("
            SELECT * FROM sensor_data 
            WHERE sensor_id = ? 
            AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY timestamp ASC
            LIMIT 1000
        ");
        $stmt->execute([$sensorId, $hours]);
        $history = $stmt->fetchAll();
        
        jsonResponse(['success' => true, 'sensor' => $sensor, 'history' => $history]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Поиск датчиков
if ($action === 'search') {
    $query = $_GET['q'] ?? '';
    if (strlen($query) < 2) {
        jsonResponse(['success' => false, 'error' => 'Query too short'], 400);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT * FROM sensors 
            WHERE is_active = 1 
            AND (device_id LIKE ? OR name LIKE ? OR sim_number LIKE ?)
            LIMIT 50
        ");
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        
        jsonResponse(['success' => true, 'results' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== АВТОРИЗАЦИЯ ==========

// Регистрация
if ($action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? null;
    $phone = $data['phone'] ?? null;
    $password = $data['password'] ?? null;
    $name = $data['name'] ?? '';
    
    if ((!$email && !$phone) || !$password) {
        jsonResponse(['success' => false, 'error' => 'Email/Phone and password required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'User already exists'], 409);
        }
        
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $verificationToken = bin2hex(random_bytes(32));
        
        $stmt = $db->prepare("
            INSERT INTO users (email, phone, password_hash, name, verification_token, is_verified) 
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$email, $phone, $passwordHash, $name, $verificationToken]);
        $userId = $db->lastInsertId();
        
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $stmt = $db->prepare("
            INSERT INTO sessions (user_id, session_token, ip_address, user_agent, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId, 
            $sessionToken, 
            $_SERVER['REMOTE_ADDR'], 
            $_SERVER['HTTP_USER_AGENT'] ?? '', 
            $expiresAt
        ]);
        
        logDebug('User registered: ' . $email . ', token: ' . substr($sessionToken, 0, 10));
        
        jsonResponse([
            'success' => true, 
            'token' => $sessionToken,
            'user' => [
                'id' => $userId,
                'email' => $email,
                'phone' => $phone,
                'name' => $name
            ]
        ]);
    } catch (Exception $e) {
        logDebug('Register error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Вход
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $login = $data['login'] ?? '';
    $password = $data['password'] ?? '';
    
    logDebug('Login attempt: ' . $login);
    
    if (!$login || !$password) {
        jsonResponse(['success' => false, 'error' => 'Login and password required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE (email = ? OR phone = ?) AND is_active = 1");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            logDebug('Invalid credentials for: ' . $login);
            jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
        }
        
        $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $stmt = $db->prepare("
            INSERT INTO sessions (user_id, session_token, ip_address, user_agent, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'], 
            $sessionToken,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $expiresAt
        ]);
        
        logDebug('Login successful: ' . $user['email'] . ', token: ' . substr($sessionToken, 0, 10));
        
        jsonResponse([
            'success' => true,
            'token' => $sessionToken,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'name' => $user['name']
            ]
        ]);
    } catch (Exception $e) {
        logDebug('Login error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Выход
if ($action === 'logout') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $token = null;
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $token = str_replace('Bearer ', '', $headers['Authorization']);
        }
    }
    
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    }
    
    if (!$token && isset($_COOKIE['session_token'])) {
        $token = $_COOKIE['session_token'];
    }
    
    if ($token) {
        $stmt = $db->prepare("DELETE FROM sessions WHERE session_token = ?");
        $stmt->execute([$token]);
        logDebug('User logged out');
    }
    
    jsonResponse(['success' => true]);
}

// ========== ЗАЩИЩЕННЫЕ ENDPOINTS ==========

// Профиль
if ($action === 'profile') {
    $user = getCurrentUser($db);
    
    logDebug('Profile request, user: ' . ($user ? $user['email'] : 'none'));
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    jsonResponse([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'name' => $user['name'],
            'created_at' => $user['created_at'],
            'is_verified' => (bool)$user['is_verified']
        ]
    ]);
}

// Мои датчики
if ($action === 'my_sensors') {
    $user = getCurrentUser($db);
    
    logDebug('My sensors request, user: ' . ($user ? $user['email'] : 'none'));
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                us.id as subscription_id, s.id, s.device_id,
                COALESCE(us.custom_name, s.name) as name,
                COALESCE(us.custom_latitude, s.latitude) as latitude,
                COALESCE(us.custom_longitude, s.longitude) as longitude,
                s.is_precise_location, us.notifications_enabled, s.last_seen,
                sd.voltage, sd.charge_percent, sd.roll_angle, sd.pitch_angle, sd.temperature,
                sd.status, sd.timestamp as last_data_time
            FROM user_sensors us
            JOIN sensors s ON us.sensor_id = s.id
            LEFT JOIN sensor_data sd ON s.id = sd.sensor_id
            WHERE us.user_id = ?
            AND s.is_active = 1
            AND sd.id = (
                SELECT id FROM sensor_data 
                WHERE sensor_id = s.id 
                ORDER BY timestamp DESC 
                LIMIT 1
            )
            ORDER BY us.added_at DESC
        ");
        $stmt->execute([$user['id']]);
        $sensors = $stmt->fetchAll();
        
        logDebug('My sensors found: ' . count($sensors));
        
        jsonResponse(['success' => true, 'sensors' => $sensors]);
    } catch (Exception $e) {
        logDebug('My sensors error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Добавить датчик
if ($action === 'add_sensor') {
    $user = getCurrentUser($db);
    
    if (!$user) {
        logDebug('Add sensor - not authenticated');
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $sensorId = $data['sensor_id'] ?? null;
    
    logDebug('Add sensor request: user=' . $user['email'] . ', sensor=' . $sensorId);
    
    if (!$sensorId) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT id FROM sensors WHERE id = ? AND is_active = 1");
        $stmt->execute([$sensorId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Sensor not found'], 404);
        }
        
        $stmt = $db->prepare("SELECT id FROM user_sensors WHERE user_id = ? AND sensor_id = ?");
        $stmt->execute([$user['id'], $sensorId]);
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Sensor already added'], 409);
        }
        
        $stmt = $db->prepare("INSERT INTO user_sensors (user_id, sensor_id) VALUES (?, ?)");
        $stmt->execute([$user['id'], $sensorId]);
        
        logDebug('Sensor added successfully');
        
        jsonResponse(['success' => true, 'message' => 'Sensor added successfully']);
    } catch (Exception $e) {
        logDebug('Add sensor error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Удалить датчик
if ($action === 'remove_sensor') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $sensorId = $data['sensor_id'] ?? null;
    
    if (!$sensorId) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("DELETE FROM user_sensors WHERE user_id = ? AND sensor_id = ?");
        $stmt->execute([$user['id'], $sensorId]);
        
        jsonResponse(['success' => true, 'message' => 'Sensor removed successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Обновить местоположение
if ($action === 'update_location') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $sensorId = $data['sensor_id'] ?? null;
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    
    if (!$sensorId || $latitude === null || $longitude === null) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID and coordinates required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT id FROM user_sensors WHERE user_id = ? AND sensor_id = ?");
        $stmt->execute([$user['id'], $sensorId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Sensor not found in your account'], 404);
        }
        
        $stmt = $db->prepare("
            UPDATE user_sensors 
            SET custom_latitude = ?, custom_longitude = ? 
            WHERE user_id = ? AND sensor_id = ?
        ");
        $stmt->execute([$latitude, $longitude, $user['id'], $sensorId]);
        
        jsonResponse(['success' => true, 'message' => 'Location updated successfully']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

logDebug('Unknown action: ' . $action);
jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 400);
