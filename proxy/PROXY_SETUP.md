# Настройка HTTP Proxy для SIM800L

## Проблема

SIM800L имеет проблемы с HTTPS/SSL сертификатами:
- Не поддерживает современные версии TLS
- Ограниченная память для SSL сертификатов
- Нестабильная работа с HTTPS

## Решение

Использование технического домена `http://a1253108.xsph.ru/` как HTTP-прокси:

```
Arduino (SIM800L)  →  HTTP  →  a1253108.xsph.ru  →  HTTPS  →  distoring.ru
```

## Архитектура

### Схема работы:

1. **Arduino** отправляет данные по HTTP на технический домен
2. **Прокси** (proxy.php) принимает данные
3. **Прокси** пытается отправить на основной сайт по HTTPS
4. Если успешно → возвращает OK датчику
5. Если ошибка → сохраняет в очередь и возвращает OK_QUEUED
6. **Cron** (каждые 5 минут) обрабатывает очередь

### Очередь данных:

- Хранит до 5 последних замеров на датчик
- Автоматически пытается отправить при следующем запросе
- Делает до 3 попыток с интервалом 5 минут
- После 3 неудачных попыток - удаляет из очереди

## 📁 Файлы для технического домена

Загрузите на `http://a1253108.xsph.ru/`:

1. **proxy.php** - основной прокси-скрипт
2. **process_queue.php** - обработчик очереди (для cron)
3. **proxy_queue.json** - файл очереди (создается автоматически)

## 🚀 Установка

### Шаг 1: Загрузка на технический домен

Через FileZilla подключитесь к SprintHost:
- Хост: `ftp.sprinthost.ru`
- Папка: `/home/username/domains/a1253108.xsph.ru/public_html/`

Загрузите:
- `proxy.php`
- `process_queue.php`

### Шаг 2: Установка прав доступа

В FileZilla:
- `proxy.php` → 755 (rwxr-xr-x)
- `process_queue.php` → 755 (rwxr-xr-x)
- Папка `public_html` → 777 (для создания proxy_queue.json)

### Шаг 3: Настройка Cron

В панели SprintHost:

1. Перейдите в **"Планировщик задач (Cron)"**
2. Нажмите **"Добавить задание"**
3. Настройте:

```
Команда: /usr/bin/php /home/ВАШЕ_ИМЯ/domains/a1253108.xsph.ru/public_html/process_queue.php
Расписание: */5 * * * * (каждые 5 минут)
```

Или в формате панели:
- Минуты: */5
- Часы: *
- Дни месяца: *
- Месяцы: *
- Дни недели: *

### Шаг 4: Проверка работы

Откройте в браузере:
```
http://a1253108.xsph.ru/proxy.php
```

Должно показать статистику:
```
=== Proxy Server Stats ===
Technical domain: http://a1253108.xsph.ru/
Main site: https://distoring.ru/log.php

Queue is empty
Server time: 2024-XX-XX XX:XX:XX
```

### Шаг 5: Тест отправки

```
http://a1253108.xsph.ru/proxy.php?id=TEST&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK
```

Должно вернуть: `OK` или `OK_QUEUED`

## 🔧 Обновление Arduino кода

### Вариант 1: Прямая отправка на прокси

В файле `InfiniteSessionTMASv2.ino` измените строку 339:

```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id="));
GSMport.print(DEVICE_ID);
GSMport.print(F("&a="));
```

### Вариант 2: С определением DEVICE_ID

```cpp
#define DEVICE_ID "SENSOR_001"

// Строка 336-342:
bool sendSingleRequest(String value) {
  while (GSMport.available()) GSMport.read();
  blinkTRAN();
  GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id="));
  GSMport.print(DEVICE_ID);
  GSMport.print(F("&a="));
  GSMport.print(value);
  GSMport.println(F("\""));
  Serial.print(F("→ Proxy URL: ")); Serial.println(value);
```

## 📊 Мониторинг очереди

### Просмотр статистики:

```
http://a1253108.xsph.ru/proxy.php
```

Покажет:
- Количество датчиков в очереди
- Количество необработанных записей
- Возраст записей
- Количество попыток отправки

### Просмотр лога очереди:

```
http://a1253108.xsph.ru/proxy_queue.json
```

## 🔒 Безопасность

### Защита proxy.php

Создайте `.htaccess` в папке с proxy.php:

```apache
# Разрешить доступ только с определенных IP (опционально)
# Order Deny,Allow
# Deny from all
# Allow from XXX.XXX.XXX.XXX

# Защита от прямого доступа к queue
<Files "proxy_queue.json">
    Order Allow,Deny
    Deny from all
</Files>

# Защита от hotlinking
RewriteEngine On
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https://(www\.)?distoring\.ru [NC]
RewriteCond %{HTTP_REFERER} !^http://(www\.)?a1253108\.xsph\.ru [NC]
RewriteRule ^proxy\.php$ - [F,L]
```

### Ограничение по User-Agent (в proxy.php)

Добавьте в начало proxy.php:

```php
// Разрешаем только запросы от SIM800L
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (empty($userAgent) || strpos($userAgent, 'SIM800L') === false) {
    // В реальности SIM800L может не передавать User-Agent
    // Закомментируйте эту проверку, если возникнут проблемы
}
```

## 📈 Преимущества решения

✅ **Надежность**: SIM800L работает с HTTP без проблем
✅ **Очередь**: Данные не теряются при временных сбоях
✅ **SSL**: Основной сайт защищен HTTPS
✅ **Простота**: Arduino код не меняется кардинально
✅ **Мониторинг**: Видно статус очереди
✅ **Автоматизация**: Cron обрабатывает очередь

## ⚙️ Настройки

В `proxy.php` можно изменить:

```php
define('MAX_QUEUE_SIZE', 5);      // Размер очереди на датчик
define('RETRY_TIMEOUT', 300);     // Таймаут между попытками (сек)
```

В `process_queue.php` аналогично.

## 🆘 Решение проблем

### Прокси возвращает ERROR

Проверьте:
- Передан ли параметр `id=`
- Передан ли параметр `a=`
- Формат данных правильный

### Данные не доходят до distoring.ru

Проверьте:
- Работает ли основной сайт: `https://distoring.ru/log.php`
- Настроен ли cron
- Посмотрите очередь: `proxy_queue.json`

### Очередь растет

Возможно основной сайт недоступен:
- Проверьте `https://distoring.ru`
- Проверьте SSL сертификат
- Посмотрите логи PHP на основном сайте

### Cron не работает

В панели SprintHost:
- Проверьте путь к PHP: `/usr/bin/php`
- Проверьте полный путь к скрипту
- Посмотрите логи Cron (если доступны)

## 📞 Тестирование

### 1. Тест прокси:
```bash
curl "http://a1253108.xsph.ru/proxy.php?id=TEST&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK"
```

### 2. Проверка на основном сайте:
```bash
curl "https://distoring.ru/api.php?action=sensors"
```

### 3. Проверка очереди:
```bash
curl "http://a1253108.xsph.ru/proxy.php"
```

## ✅ Готово!

Теперь SIM800L отправляет данные по HTTP, а они автоматически пересылаются на основной сайт по HTTPS! 🎉

---

**Важно**: После установки протестируйте весь цикл:
1. Отправьте тестовые данные на прокси
2. Убедитесь что они появились на distoring.ru
3. Проверьте работу очереди (выключите на время основной сайт)
4. Проверьте работу cron через несколько минут
