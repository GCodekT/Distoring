<?php
/**
 * API Endpoint для приема данных от датчиков Arduino + SIM800L
 * Теперь поддерживает GPS координаты
 * URL: https://distoring.ru/log.php?id=DEVICE_ID&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK,T:20.24,LA:55.123456,LO:82.654321
 */

require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

function logDebug($message) {
    error_log('[SENSOR_API] ' . $message);
}

$data = isset($_GET['a']) ? trim($_GET['a']) : '';
$deviceId = isset($_GET['id']) ? trim($_GET['id']) : '';

if (empty($data)) {
    showStats();
    exit;
}

if (empty($deviceId)) {
    logDebug("No device ID provided");
    echo "ERROR: Device ID required";
    exit;
}

// Инициализируем переменные
$voltage     = null;
$charge      = null;
$roll        = null;
$pitch       = null;
$status      = 'OK';
$temperature = null;
$latitude    = null;
$longitude   = null;

// Парсим каждый параметр независимо (порядок не важен)
if (preg_match('/V:([\d.]+)/i', $data, $m)) $voltage = (float)$m[1];
if (preg_match('/C:(\d+)/i', $data, $m)) $charge = (int)$m[1];
if (preg_match('/R:([-\d.]+)/i', $data, $m)) $roll = (float)$m[1];
if (preg_match('/P:([-\d.]+)/i', $data, $m)) $pitch = (float)$m[1];
if (preg_match('/S:([A-Z_]+)/i', $data, $m)) $status = strtoupper(trim($m[1]));
if (preg_match('/T:([-\d.]+)/i', $data, $m)) $temperature = (float)$m[1];
if (preg_match('/LA:([-\d.]+)/i', $data, $m)) $latitude = (float)$m[1];
if (preg_match('/LO:([-\d.]+)/i', $data, $m)) $longitude = (float)$m[1];

// Проверяем обязательные параметры
if ($voltage === null || $charge === null || $roll === null || $pitch === null) {
    logDebug("Invalid data format: $data");
    echo "ERROR: Invalid data format";
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Проверяем существует ли датчик
    $stmt = $db->prepare("SELECT id FROM sensors WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    $sensor = $stmt->fetch();
    
    if (!$sensor) {
        // Создаем новый датчик
        logDebug("Creating new sensor: $deviceId");
        
        $stmt = $db->prepare("
            INSERT INTO sensors (device_id, name, first_seen, last_seen, is_active, latitude, longitude) 
            VALUES (?, ?, NOW(), NOW(), 1, ?, ?)
        ");
        $stmt->execute([$deviceId, "Датчик $deviceId", $latitude, $longitude]);
        $sensorId = $db->lastInsertId();
        
        $stmt = $db->prepare("
            INSERT INTO event_logs (sensor_id, event_type, description) 
            VALUES (?, 'SENSOR_REGISTERED', ?)
        ");
        $stmt->execute([$sensorId, "Новый датчик зарегистрирован: $deviceId"]);
    } else {
        $sensorId = $sensor['id'];
        
        // Обновляем время последней активности
        $stmt = $db->prepare("UPDATE sensors SET last_seen = NOW() WHERE id = ?");
        $stmt->execute([$sensorId]);
        
        // Если получены новые GPS координаты — обновляем их (если датчик отправляет данные)
        if ($latitude !== null && $longitude !== null && 
            ($latitude != 0 || $longitude != 0)) {
            $stmt = $db->prepare("
                UPDATE sensors 
                SET latitude = ?, longitude = ?, is_precise_location = 1 
                WHERE id = ? AND (latitude IS NULL OR longitude IS NULL)
            ");
            $stmt->execute([$latitude, $longitude, $sensorId]);
            logDebug("Updated GPS coordinates for sensor $deviceId: $latitude, $longitude");
        }
    }
    
    // Сохраняем данные
    $stmt = $db->prepare("
        INSERT INTO sensor_data 
            (sensor_id, voltage, charge_percent, roll_angle, pitch_angle, temperature, status, timestamp) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$sensorId, $voltage, $charge, $roll, $pitch, $temperature, $status]);
    
    // Проверяем критические события
    if ($charge < 20) {
        $stmt = $db->prepare("
            INSERT INTO event_logs (sensor_id, event_type, description) 
            VALUES (?, 'LOW_BATTERY', ?)
        ");
        $stmt->execute([$sensorId, "Низкий заряд батареи: {$charge}%"]);
    }
    
    if ($status !== 'OK') {
        $stmt = $db->prepare("
            INSERT INTO event_logs (sensor_id, event_type, description) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$sensorId, $status, "Статус датчика: $status"]);
    }
    
    logDebug("Data saved for sensor $deviceId: V=$voltage, C=$charge%, R=$roll°, P=$pitch°, T=$temperature°C, LA=$latitude, LO=$longitude, S=$status");
    echo "OK";
    
} catch (Exception $e) {
    logDebug("Error: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage();
}

function showStats() {
    try {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->query("SELECT COUNT(*) as total FROM sensors WHERE is_active = 1");
        $stats = $stmt->fetch();
        
        $stmt = $db->query("
            SELECT COUNT(*) as total 
            FROM sensor_data 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $recentData = $stmt->fetch();
        
        echo "=== Distoring.ru - Статистика ===\n";
        echo "Активных датчиков: {$stats['total']}\n";
        echo "Записей за 24 часа: {$recentData['total']}\n";
        echo "Время сервера: " . date('Y-m-d H:i:s') . "\n";
        echo "\nДля отправки данных используйте:\n";
        echo "https://distoring.ru/log.php?id=YOUR_DEVICE_ID&a=V:12.5,C:85,R:0.5,P:-1.2,T:20.24,S:OK,LA:55.123456,LO:82.654321\n";
        echo "Координаты (LA, LO) - опциональны\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage();
    }
}