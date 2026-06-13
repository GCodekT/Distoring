<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

// Ответы
function respond($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    respond(false, 'Не авторизован');
}

$userId = $_SESSION['user_id'];
$orgId = $_SESSION['organization_id'];
$role = $_SESSION['role'] ?? 'employee';

// Получаем параметр action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'update_coordinates') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';

    if (!$sensorId || $lat === '' || $lng === '') {
        respond(false, 'Отсутствуют обязательные поля');
    }

    // Проверяем, что инженер может редактировать датчик этой организации
    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут редактировать датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Обновляем координаты
    $stmt = $pdo->prepare("UPDATE sensors SET lat = ?, lng = ? WHERE id = ?");
    $stmt->execute([$lat, $lng, $sensorId]);

    respond(true, 'Координаты успешно обновлены', ['sensor_id' => $sensorId]);
}

elseif ($action === 'set_baseline_manual') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $rollBaseline = $_POST['roll_baseline'] ?? '';
    $pitchBaseline = $_POST['pitch_baseline'] ?? '';

    if (!$sensorId || $rollBaseline === '' || $pitchBaseline === '') {
        respond(false, 'Отсутствуют обязательные поля');
    }

    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут редактировать датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Обновляем базовые значения
    $stmt = $pdo->prepare("UPDATE sensors SET 
        roll_baseline = ?, 
        pitch_baseline = ?,
        baseline_initialized = 1
        WHERE id = ?");
    $stmt->execute([$rollBaseline, $pitchBaseline, $sensorId]);

    respond(true, 'Базовые значения успешно установлены', [
        'sensor_id' => $sensorId,
        'roll_baseline' => $rollBaseline,
        'pitch_baseline' => $pitchBaseline
    ]);
}

elseif ($action === 'set_baseline_auto') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';

    if (!$sensorId) {
        respond(false, 'Отсутствует sensor_id');
    }

    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут редактировать датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Получаем последние данные датчика для автоматического установления базовых значений
    $stmt = $pdo->prepare("SELECT roll, pitch FROM sensor_logs WHERE sensor_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$sensorId]);
    $latest = $stmt->fetch();

    if (!$latest) {
        respond(false, 'Нет данных датчика для автоматического установления базовых значений');
    }

    $rollBaseline = $latest['roll'];
    $pitchBaseline = $latest['pitch'];

    // Обновляем базовые значения на основе последних данных
    $stmt = $pdo->prepare("UPDATE sensors SET 
        roll_baseline = ?, 
        pitch_baseline = ?,
        baseline_initialized = 1
        WHERE id = ?");
    $stmt->execute([$rollBaseline, $pitchBaseline, $sensorId]);

    respond(true, 'Базовые значения успешно установлены автоматически', [
        'sensor_id' => $sensorId,
        'roll_baseline' => $rollBaseline,
        'pitch_baseline' => $pitchBaseline
    ]);
}

elseif ($action === 'update_thresholds') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $rollThreshold = $_POST['roll_threshold'] ?? '';
    $pitchThreshold = $_POST['pitch_threshold'] ?? '';

    if (!$sensorId) {
        respond(false, 'Отсутствует sensor_id');
    }

    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут редактировать датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Обновляем пороги
    $stmt = $pdo->prepare("UPDATE sensors SET 
        roll_threshold = NULLIF(?, ''), 
        pitch_threshold = NULLIF(?, '')
        WHERE id = ?");
    $stmt->execute([$rollThreshold, $pitchThreshold, $sensorId]);

    respond(true, 'Пороги успешно обновлены', ['sensor_id' => $sensorId]);
}

elseif ($action === 'delete_sensor') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';

    if (!$sensorId) {
        respond(false, 'Отсутствует sensor_id');
    }

    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут удалять датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Удаляем датчик
    $stmt = $pdo->prepare("DELETE FROM sensors WHERE id = ?");
    $stmt->execute([$sensorId]);

    respond(true, 'Датчик успешно удален', ['sensor_id' => $sensorId]);
}

elseif ($action === 'get_sensor_data') {
    $sensorId = $_GET['sensor_id'] ?? '';

    if (!$sensorId) {
        respond(false, 'Отсутствует sensor_id');
    }

    // Получаем данные датчика
    $stmt = $pdo->prepare("SELECT * FROM sensors WHERE id = ? AND organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден');
    }

    respond(true, 'Данные датчика получены', $sensor);
}

else {
    respond(false, 'Неизвестное действие');
}
?>
