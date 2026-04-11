<?php
/**
 * Database Configuration for distoring.ru
 */

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
define('JWT_SECRET', 'KfhjA39P7Ft1qfSFmruZRBOQIKOIsGP2kHba7QO8BZ5xnw6KMYglxxacPgLf2DIX');
define('SESSION_LIFETIME', 86400 * 30); // 30 дней

// Timezone
date_default_timezone_set('Europe/Moscow');

// Класс для работы с базой данных
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
