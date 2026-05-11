<?php
require_once 'config.php';

// Проверка пароля администратора
$admin_authenticated = false;
$error = '';
$success = '';

if (isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true) {
    $admin_authenticated = true;
}

// Обработка входа администратора
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $entered_password = $_POST['admin_password'] ?? '';
    
    if ($entered_password === ADMIN_PASSWORD) {
        $_SESSION['admin_authenticated'] = true;
        $admin_authenticated = true;
    } else {
        $error = 'Неверный пароль администратора';
    }
}

/**
 * Функция для валидации email
 */
function validateEmail($email) {
    if (empty($email)) {
        return true; // Email опциональный
    }
    
    // Проверяем формат email: адрес + @ + домен + точка + расширение
    if (!preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email)) {
        return false;
    }
    
    return true;
}

/**
 * Функция для валидации телефона
 */
function validatePhone($phone) {
    if (empty($phone)) {
        return true; // Телефон опциональный
    }
    
    // Проверяем, что телефон начинается с +
    if ($phone[0] !== '+') {
        return false;
    }
    
    // Проверяем, что остаток содержит только цифры (от 10 до 15)
    $digits = substr($phone, 1);
    if (!preg_match('/^\d{10,15}$/', $digits)) {
        return false;
    }
    
    return true;
}

// Обработка создания организации
if ($admin_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_organization') {
    $org_name = trim($_POST['org_name'] ?? '');
    
    if (!empty($org_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO organizations (name) VALUES (?)");
            $stmt->execute([$org_name]);
            $success = 'Организация успешно создана';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Организация с таким названием уже существует';
            } else {
                $error = 'Ошибка создания организации';
            }
        }
    } else {
        $error = 'Введите название организации';
    }
}

// Обработка создания пользователя
if ($admin_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['user_password'] ?? '';
    $role = $_POST['role'] ?? 'employee';
    $org_id = !empty($_POST['organization_id']) ? intval($_POST['organization_id']) : null;
    
    // Валидация
    if (empty($email) && empty($phone)) {
        $error = 'Заполните email или телефон';
    } elseif (!empty($email) && !validateEmail($email)) {
        $error = 'Некорректный email. Используйте формат: example@mail.ru';
    } elseif (!empty($phone) && !validatePhone($phone)) {
        $error = 'Некорректный номер телефона. Используйте формат: +79001234567 (начинается с +)';
    } elseif (empty($password)) {
        $error = 'Введите пароль';
    } else {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users (email, phone, password_hash, role, organization_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                !empty($email) ? $email : null,
                !empty($phone) ? $phone : null,
                $password_hash,
                $role,
                $org_id
            ]);
            
            $success = 'Пользователь успешно создан';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = 'Пользователь с таким email или телефоном уже существует';
            } else {
                $error = 'Ошибка создания пользователя: ' . $e->getMessage();
            }
        }
    }
}

