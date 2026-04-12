/**
 * IoT Sensor Monitoring - Frontend Application
 * MapLibre GL JS версия
 */

// Configuration
const API_URL = 'api.php';
let map;
let markers = {};
let sensors = [];
let currentUser = null;
let currentFilter = 'all';
let selectedSensor = null;

// Стилизация карты (темная тема)
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

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    initTimezoneSelector(document.getElementById('tzSelect'));
    initMap();
    checkAuth();
    setupEventListeners();
    
    // Auto-refresh every 30 seconds
    setInterval(() => {
        if (currentFilter === 'all') {
            loadSensors();
        } else if (currentFilter === 'my' && currentUser) {
            loadMySensors();
        }
    }, 30000);
});

// Initialize MapLibre map
function initMap() {
    map = new maplibregl.Map({
        container: 'map',
        style: MAP_STYLE,
        center: [82.9429, 55.0144],  // Новосибирск [lon, lat]
        zoom: 4,
        pitch: 0,
        bearing: 0
    });

    // Добавляем контролы
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl(), 'bottom-left');

    // Загружаем датчики после инициализации карты
    map.on('load', () => {
        loadSensors();
    });
}

// Create custom marker
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
    
    // Создаем DOM элемент маркера
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
    el.innerHTML = isPrecise ? '📍' : '📡';
    
    // Добавляем маркер на карту
    const marker = new maplibregl.Marker({ element: el })
        .setLngLat([sensor.longitude, sensor.latitude])
        .addTo(map);
    
    // Popup
    const popup = new maplibregl.Popup({ offset: 25 })
        .setHTML(createPopupContent(sensor));
    
    marker.setPopup(popup);
    
    // Клик на маркер
    el.addEventListener('click', () => {
        popup.addTo(map);
    });
    
    markers[sensor.id] = { marker, popup };
}

// Create popup content
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
                Последнее обновление: ${formatDateTime(sensor.last_data_time)}
            </div>
            ` : ''}
        </div>
        <div class="popup-actions">
            <button class="btn btn-block" onclick="viewSensorDetails(${sensor.id})">Подробнее</button>
            ${currentUser ? `
            <button class="btn btn-primary btn-block" onclick="addSensorToAccount(${sensor.id})">
                Добавить
            </button>
            ` : ''}
        </div>
    `;
}

// Check if timestamp is recent
function isRecent(timestamp) {
    const now = new Date();
    const lastSeen = new Date(timestamp);
    const diff = (now - lastSeen) / 1000 / 60;
    return diff < 5;
}

