<?php
require_once 'config.php';
$sensorId = $_GET['id'] ?? '';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'employee';

// Получаем все доступные данные для последних 7 дней
$stmt = $pdo->prepare("SELECT * FROM sensor_logs WHERE sensor_id = ? ORDER BY created_at DESC LIMIT 10000");
$stmt->execute([$sensorId]);
$allLogs = array_reverse($stmt->fetchAll());

// Получаем информацию о датчике
$stmt = $pdo->prepare("SELECT * FROM sensors WHERE id = ?");
$stmt->execute([$sensorId]);
$sensorInfo = $stmt->fetch();

// Проверяем принадлежность датчика организации пользователя
if ($sensorInfo) {
    // Если пользователь не инженер и датчик не его организации
    if ($userRole === 'employee' && $sensorInfo['organization_id'] !== $_SESSION['organization_id']) {
        header("Location: main.php");
        exit;
    }
}

$latest = end($allLogs);

// Функция для проверки критического состояния
function checkCriticalStatus($sensorInfo, $latest) {
    if (!$latest || !$sensorInfo) {
        return ['critical' => false, 'reasons' => []];
    }
    
    $reasons = [];
    $critical = false;
    
    // Проверка крена
    $roll_diff = abs($latest['roll'] - $sensorInfo['roll_baseline']);
    if ($roll_diff > $sensorInfo['roll_threshold']) {
        $reasons[] = "Крен отклонился на " . round($roll_diff, 2) . "° (порог: " . $sensorInfo['roll_threshold'] . "°)";
        $critical = true;
    }
    
    // Проверка тангажа
    $pitch_diff = abs($latest['pitch'] - $sensorInfo['pitch_baseline']);
    if ($pitch_diff > $sensorInfo['pitch_threshold']) {
        $reasons[] = "Тангаж отклонился на " . round($pitch_diff, 2) . "° (порог: " . $sensorInfo['pitch_threshold'] . "°)";
        $critical = true;
    }
    
    // Проверка батареи
    if ($latest['charge'] < 20) {
        $reasons[] = "Низкий заряд батареи (" . $latest['charge'] . "%)";
        $critical = true;
    }
    
    return ['critical' => $critical, 'reasons' => $reasons];
}

