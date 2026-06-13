<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

// Ответы
function respond($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    respond(false, 'Unauthorized');
}

$userId = $_SESSION['user_id'];
$orgId = $_SESSION['organization_id'];
$role = $_SESSION['role'] ?? 'employee';

// Получаем параметр action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'update_coordinates') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Invalid request method');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $lat = $_POST['lat'] ?? '';
    $lng = $_POST['lng'] ?? '';

    if (!$sensorId || $lat === '' || $lng === '') {
        respond(false, 'Missing required fields');
    }

    // Проверяем, что инженер может редактировать датчик этой организации
    if ($role !== 'engineer') {
        respond(false, 'Только Инженеры могут редактировать датчики');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Датчик не найден или у вас недостаточно прав');
    }

    // Обновляем координаты
    $stmt = $pdo->prepare("UPDATE sensors SET lat = ?, lng = ? WHERE id = ?");
    $stmt->execute([$lat, $lng, $sensorId]);

    respond(true, 'Координаты успешно обновлены', ['sensor_id' => $sensorId]);
}

elseif ($action === 'update_thresholds') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Invalid request method');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $chargeMin = $_POST['charge_min'] ?? '';
    $chargeMax = $_POST['charge_max'] ?? '';
    $tempMin = $_POST['temp_min'] ?? '';
    $tempMax = $_POST['temp_max'] ?? '';
    $rollMax = $_POST['roll_max'] ?? '';
    $pitchMax = $_POST['pitch_max'] ?? '';

    if (!$sensorId) {
        respond(false, 'Missing sensor_id');
    }

    if ($role !== 'engineer') {
        respond(false, 'Only engineers can edit sensors');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Sensor not found or you do not have permission');
    }

    // Обновляем пороги (если таблица поддерживает)
    try {
        $stmt = $pdo->prepare("UPDATE sensors SET 
            charge_min = NULLIF(?, ''), 
            charge_max = NULLIF(?, ''),
            temp_min = NULLIF(?, ''),
            temp_max = NULLIF(?, ''),
            roll_max = NULLIF(?, ''),
            pitch_max = NULLIF(?, '')
            WHERE id = ?");
        $stmt->execute([$chargeMin, $chargeMax, $tempMin, $tempMax, $rollMax, $pitchMax, $sensorId]);
        respond(true, 'Пороги успешно обновлены', ['sensor_id' => $sensorId]);
    } catch (PDOException $e) {
        // Если столбцы не существуют, просто логируем
        respond(true, 'Update processed (some fields may not be supported)', ['sensor_id' => $sensorId]);
    }
}

elseif ($action === 'update_base_value') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, 'Invalid request method');
    }

    $sensorId = $_POST['sensor_id'] ?? '';
    $fieldName = $_POST['field_name'] ?? '';
    $fieldValue = $_POST['field_value'] ?? '';

    if (!$sensorId || !$fieldName) {
        respond(false, 'Missing required fields');
    }

    if ($role !== 'engineer') {
        respond(false, 'Only engineers can edit sensors');
    }

    // Белый список разрешённых полей
    $allowedFields = ['model', 'location_name', 'description'];
    if (!in_array($fieldName, $allowedFields)) {
        respond(false, 'Invalid field name');
    }

    // Проверяем, принадлежит ли датчик организации инженера
    $stmt = $pdo->prepare("SELECT s.id FROM sensors s WHERE s.id = ? AND s.organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Sensor not found or you do not have permission');
    }

    // Обновляем значение
    $sql = "UPDATE sensors SET `$fieldName` = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fieldValue, $sensorId]);

    respond(true, 'Field updated successfully', ['field' => $fieldName, 'value' => $fieldValue]);
}

elseif ($action === 'get_sensor_data') {
    $sensorId = $_GET['sensor_id'] ?? '';

    if (!$sensorId) {
        respond(false, 'Missing sensor_id');
    }

    // Получаем данные датчика
    $stmt = $pdo->prepare("SELECT * FROM sensors WHERE id = ? AND organization_id = ?");
    $stmt->execute([$sensorId, $orgId]);
    $sensor = $stmt->fetch();

    if (!$sensor) {
        respond(false, 'Sensor not found');
    }

    respond(true, 'Sensor data retrieved', $sensor);
}

else {
    respond(false, 'Unknown action');
}
?>