// Format datetime
function formatDateTime(dateStr) {
    const date = new Date(dateStr);
    const now = new Date();
    const diff = (now - date) / 1000;
    
    if (diff < 60) return 'только что';
    if (diff < 3600) return `${Math.floor(diff / 60)} мин назад`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} ч назад`;
    
    return date.toLocaleString('ru-RU');
}

// Load all sensors
async function loadSensors() {
    try {
        const response = await fetch(`${API_URL}?action=sensors`);
        const data = await response.json();
        
        if (data.success) {
            sensors = data.sensors;
            displaySensors(sensors);
            updateMap(sensors);
        }
    } catch (error) {
        console.error('Error loading sensors:', error);
    }
}

// Load user's sensors
async function loadMySensors() {
    if (!currentUser) {
        console.log('Not authenticated, cannot load my sensors');
        showError('Требуется авторизация');
        switchToAllSensors();
        return;
    }
    
    try {
        const token = localStorage.getItem('auth_token');
        
        if (!token) {
            console.log('No token found');
            currentUser = null;
            updateAuthUI();
            switchToAllSensors();
            return;
        }
        
        const response = await fetch(`${API_URL}?action=my_sensors`, {
            headers: {
                'Authorization': `Bearer ${token}`
            }
        });
        
        if (response.status === 401) {
            console.log('Token invalid, logging out');
            localStorage.removeItem('auth_token');
            currentUser = null;
            updateAuthUI();
            showError('Сессия истекла. Войдите снова.');
            switchToAllSensors();
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            sensors = data.sensors;
            console.log('My sensors loaded:', sensors.length);
            displaySensors(sensors);
            updateMap(sensors);
        } else {
            console.error('Failed to load my sensors:', data.error);
        }
    } catch (error) {
        console.error('Error loading my sensors:', error);
    }
}

// Switch to all sensors tab
function switchToAllSensors() {
    currentFilter = 'all';
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.filter === 'all');
    });
    loadSensors();
}

// Display sensors in sidebar
function displaySensors(sensorList) {
    const container = document.getElementById('sensorsList');
    
    if (sensorList.length === 0) {
        container.innerHTML = '<div class="loading"><p>Датчики не найдены</p></div>';
        return;
    }
    
    container.innerHTML = sensorList.map(sensor => {
        const isOnline = sensor.last_seen && isRecent(sensor.last_seen);
        return `
            <div class="sensor-card" onclick="selectSensor(${sensor.id})">
                <div class="sensor-name">
                    <span>${sensor.name || 'Датчик'}</span>
                    <span class="sensor-id">${sensor.device_id}</span>
                </div>
                <div class="sensor-status">
                    <div class="status-item">
                        <span class="status-badge ${isOnline ? 'online' : 'offline'}"></span>
                        <span>${isOnline ? 'Онла��н' : 'Оффлайн'}</span>
                    </div>
                    ${sensor.charge_percent ? `
                    <div class="status-item">
                        🔋 ${sensor.charge_percent}%
                    </div>
                    ` : ''}
                    ${sensor.last_seen ? `
                    <div class="status-item">
                        🕐 ${formatDateTime(sensor.last_seen)}
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

// Update map markers
function updateMap(sensorList) {
    // Очищаем старые маркеры
    Object.values(markers).forEach(({ marker }) => marker.remove());
    markers = {};
    
    // Добавляем новые маркеры
    sensorList.forEach(sensor => {
        if (sensor.latitude && sensor.longitude) {
            createMarker(sensor);
        }
    });
}

// Select sensor
function selectSensor(sensorId) {
    selectedSensor = sensorId;
    
    document.querySelectorAll('.sensor-card').forEach(card => {
        card.classList.remove('selected');
    });
    event.target.closest('.sensor-card').classList.add('selected');
    
    const sensor = sensors.find(s => s.id == sensorId);
    if (sensor && sensor.latitude && sensor.longitude) {
        map.flyTo({
            center: [sensor.longitude, sensor.latitude],
            zoom: 12,
            duration: 1500
        });
        
        if (markers[sensorId]) {
            markers[sensorId].popup.addTo(map);
        }
    }
}

// View sensor details
function viewSensorDetails(sensorId) {
    window.location.href = `sensor.html?id=${sensorId}`;
}

// Add sensor to account
async function addSensorToAccount(sensorId) {
    if (!currentUser) {
        openAuthModal();
        return;
    }
    
    try {
        const token = localStorage.getItem('auth_token');
        if (!token) {
            showError('Требуется авторизация');
            openAuthModal();
            return;
        }
        
        const response = await fetch(`${API_URL}?action=add_sensor`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({ sensor_id: sensorId })
        });
        
        if (response.status === 401) {
            localStorage.removeItem('auth_token');
            currentUser = null;
            updateAuthUI();
            showError('Сессия истекла. Войдите снова.');
            openAuthModal();
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess('Датчик успешно добавлен!');
            if (currentFilter === 'my') {
                loadMySensors();
            }
        } else {
            showError(data.error);
        }
    } catch (error) {
        console.error('Error adding sensor:', error);
        showError('Ошибка при добавлении датчика');
    }
}

// Setup event listeners
function setupEventListeners() {
    // Toggle sidebar
    document.getElementById('toggleSidebar').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const mapEl = document.getElementById('map');
        const toggle = this;
        
        sidebar.classList.toggle('hidden');
        mapEl.classList.toggle('fullwidth');
        toggle.classList.toggle('collapsed');
        
        setTimeout(() => map.resize(), 300);
    });
    
    // Search
    document.getElementById('searchBox').addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase();
        const filtered = sensors.filter(sensor => 
            sensor.device_id.toLowerCase().includes(query) ||
            (sensor.name && sensor.name.toLowerCase().includes(query))
        );
        displaySensors(filtered);
    });
    
    // Filter tabs
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            if (this.dataset.filter === 'my' && !currentUser) {
                showError('Для просмотра "Мои датчики" необходимо войти в систему');
                return;
            }
            
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            currentFilter = this.dataset.filter;
            if (currentFilter === 'all') {
                loadSensors();
            } else {
                loadMySensors();
            }
        });
    });
    
    // Auth button
    document.getElementById('btnAuth').addEventListener('click', function() {
        if (currentUser) {
            logout();
        } else {
            openAuthModal();
        }
    });
    
    // About
    document.getElementById('btnAbout').addEventListener('click', function() {
        window.location.href = 'about.html';
    });
    
    // Support
    document.getElementById('btnSupport').addEventListener('click', function() {
        window.location.href = 'support.html';
    });
}

// Check authentication
async function checkAuth() {
    const token = localStorage.getItem('auth_token');
    
    if (token) {
        try {
            const response = await fetch(`${API_URL}?action=profile`, {
                headers: { 'Authorization': `Bearer ${token}` }
            });
            
            if (response.status === 401) {
                localStorage.removeItem('auth_token');
                currentUser = null;
                updateAuthUI();
                loadSensors();
                return;
            }
            
            if (!response.ok) throw new Error('Ошибка сети');
            const data = await response.json();
            
            if (data.success) {
                currentUser = data.user;
                updateAuthUI();
            } else {
                localStorage.removeItem('auth_token');
            }
        } catch (error) {
            console.error('Auth check error:', error);
            localStorage.removeItem('auth_token');
        }
    }
    
    loadSensors();
}

function updateAuthUI() {
    const btn = document.getElementById('btnAuth');
    
    if (currentUser) {
        btn.textContent = currentUser.name || currentUser.email;
        btn.classList.remove('btn-primary');
    } else {
        btn.textContent = 'Вход / Регистрация';
        btn.classList.add('btn-primary');
    }
}

function openAuthModal() {
    document.getElementById('authModal').classList.add('active');
    showLoginForm();
}

function closeAuthModal() {
    document.getElementById('authModal').classList.remove('active');
    clearAuthForms();
}

function showLoginForm() {
    document.getElementById('loginForm').style.display = 'block';
    document.getElementById('registerForm').style.display = 'none';
    document.getElementById('authModalTitle').textContent = 'Вход в систему';
    clearAuthMessages();
}

function showRegisterForm() {
    document.getElementById('loginForm').style.display = 'none';
    document.getElementById('registerForm').style.display = 'block';
    document.getElementById('authModalTitle').textContent = 'Регистрация';
    clearAuthMessages();
}

function clearAuthForms() {
    document.getElementById('loginInput').value = '';
    document.getElementById('loginPassword').value = '';
    document.getElementById('regEmail').value = '';
    document.getElementById('regPhone').value = '';
    document.getElementById('regName').value = '';
    document.getElementById('regPassword').value = '';
    clearAuthMessages();
}

function clearAuthMessages() {
    document.getElementById('authError').innerHTML = '';
    document.getElementById('authSuccess').innerHTML = '';
}

async function login() {
    const login = document.getElementById('loginInput').value;
    const password = document.getElementById('loginPassword').value;
    
    if (!login || !password) {
        showAuthError('Заполните все поля');
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ login, password })
        });
        
        if (!response.ok) throw new Error('Ошибка сети');
        const data = await response.json();
        
        if (data.success) {
            localStorage.setItem('auth_token', data.token);
            currentUser = data.user;
            updateAuthUI();
            closeAuthModal();
            showSuccess('Вход выполнен успешно!');
            
            if (currentFilter === 'my') {
                loadMySensors();
            }
        } else {
            showAuthError(data.error);
        }
    } catch (error) {
        console.error('Login error:', error);
        showAuthError('Ошибка при входе');
    }
}

async function register() {
    const email = document.getElementById('regEmail').value;
    const phone = document.getElementById('regPhone').value;
    const name = document.getElementById('regName').value;
    const password = document.getElementById('regPassword').value;
    
    if (!email || !password) {
        showAuthError('Email и пароль обязательны');
        return;
    }
    
    if (password.length < 6) {
        showAuthError('Пароль должен быть минимум 6 символов');
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, phone, name, password })
        });
        
        const data = await response.json();
        
        if (data.success) {
            localStorage.setItem('auth_token', data.token);
            currentUser = data.user;
            updateAuthUI();
            closeAuthModal();
            showSuccess('Регистрация успешна!');
        } else {
            showAuthError(data.error);
        }
    } catch (error) {
        console.error('Register error:', error);
        showAuthError('Ошибка при регистрации');
    }
}

function logout() {
    const token = localStorage.getItem('auth_token');
    if (token) {
        fetch(`${API_URL}?action=logout`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${token}` }
        }).catch(err => console.error('Logout error:', err));
    }
    
    localStorage.removeItem('auth_token');
    currentUser = null;
    updateAuthUI();
    currentFilter = 'all';
    document.querySelector('.filter-tab[data-filter="all"]').click();
    showSuccess('Выход выполнен');
}

// UI helpers
function showError(message) {
    alert('⚠️ ' + message);
}

function showSuccess(message) {
    alert('✓ ' + message);
}

function showAuthError(message) {
    document.getElementById('authError').innerHTML = `<div class="error-message">${message}</div>`;
}

// Click outside modal to close
document.getElementById('authModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAuthModal();
    }
});