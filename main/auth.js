/**
 * Управление авторизацией и сессией
 */

const API_URL = 'api.php';

let currentUser = null;
let currentToken = null;
let currentOrganization = null;
let userOrganizations = [];

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initTimezoneSelector(document.getElementById('tzSelect'));
    checkAndRestoreSession();
});

// Проверка и восстановление сессии
async function checkAndRestoreSession() {
    const savedToken = localStorage.getItem('auth_token');
    
    if (!savedToken) {
        showLoginScreen();
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=profile`, {
            headers: { 'Authorization': `Bearer ${savedToken}` }
        });
        
        if (response.status === 401) {
            localStorage.removeItem('auth_token');
            showLoginScreen();
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            currentUser = data.user;
            currentToken = savedToken;
            userOrganizations = data.organizations;
            showAppScreen();
            loadOrganizations();
        } else {
            localStorage.removeItem('auth_token');
            showLoginScreen();
        }
    } catch (error) {
        console.error('Session check error:', error);
        localStorage.removeItem('auth_token');
        showLoginScreen();
    }
}

// Вход в систему
async function handleLogin() {
    const login = document.getElementById('loginInput').value.trim();
    const password = document.getElementById('loginPassword').value.trim();
    const errorEl = document.getElementById('loginError');
    
    if (!login || !password) {
        showLoginError('Заполните все поля');
        return;
    }
    
    try {
        const response = await fetch(`${API_URL}?action=login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ login, password })
        });
        
        const data = await response.json();
        
        if (data.success) {
            currentUser = data.user;
            currentToken = data.token;
            userOrganizations = data.organizations || [];
            
            localStorage.setItem('auth_token', data.token);
            
            // Очищаем форму
            document.getElementById('loginInput').value = '';
            document.getElementById('loginPassword').value = '';
            errorEl.style.display = 'none';
            
            showAppScreen();
            loadOrganizations();
        } else {
            showLoginError(data.error || 'Ошибка входа');
        }
    } catch (error) {
        console.error('Login error:', error);
        showLoginError('Ошибка сети');
    }
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
    
    showLoginScreen();
}

// ========== UI ФУНКЦИИ ==========

function showLoginScreen() {
    document.getElementById('loginScreen').classList.add('screen-active');
    document.getElementById('appScreen').classList.remove('screen-active');
}

function showAppScreen() {
    document.getElementById('loginScreen').classList.remove('screen-active');
    document.getElementById('appScreen').classList.add('screen-active');
    
    // Показываем кнопку "Управление" только для инженеров и выше
    const manageBtn = document.getElementById('btnManage');
    if (currentUser.role === 'admin' || currentUser.role === 'lead_engineer') {
        manageBtn.style.display = 'block';
    } else {
        manageBtn.style.display = 'none';
    }
}

function showLoginError(message) {
    const errorEl = document.getElementById('loginError');
    errorEl.textContent = message;
    errorEl.style.display = 'block';
}

// Загрузить организации
async function loadOrganizations() {
    const orgSelect = document.getElementById('orgSelect');
    orgSelect.innerHTML = '<option value="">-- Выберите организацию --</option>';
    
    userOrganizations.forEach(org => {
        const option = document.createElement('option');
        option.value = org.id;
        option.textContent = `${org.name} (${org.role_name})`;
        orgSelect.appendChild(option);
    });
    
    orgSelect.addEventListener('change', function() {
        if (this.value) {
            currentOrganization = userOrganizations.find(o => o.id == this.value);
            loadSensorsForOrganization(this.value);
        } else {
            currentOrganization = null;
            document.getElementById('sensorsList').innerHTML = 
                '<div class="loading"><p>Выберите организацию</p></div>';
            document.getElementById('manageSensorsList').innerHTML = 
                '<p class="text-muted">Выберите организацию</p>';
        }
    });
}

// Переключение между видами
function switchView(view) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('view-active'));
    document.getElementById(view + 'View').classList.add('view-active');
    
    document.getElementById('btnMonitor').classList.toggle('btn-primary', view === 'monitor');
    document.getElementById('btnManage').classList.toggle('btn-primary', view === 'manage');
    
    if (view === 'manage' && currentOrganization) {
        loadSensorsForManagement();
    }
}

// Открыть личный кабинет
function openUserProfile() {
    document.getElementById('profileModal').classList.add('active');
    loadProfileData();
}

function closeUserProfile() {
    document.getElementById('profileModal').classList.remove('active');
}

// Загрузить данные профиля
async function loadProfileData() {
    document.getElementById('profileName').value = currentUser.name || '';
    document.getElementById('profileEmail').value = currentUser.email || '';
    document.getElementById('profilePhone').value = currentUser.phone || '';
    document.getElementById('profileRole').value = currentUser.role;
    
    // Загружаем организации
    const orgsContainer = document.getElementById('profileOrganizations');
    orgsContainer.innerHTML = userOrganizations.map(org => `
        <div class="org-item">
            <div>
                <div class="org-name">${org.name}</div>
            </div>
            <div class="org-role">${org.role_name}</div>
        </div>
    `).join('');
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
    successEl.textContent = text;
    successEl.style.display = 'block';
    
    setTimeout(() => {
        successEl.style.display = 'none';
    }, 3000);
}

// Обработчик события смены ЧП
window.addEventListener('timezoneChanged', function() {
    if (currentOrganization) {
        loadSensorsForOrganization(currentOrganization.id);
    }
});