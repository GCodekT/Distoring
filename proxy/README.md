# Distoring.ru - IoT Sensor Monitoring

Система мониторинга IoT датчиков с картой мира для distoring.ru

## 📦 Структура файлов

```
distoring.ru/
├── index.html          # Главная страница с картой
├── sensor.html         # Детальный просмотр датчика
├── about.html          # О проекте
├── support.html        # Поддержка
├── style.css           # Стили
├── app.js              # JavaScript логика
├── api.php             # REST API
├── log.php             # Endpoint для Arduino
├── config.php          # Конфигурация БД
└── database.sql        # Схема БД
```

## 🚀 Установка на SprintHost через FileZilla

### Шаг 1: Создание базы данных

1. Войдите в панель управления SprintHost
2. Перейдите в **"Базы данных MySQL"**
3. Нажмите **"Создать базу данных"**
4. Запомните:
   - Имя БД: `username_dbname`
   - Пользователь: `username_dbuser`
   - Пароль: (ваш пароль)
   - Хост: `localhost`

5. Откройте **phpMyAdmin**
6. Выберите созданную базу
7. Перейдите на вкладку **"SQL"**
8. Откройте файл `database.sql` в текстовом редакторе
9. Скопируйте весь код (кроме первых 5 строк с CREATE DATABASE)
10. Вставьте в phpMyAdmin и нажмите **"Вперед"**

### Шаг 2: Настройка config.php

Откройте файл `config.php` в текстовом редакторе и замените:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'username_dbname');      // Ваше имя БД
define('DB_USER', 'username_dbuser');      // Ваш пользователь
define('DB_PASS', 'your_password_here');   // Ваш пароль
```

**Важно!** Также сгенерируйте уникальный `JWT_SECRET`:
- Откройте https://www.random.org/strings/
- Или используйте любой генератор паролей на 64 символа
- Замените `CHANGE_THIS_TO_RANDOM_64_CHAR_HEX_STRING`
KfhjA39P7Ft1qfSFmruZRBOQIKOIsGP2kHba7QO8BZ5xnw6KMYglxxacPgLf2DIX

### Шаг 3: Загрузка файлов через FileZilla

1. **Откройте FileZilla**
2. **Подключитесь к серверу:**
   - Хост: `ftp.sprinthost.ru` (или ваш FTP)
   - Пользователь: ваш логин
   - Пароль: ваш пароль
   - Порт: 21

3. **Перейдите в корневую папку сайта:**
   - Обычно это: `/home/username/domains/distoring.ru/public_html/`

4. **Загрузите все файлы:**
   - Выделите все файлы в левой панели (локальные файлы)
   - Перетащите в правую панель (сервер)
   - Дождитесь завершения загрузки

### Шаг 4: Установка прав доступа

В FileZilla, правой кнопкой на файле → **"Права доступа к файлу"**:

- `config.php` → 644 (rw-r--r--)
- `api.php` → 755 (rwxr-xr-x)
- `log.php` → 755 (rwxr-xr-x)
- Остальные файлы → 644

### Шаг 5: Проверка работы

1. Откройте в браузере: `https://distoring.ru`
2. Должна появиться карта мира
3. Проверьте API: `https://distoring.ru/log.php`
   - Должна появиться статистика

## 🔧 Настройка Arduino

### ⚠️ Важно: SIM800L и HTTPS

SIM800L имеет проблемы с HTTPS/SSL. Используйте HTTP-прокси!

### Рекомендуемый способ (через HTTP-прокси):

В файле `InfiniteSessionTMASv2.ino` измените строку 339:

**Было:**
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://monitoring54.isp.regruhosting.ru/log.php?a="));
```

**Стало:**
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id=SENSOR_001&a="));
```

Прокси автоматически перенаправит данные на основной сайт по HTTPS.

См. **PROXY_SETUP.md** для полной настройки прокси.

### Альтернатива (прямая отправка на HTTPS):

Если ваш SIM800L поддерживает HTTPS:
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"https://distoring.ru/log.php?id=SENSOR_001&a="));
```

**Важно:**
- Добавлен параметр `id=SENSOR_001` - замените на уникальный ID вашего датчика

### Добавление уникального ID

Вариант 1 - Хардкод в начале файла:
```cpp
#define DEVICE_ID "SENSOR_001"  // Уникальный ID датчика

// В строке 339:
GSMport.print(F("AT+HTTPPARA=\"URL\",\"https://distoring.ru/log.php?id="));
GSMport.print(DEVICE_ID);
GSMport.print(F("&a="));
```

Вариант 2 - По номеру SIM-карты (автоматически):
```cpp
// Получение номера телефона
String getPhoneNumber() {
  GSMport.println("AT+CNUM");
  delay(1000);
  String response = "";
  while (GSMport.available()) {
    response += (char)GSMport.read();
  }
  // Парсинг номера из ответа
  return response; // Упростить и вернуть только номер
}

String deviceId = getPhoneNumber();
```

## ✅ Проверочный список

- [ ] База данных создана
- [ ] Схема БД импортирована через phpMyAdmin
- [ ] config.php настроен (DB_NAME, DB_USER, DB_PASS, JWT_SECRET)
- [ ] Все файлы загружены через FileZilla
- [ ] Права доступа установлены (755 для PHP)
- [ ] Сайт открывается: https://distoring.ru
- [ ] API работает: https://distoring.ru/log.php
- [ ] Arduino код обновлен с новым URL
- [ ] Добавлен уникальный ID датчика
- [ ] Тестовая отправка данных прошла успешно

## 📡 Тестирование отправки данных

Откройте в браузере:
```
https://distoring.ru/log.php?id=TEST_SENSOR&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK
```

Должно вернуться: `OK`

Затем откройте `https://distoring.ru` и найдите датчик TEST_SENSOR на карте.

## 🔐 Безопасность

1. **HTTPS обязателен** - SprintHost предоставляет бесплатный Let's Encrypt сертификат
   - Панель управления → SSL сертификаты → Установить бесплатный

2. **Защита config.php**
   - Создайте файл `.htaccess` в корне сайта:
   ```apache
   <Files "config.php">
       Order Allow,Deny
       Deny from all
   </Files>
   ```

3. **Измените JWT_SECRET** - используйте уникальный ключ!

## 🆘 Решение проблем

### Ошибка подключения к БД
- Проверьте данные в `config.php`
- Убедитесь что БД создана в phpMyAdmin
- Проверьте что схема импортирована

### Белый экран
- Откройте логи ошибок в панели SprintHost
- Или добавьте в начало `index.html`:
  ```php
  <?php
  error_reporting(E_ALL);
  ini_set('display_errors', 1);
  ?>
  ```

### Датчик не появляется на карте
- Проверьте что данные отправляются: откройте `log.php` в браузере
- Проверьте таблицу `sensors` в phpMyAdmin
- Убедитесь что добавлен параметр `id=` в URL

### FileZilla не подключается
- Хост: `ftp.sprinthost.ru` или IP из панели управления
- Порт: 21 (обычный FTP) или 22 (SFTP)
- Проверьте логин и пароль
- Попробуйте "Пассивный режим" в настройках

## 📞 Поддержка

- Email: support@distoring.ru (настройте почту)
- Telegram: @distoring_support (создайте канал)

## 🎯 Что дальше?

1. Зарегистрируйте аккаунт на сайте
2. Прошейте Arduino с уникальным ID
3. Датчик появится на карте автоматически
4. Добавьте его в свой аккаунт
5. Установите точные координаты

Готово! Ваша система мониторинга работает! 🎉
