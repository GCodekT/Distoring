<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
?>
<h1>Добро пожаловать в систему мониторинга</h1>
<p>Здесь скоро будут ваши датчики.</p>
<a href="logout.php">Выйти</a>