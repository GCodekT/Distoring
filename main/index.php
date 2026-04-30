<?php
require_once 'config.php';

// Если пользователь уже авторизован, перенаправляем на главную
if (isset($_SESSION['user_id'])) {
    header('Location: main.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($login) && !empty($password)) {
        // Определяем, что ввел пользователь: email или телефон
        $is_email = filter_var($login, FILTER_VALIDATE_EMAIL);
        
        if ($is_email) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? AND is_active = 1");
        }
        
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Успешная авторизация
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_phone'] = $user['phone'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['organization_id'] = $user['organization_id'];
            
            // Обновляем время последнего входа
            $update = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->execute([$user['id']]);
            
            header('Location: main.php');
            exit;
        } else {
            $error = 'Неверный логин или пароль';
        }
    } else {
        $error = 'Заполните все поля';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация - Мониторинг датчиков</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
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
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
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
        
        .hint {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🔧 Мониторинг датчиков</h1>
            <p>Вход в систему</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="login">Email или телефон</label>
                <input 
                    type="text" 
                    id="login" 
                    name="login" 
                    placeholder="example@mail.ru или +79001234567"
                    required
                    autocomplete="username"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Пароль</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="Введите пароль"
                    required
                    autocomplete="current-password"
                >
            </div>
            
            <button type="submit" class="btn">Войти</button>
        </form>
        
        <div class="hint">
            Данные для входа предоставляет администратор
        </div>
    </div>
</body>
</html>
