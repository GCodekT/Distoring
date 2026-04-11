# 🚀 Быстрый старт с HTTP-прокси

## Архитектура системы

```
┌─────────────┐      HTTP      ┌──────────────────┐     HTTPS     ┌─────────────┐
│  Arduino    │ ─────────────> │  Технический     │ ────────────> │  Основной   │
│  + SIM800L  │                │  домен (прокси)  │               │  сайт       │
│  SENSOR_001 │                │  a1253108.xsph.ru│               │ distoring.ru│
└─────────────┘                └──────────────────┘               └─────────────┘
                                        │                                 │
                                        │                                 │
                                        ▼                                 ▼
                                ┌──────────────┐              ┌──────────────────┐
                                │ Очередь      │              │ MySQL база       │
                                │ (5 записей)  │              │ + Веб-интерфейс  │
                                │ proxy_queue  │              │ + REST API       │
                                └──────────────┘              └──────────────────┘
                                        │
                                        │ каждые 5 мин
                                        ▼
                                ┌──────────────┐
                                │ Cron         │
                                │ process_queue│
                                └──────────────┘
```

## 📦 Что нужно установить

### На основной сайт (distoring.ru):
1. database.sql
2. config.php (настроить!)
3. log.php
4. api.php
5. index.html
6. style.css
7. app.js
8. sensor.html
9. about.html
10. support.html
11. .htaccess (переименовать htaccess.txt)

### На технический домен (a1253108.xsph.ru):
1. proxy.php
2. process_queue.php
3. Настроить cron (каждые 5 минут)

### В Arduino:
1. Изменить URL на: `http://a1253108.xsph.ru/proxy.php?id=SENSOR_001&a=`
2. Добавить уникальный DEVICE_ID

## 🎯 Пошаговая инструкция

### 1. Основной сайт (30 минут)

**A. База данных:**
```
SprintHost → Базы данных → Создать БД
phpMyAdmin → Выбрать БД → SQL → Вставить database.sql
```

**B. Настройка config.php:**
```php
define('DB_NAME', 'ваша_база');
define('DB_USER', 'ваш_пользователь');
define('DB_PASS', 'ваш_пароль');
define('JWT_SECRET', 'случайная_строка_64_символа');
```

**C. Загрузка через FileZilla:**
```
Хост: ftp.sprinthost.ru
Папка: /home/username/domains/distoring.ru/public_html/
Загрузить: все файлы кроме proxy.php и process_queue.php
```

**D. Проверка:**
```
https://distoring.ru - должна открыться карта
https://distoring.ru/log.php - статистика
```

### 2. Технический домен (10 минут)

**A. Загрузка через FileZilla:**
```
Папка: /home/username/domains/a1253108.xsph.ru/public_html/
Загрузить: proxy.php, process_queue.php
Права: 755 (rwxr-xr-x)
```

**B. Настройка Cron:**
```
Панель → Планировщик задач → Добавить
Команда: /usr/bin/php /home/USERNAME/domains/a1253108.xsph.ru/public_html/process_queue.php
Расписание: */5 * * * * (каждые 5 минут)
```

**C. Проверка:**
```
http://a1253108.xsph.ru/proxy.php - статистика прокси
```

### 3. Arduino (5 минут)

**A. Обновить код:**
```cpp
#define DEVICE_ID "SENSOR_001"

// Строка 339:
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id="));
GSMport.print(DEVICE_ID);
GSMport.print(F("&a="));
```

**B. Прошить и проверить Serial Monitor:**
```
→ Proxy URL: V:12.50,C:85,R:0.50,P:-1.20,S:OK
GSM< +HTTPACTION: 0,200
```

### 4. Финальный тест (2 минуты)

**A. Тест прокси:**
```
http://a1253108.xsph.ru/proxy.php?id=TEST&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK
→ Должно вернуть: OK
```

**B. Проверка на карте:**
```
https://distoring.ru
→ Найдите датчик TEST на карте
```

**C. Регистрация:**
```
https://distoring.ru → Вход/Регистрация
Добавить датчик TEST в свой аккаунт
```

## ✅ Чек-лист

### Основной сайт:
- [ ] База данных создана
- [ ] Схема БД импортирована
- [ ] config.php настроен (DB_NAME, DB_USER, DB_PASS, JWT_SECRET)
- [ ] Все файлы загружены
- [ ] .htaccess переименован и загружен
- [ ] Сайт открывается: https://distoring.ru
- [ ] API работает: https://distoring.ru/log.php

### Технический домен:
- [ ] proxy.php загружен
- [ ] process_queue.php загружен
- [ ] Права 755 установлены
- [ ] Cron настроен (каждые 5 минут)
- [ ] Прокси работает: http://a1253108.xsph.ru/proxy.php

### Arduino:
- [ ] DEVICE_ID добавлен
- [ ] URL изменен на прокси
- [ ] Код прошит
- [ ] Тестовая отправка успешна
- [ ] Датчик виден на карте

## 🎉 Готово!

Система работает:
- Arduino отправляет по HTTP → стабильно
- Прокси пересылает по HTTPS → безопасно
- Очередь сохраняет данные → надежно
- Карта показывает датчики → красиво

## 📞 Поддержка

**Проблемы?** Проверьте:
1. Логи PHP: панель SprintHost → Логи
2. Статус прокси: http://a1253108.xsph.ru/proxy.php
3. Очередь: http://a1253108.xsph.ru/proxy_queue.json

**Документация:**
- PROXY_SETUP.md - детальная настройка прокси
- README.md - установка основного сайта
- ARDUINO_SETUP.md - настройка датчика

---

**Время установки:** ~45 минут  
**Сложность:** Средняя  
**Результат:** Профессиональная система мониторинга! 🚀
