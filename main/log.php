<?php
/**
 * API Endpoint для приема данных от датчиков Arduino + SIM800L
 * URL: https://distoring.ru/log.php?id=DEVICE_ID&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK,T:20.24
 */

require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

// Логирование
function logDebug($message) {
    error_log('[SENSOR_API] ' . $message);
}

// Получаем параметры
$data = isset($_GET['a']) ? trim($_GET['a']) : '';
$deviceId = isset($_GET['id']) ? trim($_GET['id']) : '';

// Если данных нет - показываем статистику
if (empty($data)) {
    showStats();
    exit;
}

// Проверка device_id
if (empty($deviceId)) {
    logDebug("No device ID provided");
    echo "ERROR: Device ID required";
    exit;
}


// Парсинг данных (порядок в строке может быть любым, но обычно V,C,R,P,S,T)
if (!preg_match('/V:([\d.]+).*?C:(\d+).*?R:([-\d.]+).*?P:([-\d.]+)(?:.*?T:([\d.-]+))?(?:.*?S:([A-Z_]+))?/i', $data, $matches)) {
    logDebug("Invalid data format: $data");
    echo "ERROR: Invalid data format";
    exit;
}

$voltage     = (float)($matches[1] ?? 0);
$charge      = (int)($matches[2] ?? 0);
$roll        = (float)($matches[3] ?? 0);
$pitch       = (float)($matches[4] ?? 0);
$temperature = !empty($matches[5]) ? (float)$matches[5] : null;  // Теперь T на группе 5
$status      = !empty($matches[6]) ? strtoupper(trim($matches[6])) : 'OK';  // S на группе 6

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
            INSERT INTO sensors (device_id, name, first_seen, last_seen, is_active) 
            VALUES (?, ?, NOW(), NOW(), 1)
        ");
        $stmt->execute([$deviceId, "Датчик $deviceId"]);
        $sensorId = $db->lastInsertId();
        
        // Логируем событие
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
    
    logDebug("Data saved for sensor $deviceId: V=$voltage, C=$charge%, R=$roll°, P=$pitch°, S=$status");
    echo "OK";
    
} catch (Exception $e) {
    logDebug("Error: " . $e->getMessage());
    echo "ERROR: " . $e->getMessage();
}

// Функция показа статистики
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
        echo "https://distoring.ru/log.php?id=YOUR_DEVICE_ID&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage();
    }
}
