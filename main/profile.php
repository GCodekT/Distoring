<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Загружаем актуальные данные пользователя
$stmt = $pdo->prepare("SELECT email, phone FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Обработка обновления данных
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_email = trim($_POST['email']);
    $new_phone = trim($_POST['phone']);
    $new_password = $_POST['password'];

    try {
        if (!empty($new_password)) {
            // Если введен новый пароль
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET email = ?, phone = ?, password_hash = ? WHERE id = ?");
            $update->execute([$new_email, $new_phone, $hash, $user_id]);
        } else {
            // Без смены пароля
            $update = $pdo->prepare("UPDATE users SET email = ?, phone = ? WHERE id = ?");
            $update->execute([$new_email, $new_phone, $user_id]);
        }
        $success = "Данные успешно обновлены!";
        // Обновляем данные в текущей переменной для формы
        $user['email'] = $new_email;
        $user['phone'] = $new_phone;
    } catch (PDOException $e) {
        $error = "Ошибка при обновлении: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Профиль пользователя</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; display: flex; justify-content: center; padding-top: 50px; }
        .profile-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 400px; }
        h2 { margin-top: 0; color: #333; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; font-size: 0.9em; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .btn-save { background: #27ae60; color: white; border: none; width: 100%; padding: 12px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        .back-link { display: block; text-align: center; margin-top: 15px; color: #7f8c8d; text-decoration: none; }
        .msg { padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 0.9em; }
        .msg-success { background: #d4edda; color: #155724; }
        .msg-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="profile-card">
        <h2>Настройки профиля</h2>
        
        <?php if($success): ?> <div class="msg msg-success"><?= $success ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="msg msg-error"><?= $error ?></div> <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Телефон</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Новый пароль (оставьте пустым, если не хотите менять)</label>
                <input type="password" name="password" placeholder="••••••••">
            </div>
            <button type="submit" class="btn-save">Сохранить изменения</button>
        </form>
        
        <a href="main.php" class="back-link">← Вернуться к карте</a>
    </div>
</body>
</html>