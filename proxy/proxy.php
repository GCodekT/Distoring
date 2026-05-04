<?php
/**
 * Proxy для SIM800L датчиков
 * Технический домен: http://a1253108.xsph.ru/
 * Основной сайт: https://distoring.ru/
 * 
 * Принимает данные по HTTP и перенаправляет на основной сайт по HTTPS
 */

// Настройки
define('MAIN_SITE_URL', 'https://distoring.ru/log.php');
define('LOG_FILE', 'proxy_queue.json');
define('MAX_QUEUE_SIZE', 6); // Количество записей в очереди на датчик
define('RETRY_TIMEOUT', 300); // 5 минут между попытками повтора

header('Content-Type: text/plain; charset=utf-8');

// Функция логирования
function logDebug($message) {
    error_log('[PROXY] ' . $message);
}

// Получаем параметры от Arduino
$data = isset($_GET['a']) ? trim($_GET['a']) : '';
$deviceId = isset($_GET['id']) ? trim($_GET['id']) : '';

// Статистика прокси
if (empty($data) && empty($deviceId)) {
    showProxyStats();
    exit;
}

// Валидация
if (empty($deviceId)) {
    logDebug("No device ID provided");
    echo "ERROR: Device ID required";
    exit;
}

if (empty($data)) {
    logDebug("No data provided from device: $deviceId");
    echo "ERROR: No data";
    exit;
}

// Формируем URL для основного сайта
$targetUrl = MAIN_SITE_URL . '?' . http_build_query([
    'id' => $deviceId,
    'a' => $data
]);

logDebug("Received from $deviceId: $data");

// Пытаемся отправить на основной сайт
$result = sendToMainSite($targetUrl);

if ($result['success']) {
    // Успешно отправлено
    logDebug("Successfully forwarded to main site: $deviceId");
    
    // Пытаемся отправить данные из очереди (если есть)
    processQueue($deviceId);
    
    echo "OK";
} else {
    // Не удалось отправить - добавляем в очередь
    logDebug("Failed to forward, adding to queue: $deviceId - " . $result['error']);
    
    addToQueue($deviceId, $data);
    
    // Всё равно возвращаем OK Arduino, чтобы не пытался повторно
    echo "OK_QUEUED";
}

// ===================== ФУНКЦИИ =====================

/**
 * Отправка данных на основной сайт
 */
function sendToMainSite($url) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'SIM800L-Proxy/1.0'
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
    
    if (strpos($response, 'OK') === false && strpos($response, 'ERROR') === false) {
        return ['success' => false, 'error' => 'Invalid response'];
    }
    
    return ['success' => true, 'response' => $response];
}

/**
 * Добавление данных в очередь
 */
function addToQueue($deviceId, $data) {
    $queue = loadQueue();
    
    // Инициализируем очередь для датчика если её нет
    if (!isset($queue[$deviceId])) {
        $queue[$deviceId] = [];
    }
    
    // Добавляем новую запись
    $queue[$deviceId][] = [
        'data' => $data,
        'timestamp' => time(),
        'attempts' => 0
    ];
    
    // Ограничиваем размер очереди (оставляем только последние N записей)
    if (count($queue[$deviceId]) > MAX_QUEUE_SIZE) {
        $queue[$deviceId] = array_slice($queue[$deviceId], -MAX_QUEUE_SIZE);
    }
    
    saveQueue($queue);
    
    logDebug("Added to queue for $deviceId. Queue size: " . count($queue[$deviceId]));
}

/**
 * Обработка очереди для конкретного датчика
 */
function processQueue($deviceId) {
    $queue = loadQueue();
    
    if (!isset($queue[$deviceId]) || empty($queue[$deviceId])) {
        return;
    }
    
    $processed = 0;
    $newQueue = [];
    
    foreach ($queue[$deviceId] as $item) {
        // Проверяем не слишком ли рано повторять
        if ($item['attempts'] > 0 && (time() - $item['timestamp']) < RETRY_TIMEOUT) {
            $newQueue[] = $item;
            continue;
        }
        
        // Формируем URL
        $targetUrl = MAIN_SITE_URL . '?' . http_build_query([
            'id' => $deviceId,
            'a' => $item['data']
        ]);
        
        // Пытаемся отправить
        $result = sendToMainSite($targetUrl);
        
        if ($result['success']) {
            $processed++;
            logDebug("Processed queued item for $deviceId");
        } else {
            // Увеличиваем счётчик попыток
            $item['attempts']++;
            $item['last_attempt'] = time();
            
            // Если попыток меньше 3 - оставляем в очереди
            if ($item['attempts'] < 3) {
                $newQueue[] = $item;
            } else {
                logDebug("Dropped queued item for $deviceId after 3 attempts");
            }
        }
    }
    
    // Обновляем очередь
    if (empty($newQueue)) {
        unset($queue[$deviceId]);
    } else {
        $queue[$deviceId] = $newQueue;
    }
    
    saveQueue($queue);
    
    if ($processed > 0) {
        logDebug("Processed $processed queued items for $deviceId");
    }
}

/**
 * Загрузка очереди из файла
 */
function loadQueue() {
    if (!file_exists(LOG_FILE)) {
        return [];
    }
    
    $content = file_get_contents(LOG_FILE);
    if (empty($content)) {
        return [];
    }
    
    $queue = json_decode($content, true);
    return $queue ?: [];
}

/**
 * Сохранение очереди в файл
 */
function saveQueue($queue) {
    file_put_contents(LOG_FILE, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Статистика прокси
 */
function showProxyStats() {
    $queue = loadQueue();
    
    echo "=== Proxy Server Stats ===\n";
    echo "Technical domain: http://a1253108.xsph.ru/\n";
    echo "Main site: " . MAIN_SITE_URL . "\n\n";
    
    if (empty($queue)) {
        echo "Queue is empty\n";
    } else {
        echo "Devices in queue: " . count($queue) . "\n\n";
        
        foreach ($queue as $deviceId => $items) {
            echo "Device: $deviceId\n";
            echo "  Queued items: " . count($items) . "\n";
            
            foreach ($items as $i => $item) {
                $age = time() - $item['timestamp'];
                echo "  [{$i}] Age: {$age}s, Attempts: {$item['attempts']}\n";
            }
            echo "\n";
        }
    }
    
    echo "Server time: " . date('Y-m-d H:i:s') . "\n";
    echo "\nUsage:\n";
    echo "http://a1253108.xsph.ru/proxy.php?id=DEVICE_ID&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK\n";
}
