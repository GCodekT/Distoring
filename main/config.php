<?php

// Включаем вывод всех ошибок PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Настройки PDO для вывода ошибок
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
// Настройки базы данных (SprintHost)
define('DB_HOST', 'localhost');
define('DB_NAME', 'a1253108_dbase'); // Замените на имя вашей БД
define('DB_USER', 'a1253108_dbase'); // Замените на пользователя БД
define('DB_PASS', 'kqz3D3C59M!'); // Замените на пароль БД
define('DB_CHARSET', 'utf8mb4');

// Настройки сайта
define('SITE_URL', 'https://distoring.ru');
define('SITE_NAME', 'Distoring - IoT Monitoring');

// Настройки безопасности - ОБЯЗАТЕЛЬНО ИЗМЕНИТЕ!
define('ADMIN_PASSWORD', 'KfhjA39P7Ft1qfSFmruZRBOQIKOIsGP2kHba7QO8BZ5xnw6KMYglxxacPgLf2DIX');
define('SESSION_LIFETIME', 86400 * 30); // 30 дней

// Настройки сессии
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Установите 1 если используете HTTPS

session_start();

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
