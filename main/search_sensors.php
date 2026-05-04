<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'engineer') {
    echo json_encode([]);
    exit;
}

$query = $_GET['q'] ?? '';

// Ищем только те датчики, у которых organization_id равен NULL
$stmt = $pdo->prepare("SELECT id FROM sensors WHERE organization_id IS NULL AND id LIKE ? LIMIT 10");
$stmt->execute(["%$query%"]);
$results = $stmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode($results);