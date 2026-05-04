<?php
require_once 'config.php';
$sensorId = $_GET['id'] ?? '';

// Получаем последние 20 записей для графиков
$stmt = $pdo->prepare("SELECT * FROM sensor_logs WHERE sensor_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$sensorId]);
$logs = array_reverse($stmt->fetchAll()); // Переворачиваем для хронологии на графике

$latest = end($logs);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Детали датчика <?= htmlspecialchars($sensorId) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .chart-container { position: relative; height: 250px; width: 100%; }
    </style>
</head>
<body>
    <h1>Датчик: <?= htmlspecialchars($sensorId) ?></h1>
    
    <div class="grid">
        <div class="card">
            <h3>Последние данные (<?= $latest['created_at'] ?>)</h3>
            <p>Напряжение: <b><?= $latest['voltage'] ?> В</b></p>
            <p>Заряд: <b><?= $latest['charge'] ?>%</b></p>
            <p>Температура: <b><?= $latest['temp'] ?>°C</b></p>
            <p>Статус: <b><?= $latest['status'] ?></b></p>
        </div>

        <div class="card">
            <h3>Заряд и Напряжение</h3>
            <div class="chart-container"><canvas id="chartCharge"></canvas></div>
        </div>

        <div class="card">
            <h3>Крен и Тангаж</h3>
            <div class="chart-container"><canvas id="chartPitch"></canvas></div>
        </div>
        
        <div class="card">
            <h3>Температура</h3>
            <div class="chart-container"><canvas id="chartTemp"></canvas></div>
        </div>
    </div>

    <script>
        const labels = <?= json_encode(array_map(function($l){ return date('H:i', strtotime($l['created_at'])); }, $logs)) ?>;
        
        new Chart(document.getElementById('chartCharge'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Заряд %', data: <?= json_encode(array_column($logs, 'charge')) ?>, borderColor: 'green' },
                    { label: 'Вольт', data: <?= json_encode(array_column($logs, 'voltage')) ?>, borderColor: 'blue' }
                ]
            }
        });

        new Chart(document.getElementById('chartTemp'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{ label: 'Температура °C', data: <?= json_encode(array_column($logs, 'temp')) ?>, borderColor: 'orange' }]
            }
        });

        new Chart(document.getElementById('chartPitch'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Крен', data: <?= json_encode(array_column($logs, 'roll')) ?>, borderColor: 'purple' },
                    { label: 'Тангаж', data: <?= json_encode(array_column($logs, 'pitch')) ?>, borderColor: 'red' }
                ]
            }
        });
    </script>
</body>
</html>