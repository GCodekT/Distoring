<?php
require_once 'config.php';
$sensorId = $_GET['id'] ?? '';

// Получаем все доступные данные для последних 7 дней (макс для демо)
$stmt = $pdo->prepare("SELECT * FROM sensor_logs WHERE sensor_id = ? ORDER BY created_at DESC LIMIT 10000");
$stmt->execute([$sensorId]);
$allLogs = array_reverse($stmt->fetchAll()); // Переворачиваем для хронологии

$latest = end($allLogs);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Детали датчика <?= htmlspecialchars($sensorId) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .btn-back {
            background: var(--bg-secondary);
            border: 1px solid var(--accent);
            color: var(--accent);
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            background: var(--accent);
            color: var(--bg-primary);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
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

        .alert {
            background: rgba(255, 82, 82, 0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stat-value {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Датчик: <?= htmlspecialchars($sensorId) ?></h1>
            <button class="btn-back" onclick="goBack()">← На главную</button>
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
                    <div class="stat-value" style="font-size: 14px;">
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
            <div class="alert">Данные для датчика не найдены</div>
        <?php endif; ?>
    </div>

    <script>
        // Данные датчика из PHP
        const allLogs = <?= json_encode($allLogs) ?>;
        
        let charts = {};
        const chartConfig = {
            battery: { canvas: 'batteryChart', type: 'charge', label: 'Заряд (%)' },
            roll: { canvas: 'rollChart', type: 'roll', label: 'Крен (°)' },
            pitch: { canvas: 'pitchChart', type: 'pitch', label: 'Тангаж (°)' },
            temperature: { canvas: 'temperatureChart', type: 'temp', label: 'Температура (°C)' }
        };

        // Функция для фильтрации данных по времени
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

        // Функция конвертации времени для графика
        function convertTimestamps(logs) {
            return logs.map(log => {
                const date = new Date(log.created_at);
                return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
            });
        }

        // Создание графика
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

        // Инициализация графиков
        function initCharts() {
            const filtered = getFilteredData(360); // Начально 6 часов

            // График заряда и напряжения
            charts.battery = createChart('batteryChart', {
                labels: convertTimestamps(filtered),
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

            // График крена
            charts.roll = createChart('rollChart', {
                labels: convertTimestamps(filtered),
                datasets: [{
                    label: 'Крен (°)',
                    data: filtered.map(l => l.roll),
                    borderColor: '#4caf50',
                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            });

            // График тангажа
            charts.pitch = createChart('pitchChart', {
                labels: convertTimestamps(filtered),
                datasets: [{
                    label: 'Тангаж (°)',
                    data: filtered.map(l => l.pitch),
                    borderColor: '#ff9800',
                    backgroundColor: 'rgba(255, 152, 0, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            });

            // График температуры
            charts.temperature = createChart('temperatureChart', {
                labels: convertTimestamps(filtered),
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

        // Обновление графика
        function updateChart(chartName, minutes) {
            const filtered = getFilteredData(minutes);
            const chart = charts[chartName];

            chart.data.labels = convertTimestamps(filtered);

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

        // Установка фильтров
        function setupFilters() {
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const chartName = this.dataset.chart;
                    const minutes = this.dataset.period;

                    // Убираем active класс у других кнопок этого графика
                    document.querySelectorAll(`.filter-btn[data-chart="${chartName}"]`)
                        .forEach(b => b.classList.remove('active'));
                    
                    // Добавляем active к текущей кнопке
                    this.classList.add('active');

                    // Обновляем график
                    updateChart(chartName, minutes === 'all' ? 'all' : parseInt(minutes));
                });
            });
        }

        // Кнопка возврата
        function goBack() {
            window.location.href = 'index.html';
        }

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', initCharts);
    </script>
</body>
</html>