$criticalStatus = checkCriticalStatus($sensorInfo, $latest);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Детали датчика <?= htmlspecialchars($sensorId) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../timezone-converter.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #1a1f3a;
            --bg-secondary: #242d47;
            --bg-tertiary: #2d3451;
            --accent: #00d4ff;
            --success: #4caf50;
            --warning: #ff9800;
            --danger: #ff5252;
            --text-primary: #e8eaed;
            --text-secondary: #9aa0a6;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 600;
        }

        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-back, .btn-edit {
            background: var(--bg-secondary);
            border: 1px solid var(--accent);
            color: var(--accent);
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-back:hover, .btn-edit:hover {
            background: var(--accent);
            color: var(--bg-primary);
        }

        .btn-edit {
            display: <?= $userRole === 'engineer' ? 'block' : 'none' ?>;
        }

        .timezone-selector-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .timezone-selector-wrapper label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
        }

        .timezone-selector-wrapper select {
            background: var(--bg-secondary);
            border: 1px solid var(--bg-tertiary);
            color: var(--text-primary);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .timezone-selector-wrapper select:hover,
        .timezone-selector-wrapper select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 10px rgba(0, 212, 255, 0.2);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .critical-alert {
            background: rgba(255, 82, 82, 0.15);
            border-left: 5px solid var(--danger);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: none;
        }

        .critical-alert.show {
            display: block;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .critical-alert h3 {
            color: var(--danger);
            margin-bottom: 8px;
            font-size: 16px;
        }

        .critical-alert ul {
            margin-left: 20px;
            color: var(--text-primary);
        }

        .critical-alert li {
            margin: 4px 0;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--bg-tertiary);
            padding: 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--accent);
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.1);
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 600;
            color: var(--accent);
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
        }

        .chart-card {
            background: var(--bg-secondary);
            border: 1px solid var(--bg-tertiary);
            padding: 20px;
            border-radius: 8px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
        }

        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: var(--bg-tertiary);
            border: 1px solid transparent;
            color: var(--text-secondary);
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            border-color: var(--text-secondary);
        }

        .filter-btn.active {
            background: var(--accent);
            color: var(--bg-primary);
            border-color: var(--accent);
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--bg-secondary);
            border: 1px solid var(--bg-tertiary);
            border-radius: 12px;
            padding: 30px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--bg-tertiary);
            padding-bottom: 15px;
        }

        .modal-header h2 {
            font-size: 20px;
        }

        .close-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 28px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 10px 12px;
            background: var(--bg-tertiary);
            border: 1px solid var(--bg-tertiary);
            color: var(--text-primary);
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 8px rgba(0, 212, 255, 0.2);
        }

        .form-section {
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .form-section h3 {
            font-size: 14px;
            color: var(--accent);
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            border-top: 1px solid var(--bg-tertiary);
            padding-top: 20px;
        }

        .btn-primary, .btn-secondary, .btn-danger {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--accent);
            color: var(--bg-primary);
        }

        .btn-primary:hover {
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.3);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--bg-tertiary);
        }

        .btn-secondary:hover {
            border-color: var(--accent);
        }

        .btn-danger {
            background: rgba(255, 82, 82, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-auto {
            background: var(--success);
            color: white;
        }

        .btn-auto:hover {
            box-shadow: 0 0 15px rgba(76, 175, 80, 0.3);
        }

        .hint {
            color: var(--text-secondary);
            font-size: 12px;
            margin-top: 6px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            border-left: 3px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(255, 82, 82, 0.2);
            border-left: 3px solid var(--danger);
            color: var(--danger);
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-right {
                width: 100%;
                justify-content: space-between;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Датчик: <?= htmlspecialchars($sensorId) ?></h1>
            <div class="header-right">
                <div class="timezone-selector-wrapper">
                    <label for="tzSelect">Часовой пояс:</label>
                    <select id="tzSelect"></select>
                </div>
                <?php if ($userRole === 'engineer'): ?>
                    <button class="btn-edit" onclick="openEditModal()">⚙️ Настройки</button>
                <?php endif; ?>
                <button class="btn-back" onclick="goBack()">← На главную</button>
            </div>
        </div>

        <!-- Критическое предупреждение -->
        <div id="criticalAlert" class="critical-alert <?= $criticalStatus['critical'] ? 'show' : '' ?>">
            <h3>⚠️ КРИТИЧЕСКОЕ СОСТОЯНИЕ ДАТЧИКА</h3>
            <ul id="criticalReasons">
                <?php foreach ($criticalStatus['reasons'] as $reason): ?>
                    <li><?= htmlspecialchars($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($latest): ?>
            <!-- Последние данные -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Напряжение</div>
                    <div class="stat-value" style="color: var(--accent)">
                        <?= $latest['voltage'] ?> <span style="font-size: 16px;">В</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Заряд</div>
                    <div class="stat-value" style="color: <?= ($latest['charge'] > 50) ? 'var(--success)' : (($latest['charge'] > 20) ? 'var(--warning)' : 'var(--danger)') ?>">
                        <?= $latest['charge'] ?> <span style="font-size: 16px;">%</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Крен</div>
                    <div class="stat-value" style="color: var(--success)">
                        <?= $latest['roll'] ?> <span style="font-size: 16px;">°</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Тангаж</div>
                    <div class="stat-value" style="color: var(--success)">
                        <?= $latest['pitch'] ?> <span style="font-size: 16px;">°</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Температура</div>
                    <div class="stat-value" style="color: var(--accent)">
                        <?= $latest['temp'] ?> <span style="font-size: 16px;">°C</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Статус</div>
                    <div class="stat-value" style="font-size: 16px;">
                        <?= $latest['status'] ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Последнее обновление</div>
                    <div id="lastUpdateTime" class="stat-value" style="font-size: 14px;">
                        <?= date('d.m.Y H:i:s', strtotime($latest['created_at'])) ?>
                    </div>
                </div>
            </div>

            <!-- Графики -->
            <div class="charts-grid">
                <!-- График заряда и напряжения -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Заряд и Напряжение</div>
                        <div class="filter-buttons">
                            <button class="filter-btn active" data-chart="battery" data-period="360">6ч</button>
                            <button class="filter-btn" data-chart="battery" data-period="1440">24ч</button>
                            <button class="filter-btn" data-chart="battery" data-period="10080">7д</button>
                            <button class="filter-btn" data-chart="battery" data-period="all">Все</button>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="batteryChart"></canvas></div>
                </div>

                <!-- График крена -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Крен</div>
                        <div class="filter-buttons">
                            <button class="filter-btn active" data-chart="roll" data-period="360">6ч</button>
                            <button class="filter-btn" data-chart="roll" data-period="1440">24ч</button>
                            <button class="filter-btn" data-chart="roll" data-period="10080">7д</button>
                            <button class="filter-btn" data-chart="roll" data-period="all">Все</button>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="rollChart"></canvas></div>
                </div>

                <!-- График тангажа -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Тангаж</div>
                        <div class="filter-buttons">
                            <button class="filter-btn active" data-chart="pitch" data-period="360">6ч</button>
                            <button class="filter-btn" data-chart="pitch" data-period="1440">24ч</button>
                            <button class="filter-btn" data-chart="pitch" data-period="10080">7д</button>
                            <button class="filter-btn" data-chart="pitch" data-period="all">Все</button>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="pitchChart"></canvas></div>
                </div>

                <!-- График температуры -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">Температура</div>
                        <div class="filter-buttons">
                            <button class="filter-btn active" data-chart="temperature" data-period="360">6ч</button>
                            <button class="filter-btn" data-chart="temperature" data-period="1440">24ч</button>
                            <button class="filter-btn" data-chart="temperature" data-period="10080">7д</button>
                            <button class="filter-btn" data-chart="temperature" data-period="all">Все</button>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="temperatureChart"></canvas></div>
                </div>
            </div>
        <?php else: ?>
            <div style="background: rgba(255, 82, 82, 0.1); border-left: 4px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 4px;">
                Данные для датчика не найдены
            </div>
        <?php endif; ?>
    </div>

    <!-- Модальное окно редактирования -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>⚙️ Настройки датчика</h2>
                <button class="close-btn" onclick="closeEditModal()">×</button>
            </div>

            <div id="modalAlert"></div>

            <!-- Редактирование координат -->
            <div class="form-section">
                <h3>📍 Координаты</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Широта (Lat)</label>
                        <input type="number" id="latInput" step="0.000001" value="<?= $sensorInfo['lat'] ?>">
                        <div class="hint">-90.0 до 90.0</div>
                    </div>
                    <div class="form-group">
                        <label>Долгота (Lng)</label>
                        <input type="number" id="lngInput" step="0.000001" value="<?= $sensorInfo['lng'] ?>">
                        <div class="hint">-180.0 до 180.0</div>
                    </div>
                </div>
                <button class="btn-primary" onclick="updateCoordinates()" style="width: 100%;">Сохранить координаты</button>
            </div>

            <!-- Базовые значения -->
            <div class="form-section">
                <h3>📐 Базовые значения крена и тангажа</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Базовое значение Крена (°)</label>
                        <input type="number" id="rollBaselineInput" step="0.1" value="<?= $sensorInfo['roll_baseline'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Базовое значение Тангажа (°)</label>
                        <input type="number" id="pitchBaselineInput" step="0.1" value="<?= $sensorInfo['pitch_baseline'] ?>">
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-primary" onclick="setBaselineManual()" style="flex: 1;">Установить вручную</button>
                    <button class="btn-auto" onclick="setBaselineAuto()" style="flex: 1;">Установить автоматически</button>
                </div>
            </div>

            <!-- Пороги -->
            <div class="form-section">
                <h3>🚨 Пороги критических значений</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Порог Крена (°)</label>
                        <input type="number" id="rollThresholdInput" step="0.1" min="0.1" value="<?= $sensorInfo['roll_threshold'] ?>">
                        <div class="hint">Допустимое отклонение от базовой стоимости</div>
                    </div>
                    <div class="form-group">
                        <label>Порог Тангажа (°)</label>
                        <input type="number" id="pitchThresholdInput" step="0.1" min="0.1" value="<?= $sensorInfo['pitch_threshold'] ?>">
                        <div class="hint">Допустимое отклонение от базовой стоимости</div>
                    </div>
                </div>
                <button class="btn-primary" onclick="updateThresholds()" style="width: 100%;">Сохранить пороги</button>
            </div>

            <!-- Удаление датчика -->
            <div class="btn-group">
                <button class="btn-secondary" onclick="closeEditModal()" style="flex: 2;">Закрыть</button>
                <button class="btn-danger" onclick="deleteSensor()" style="flex: 1;">🗑️ Удалить датчик</button>
            </div>
        </div>
    </div>

    <script>
        // Данные датчика из PHP
        const allLogs = <?= json_encode($allLogs) ?>;
        const latestData = <?= json_encode($latest) ?>;
        const sensorInfo = <?= json_encode($sensorInfo) ?>;
        const sensorId = '<?= htmlspecialchars($sensorId) ?>';
        
        let charts = {};

        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            initTimezoneSelector(document.getElementById('tzSelect'));
            updateLastUpdateTime();
            initCharts();
        });

        // Функции модального окна
        function openEditModal() {
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('show');
        }

        function showAlert(message, type = 'success') {
            const alertDiv = document.getElementById('modalAlert');
            alertDiv.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
            setTimeout(() => {
                alertDiv.innerHTML = '';
            }, 3000);
        }

        // API запросы
        function sendSensorRequest(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('sensor_id', sensorId);
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });

            fetch('../api_sensor_edit.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showAlert(result.message, 'success');
                    if (action === 'delete_sensor') {
                        setTimeout(() => window.location.href = 'main.php', 1500);
                    }
                    // Обновляем информацию о датчике
                    if (action === 'set_baseline_auto') {
                        document.getElementById('rollBaselineInput').value = result.roll_baseline;
                        document.getElementById('pitchBaselineInput').value = result.pitch_baseline;
                        sensorInfo.roll_baseline = result.roll_baseline;
                        sensorInfo.pitch_baseline = result.pitch_baseline;
                    }
                } else {
                    showAlert(result.message, 'error');
                }
            })
            .catch(err => {
                showAlert('Ошибка при отправке запроса', 'error');
                console.error(err);
            });
        }

        function updateCoordinates() {
            const lat = parseFloat(document.getElementById('latInput').value);
            const lng = parseFloat(document.getElementById('lngInput').value);

            if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
                showAlert('Неверные координаты', 'error');
                return;
            }

            sendSensorRequest('update_coordinates', { lat, lng });
        }

        function setBaselineManual() {
            const roll = parseFloat(document.getElementById('rollBaselineInput').value);
            const pitch = parseFloat(document.getElementById('pitchBaselineInput').value);

            if (isNaN(roll) || isNaN(pitch)) {
                showAlert('Введите корректные значения', 'error');
                return;
            }

            sendSensorRequest('set_baseline_manual', {
                roll_baseline: roll,
                pitch_baseline: pitch
            });
        }

        function setBaselineAuto() {
            sendSensorRequest('set_baseline_auto', {});
        }

        function updateThresholds() {
            const roll = parseFloat(document.getElementById('rollThresholdInput').value);
            const pitch = parseFloat(document.getElementById('pitchThresholdInput').value);

            if (isNaN(roll) || isNaN(pitch) || roll <= 0 || pitch <= 0) {
                showAlert('Пороги должны быть положительными', 'error');
                return;
            }

            sendSensorRequest('update_thresholds', {
                roll_threshold: roll,
                pitch_threshold: pitch
            });
        }

        function deleteSensor() {
            if (confirm('Вы уверены? Датчик будет удален из организации.')) {
                sendSensorRequest('delete_sensor', {});
            }
        }

        // Функции обновления времени
        function updateLastUpdateTime() {
            const lastUpdateElement = document.getElementById('lastUpdateTime');
            if (lastUpdateElement && latestData) {
                lastUpdateElement.textContent = formatDateInTimezone(latestData.created_at, 'full');
            }
        }

        // Функции для графиков
        function getFilteredData(minutes) {
            if (minutes === 'all') {
                return allLogs;
            }
            
            const now = new Date();
            const cutoffTime = new Date(now - minutes * 60 * 1000);
            
            return allLogs.filter(log => {
                const logTime = new Date(log.created_at);
                return logTime >= cutoffTime;
            });
        }

        function convertTimestampsForCharts(logs) {
            return logs.map(log => formatDateInTimezone(log.created_at, 'time'));
        }

        function createChart(canvasId, chartData, extraScales = {}) {
            const scales = {
                x: {
                    ticks: { color: '#9aa0a6' },
                    grid: { color: '#2d3451' }
                },
                y: {
                    ticks: { color: '#9aa0a6' },
                    grid: { color: '#2d3451' },
                    ...extraScales.y
                },
                ...extraScales
            };
            
            return new Chart(document.getElementById(canvasId), {
                type: 'line',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: '#e8eaed' }
                        }
                    },
                    scales
                }
            });
        }

        function initCharts() {
            const filtered = getFilteredData(360);

            charts.battery = createChart('batteryChart', {
                labels: convertTimestampsForCharts(filtered),
                datasets: [{
                    label: 'Заряд (%)',
                    data: filtered.map(l => l.charge),
                    borderColor: '#00d4ff',
                    backgroundColor: 'rgba(0, 212, 255, 0.1)',
                    fill: true,
                    tension: 0.3
                }, {
                    label: 'Напряжение (В)',
                    data: filtered.map(l => l.voltage),
                    borderColor: '#9aa0a6',
                    borderDash: [5, 5],
                    fill: false,
                    yAxisID: 'y1'
                }]
            }, { y: {}, y1: { position: 'right' } });

            charts.roll = createChart('rollChart', {
                labels: convertTimestampsForCharts(filtered),
                datasets: [{
                    label: 'Крен (°)',
                    data: filtered.map(l => l.roll),
                    borderColor: '#4caf50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            });

            charts.pitch = createChart('pitchChart', {
                labels: convertTimestampsForCharts(filtered),
                datasets: [{
                    label: 'Тангаж (°)',
                    data: filtered.map(l => l.pitch),
                    borderColor: '#ff9800',
                    backgroundColor: 'rgba(255, 152, 0, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            });

            charts.temperature = createChart('temperatureChart', {
                labels: convertTimestampsForCharts(filtered),
                datasets: [{
                    label: 'Температура (°C)',
                    data: filtered.map(l => l.temp),
                    borderColor: '#ff5722',
                    backgroundColor: 'rgba(255, 87, 34, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            });

            setupFilters();
        }

        function updateChart(chartName, minutes) {
            const filtered = getFilteredData(minutes);
            const chart = charts[chartName];

            chart.data.labels = convertTimestampsForCharts(filtered);

            if (chartName === 'battery') {
                chart.data.datasets[0].data = filtered.map(l => l.charge);
                chart.data.datasets[1].data = filtered.map(l => l.voltage);
            } else if (chartName === 'roll') {
                chart.data.datasets[0].data = filtered.map(l => l.roll);
            } else if (chartName === 'pitch') {
                chart.data.datasets[0].data = filtered.map(l => l.pitch);
            } else if (chartName === 'temperature') {
                chart.data.datasets[0].data = filtered.map(l => l.temp);
            }

            chart.update();
        }

        function setupFilters() {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const chartName = this.dataset.chart;
                    const minutes = this.dataset.period;

                    document.querySelectorAll(`.filter-btn[data-chart="${chartName}"]`)
                        .forEach(b => b.classList.remove('active'));
                    
                    this.classList.add('active');
                    updateChart(chartName, minutes === 'all' ? 'all' : parseInt(minutes));
                });
            });
        }

        // Обработчик изменения часового пояса
        window.addEventListener('timezoneChanged', function() {
            updateLastUpdateTime();
            document.querySelectorAll('.filter-btn.active').forEach(btn => {
                const chartName = btn.dataset.chart;
                const minutes = btn.dataset.period;
                updateChart(chartName, minutes === 'all' ? 'all' : parseInt(minutes));
            });
        });

        // Закрытие модального окна при клике вне его
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                closeEditModal();
            }
        });

        function goBack() {
            window.location.href = 'main.php';
        }
    </script>
</body>
</html>
