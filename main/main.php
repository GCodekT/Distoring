<?php
require_once 'config.php';

// 1. Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'];
$orgId  = $_SESSION['organization_id'];
$role   = $_SESSION['role'] ?? 'employee'; // По умолчанию 'employee', если роль не задана

// 2. Получаем список датчиков организации и их последние замеры
// Используем LEFT JOIN, чтобы видеть датчик, даже если логов еще нет
$sql = "SELECT s.*, sl.charge, sl.created_at as last_time 
        FROM sensors s 
        LEFT JOIN (
            SELECT sensor_id, charge, created_at 
            FROM sensor_logs 
            WHERE id IN (SELECT MAX(id) FROM sensor_logs GROUP BY sensor_id)
        ) sl ON s.id = sl.sensor_id
        WHERE s.organization_id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$orgId]);
$mySensors = $stmt->fetchAll();

// 3. Для инженера подгружаем список всех организаций (для формы добавления)
$allOrganizations = [];
if ($role === 'engineer') {
    $allOrganizations = $pdo->query("SELECT id, name FROM organizations ORDER BY name ASC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система мониторинга - Карта</title>
    
    <!-- Подключаем стили MapLibre и Chart.js (для будущего) -->
    <link href="https://unpkg.com/maplibre-gl@3.x/dist/maplibre-gl.css" rel="stylesheet" />
    
    <style>
        :root {
            --sidebar-width: 320px;
            --primary-dark: #2c3e50;
            --accent-blue: #3498db;
            --danger-red: #e74c3c;
        }

        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; height: 100vh; overflow: hidden; }
        
        /* Боковая панель */
        .sidebar { width: var(--sidebar-width); background: var(--primary-dark); color: white; display: flex; flex-direction: column; z-index: 10; box-shadow: 2px 0 5px rgba(0,0,0,0.3); }
        .sidebar-header { padding: 20px; background: #1a252f; font-size: 1.1em; font-weight: bold; border-bottom: 1px solid #34495e; }
        .admin-panel { padding: 15px; background: #1a252f; border-bottom: 1px solid #34495e; }
        .sensor-list { flex-grow: 1; overflow-y: auto; }
        
        .sensor-item { padding: 15px; border-bottom: 1px solid #3e4f5f; cursor: pointer; transition: 0.2s; }
        .sensor-item:hover { background: #34495e; }
        .sensor-id { font-weight: bold; font-size: 1.1em; display: block; color: var(--accent-blue); }
        .sensor-meta { font-size: 0.85em; color: #bdc3c7; margin-top: 5px; }

        /* Карта и кнопки */
        .main-content { flex-grow: 1; position: relative; }
        #map { width: 100%; height: 100%; }
        
        .top-header { 
            position: absolute; top: 15px; right: 15px; z-index: 5;
            display: flex; gap: 10px; 
        }
        
        .btn { padding: 10px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .btn-add { background: #27ae60; color: white; width: 100%; justify-content: center; }
        .btn-profile { background: white; color: var(--primary-dark); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .btn-logout { background: var(--danger-red); color: white; }

        /* Модальное окно */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); }
        .modal-content { background: white; margin: 8% auto; padding: 25px; width: 420px; border-radius: 12px; position: relative; }
        .modal-content h3 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 0.9em; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; }
        
        #search-results { border: 1px solid #ddd; border-top: none; max-height: 150px; overflow-y: auto; display: none; background: #fff; }
        .search-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; }
        .search-item:hover { background: #f0f7ff; }
    </style>
</head>
<body>

    <!-- Боковое меню -->
    <div class="sidebar">
        <div class="sidebar-header">📍 Мониторинг датчиков</div>
        
        <?php if ($role === 'engineer'): ?>
        <div class="admin-panel">
            <button class="btn btn-add" onclick="openModal()">➕ Добавить датчик</button>
        </div>
        <?php endif; ?>

        <div class="sensor-list">
            <?php if (empty($mySensors)): ?>
                <p style="padding: 20px; color: #95a5a6; text-align: center;">Датчики не найдены</p>
            <?php else: ?>
                <?php foreach ($mySensors as $s): ?>
                <div class="sensor-item" onclick="flyToSensor(<?= (float)$s['lng'] ?>, <?= (float)$s['lat'] ?>)">
                    <span class="sensor-id"><?= htmlspecialchars($s['id']) ?></span>
                    <div class="sensor-meta">
                        🔋 <?= $s['charge'] !== null ? $s['charge'].'%' : '??' ?> | 
                        🕒 <?= $s['last_time'] ? date('H:i d.m', strtotime($s['last_time'])) : 'Нет замеров' ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Область карты -->
    <div class="main-content">
        <div class="top-header">
            <a href="profile.php" class="btn btn-profile">👤 Профиль</a>
            <a href="logout.php" class="btn btn-logout">🚪 Выход</a>
        </div>
        
        <div id="map"></div>
    </div>

    <!-- Модальное окно (Только для инженеров) -->
    <?php if ($role === 'engineer'): ?>
    <div id="addSensorModal" class="modal">
        <div class="modal-content">
            <h3>Привязка нового датчика</h3>
            <form action="actions_sensor.php" method="POST" autocomplete="off">
                <input type="hidden" name="action" value="bind">
                
                <div class="form-group">
                    <label>Поиск по ID датчика (из логов)</label>
                    <input type="text" id="sensorSearch" placeholder="Начните вводить ID..." required>
                    <input type="hidden" id="selectedSensorId" name="sensor_id">
                    <div id="search-results"></div>
                </div>

                <div class="form-group">
                    <label>Организация</label>
                    <select name="organization_id" required>
                        <?php foreach ($allOrganizations as $org): ?>
                            <option value="<?= $org['id'] ?>" <?= $org['id'] == $orgId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label>Широта (Lat)</label>
                        <input type="number" step="0.000001" name="lat" id="latInput" required>
                    </div>
                    <div style="flex: 1;">
                        <label>Долгота (Lng)</label>
                        <input type="number" step="0.000001" name="lng" id="lngInput" required>
                    </div>
                </div>

                <button type="button" onclick="setNovosibirsk()" style="margin-bottom: 15px; cursor: pointer; background: none; border: 1px solid #3498db; color: #3498db; padding: 5px; border-radius: 4px;">📍 Новосибирск по умолчанию</button>

                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn" style="background: #27ae60; color: white; flex: 1; justify-content: center;">Привязать</button>
                    <button type="button" class="btn" onclick="closeModal()" style="background: #95a5a6; color: white; flex: 1; justify-content: center;">Отмена</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Скрипты -->
    <script src="https://unpkg.com/maplibre-gl@3.x/dist/maplibre-gl.js"></script>
    <script>
        // --- 1. Инициализация карты ---
        const map = new maplibregl.Map({
            container: 'map',
            style: {
                "version": 8,
                "sources": {
                    "osm": { "type": "raster", "tiles": ["https://a.tile.openstreetmap.org/{z}/{x}/{y}.png"], "tileSize": 256, "attribution": "&copy; OpenStreetMap" }
                },
                "layers": [{ "id": "osm-tiles", "type": "raster", "source": "osm" }]
            },
            center: [82.935733, 55.008353], // Новосибирск
            zoom: 11
        });

        // --- 2. Добавление маркеров датчиков ---
        const sensorsData = <?= json_encode($mySensors) ?>;
        
        sensorsData.forEach(s => {
            if (!s.lat || !s.lng) return;

            const popup = new maplibregl.Popup({ offset: 25 }).setHTML(`
                <div style="font-family: sans-serif; min-width: 150px;">
                    <b style="color: #2980b9;">${s.id}</b><br>
                    <hr>
                    Заряд: <b>${s.charge || '??'}%</b><br>
                    Последний замер: <br><small>${s.last_time || 'нет'}</small><br>
                    <a href="sensor_details.php?id=${s.id}" style="display: block; margin-top: 8px; color: #3498db; font-weight: bold; text-decoration: none;">📄 Подробнее</a>
                </div>
            `);

            new maplibregl.Marker({ color: s.charge < 20 ? '#e74c3c' : '#3498db' })
                .setLngLat([parseFloat(s.lng), parseFloat(s.lat)])
                .setPopup(popup)
                .addTo(map);
        });

        function flyToSensor(lng, lat) {
            map.flyTo({ center: [lng, lat], zoom: 16, essential: true });
        }

        // --- 3. Логика модального окна и AJAX поиска ---
        function openModal() { document.getElementById('addSensorModal').style.display = 'block'; }
        function closeModal() { document.getElementById('addSensorModal').style.display = 'none'; }
        function setNovosibirsk() {
            document.getElementById('latInput').value = "55.008353";
            document.getElementById('lngInput').value = "82.935733";
        }

        <?php if ($role === 'engineer'): ?>
        const searchInput = document.getElementById('sensorSearch');
        const resultsBox = document.getElementById('search-results');
        const hiddenId = document.getElementById('selectedSensorId');

        searchInput.addEventListener('input', function() {
            const val = this.value.trim();
            if (val.length < 1) { resultsBox.style.display = 'none'; return; }

            fetch('search_sensors.php?q=' + encodeURIComponent(val))
                .then(r => r.json())
                .then(data => {
                    resultsBox.innerHTML = '';
                    if (data.length > 0) {
                        resultsBox.style.display = 'block';
                        data.forEach(id => {
                            const item = document.createElement('div');
                            item.className = 'search-item';
                            item.textContent = id;
                            item.onclick = () => {
                                searchInput.value = id;
                                hiddenId.value = id;
                                resultsBox.style.display = 'none';
                                searchInput.style.borderColor = '#27ae60';
                            };
                            resultsBox.appendChild(item);
                        });
                    } else {
                        resultsBox.style.display = 'none';
                    }
                });
        });
        <?php endif; ?>

        // Закрытие модалки при клике вне её
        window.onclick = function(event) {
            const modal = document.getElementById('addSensorModal');
            if (event.target == modal) closeModal();
        }
    </script>
</body>
</html>