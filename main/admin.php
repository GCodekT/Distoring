<?php
/**
 * Административная панель для управления датчиками
 * URL: https://distoring.ru/admin.php
 * Пароль по умолчанию: admin123 (ИЗМЕНИТЕ!)
 */

require_once 'config.php';

session_start();

// НАСТРОЙКИ
define('ADMIN_PASSWORD', 'KfhjA39P7Ft1qfSFmruZRBOQIKOIsGP2kHba7QO8BZ5xnw6KMYglxxacPgLf2DIX'); // ИЗМЕНИТЕ НА СВОЙ ПАРОЛЬ!

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $error = 'Неверный пароль';
        }
    }
    
    // Форма входа
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход - Админ панель</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, sans-serif; background: #0a0e27; color: #e8eaed; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .login-box { background: #151933; padding: 40px; border-radius: 12px; border: 1px solid #2d3451; max-width: 400px; width: 90%; }
            h1 { margin-bottom: 24px; color: #00d4ff; }
            input { width: 100%; padding: 12px; background: #1e2442; border: 1px solid #2d3451; border-radius: 8px; color: #e8eaed; font-size: 14px; margin-bottom: 16px; }
            button { width: 100%; padding: 12px; background: #00d4ff; border: none; border-radius: 8px; color: #000; font-weight: 600; cursor: pointer; font-size: 14px; }
            button:hover { background: #00b8e6; }
            .error { background: rgba(248, 81, 73, 0.1); border: 1px solid #f44336; color: #f44336; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔐 Админ панель</h1>
            <?php if (isset($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Введите пароль" required autofocus>
                <button type="submit">Войти</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Обработка действий
$message = '';

// Установить/обновить координаты
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'set_coords') {
        $sensorId = $_POST['sensor_id'];
        $lat = $_POST['latitude'];
        $lng = $_POST['longitude'];
        
        $stmt = $db->prepare("UPDATE sensors SET latitude = ?, longitude = ?, is_precise_location = 1 WHERE id = ?");
        $stmt->execute([$lat, $lng, $sensorId]);
        
        $message = "✓ Координаты обновлены для датчика #$sensorId";
    }
    
    // Установить координаты по умолчанию для всех
    if ($_POST['action'] === 'set_default_coords') {
        $lat = $_POST['default_lat'] ?? 55.0144;
        $lng = $_POST['default_lng'] ?? 82.9429;
        
        $stmt = $db->prepare("UPDATE sensors SET latitude = ?, longitude = ?, is_precise_location = 0 WHERE latitude IS NULL OR longitude IS NULL");
        $stmt->execute([$lat, $lng]);
        
        $affected = $stmt->rowCount();
        $message = "✓ Установлены координаты по умолчанию для $affected датчиков";
    }
    
    // Удалить датчик
    if ($_POST['action'] === 'delete') {
        $sensorId = $_POST['sensor_id'];
        $stmt = $db->prepare("DELETE FROM sensors WHERE id = ?");
        $stmt->execute([$sensorId]);
        
        $message = "✓ Датчик #$sensorId удален";
    }
}

// Получаем статистику
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM sensors WHERE is_active = 1) as total_sensors,
        (SELECT COUNT(*) FROM sensors WHERE latitude IS NULL OR longitude IS NULL) as no_coords,
        (SELECT COUNT(*) FROM sensor_data WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as data_24h,
        (SELECT COUNT(*) FROM users) as total_users
")->fetch();

// Получаем датчики
$sensors = $db->query("
    SELECT 
        s.*,
        (SELECT COUNT(*) FROM sensor_data WHERE sensor_id = s.id) as data_count,
        (SELECT timestamp FROM sensor_data WHERE sensor_id = s.id ORDER BY timestamp DESC LIMIT 1) as last_data_time
    FROM sensors s
    ORDER BY s.last_seen DESC
    LIMIT 100
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ панель - Distoring.ru</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #0a0e27; color: #e8eaed; padding: 24px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { margin-bottom: 24px; color: #00d4ff; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #2d3451; }
        .btn { padding: 8px 16px; background: #00d4ff; border: none; border-radius: 8px; color: #000; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #00b8e6; }
        .btn-danger { background: #f44336; color: #fff; }
        .btn-danger:hover { background: #d32f2f; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: #151933; padding: 20px; border-radius: 12px; border: 1px solid #2d3451; }
        .stat-value { font-size: 32px; font-weight: 700; color: #00d4ff; margin-bottom: 8px; }
        .stat-label { color: #9aa0a6; font-size: 14px; }
        .message { background: rgba(76, 175, 80, 0.1); border: 1px solid #4caf50; color: #4caf50; padding: 12px; border-radius: 8px; margin-bottom: 24px; }
        .section { background: #151933; padding: 24px; border-radius: 12px; border: 1px solid #2d3451; margin-bottom: 24px; }
        .section h2 { color: #00d4ff; margin-bottom: 16px; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #2d3451; }
        th { background: #1e2442; color: #9aa0a6; font-weight: 600; font-size: 12px; text-transform: uppercase; }
        td { font-size: 14px; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: rgba(76, 175, 80, 0.2); color: #4caf50; }
        .badge-warning { background: rgba(255, 152, 0, 0.2); color: #ff9800; }
        .badge-danger { background: rgba(244, 67, 54, 0.2); color: #f44336; }
        input[type="text"], input[type="number"] { padding: 8px 12px; background: #1e2442; border: 1px solid #2d3451; border-radius: 6px; color: #e8eaed; font-size: 14px; margin-right: 8px; }
        .form-inline { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; }
        .actions { display: flex; gap: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛠 Админ панель</h1>
            <a href="?logout=1" class="btn btn-danger">Выход</a>
        </div>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_sensors'] ?></div>
                <div class="stat-label">Всего датчиков</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['no_coords'] ?></div>
                <div class="stat-label">Без координат</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['data_24h'] ?></div>
                <div class="stat-label">Данных за 24ч</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_users'] ?></div>
                <div class="stat-label">Пользователей</div>
            </div>
        </div>
        
        <?php if ($stats['no_coords'] > 0): ?>
        <div class="section">
            <h2>Установить координаты по умолчанию</h2>
            <form method="POST" class="form-inline">
                <input type="hidden" name="action" value="set_default_coords">
                <input type="number" step="0.000001" name="default_lat" value="55.014457" placeholder="Широта">
                <input type="number" step="0.000001" name="default_lng" value="82.942926" placeholder="Долгота">
                <button type="submit" class="btn">Установить всем датчикам без координат</button>
            </form>
            <small style="color: #9aa0a6;">По умолчанию: Новосибирск (55.0144, 82.9429)</small>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>Датчики</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Device ID</th>
                        <th>Название</th>
                        <th>Координаты</th>
                        <th>Последняя активность</th>
                        <th>Данных</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sensors as $sensor): ?>
                    <tr>
                        <td><?= $sensor['id'] ?></td>
                        <td><strong><?= htmlspecialchars($sensor['device_id']) ?></strong></td>
                        <td><?= htmlspecialchars($sensor['name']) ?></td>
                        <td>
                            <?php if ($sensor['latitude'] && $sensor['longitude']): ?>
                                <span class="badge badge-success">
                                    <?= number_format($sensor['latitude'], 6) ?>, <?= number_format($sensor['longitude'], 6) ?>
                                </span>
                                <form method="POST" class="form-inline" style="margin: 8px 0; display: block;">
                                    <input type="hidden" name="action" value="set_coords">
                                    <input type="hidden" name="sensor_id" value="<?= $sensor['id'] ?>">
                                    <input type="number" step="0.000001" name="latitude" value="<?= $sensor['latitude'] ?>" placeholder="Широта" style="width: 100px; margin: 0 4px;">
                                    <input type="number" step="0.000001" name="longitude" value="<?= $sensor['longitude'] ?>" placeholder="Долгота" style="width: 100px; margin: 0 4px;">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 12px;">Обновить</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" class="form-inline" style="margin: 0;">
                                    <input type="hidden" name="action" value="set_coords">
                                    <input type="hidden" name="sensor_id" value="<?= $sensor['id'] ?>">
                                    <input type="number" step="0.000001" name="latitude" placeholder="Широта" style="width: 100px; margin: 0 4px;">
                                    <input type="number" step="0.000001" name="longitude" placeholder="Долгота" style="width: 100px; margin: 0 4px;">
                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 12px;">Установить</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td><?= $sensor['last_seen'] ? date('d.m.Y H:i', strtotime($sensor['last_seen'])) : '—' ?></td>
                        <td><?= $sensor['data_count'] ?></td>
                        <td>
                            <?php if ($sensor['is_active']): ?>
                                <span class="badge badge-success">Активен</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Неактивен</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="sensor.html?id=<?= $sensor['id'] ?>" class="btn" style="padding: 4px 8px; font-size: 12px;" target="_blank">Открыть</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить датчик?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="sensor_id" value="<?= $sensor['id'] ?>">
                                    <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 12px;">Удалить</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
