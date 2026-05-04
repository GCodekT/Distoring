<?php
require_once 'config.php';

$deviceId = $_GET['id'] ?? '';
$rawData = $_GET['a'] ?? '';

if (empty($deviceId) || empty($rawData)) {
    die("ERROR: No data");
}

// Парсим строку типа V:12.5,C:85,R:0.5,P:-1.2,T:21.31,S:OK
$params = [];
$parts = explode(',', $rawData);
foreach ($parts as $part) {
    $kv = explode(':', $part);
    if (count($kv) === 2) {
        $params[$kv[0]] = $kv[1];
    }
}

try {
    $pdo->beginTransaction();

    // 1. Проверяем/создаем запись о датчике
    $stmt = $pdo->prepare("INSERT INTO sensors (id, last_seen) VALUES (?, NOW()) 
                           ON DUPLICATE KEY UPDATE last_seen = NOW()");
    $stmt->execute([$deviceId]);

    // 2. Записываем лог
    $stmt = $pdo->prepare("INSERT INTO sensor_logs (sensor_id, voltage, charge, roll, pitch, temp, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $deviceId,
        $params['V'] ?? 0,
        $params['C'] ?? 0,
        $params['R'] ?? 0,
        $params['P'] ?? 0,
        $params['T'] ?? 0,
        $params['S'] ?? 'UNKNOWN'
    ]);

    $pdo->commit();
    echo "OK";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage();
}