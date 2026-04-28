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
        // Удаляем токен из URL
        window.history.replaceState({}, document.title, window.location.pathname);
        return tokenFromUrl;
    }
    
    return localStorage.getItem('auth_token');
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initTimezoneSelector(document.getElementById('tzSelect'));
    checkAndRestoreSession();
});

// Проверка и восстановление сессии
async function checkAndRestoreSession() {
    currentToken = getAuthToken();
    
    if (!currentToken) {
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

// ========== UI ФУНКЦИИ ==========

function loadOrganizations() {
    const orgSelect = document.getElementById('orgSelect');
    if (!orgSelect) return;
    
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
            if (document.getElementById('manageSensorsList')) {
                document.getElementById('manageSensorsList').innerHTML = 
                    '<p class="text-muted">Выберите организацию</p>';
            }
        }
    });
}

function switchView(view) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('view-active'));
    document.getElementById(view + 'View').classList.add('view-active');
    
    document.getElementById('btnMonitor').classList.toggle('btn-primary', view === 'monitor');
    document.getElementById('btnManage').classList.toggle('btn-primary', view === 'manage');
    
    if (view === 'manage' && currentOrganization) {
        loadSensorsForManagement();
    } else if (view === 'monitor') {
        if (typeof initMapOnce === 'function') {
            setTimeout(() => initMapOnce(), 100);
        }
    }
}

function openUserProfile() {
    document.getElementById('profileModal').classList.add('active');
    loadProfileData();
}

function closeUserProfile() {
    document.getElementById('profileModal').classList.remove('active');
}

async function loadProfileData() {
    document.getElementById('profileName').value = currentUser.name || '';
    document.getElementById('profileEmail').value = currentUser.email || '';
    document.getElementById('profilePhone').value = currentUser.phone || '';
    document.getElementById('profileRole').value = currentUser.role;
    
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

window.addEventListener('timezoneChanged', function() {
    if (currentOrganization) {
        loadSensorsForOrganization(currentOrganization.id);
    }
});