<?php
require_once 'config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мониторинг - Карта</title>
    <!-- Подключаем стили и скрипты MapLibre -->
    <link href="https://unpkg.com/maplibre-gl@3.x/dist/maplibre-gl.css" rel="stylesheet" />
    <style>
        body { margin: 0; padding: 0; font-family: sans-serif; display: flex; height: 100vh; overflow: hidden; }
        
        /* Сетка интерфейса */
        .sidebar { width: 300px; background: #2c3e50; color: white; display: flex; flex-direction: column; z-index: 10; }
        .sidebar-header { padding: 20px; background: #1a252f; font-size: 1.2em; border-bottom: 1px solid #34495e; }
        .sensor-list { flex-grow: 1; padding: 10px; overflow-y: auto; }
        
        .main-content { flex-grow: 1; position: relative; display: flex; flex-direction: column; }
        
        /* Верхняя панель */
        .top-header { 
            position: absolute; top: 10px; right: 10px; z-index: 5;
            display: flex; gap: 10px; background: rgba(255,255,255,0.9);
            padding: 10px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .btn { padding: 8px 15px; border-radius: 5px; text-decoration: none; font-size: 14px; font-weight: bold; }
        .btn-profile { background: #3498db; color: white; }
        .btn-logout { background: #e74c3c; color: white; }
        
        #map { flex-grow: 1; width: 100%; height: 100%; }
    </style>
</head>
<body>

    <!-- Боковое меню -->
    <div class="sidebar">
        <div class="sidebar-header">Мои датчики</div>
        <div class="sensor-list">
            <p style="color: #95a5a6; font-size: 0.9em;">Датчики пока не привязаны...</p>
        </div>
    </div>

    <!-- Основная область -->
    <div class="main-content">
        <div class="top-header">
            <a href="profile.php" class="btn btn-profile">👤 Профиль</a>
            <a href="logout.php" class="btn btn-logout">🚪 Выход</a>
        </div>
        
        <!-- Контейнер для карты -->
        <div id="map"></div>
    </div>

    <script src="https://unpkg.com/maplibre-gl@3.x/dist/maplibre-gl.js"></script>
    <script>
        // Инициализация карты с использованием OpenStreetMap
        const map = new maplibregl.Map({
            container: 'map',
            style: {
                "version": 8,
                "sources": {
                    "osm": {
                        "type": "raster",
                        "tiles": ["https://a.tile.openstreetmap.org/{z}/{x}/{y}.png"],
                        "tileSize": 256,
                        "attribution": "&copy; OpenStreetMap contributors"
                    }
                },
                "layers": [
                    {
                        "id": "osm-tiles",
                        "type": "raster",
                        "source": "osm",
                        "minzoom": 0,
                        "maxzoom": 19
                    }
                ]
            },
            center: [37.6173, 55.7558], // Начальные координаты (Москва)
            zoom: 10
        });

        // Добавляем кнопки навигации
        map.addControl(new maplibregl.NavigationControl());
    </script>
</body>
</html>