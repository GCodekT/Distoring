<?php
/**
 * REST API для Distoring.ru
 * Многоуровневая система управления с организациями
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

function logDebug($message) {
    error_log('[API] ' . $message);
}

function getCurrentUser($db) {
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
    
    if (!$token) {
        return null;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT u.* FROM users u
            JOIN sessions s ON u.id = s.user_id
            WHERE s.session_token = ? AND s.expires_at > NOW() AND u.is_active = 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch();
    } catch (Exception $e) {
        logDebug('Get user error: ' . $e->getMessage());
        return null;
    }
}

// Получить роль пользователя
function getUserRole($db, $userId) {
    $stmt = $db->prepare("
        SELECT r.name FROM roles r
        JOIN users u ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result ? $result['name'] : 'employee';
}

// Проверка прав доступа
function checkPermission($user, $requiredRole) {
    $roleHierarchy = ['admin' => 4, 'lead_engineer' => 3, 'engineer' => 2, 'employee' => 1];
    $userRole = getUserRole($GLOBALS['db'], $user['id']);
    return isset($roleHierarchy[$userRole]) && $roleHierarchy[$userRole] >= $roleHierarchy[$requiredRole];
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ========== АВТОРИЗАЦИЯ ==========

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
        
        // Получаем роль и организации пользователя
        $role = getUserRole($db, $user['id']);
        
        $stmt = $db->prepare("
            SELECT DISTINCT o.id, o.name FROM organizations o
            JOIN user_organizations uo ON o.id = uo.organization_id
            WHERE uo.user_id = ? AND o.is_active = 1
        ");
        $stmt->execute([$user['id']]);
        $organizations = $stmt->fetchAll();
        
        logDebug('Login successful: ' . $user['email']);
        
        jsonResponse([
            'success' => true,
            'token' => $sessionToken,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'name' => $user['name'],
                'role' => $role,
                'is_super_admin' => (bool)$user['is_super_admin']
            ],
            'organizations' => $organizations
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
    }
    
    jsonResponse(['success' => true]);
}

// Профиль
if ($action === 'profile') {
    $user = getCurrentUser($db);
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $role = getUserRole($db, $user['id']);
    
    // Получаем организации пользователя
    $stmt = $db->prepare("
        SELECT o.id, o.name, uo.role_id, r.name as role_name
        FROM organizations o
        JOIN user_organizations uo ON o.id = uo.organization_id
        JOIN roles r ON uo.role_id = r.id
        WHERE uo.user_id = ? AND o.is_active = 1
    ");
    $stmt->execute([$user['id']]);
    $organizations = $stmt->fetchAll();
    
    jsonResponse([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'name' => $user['name'],
            'role' => $role,
            'is_super_admin' => (bool)$user['is_super_admin'],
            'created_at' => $user['created_at']
        ],
        'organizations' => $organizations
    ]);
}

// ========== ДАТЧИКИ (ПУБЛИЧНЫЕ) ==========

// Получить датчики для пользователя
if ($action === 'my_sensors') {
    $user = getCurrentUser($db);
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $orgId = $_GET['org_id'] ?? null;
    
    try {
        if ($orgId) {
            // Проверяем что пользователь имеет доступ к этой организации
            $stmt = $db->prepare("
                SELECT id FROM user_organizations 
                WHERE user_id = ? AND organization_id = ?
            ");
            $stmt->execute([$user['id'], $orgId]);
            
            if (!$stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Access denied'], 403);
            }
            
            $query = "
                SELECT 
                    s.id, s.device_id, s.name, 
                    COALESCE(s.custom_latitude, s.latitude) as latitude,
                    COALESCE(s.custom_longitude, s.longitude) as longitude,
                    s.is_precise_location, s.first_seen, s.last_seen,
                    sd.voltage, sd.charge_percent, sd.roll_angle, sd.pitch_angle, sd.temperature,
                    sd.status, sd.timestamp as last_data_time
                FROM sensors s
                LEFT JOIN sensor_data sd ON s.id = sd.sensor_id
                JOIN organization_sensors os ON s.id = os.sensor_id
                WHERE s.is_active = 1
                AND os.organization_id = ?
                AND sd.id = (
                    SELECT id FROM sensor_data 
                    WHERE sensor_id = s.id 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                )
                ORDER BY s.last_seen DESC
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$orgId]);
        } else {
            // Получаем все датчики всех организаций пользователя
            $query = "
                SELECT 
                    s.id, s.device_id, s.name, 
                    COALESCE(s.custom_latitude, s.latitude) as latitude,
                    COALESCE(s.custom_longitude, s.longitude) as longitude,
                    s.is_precise_location, s.first_seen, s.last_seen,
                    sd.voltage, sd.charge_percent, sd.roll_angle, sd.pitch_angle, sd.temperature,
                    sd.status, sd.timestamp as last_data_time,
                    o.id as organization_id, o.name as organization_name
                FROM sensors s
                LEFT JOIN sensor_data sd ON s.id = sd.sensor_id
                JOIN organization_sensors os ON s.id = os.sensor_id
                JOIN organizations o ON os.organization_id = o.id
                JOIN user_organizations uo ON o.id = uo.organization_id
                WHERE s.is_active = 1 AND o.is_active = 1 AND uo.user_id = ?
                AND sd.id = (
                    SELECT id FROM sensor_data 
                    WHERE sensor_id = s.id 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                )
                ORDER BY o.name, s.last_seen DESC
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$user['id']]);
        }
        
        $sensors = $stmt->fetchAll();
        jsonResponse(['success' => true, 'sensors' => $sensors]);
        
    } catch (Exception $e) {
        logDebug('My sensors error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Получить конкретный датчик с историей
if ($action === 'sensor') {
    $user = getCurrentUser($db);
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $sensorId = $_GET['id'] ?? null;
    if (!$sensorId) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID required'], 400);
    }
    
    try {
        // Проверяем доступ пользователя к датчику
        $stmt = $db->prepare("
            SELECT s.* FROM sensors s
            JOIN organization_sensors os ON s.id = os.sensor_id
            JOIN organizations o ON os.organization_id = o.id
            JOIN user_organizations uo ON o.id = uo.organization_id
            WHERE s.id = ? AND s.is_active = 1 AND o.is_active = 1 AND uo.user_id = ?
        ");
        $stmt->execute([$sensorId, $user['id']]);
        $sensor = $stmt->fetch();
        
        if (!$sensor) {
            jsonResponse(['success' => false, 'error' => 'Sensor not found or access denied'], 404);
        }
        
        $hours = $_GET['hours'] ?? 24;
        $limit = $_GET['limit'] ?? 10000;
        $limit = min($limit, 50000);
        
        $stmt = $db->prepare("
            SELECT * FROM sensor_data 
            WHERE sensor_id = ? 
            AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY timestamp ASC
            LIMIT ?
        ");
        $stmt->execute([$sensorId, $hours, $limit]);
        $history = $stmt->fetchAll();
        
        // Подсчитываем всего
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM sensor_data 
            WHERE sensor_id = ? 
            AND timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$sensorId, $hours]);
        $countResult = $stmt->fetch();
        
        jsonResponse([
            'success' => true, 
            'sensor' => $sensor, 
            'history' => $history,
            'count' => count($history),
            'total_available' => $countResult['total']
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== УПРАВЛЕНИЕ ДАТЧИКАМИ (ИНЖЕНЕР) ==========

// Добавить датчик в организацию
if ($action === 'add_sensor_to_org') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $role = getUserRole($db, $user['id']);
    if (!in_array($role, ['admin', 'lead_engineer'])) {
        jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $orgId = $data['organization_id'] ?? null;
    $sensorId = $data['sensor_id'] ?? null;
    
    if (!$orgId || !$sensorId) {
        jsonResponse(['success' => false, 'error' => 'Organization and sensor IDs required'], 400);
    }
    
    try {
        // Проверяем права
        $stmt = $db->prepare("
            SELECT uo.role_id FROM user_organizations uo
            WHERE uo.user_id = ? AND uo.organization_id = ?
        ");
        $stmt->execute([$user['id'], $orgId]);
        $userOrg = $stmt->fetch();
        
        if (!$userOrg) {
            jsonResponse(['success' => false, 'error' => 'Access denied to organization'], 403);
        }
        
        // Проверяем датчик существует
        $stmt = $db->prepare("SELECT id FROM sensors WHERE id = ? AND is_active = 1");
        $stmt->execute([$sensorId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Sensor not found'], 404);
        }
        
        // Добавляем датчик в организацию
        $stmt = $db->prepare("
            INSERT INTO organization_sensors (organization_id, sensor_id) 
            VALUES (?, ?)
        ");
        $stmt->execute([$orgId, $sensorId]);
        
        logDebug('Sensor added to organization: ' . $sensorId . ' -> ' . $orgId);
        jsonResponse(['success' => true, 'message' => 'Sensor added to organization']);
        
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            jsonResponse(['success' => false, 'error' => 'Sensor already in organization'], 409);
        }
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Удалить датчик из организации
if ($action === 'remove_sensor_from_org') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $role = getUserRole($db, $user['id']);
    if (!in_array($role, ['admin', 'lead_engineer'])) {
        jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $orgId = $data['organization_id'] ?? null;
    $sensorId = $data['sensor_id'] ?? null;
    
    try {
        $stmt = $db->prepare("
            DELETE FROM organization_sensors 
            WHERE organization_id = ? AND sensor_id = ?
        ");
        $stmt->execute([$orgId, $sensorId]);
        
        jsonResponse(['success' => true, 'message' => 'Sensor removed from organization']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Обновить координаты датчика
if ($action === 'update_sensor_location') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $role = getUserRole($db, $user['id']);
    if (!in_array($role, ['admin', 'lead_engineer'])) {
        jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $sensorId = $data['sensor_id'] ?? null;
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;
    
    if (!$sensorId || $latitude === null || $longitude === null) {
        jsonResponse(['success' => false, 'error' => 'Sensor ID and coordinates required'], 400);
    }
    
    try {
        // Проверяем доступ
        $stmt = $db->prepare("
            SELECT os.organization_id FROM organization_sensors os
            JOIN organizations o ON os.organization_id = o.id
            JOIN user_organizations uo ON o.id = uo.organization_id
            WHERE os.sensor_id = ? AND uo.user_id = ?
        ");
        $stmt->execute([$sensorId, $user['id']]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Access denied to sensor'], 403);
        }
        
        $stmt = $db->prepare("
            UPDATE sensors 
            SET custom_latitude = ?, custom_longitude = ? 
            WHERE id = ?
        ");
        $stmt->execute([$latitude, $longitude, $sensorId]);
        
        jsonResponse(['success' => true, 'message' => 'Sensor location updated']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== УПРАВЛЕНИЕ ОРГАНИЗАЦИЕЙ ==========

// Получить организации пользователя
if ($action === 'organizations') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    try {
        $stmt = $db->prepare("
            SELECT o.id, o.name, uo.role_id, r.name as role_name, 
                   (SELECT COUNT(*) FROM user_organizations WHERE organization_id = o.id) as member_count,
                   (SELECT COUNT(*) FROM organization_sensors WHERE organization_id = o.id) as sensor_count
            FROM organizations o
            JOIN user_organizations uo ON o.id = uo.organization_id
            JOIN roles r ON uo.role_id = r.id
            WHERE uo.user_id = ? AND o.is_active = 1
            ORDER BY o.name
        ");
        $stmt->execute([$user['id']]);
        $organizations = $stmt->fetchAll();
        
        jsonResponse(['success' => true, 'organizations' => $organizations]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// Обновить название организации
if ($action === 'update_organization') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $orgId = $data['organization_id'] ?? null;
    $name = $data['name'] ?? null;
    
    try {
        // Проверяем права (только lead_engineer и выше)
        $stmt = $db->prepare("
            SELECT uo.role_id FROM user_organizations uo
            WHERE uo.user_id = ? AND uo.organization_id = ?
        ");
        $stmt->execute([$user['id'], $orgId]);
        $userOrg = $stmt->fetch();
        
        if (!$userOrg || $userOrg['role_id'] > 2) { // role_id < 3 это lead_engineer и admin
            jsonResponse(['success' => false, 'error' => 'Permission denied'], 403);
        }
        
        $stmt = $db->prepare("UPDATE organizations SET name = ? WHERE id = ?");
        $stmt->execute([$name, $orgId]);
        
        jsonResponse(['success' => true, 'message' => 'Organization updated']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ========== ЛИЧНЫЙ КАБИНЕТ ==========

// Обновить профиль
if ($action === 'update_profile') {
    $user = getCurrentUser($db);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? null;
    $phone = $data['phone'] ?? null;
    $name = $data['name'] ?? null;
    
    try {
        if ($email && $email !== $user['email']) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Email already in use'], 409);
            }
        }
        
        if ($phone && $phone !== $user['phone']) {
            $stmt = $db->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $user['id']]);
            if ($stmt->fetch()) {
                jsonResponse(['success' => false, 'error' => 'Phone already in use'], 409);
            }
        }
        
        $stmt = $db->prepare("
            UPDATE users 
            SET email = COALESCE(?, email),
                phone = COALESCE(?, phone),
                name = COALESCE(?, name)
            WHERE id = ?
        ");
        $stmt->execute([$email, $phone, $name, $user['id']]);
        
        jsonResponse(['success' => true, 'message' => 'Profile updated']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

logDebug('Unknown action: ' . $action);
jsonResponse(['success' => false, 'error' => 'Unknown action: ' . $action], 400);