<?php
/**
 * Cron-скрипт для обработки очереди прокси
 * Запускать каждые 5-10 минут через cron
 * 
 * Настройка в SprintHost:
 * Панель управления → Cron → Добавить задание
 * */5 * * * * /usr/bin/php /home/username/domains/a1253108.xsph.ru/public_html/process_queue.php
 */

define('MAIN_SITE_URL', 'https://distoring.ru/log.php');
define('LOG_FILE', 'proxy_queue.json');
define('RETRY_TIMEOUT', 300); // 5 минут

echo "=== Queue Processor Started ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$queue = loadQueue();

if (empty($queue)) {
    echo "Queue is empty. Nothing to process.\n";
    exit;
}

$totalProcessed = 0;
$totalDropped = 0;

foreach ($queue as $deviceId => $items) {
    echo "Processing device: $deviceId (" . count($items) . " items)\n";
    
    $newQueue = [];
    
    foreach ($items as $item) {
        // Проверяем таймаут
        if ($item['attempts'] > 0 && (time() - $item['timestamp']) < RETRY_TIMEOUT) {
            $newQueue[] = $item;
            continue;
        }
        
        // Формируем URL
        $targetUrl = MAIN_SITE_URL . '?' . http_build_query([
            'id' => $deviceId,
            'a' => $item['data']
        ]);
        
        // Отправляем
        $result = sendToMainSite($targetUrl);
        
        if ($result['success']) {
            echo "  ✓ Sent: {$item['data']}\n";
            $totalProcessed++;
        } else {
            echo "  ✗ Failed: {$result['error']}\n";
            
            $item['attempts']++;
            $item['last_attempt'] = time();
            
            if ($item['attempts'] < 3) {
                $newQueue[] = $item;
            } else {
                echo "  ! Dropped after 3 attempts\n";
                $totalDropped++;
            }
        }
    }
    
    // Обновляем очередь
    if (empty($newQueue)) {
        unset($queue[$deviceId]);
    } else {
        $queue[$deviceId] = $newQueue;
    }
    
    echo "\n";
}

saveQueue($queue);

echo "=== Summary ===\n";
echo "Processed: $totalProcessed\n";
echo "Dropped: $totalDropped\n";
echo "Remaining in queue: " . array_sum(array_map('count', $queue)) . "\n";

// ============ ФУНКЦИИ ============

function sendToMainSite($url) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'SIM800L-Proxy-Cron/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }
    
    return ['success' => true, 'response' => $response];
}

function loadQueue() {
    if (!file_exists(LOG_FILE)) {
        return [];
    }
    
    $content = file_get_contents(LOG_FILE);
    if (empty($content)) {
        return [];
    }
    
    return json_decode($content, true) ?: [];
}

function saveQueue($queue) {
    file_put_contents(LOG_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
