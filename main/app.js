/**
 * Основная логика приложения Distoring
 * Управление датчиками, организациями, мониторинг
 */

let map;
let markers = {};
let sensors = [];
let selectedSensor = null;
let editingSensor = null;

const MAP_STYLE = {
    version: 8,
    sources: {
        osm: {
            type: 'raster',
            tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
            tileSize: 256,
            attribution: '© OpenStreetMap contributors'
        }
    },
    layers: [
        {
            id: 'osm',
            type: 'raster',
            source: 'osm'
        }
    ]
};

// Инициализация при переходе на вид мониторинга
function initMapOnce() {
    if (map) return;
    
    map = new maplibregl.Map({
        container: 'map',
        style: MAP_STYLE,
        center: [82.9429, 55.0144],
        zoom: 4,
        pitch: 0,
        bearing: 0
    });

    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl(), 'bottom-left');

    map.on('load', () => {
        if (currentOrganization) {
            loadSensorsForOrganization(currentOrganization.id);
        }
    });
    
    setupEventListeners();
}

function setupEventListeners() {
    // Переключение sidebar
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const mapEl = document.getElementById('map');
        const toggle = this;
        
        sidebar.classList.toggle('hidden');
        mapEl.classList.toggle('fullwidth');
        toggle.classList.toggle('collapsed');
        
        setTimeout(() => map.resize(), 300);
    });
    
    // Поиск датчиков
    document.getElementById('searchBox').addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase();
        const filtered = sensors.filter(sensor =>
            sensor.device_id.toLowerCase().includes(query) ||
            (sensor.name && sensor.name.toLowerCase().includes(query))
        );
        displaySensors(filtered);
    });
}

// ========== ЗАГРУЗКА ДАТЧИКОВ ==========

async function loadSensorsForOrganization(orgId) {
    try {
        const response = await fetch(`${API_URL}?action=my_sensors&org_id=${orgId}`, {
            headers: { 'Authorization': `Bearer ${currentToken}` }
        });
        
        const data = await response.json();
        
        if (data.success) {
            sensors = data.sensors;
            displaySensors(sensors);
            if (map) {
                updateMap(sensors);
            }
        } else {
            console.error('Load sensors error:', data.error);
        }
    } catch (error) {
        console.error('Error loading sensors:', error);
    }
}

async function loadSensorsForManagement() {
    if (!currentOrganization) return;
    
    try {
        const response = await fetch(`${API_URL}?action=my_sensors&org_id=${currentOrganization.id}`, {
            headers: { 'Authorization': `Bearer ${currentToken}` }
        });
        
        const data = await response.json();
        
        if (data.success) {
            sensors = data.sensors;
            displaySensorsForManagement(sensors);
        }
    } catch (error) {
        console.error('Error loading sensors for management:', error);
    }
}

// ========== ОТОБРАЖЕНИЕ ДАТЧИКОВ ==========

