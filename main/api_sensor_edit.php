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

elseif ($action === 'update_baselines') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $rollBaseline = $_POST['roll_baseline'] ?? '';
    $pitchBaseline = $_POST['pitch_baseline'] ?? '';

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

    // Обновляем базовые значения
    $stmt = $pdo->prepare("UPDATE sensors SET 
        roll_baseline = NULLIF(?, ''), 
        pitch_baseline = NULLIF(?, ''),
        baseline_initialized = 1
        WHERE id = ?");
    $stmt->execute([$rollBaseline, $pitchBaseline, $sensorId]);

    respond(true, 'Базовые значения успешно обновлены', ['sensor_id' => $sensorId]);
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

elseif ($action === 'update_base_value') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Неверный метод запроса');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $fieldName = $_POST['field_name'] ?? '';
    $fieldValue = $_POST['field_value'] ?? '';

    if (!$sensorId || !$fieldName) {
        respond(false, 'Отсутствуют обязательные поля');
    }

    if ($role !== 'engineer') {
        respond(false, 'Только инженеры могут редактировать датчики');
    }

    // Белый список разрешённых полей
    $allowedFields = ['model', 'location_name', 'description'];
    if (!in_array($fieldName, $allowedFields)) {
        respond(false, 'Неверное имя поля');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас нет прав доступа');
    }

    // Обновляем значение
    $sql = "UPDATE sensors SET `$fieldName` = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fieldValue, $sensorId]);

    respond(true, 'Поле успешно обновлено', ['field' => $fieldName, 'value' => $fieldValue]);
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
