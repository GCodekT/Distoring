<?php
require_once 'config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен");
}

// СТРОГАЯ ПРОВЕРКА РОЛИ (Безопасность)
if ($_SESSION['role'] !== 'engineer') {
    die("Ошибка: Только инженеры могут управлять датчиками.");
}

$action = $_POST['action'] ?? '';

if ($action === 'bind') {
    // Привязка датчика к организации
    $sensorId = $_POST['sensor_id'] ?? '';
    $orgId = $_POST['organization_id'] ?? '';
    $lat = (float)($_POST['lat'] ?? 0);
    $lng = (float)($_POST['lng'] ?? 0);

    if (!empty($sensorId) && !empty($orgId)) {
        $stmt = $pdo->prepare("UPDATE sensors SET organization_id = ?, lat = ?, lng = ? WHERE id = ?");
        $stmt->execute([$orgId, $lat, $lng, $sensorId]);
    }
} 

if ($action === 'unbind') {
    // Удаление из организации (датчик остается в БД, но organization_id = NULL)
    $sensorId = $_POST['sensor_id'] ?? '';
    $stmt = $pdo->prepare("UPDATE sensors SET organization_id = NULL WHERE id = ?");
    $stmt->execute([$sensorId]);
}

// Возвращаемся на карту
header("Location: main.php");
exit;