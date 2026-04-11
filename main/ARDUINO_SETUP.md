# Обновление кода Arduino для Distoring.ru

## ⚠️ Важно: SIM800L и HTTPS

**SIM800L не работает стабильно с HTTPS!** Используйте HTTP-прокси для надежной работы.

## Рекомендуемый способ: HTTP-прокси

### Преимущества:
✅ Стабильная работа SIM800L (только HTTP)
✅ Основной сайт защищен HTTPS
✅ Очередь данных при сбоях
✅ Автоматическая повторная отправка

## Изменения в файле InfiniteSessionTMASv2.ino

### 1. Добавьте уникальный ID датчика

В начало файла (после строки 1) добавьте:

```cpp
#define DEVICE_ID "SENSOR_001"  // Замените на уникальный ID
```

Для каждого датчика используйте разный ID:
- SENSOR_001, SENSOR_002, SENSOR_003...
- Или по местоположению: MOSCOW_BRIDGE_1, SPB_TOWER_2
- Или по номеру SIM: SIM_79991234567

### 2. Измените URL отправки данных

**Найдите строку 339:**
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://monitoring54.isp.regruhosting.ru/log.php?a="));
```

**Замените на (РЕКОМЕНДУЕТСЯ - через прокси):**
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id="));
GSMport.print(DEVICE_ID);
GSMport.print(F("&a="));
```

**Или (только если SIM800L поддерживает HTTPS):**
```cpp
GSMport.print(F("AT+HTTPPARA=\"URL\",\"https://distoring.ru/log.php?id="));
GSMport.print(DEVICE_ID);
GSMport.print(F("&a="));
```

### 3. Полный блок кода (строки 336-342)

```cpp
bool sendSingleRequest(String value) {
  while (GSMport.available()) GSMport.read();
  blinkTRAN();
  GSMport.print(F("AT+HTTPPARA=\"URL\",\"https://distoring.ru/log.php?id="));
  GSMport.print(DEVICE_ID);
  GSMport.print(F("&a="));
  GSMport.print(value);
  GSMport.println(F("\""));
  Serial.print(F("→ URL: ")); Serial.println(value);
```

### 4. Альтернатива: Автоматический ID по IMEI

Если хотите использовать IMEI модуля как ID:

```cpp
// Глобальная переменная
String deviceId = "";

// В функции setup() после строки 155 добавьте:
deviceId = getIMEI();
Serial.print(F("Device ID: ")); Serial.println(deviceId);

// Новая функция (добавьте в конец файла):
String getIMEI() {
  GSMport.println("AT+GSN");
  delay(500);
  String imei = "";
  while (GSMport.available()) {
    char c = GSMport.read();
    if (isDigit(c)) imei += c;
  }
  return imei.length() > 0 ? imei : "UNKNOWN";
}

// В sendSingleRequest используйте:
GSMport.print(deviceId);  // вместо DEVICE_ID
```

### 5. Проверка

После прошивки откройте Serial Monitor (9600 baud):

Вы должны увидеть:
```
→ URL: V:12.50,C:85,R:0.50,P:-1.20,S:OK
GSM< OK
GSM< +HTTPACTION: 0,200
```

Если видите `200` - данные успешно отправлены!

### 6. Тестирование без Arduino

Откройте в браузере:
```
https://distoring.ru/log.php?id=SENSOR_001&a=V:12.5,C:85,R:0.5,P:-1.2,S:OK
```

Должно вернуться: **OK**

Затем откройте https://distoring.ru и найдите датчик на карте.

## Формат данных

Arduino отправляет строку в формате:
```
V:12.50,C:85,R:0.50,P:-1.20,S:OK
```

Где:
- **V** = Voltage (напряжение батареи в вольтах)
- **C** = Charge (заряд батареи в процентах)
- **R** = Roll (крен в градусах)
- **P** = Pitch (тангаж в градусах)  
- **S** = Status (статус: OK, LOW_BATTERY, MODEM_REINIT)

## Рекомендации по ID

### Хорошие примеры:
✅ `SENSOR_001` - простая нумерация
✅ `BRIDGE_MSK_SOUTH` - понятное название
✅ `79991234567` - номер SIM карты
✅ `TOWER_LAT55_LON37` - с координатами
✅ `USER_IVAN_HOME` - по пользователю

### Плохие примеры:
❌ `SENSOR` - одинаковый для всех
❌ `123` - слишком короткий
❌ `Датчик №1` - кириллица и спецсимволы
❌ `MY SENSOR` - пробелы

## Готово!

После прошивки и первой успешной отправки:
1. Датчик автоматически появится на карте
2. Зарегистрируйтесь на distoring.ru
3. Добавьте датчик в свой аккаунт
4. Установите точные координаты

Удачи! 🚀