// Получаем список организаций
$organizations = [];
if ($admin_authenticated) {
    $stmt = $pdo->query("SELECT * FROM organizations ORDER BY name");
    $organizations = $stmt->fetchAll();
    
    // Получаем список пользователей
    $stmt = $pdo->query("
        SELECT u.*, o.name as organization_name 
        FROM users u 
        LEFT JOIN organizations o ON u.organization_id = o.id 
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
}

// Выход из админ-панели
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_authenticated']);
    header('Location: admin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - Мониторинг датчиков</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .auth-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        
        .auth-box h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 8px;
            text-align: center;
        }
        
        .auth-box p {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #666;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .card h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        input.error,
        input.error:focus {
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        
        .success {
            background: #efe;
            color: #2a2;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #2a2;
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .user-table th {
            background: #f5f7fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            font-size: 13px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .user-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
            color: #666;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-engineer {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .badge-employee {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .hint {
            color: #999;
            font-size: 13px;
            margin-top: 6px;
        }

        .validation-hint {
            color: #e74c3c;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }

        .validation-hint.show {
            display: block;
        }
    </style>
</head>
<body>
    <?php if (!$admin_authenticated): ?>
        <div class="auth-container">
            <div class="auth-box">
                <h1>🔐 Админ-панель</h1>
                <p>Введите пароль администратора</p>
                
                <?php if ($error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="admin_password">Пароль администратора</label>
                        <input 
                            type="password" 
                            id="admin_password" 
                            name="admin_password" 
                            placeholder="Введите пароль"
                            required
                            autofocus
                        >
                    </div>
                    <button type="submit" class="btn">Войти</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="header">
            <div class="header-content">
                <h1>⚙️ Панель администратора</h1>
                <a href="?logout" class="logout-btn">Выйти</a>
            </div>
        </div>
        
        <div class="container">
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="showTab('users')">Пользователи</button>
                <button class="tab" onclick="showTab('organizations')">Организации</button>
            </div>
            
            <!-- Вкладка пользователей -->
            <div id="tab-users" class="tab-content active">
                <div class="card">
                    <h2>Создать пользователя</h2>
                    <form method="POST" id="userForm">
                        <input type="hidden" name="action" value="create_user">
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                placeholder="example@mail.ru"
                                onchange="validateEmailField(this)"
                                onblur="validateEmailField(this)"
                            >
                            <div class="hint">Оставьте пустым, если используете только телефон</div>
                            <div class="validation-hint" id="emailHint">Email должен содержать @ и расширение домена (.ru, .com и т.д.)</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Телефон</label>
                            <input 
                                type="tel" 
                                id="phone" 
                                name="phone" 
                                placeholder="+79001234567"
                                onchange="validatePhoneField(this)"
                                onblur="validatePhoneField(this)"
                            >
                            <div class="hint">Оставьте пустым, если используете только email</div>
                            <div class="validation-hint" id="phoneHint">Номер должен начинаться с + и содержать 10-15 цифр (+79001234567)</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="user_password">Пароль</label>
                            <input 
                                type="password" 
                                id="user_password" 
                                name="user_password" 
                                placeholder="Создайте пароль"
                                required
                            >
                        </div>
                        
                        <div class="form-group">
                            <label for="role">Роль</label>
                            <select id="role" name="role" required>
                                <option value="employee">Сотрудник</option>
                                <option value="engineer">Инженер</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="organization_id">Организация</label>
                            <select id="organization_id" name="organization_id">
                                <option value="">Без организации</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>">
                                        <?php echo htmlspecialchars($org['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn">Создать пользователя</button>
                    </form>
                </div>
                
                <div class="card">
                    <h2>Список пользователей</h2>
                    <?php if (empty($users)): ?>
                        <p style="color: #999; text-align: center; padding: 40px;">Пользователей пока нет</p>
                    <?php else: ?>
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Email / Телефон</th>
                                    <th>Роль</th>
                                    <th>Организация</th>
                                    <th>Создан</th>
                                    <th>Последний вход</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo $user['id']; ?></td>
                                        <td>
                                            <?php 
                                            if ($user['email']) {
                                                echo htmlspecialchars($user['email']);
                                            }
                                            if ($user['email'] && $user['phone']) {
                                                echo '<br>';
                                            }
                                            if ($user['phone']) {
                                                echo htmlspecialchars($user['phone']);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $user['role']; ?>">
                                                <?php echo $user['role'] === 'engineer' ? 'Инженер' : 'Сотрудник'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($user['organization_name'] ?? '-'); ?></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                                        <td><?php echo $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка организаций -->
            <div id="tab-organizations" class="tab-content">
                <div class="card">
                    <h2>Создать организацию</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_organization">
                        
                        <div class="form-group">
                            <label for="org_name">Название организации</label>
                            <input 
                                type="text" 
                                id="org_name" 
                                name="org_name" 
                                placeholder="ООО &quot;Компания&quot;"
                                required
                            >
                        </div>
                        
                        <button type="submit" class="btn">Создать организацию</button>
                    </form>
                </div>
                
                <div class="card">
                    <h2>Список организаций</h2>
                    <?php if (empty($organizations)): ?>
                        <p style="color: #999; text-align: center; padding: 40px;">Организаций пока нет</p>
                    <?php else: ?>
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Название</th>
                                    <th>Создана</th>
                                    <th>Пользователей</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($organizations as $org): ?>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE organization_id = ?");
                                    $stmt->execute([$org['id']]);
                                    $user_count = $stmt->fetch()['count'];
                                    ?>
                                    <tr>
                                        <td><?php echo $org['id']; ?></td>
                                        <td><?php echo htmlspecialchars($org['name']); ?></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($org['created_at'])); ?></td>
                                        <td><?php echo $user_count; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <script>
            function showTab(tabName) {
                // Скрываем все вкладки
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                // Убираем активный класс со всех кнопок
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Показываем нужную вкладку
                document.getElementById('tab-' + tabName).classList.add('active');
                
                // Делаем активной нужную кнопку
                event.target.classList.add('active');
            }

            // Валидация email на клиенте
            function validateEmailField(input) {
                const emailRegex = /^[a-zA-Z0-9._-]*@?[a-zA-Z0-9.-]*\.?[a-zA-Z]*$/;
                const hint = document.getElementById('emailHint');
                
                if (input.value === '') {
                    input.classList.remove('error');
                    hint.classList.remove('show');
                    return;
                }
                
                // Проверяем полный формат
                const fullEmailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
                
                if (!fullEmailRegex.test(input.value)) {
                    input.classList.add('error');
                    hint.classList.add('show');
                } else {
                    input.classList.remove('error');
                    hint.classList.remove('show');
                }
            }

            // Валидация телефона на клиенте
            function validatePhoneField(input) {
                const hint = document.getElementById('phoneHint');
                
                if (input.value === '') {
                    input.classList.remove('error');
                    hint.classList.remove('show');
                    return;
                }
                
                const phoneRegex = /^\+\d{10,15}$/;
                
                if (!phoneRegex.test(input.value)) {
                    input.classList.add('error');
                    hint.classList.add('show');
                } else {
                    input.classList.remove('error');
                    hint.classList.remove('show');
                }
            }

            // Валидация при отправке формы
            document.getElementById('userForm').addEventListener('submit', function(e) {
                const email = document.getElementById('email');
                const phone = document.getElementById('phone');
                
                if (email.value) {
                    validateEmailField(email);
                    if (email.classList.contains('error')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                if (phone.value) {
                    validatePhoneField(phone);
                    if (phone.classList.contains('error')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>
