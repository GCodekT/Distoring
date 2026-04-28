/**
 * Логика страницы детального просмотра датчика
 */

const API_URL = 'api.php';
const urlParams = new URLSearchParams(window.location.search);
const sensorId = urlParams.get('id');
const token = urlParams.get('token');

let sensorData = null;
let history = [];
let charts = {};

document.addEventListener('DOMContentLoaded', function() {
    // Проверяем авторизацию
    if (!token) {
        alert('Требуется авторизация');
        window.location.href = 'index.html';
        return;
    }
    
    if (!sensorId) {
        alert('ID датчика не указан');
        window.location.href = 'index.html';
        return;
    }
    
    initTimezoneSelector(document.getElementById('tzSelect'));
    loadSensorData();
    setupFilters();
    
    setInterval(loadSensorData, 30000);
});

async function loadSensorData() {
    try {
        const response = await fetch(`${API_URL}?action=sensor&id=${sensorId}&hours=168&limit=10000`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        const data = await response.json();
        
        if (data.success) {
            sensorData = data.sensor;
            history = data.history;
            displaySensorInfo();
            
            if (Object.keys(charts).length === 0) {
                initCharts();
            } else {
                updateChartsData();
            }
        } else {
            document.querySelector('.container').innerHTML =
                '<div class="loading">Датчик не найден или доступ запрещён</div>';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Ошибка при загрузке данных');
    }
}

function displaySensorInfo() {
    document.getElementById('sensorTitle').textContent =
        `${sensorData.name} (${sensorData.device_id})`;
    
    const lastData = history[history.length - 1];
    
    const getBatteryColor = (percent) => {
        if (!percent) return 'var(--text-secondary)';
        if (percent > 50) return 'var(--success)';
        if (percent > 20) return 'var(--warning)';
        return 'var(--danger)';
    };
    
    const formatDateTime = (dateStr) => {
        if (!dateStr) return '—';
        return convertTimeToCurrentTZ(dateStr);
    };
    
    document.getElementById('statsGrid').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Напряжение</div>
            <div class="stat-value" style="color: var(--accent)">
                ${lastData?.voltage || '—'} <span style="font-size: 16px;">В</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Заряд</div>
            <div class="stat-value" style="color: ${getBatteryColor(lastData?.charge_percent)}">
                ${lastData?.charge_percent || '—'} <span style="font-size: 16px;">%</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Крен</div>
            <div class="stat-value" style="color: var(--success)">
                ${lastData?.roll_angle || '—'} <span style="font-size: 16px;">°</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Тангаж</div>
            <div class="stat-value" style="color: var(--success)">
                ${lastData?.pitch_angle || '—'} <span style="font-size: 16px;">°</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Температура</div>
            <div class="stat-value" style="color: var(--accent)">
                ${lastData?.temperature || '—'} <span style="font-size: 16px;">°C</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Статус</div>
            <div class="stat-value" style="font-size: 16px;">
                ${lastData?.status || 'OK'}
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Последнее обновление</div>
            <div class="stat-value" style="font-size: 14px;">
                ${formatDateTime(lastData?.timestamp)}
            </div>
        </div>
    `;
}

function initCharts() {
    const filtered = getFilteredData(60);
    
    charts.battery = createChart('batteryChart', {
        labels: convertTimestamps(filtered.timestamps),
        datasets: [{
            label: 'Заряд (%)',
            data: filtered.charges,
            borderColor: '#00d4ff',
            backgroundColor: 'rgba(0, 212, 255, 0.1)',
            fill: true,
            tension: 0.3
        }, {
            label: 'Напряжение (В)',
            data: filtered.voltages,
            borderColor: '#9aa0a6',
            borderDash: [5, 5],
            fill: false,
            yAxisID: 'y1'
        }]
    }, { y: {}, y1: { position: 'right' } });
    
    charts.roll = createChart('rollChart', {
        labels: convertTimestamps(filtered.timestamps),
        datasets: [{
            label: 'Крен (°)',
            data: filtered.rolls,
            borderColor: '#4caf50',
            backgroundColor: 'rgba(76, 175, 80, 0.1)',
            fill: true,
            tension: 0.3
        }]
    });
    
    charts.pitch = createChart('pitchChart', {
        labels: convertTimestamps(filtered.timestamps),
        datasets: [{
            label: 'Тангаж (°)',
            data: filtered.pitches,
            borderColor: '#ff9800',
            backgroundColor: 'rgba(255, 152, 0, 0.1)',
            fill: true,
            tension: 0.3
        }]
    });
    
    charts.temperature = createChart('temperatureChart', {
        labels: convertTimestamps(filtered.timestamps),
        datasets: [{
            label: 'Температура (°C)',
            data: filtered.temperatures,
            borderColor: '#ff5722',
            backgroundColor: 'rgba(255, 87, 34, 0.1)',
            fill: true,
            tension: 0.3
        }]
    });
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
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    labels: { color: '#e8eaed' }
                }
            },
            scales
        }
    });
}

function getFilteredData(minutes) {
    const indices = filterByPeriod(history.map(h => h.timestamp), minutes);
    
    return {
        timestamps: indices.map(i => history[i].timestamp),
        voltages: indices.map(i => history[i].voltage),
        charges: indices.map(i => history[i].charge_percent),
        rolls: indices.map(i => history[i].roll_angle),
        pitches: indices.map(i => history[i].pitch_angle),
        temperatures: indices.map(i => history[i].temperature)
    };
}

function updateChart(chartName, minutes) {
    const filtered = getFilteredData(minutes);
    const chart = charts[chartName];
    
    chart.data.labels = convertTimestamps(filtered.timestamps);
    
    if (chartName === 'battery') {
        chart.data.datasets[0].data = filtered.charges;
        chart.data.datasets[1].data = filtered.voltages;
    } else if (chartName === 'roll') {
        chart.data.datasets[0].data = filtered.rolls;
    } else if (chartName === 'pitch') {
        chart.data.datasets[0].data = filtered.pitches;
    } else if (chartName === 'temperature') {
        chart.data.datasets[0].data = filtered.temperatures;
    }
    
    chart.update();
}

function setupFilters() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const chartName = this.dataset.chart;
            const minutes = parseInt(this.dataset.period);
            
            document.querySelectorAll(`.filter-btn[data-chart="${chartName}"]`)
                .forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            updateChart(chartName, minutes);
        });
    });
}

function updateChartsData() {
    document.querySelectorAll('.filter-btn.active').forEach(btn => {
        const chartName = btn.dataset.chart;
        const minutes = parseInt(btn.dataset.period);
        updateChart(chartName, minutes);
    });
}

window.addEventListener('timezoneChanged', function() {
    updateChartsData();
    displaySensorInfo();
});

function goBack() {
    window.location.href = 'index.html';
}