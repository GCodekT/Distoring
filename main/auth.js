/**
 * Управление авторизацией и сессией
 */

const API_URL = 'api.php';

let currentUser = null;
let currentToken = null;
let currentOrganization = null;
let userOrganizations = [];

// Получаем токен из URL или localStorage
function getAuthToken() {
    const urlParams = new URLSearchParams(window.location.search);
    const tokenFromUrl = urlParams.get('token');
    
    if (tokenFromUrl) {
        localStorage.setItem('auth_token', tokenFromUrl);
        // Удаляем токен из URL для чистоты
        window.history.replaceState({}, document.title, window.location.pathname);
        return tokenFromUrl;
    }
    
    return localStorage.getItem('auth_token');
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    const loadingScreen = document.getElementById('loadingScreen');
    const appScreen = document.getElementById('appScreen');
    
    if (!loadingScreen || !appScreen) {
        // На странице index.html
        initTimezoneSelector(document.getElementById('tzSelect'));
        checkAndRestoreSession();
    }
});

// Проверка и восстановление сессии
async function checkAndRestoreSession() {
    currentToken = getAuthToken();
    
    if (!currentToken) {
        // Перенаправляем на логин
        window.location.href = 'login.html';
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=profile`, {
            headers: { 'Authorization': `Bearer ${currentToken}` }
        });
        
        if (response.status === 401) {
            localStorage.removeItem('auth_token');
            window.location.href = 'login.html';
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            currentUser = data.user;
            userOrganizations = data.organizations;
            
            // Скрываем экран загрузки
            const loadingScreen = document.getElementById('loadingScreen');
            const appScreen = document.getElementById('appScreen');
            
            if (loadingScreen && appScreen) {
                loadingScreen.style.display = 'none';
                appScreen.style.display = 'flex';
            }
            
            loadOrganizations();
        } else {
            localStorage.removeItem('auth_token');
            window.location.href = 'login.html';
        }
    } catch (error) {
        console.error('Session check error:', error);
        localStorage.removeItem('auth_token');
        window.location.href = 'login.html';
    }
}

// Загрузить организации
async function loadOrganizations() {
    const orgSelect = document.getElementById('orgSelect');
    if (!orgSelect) return;
    
    orgSelect.innerHTML = '<option value="">-- Выберите организацию --</option>';
    
    if (!userOrganizations || userOrganizations.length === 0) {
        return;
    }
    
    userOrganizations.forEach(org => {
        const option = document.createElement('option');
        option.value = org.id;
        option.textContent = `${org.name} (${org.role_name})`;
        orgSelect.appendChild(option);
    });
    
    orgSelect.addEventListener('change', function() {
        if (this.value) {
            currentOrganization = userOrganizations.find(o => o.id == this.value);
            if (typeof loadSensorsForOrganization === 'function') {
                loadSensorsForOrganization(this.value);
            }
        } else {
            currentOrganization = null;
            const sensorsList = document.getElementById('sensorsList');
            const manageSensorsList = document.getElementById('manageSensorsList');
            
            if (sensorsList) {
                sensorsList.innerHTML = '<div class="loading"><p>Выберите организацию</p></div>';
            }
            if (manageSensorsList) {
                manageSensorsList.innerHTML = '<p class="text-muted">Выберите организацию</p>';
            }
        }
    });
}

// Выход
async function handleLogout() {
    if (!confirm('Вы уверены, что хотите выйти?')) {
        return;
    }
    
    try {
        await fetch(`${API_URL}?action=logout`, {
            method: 'POST',
            headers: { 'Authorization': `Bearer ${currentToken}` }
        }).catch(() => {});
    } catch (error) {
        console.error('Logout error:', error);
    }
    
    localStorage.removeItem('auth_token');
    currentUser = null;
    currentToken = null;
    currentOrganization = null;
    userOrganizations = [];
    
    window.location.href = 'login.html';
}

// Переключение видов
function switchView(view) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('view-active'));
    document.getElementById(view + 'View').classList.add('view-active');
    
    document.getElementById('btnMonitor').classList.toggle('btn-primary', view === 'monitor');
    document.getElementById('btnManage').classList.toggle('btn-primary', view === 'manage');
    
    if (view === 'manage' && currentOrganization) {
        if (typeof loadSensorsForManagement === 'function') {
            loadSensorsForManagement();
        }
    } else if (view === 'monitor') {
        setTimeout(() => {
            if (typeof initMapOnce === 'function') {
                initMapOnce();
            }
        }, 100);
    }
}

// Открыть личный кабинет
function openUserProfile() {
    const profileModal = document.getElementById('profileModal');
    if (profileModal) {
        profileModal.classList.add('active');
        loadProfileData();
    }
}

function closeUserProfile() {
    const profileModal = document.getElementById('profileModal');
    if (profileModal) {
        profileModal.classList.remove('active');
    }
}

// Загрузить данные профиля
async function loadProfileData() {
    const profileName = document.getElementById('profileName');
    const profileEmail = document.getElementById('profileEmail');
    const profilePhone = document.getElementById('profilePhone');
    const profileRole = document.getElementById('profileRole');
    const profileOrganizations = document.getElementById('profileOrganizations');
    
    if (profileName) profileName.value = currentUser.name || '';
    if (profileEmail) profileEmail.value = currentUser.email || '';
    if (profilePhone) profilePhone.value = currentUser.phone || '';
    if (profileRole) profileRole.value = currentUser.role;
    
    if (profileOrganizations) {
        profileOrganizations.innerHTML = userOrganizations.map(org => `
            <div class="org-item">
                <div>
                    <div class="org-name">${org.name}</div>
                </div>
                <div class="org-role">${org.role_name}</div>
            </div>
        `).join('');
    }
}

// Сохранить профиль
async function saveProfile() {
    const name = document.getElementById('profileName').value;
    const email = document.getElementById('profileEmail').value;
    const phone = document.getElementById('profilePhone').value;
    
    if (!email) {
        alert('Email обязателен');
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=update_profile`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${currentToken}`
            },
            body: JSON.stringify({ name, email, phone })
        });
        
        const data = await response.json();
        
        if (data.success) {
            currentUser.name = name;
            currentUser.email = email;
            currentUser.phone = phone;
            
            showSuccessMessage('Профиль обновлён');
        } else {
            alert('Ошибка: ' + data.error);
        }
    } catch (error) {
        console.error('Update profile error:', error);
        alert('Ошибка при обновлении профиля');
    }
}

function showSuccessMessage(text) {
    const successEl = document.getElementById('profileSuccess');
    if (!successEl) return;
    
    successEl.textContent = text;
    successEl.style.display = 'block';
    
    setTimeout(() => {
        successEl.style.display = 'none';
    }, 3000);
}

// События
window.addEventListener('timezoneChanged', function() {
    if (currentOrganization && typeof loadSensorsForOrganization === 'function') {
        loadSensorsForOrganization(currentOrganization.id);
    }
});