function displaySensors(sensorList) {
    const container = document.getElementById('sensorsList');
    
    if (!currentOrganization) {
        container.innerHTML = '<div class="loading"><p>Выберите организацию</p></div>';
        return;
    }
    
    if (sensorList.length === 0) {
        container.innerHTML = '<div class="loading"><p>Датчики не найдены</p></div>';
        return;
    }
    
    container.innerHTML = sensorList.map(sensor => {
        const isOnline = sensor.last_seen && isRecent(sensor.last_seen);
        const batteryColor = sensor.charge_percent > 50 ? '#4caf50' :
                           sensor.charge_percent > 20 ? '#ff9800' : '#f44336';
        
        return `
            <div class="sensor-card" onclick="selectSensorForMonitor(${sensor.id})">
                <div class="sensor-name">
                    <span>${sensor.name || 'Датчик'}</span>
                    <span class="sensor-id">${sensor.device_id}</span>
                </div>
                <div class="sensor-status">
                    <div class="status-item">
                        <span class="status-badge ${isOnline ? 'online' : 'offline'}"></span>
                        <span>${isOnline ? 'Онлайн' : 'Оффлайн'}</span>
                    </div>
                    ${sensor.charge_percent ? `
                    <div class="status-item">
                        🔋 <span style="color: ${batteryColor}">${sensor.charge_percent}%</span>
                    </div>
                    ` : ''}
                    ${sensor.last_data_time ? `
                    <div class="status-item">
                        🕐 ${formatDateTime(sensor.last_data_time)}
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function displaySensorsForManagement(sensorList) {
    const container = document.getElementById('manageSensorsList');
    
    if (!currentOrganization) {
        container.innerHTML = '<p class="text-muted">Выберите организацию</p>';
        return;
    }
    
    if (sensorList.length === 0) {
        container.innerHTML = '<p class="text-muted">Датчики не добавлены</p>';
        return;
    }
    
    container.innerHTML = sensorList.map(sensor => `
        <div class="sensor-manage-item" onclick="editSensor(${sensor.id}, '${sensor.device_id}', '${sensor.name || ''}', ${sensor.latitude}, ${sensor.longitude}, '${sensor.last_data_time || ''}')">
            <div style="font-weight: 600;">${sensor.name || 'Датчик'}</div>
            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 4px;">
                ${sensor.device_id}
            </div>
        </div>
    `).join('');
}

// Выбор датчика для мониторинга
function selectSensorForMonitor(sensorId) {
    selectedSensor = sensorId;
    
    document.querySelectorAll('.sensor-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.target.closest('.sensor-card')?.classList.add('selected');
    
    const sensor = sensors.find(s => s.id == sensorId);
    if (sensor && map && sensor.latitude && sensor.longitude) {
        initMapOnce();
        map.flyTo({
            center: [sensor.longitude, sensor.latitude],
            zoom: 12,
            duration: 1500
        });
        
        if (markers[sensorId]) {
            setTimeout(() => {
                markers[sensorId].marker.togglePopup();
            }, 800);
        }
    }
}

// ========== КАРТА ==========

function createMarker(sensor) {
    const isOnline = sensor.last_seen && isRecent(sensor.last_seen);
    const batteryLevel = sensor.charge_percent || 0;
    
    let color = '#9aa0a6';
    if (isOnline) {
        if (batteryLevel > 50) color = '#4caf50';
        else if (batteryLevel > 20) color = '#ff9800';
        else color = '#f44336';
    }
    
    const isPrecise = sensor.is_precise_location === 1 || sensor.is_precise_location === true;
    
    const el = document.createElement('div');
    el.className = 'marker';
    el.style.backgroundColor = color;
    el.style.width = '40px';
    el.style.height = '40px';
    el.style.borderRadius = '50%';
    el.style.border = '3px solid #fff';
    el.style.boxShadow = '0 2px 8px rgba(0,0,0,0.3)';
    el.style.display = 'flex';
    el.style.alignItems = 'center';
    el.style.justifyContent = 'center';
    el.style.fontSize = '18px';
    el.style.cursor = 'pointer';
    el.style.transition = 'transform 0.2s';
    el.innerHTML = isPrecise ? '📍' : '📡';
    
    el.addEventListener('mouseenter', () => el.style.transform = 'scale(1.2)');
    el.addEventListener('mouseleave', () => el.style.transform = 'scale(1)');
    
    const popup = new maplibregl.Popup({
        offset: 25,
        closeButton: true,
        closeOnClick: false,
        className: 'sensor-popup'
    }).setHTML(createPopupContent(sensor));
    
    const marker = new maplibregl.Marker({
        element: el,
        anchor: 'center'
    })
        .setLngLat([sensor.longitude, sensor.latitude])
        .setPopup(popup)
        .addTo(map);
    
    el.addEventListener('click', (e) => {
        e.stopPropagation();
        document.querySelectorAll('.maplibregl-popup').forEach(p => {
            if (p !== popup.getElement()) {
                p.style.display = 'none';
            }
        });
        marker.togglePopup();
    });
    
    markers[sensor.id] = { marker, popup };
}

function createPopupContent(sensor) {
    const isOnline = sensor.last_seen && isRecent(sensor.last_seen);
    const batteryColor = sensor.charge_percent > 50 ? '#4caf50' :
                        sensor.charge_percent > 20 ? '#ff9800' : '#f44336';
    
    return `
        <div class="popup-header">
            <div class="popup-title">${sensor.name || 'Датчик'}</div>
            <div class="sensor-id">${sensor.device_id}</div>
        </div>
        <div class="popup-body">
            <div class="status-item" style="margin-bottom: 12px;">
                <span class="status-badge ${isOnline ? 'online' : 'offline'}"></span>
                <span>${isOnline ? 'Онлайн' : 'Оффлайн'}</span>
            </div>
            ${sensor.voltage ? `
            <div class="data-grid">
                <div class="data-item">
                    <div class="data-label">Напряжение</div>
                    <div class="data-value">${sensor.voltage} В</div>
                </div>
                <div class="data-item">
                    <div class="data-label">Заряд</div>
                    <div class="data-value" style="color: ${batteryColor}">${sensor.charge_percent}%</div>
                </div>
                <div class="data-item">
                    <div class="data-label">Крен</div>
                    <div class="data-value">${sensor.roll_angle}°</div>
                </div>
                <div class="data-item">
                    <div class="data-label">Тангаж</div>
                    <div class="data-value">${sensor.pitch_angle}°</div>
                </div>
            </div>
            ` : '<p style="color: var(--text-secondary);">Нет данных</p>'}
            ${sensor.last_data_time ? `
            <div style="margin-top: 12px; font-size: 12px; color: var(--text-secondary);">
                Обновлено: ${formatDateTime(sensor.last_data_time)}
            </div>
            ` : ''}
        </div>
        <div class="popup-actions">
            <button class="btn" style="flex: 1;" onclick="openSensorDetails(${sensor.id})">
                📊 Подробнее
            </button>
        </div>
    `;
}

function updateMap(sensorList) {
    Object.values(markers).forEach(({ marker }) => marker.remove());
    markers = {};
    
    sensorList.forEach(sensor => {
        if (sensor.latitude && sensor.longitude) {
            createMarker(sensor);
        }
    });
}

// ========== УПРАВЛЕНИЕ ДАТЧИКАМИ ==========

function editSensor(sensorId, deviceId, name, lat, lon, lastSeen) {
    editingSensor = {
        id: sensorId,
        device_id: deviceId,
        name: name,
        latitude: lat,
        longitude: lon,
        last_seen: lastSeen
    };
    
    document.querySelectorAll('.sensor-manage-item').forEach(item => {
        item.classList.remove('active');
    });
    event.target.closest('.sensor-manage-item')?.classList.add('active');
    
    // Заполняем форму редактора
    document.getElementById('editorDeviceId').value = deviceId;
    document.getElementById('editorName').value = name;
    document.getElementById('editorLat').value = lat;
    document.getElementById('editorLon').value = lon;
    document.getElementById('editorLastSeen').value = formatDateTime(lastSeen);
    
    document.getElementById('managePlaceholder').style.display = 'none';
    document.getElementById('sensorEditor').style.display = 'block';
    document.getElementById('addSensorForm').style.display = 'none';
}

function closeSensorEditor() {
    document.getElementById('sensorEditor').style.display = 'none';
    document.getElementById('managePlaceholder').style.display = 'block';
    editingSensor = null;
    
    document.querySelectorAll('.sensor-manage-item').forEach(item => {
        item.classList.remove('active');
    });
}

async function saveSensorLocation() {
    if (!editingSensor) return;
    
    const lat = parseFloat(document.getElementById('editorLat').value);
    const lon = parseFloat(document.getElementById('editorLon').value);
    
    if (isNaN(lat) || isNaN(lon)) {
        alert('Введите корректные координаты');
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=update_sensor_location`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${currentToken}`
            },
            body: JSON.stringify({
                sensor_id: editingSensor.id,
                latitude: lat,
                longitude: lon
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Координаты сохранены');
            loadSensorsForManagement();
            if (map) {
                loadSensorsForOrganization(currentOrganization.id);
            }
        } else {
            alert('Ошибка: ' + data.error);
        }
    } catch (error) {
        console.error('Error saving location:', error);
        alert('Ошибка при сохранении');
    }
}

async function removeSensorFromOrg() {
    if (!editingSensor) return;
    
    if (!confirm('Удалить датчик из организации?')) {
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=remove_sensor_from_org`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${currentToken}`
            },
            body: JSON.stringify({
                organization_id: currentOrganization.id,
                sensor_id: editingSensor.id
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Датчик удалён');
            closeSensorEditor();
            loadSensorsForManagement();
            if (map) {
                loadSensorsForOrganization(currentOrganization.id);
            }
        } else {
            alert('Ошибка: ' + data.error);
        }
    } catch (error) {
        console.error('Error removing sensor:', error);
        alert('Ошибка при удалении');
    }
}

function openAddSensorForm() {
    document.getElementById('addSensorForm').style.display = 'block';
    document.getElementById('sensorEditor').style.display = 'none';
    document.getElementById('managePlaceholder').style.display = 'none';
    document.getElementById('newSensorId').value = '';
}

function closeAddSensorForm() {
    document.getElementById('addSensorForm').style.display = 'none';
    document.getElementById('managePlaceholder').style.display = 'block';
}

async function addSensorToOrg() {
    if (!currentOrganization) {
        alert('Выберите организацию');
        return;
    }
    
    const deviceId = document.getElementById('newSensorId').value.trim();
    
    if (!deviceId) {
        alert('Введите Device ID датчика');
        return;
    }
    
    // Находим датчик по device_id в глобальном реестре
    // Для этого нужно сделать поиск в API
    try {
        const response = await fetch(`${API_URL}?action=search&q=${deviceId}`, {
            headers: { 'Authorization': `Bearer ${currentToken}` }
        });
        
        const data = await response.json();
        
        if (!data.success || data.results.length === 0) {
            alert('Датчик не найден');
            return;
        }
        
        const sensorId = data.results[0].id;
        
        // Добавляем в организацию
        const addResponse = await fetch(`${API_URL}?action=add_sensor_to_org`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${currentToken}`
            },
            body: JSON.stringify({
                organization_id: currentOrganization.id,
                sensor_id: sensorId
            })
        });
        
        const addData = await addResponse.json();
        
        if (addData.success) {
            alert('Датчик добавлен');
            closeAddSensorForm();
            loadSensorsForManagement();
            if (map) {
                loadSensorsForOrganization(currentOrganization.id);
            }
        } else {
            alert('Ошибка: ' + addData.error);
        }
    } catch (error) {
        console.error('Error adding sensor:', error);
        alert('Ошибка при добавлении датчика');
    }
}

// ========== ОТКРЫТИЕ ДЕТАЛЕЙ ДАТЧИКА ==========

function openSensorDetails(sensorId) {
    // Переходим на страницу сенсора
    window.location.href = `sensor.html?id=${sensorId}&token=${encodeURIComponent(currentToken)}`;
}

// ========== УТИЛИТЫ ==========

function isRecent(timestamp) {
    const now = new Date();
    const lastSeen = new Date(timestamp);
    const diff = (now - lastSeen) / 1000 / 60;
    return diff < 5;
}

function getDateWithTZ(dateStr, tzOffset = currentTZOffset) {
    if (!dateStr) return new Date();
    
    const [datePart, timePart] = dateStr.split(' ');
    if (!datePart || !timePart) return new Date();
    
    const [y, mo, d] = datePart.split('-').map(Number);
    const [h, mi, s] = timePart.split(':').map(Number);
    
    const timestamp = Date.UTC(y, mo - 1, d, h, mi, s) - (3 * 60 * 60 * 1000);
    const sensorDate = new Date(timestamp);
    
    return sensorDate;
}

function formatDateTime(dateStr) {
    if (!dateStr) return '—';
    
    const [datePart, timePart] = dateStr.split(' ');
    if (!datePart || !timePart) return '—';
    
    const [y, mo, d] = datePart.split('-').map(Number);
    const [h, mi, s] = timePart.split(':').map(Number);
    
    const timestamp = Date.UTC(y, mo - 1, d, h, mi, s) - (3 * 60 * 60 * 1000);
    const sensorDate = new Date(timestamp);
    
    const now = new Date();
    const diff = (now.getTime() - sensorDate.getTime()) / 1000;
    
    if (diff < 60) return 'только что';
    if (diff < 3600) return `${Math.floor(diff / 60)} мин назад`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} ч назад`;
    
    const msOffset = currentTZOffset * 60 * 60 * 1000;
    const displayDate = new Date(sensorDate.getTime() + msOffset);
    
    const year = displayDate.getUTCFullYear();
    const month = String(displayDate.getUTCMonth() + 1).padStart(2, '0');
    const day = String(displayDate.getUTCDate()).padStart(2, '0');
    const hour = String(displayDate.getUTCHours()).padStart(2, '0');
    const minute = String(displayDate.getUTCMinutes()).padStart(2, '0');
    const second = String(displayDate.getUTCSeconds()).padStart(2, '0');
    
    return `${day}.${month}.${year}, ${hour}:${minute}:${second}`;
}

// Инициализируем карту при переключении на вид мониторинга
document.addEventListener('DOMContentLoaded', function() {
    const originalSwitchView = window.switchView;
    window.switchView = function(view) {
        originalSwitchView(view);
        if (view === 'monitor') {
            setTimeout(() => initMapOnce(), 100);
        }
    };
});

// Перезагружаем датчики при смене часового пояса
window.addEventListener('timezoneChanged', function() {
    if (currentOrganization) {
        loadSensorsForOrganization(currentOrganization.id);
    }
});