#define _SS_MAX_RX_BUFF 256
#include <SoftwareSerial.h>
#include <avr/sleep.h>
#include <avr/wdt.h>
#include <avr/power.h>
#include "I2Cdev.h"
#include "MPU6050_6Axis_MotionApps20.h"

#define LED_POWER     2
#define LED_NETWORK   3
#define LED_TRANSMIT  4
#define BAT_PIN       A0
#define DEVICE_ID "SENSOR_001"

SoftwareSerial GSMport(8, 9);

MPU6050 mpu;
uint8_t fifoBuffer[64];

// ── Параметры батареи ──
const float R1         = 9900.0;
const float R2         = 4600.0;
const float VREF       = 4.98;
const float ADC_MAX    = 1024.0;
const float BAT_MAX    = 12.6;
const float BAT_MIN    = 9.0;
const float CORRECTION = 0.93;
const float BAT_CUTOFF = 9.3;

// ── Статус текущего замера ──
String currentStatus = "OK";

// ── Флаг выхода из цикла при разряде ──
// Когда true — runSessions() немедленно завершается,
// управление возвращается в loop(), и только оттуда уходим в сон.
bool lowBatteryFlag = false;

// ── WDT: 8 сек × 15 = 120 сек = 2 минуты ──
volatile uint8_t wdtCount = 0;
#define WDT_INTERVALS 15

ISR(WDT_vect) {
  wdtCount++;
}

// ====================== БАТАРЕЯ ======================
float readBatteryVoltage() {
  long sum = 0;
  for (int i = 0; i < 10; i++) { sum += analogRead(BAT_PIN); delayMicroseconds(400); }
  float vPin = ((sum / 10) * VREF) / ADC_MAX;
  return vPin * ((R1 + R2) / R2) * CORRECTION;
}

int readBatteryPercent() {
  float v = constrain(readBatteryVoltage(), BAT_MIN, BAT_MAX);
  return (int)((v - BAT_MIN) / (BAT_MAX - BAT_MIN) * 100.0);
}

// Двойная проверка с паузой — защита от случайных импульсов/наводок
bool isBatteryCritical() {
  float v1 = readBatteryVoltage();
  delay(300);
  float v2 = readBatteryVoltage();
  return (v1 < BAT_CUTOFF && v2 < BAT_CUTOFF);
}

// ====================== СОН ======================
void sleepNow() {
  set_sleep_mode(SLEEP_MODE_PWR_DOWN);
  sleep_enable();
  sleep_cpu();
  sleep_disable();
}

void startWDT() {
  cli();
  wdt_reset();
  WDTCSR |= (1 << WDCE) | (1 << WDE);
  WDTCSR  = (1 << WDIE) | (1 << WDP3) | (1 << WDP0);
  sei();
}

void stopWDT() {
  cli();
  wdt_reset();
  WDTCSR |= (1 << WDCE) | (1 << WDE);
  WDTCSR  = 0x00;
  sei();
}

void sleepTwoMinutes() {
  Serial.println(F("--- Сон 2 минуты ---"));
  Serial.flush();
  mpu.resetFIFO();
  digitalWrite(LED_POWER,    LOW);
  digitalWrite(LED_NETWORK,  LOW);
  digitalWrite(LED_TRANSMIT, HIGH);

  wdtCount = 0;
  startWDT();
  while (wdtCount < WDT_INTERVALS) sleepNow();
  stopWDT();

  digitalWrite(LED_POWER,    HIGH);
  digitalWrite(LED_TRANSMIT, HIGH);
  Serial.println(F("--- Просыпаемся ---"));
}

// ====================== ГЛУБОКИЙ СОН ======================
// Вызывается ТОЛЬКО из loop() — никогда из глубины цикла.
// К этому моменту сессия уже закрыта, флаг установлен.
void enterDeepSleep() {
  Serial.println(F("=== КРИТИЧЕСКИЙ ЗАРЯД — ОТКЛЮЧЕНИЕ ==="));
  Serial.flush();

  // Гасим все светодиоды
  digitalWrite(LED_TRANSMIT, LOW);
  digitalWrite(LED_NETWORK,  LOW);
  digitalWrite(LED_POWER,    LOW);

  // Выключаем GSM модуль
  sendAT("AT+CFUN=0", 8000);

  // Отключаем периферию AVR для минимального потребления
  ADCSRA = 0;
  power_all_disable();

  // Запрещаем все прерывания — WDT не запущен, просыпаться некому
  cli();

  set_sleep_mode(SLEEP_MODE_PWR_DOWN);
  sleep_enable();
  sei();
  sleep_cpu();
  // сюда не доходим
}

// ====================== SETUP ======================
void setup() {
  Serial.begin(9600);
  delay(1000);

  pinMode(LED_POWER,    OUTPUT);
  pinMode(LED_NETWORK,  OUTPUT);
  pinMode(LED_TRANSMIT, OUTPUT);
  pinMode(BAT_PIN,      INPUT);

  digitalWrite(LED_POWER,    HIGH);
  digitalWrite(LED_NETWORK,  LOW);
  digitalWrite(LED_TRANSMIT, LOW);

  Wire.begin();
  mpu.initialize();
  mpu.dmpInitialize();
  mpu.setDMPEnabled(true);
  Serial.println(F("MPU6050 DMP запущен"));

  float v = readBatteryVoltage();
  Serial.print(F("Батарея: ")); Serial.print(v, 2);
  Serial.print(F("В — ")); Serial.print(readBatteryPercent()); Serial.println(F("%"));

  // Двойная проверка при старте
  if (isBatteryCritical()) {
    Serial.println(F("Батарея ниже порога при старте"));
    // GSM ещё не инициализирован — сразу в сон
    digitalWrite(LED_TRANSMIT, LOW);
    digitalWrite(LED_NETWORK,  LOW);
    digitalWrite(LED_POWER,    LOW);
    ADCSRA = 0;
    power_all_disable();
    cli();
    set_sleep_mode(SLEEP_MODE_PWR_DOWN);
    sleep_enable();
    sei();
    sleep_cpu();
  }

  GSMport.begin(9600);
  delay(5000);

  Serial.println(F("=== GPRS инициализация ==="));
  sendAT("AT+IPR=9600", 1000);
  sendAT("AT", 500);
  gprs_init();

  Serial.println(F("\n=== УСТРОЙСТВО ГОТОВО ==="));
}

// ====================== LOOP ======================
// Единственное место откуда уходим в глубокий сон.
// runSessions() выставляет lowBatteryFlag и возвращается —
// тогда loop() чисто и безопасно вызывает enterDeepSleep().
void loop() {
  lowBatteryFlag = false;
  runSessions();

  // Сюда попадаем только если runSessions() вышел через lowBatteryFlag
  if (lowBatteryFlag) {
    Serial.println(F("Выход из цикла по разряду — уходим в сон"));
    Serial.flush();
    delay(200); // дать Serial допечатать
    enterDeepSleep();
  }
}

// ====================== ГЛАВНЫЙ ЦИКЛ СЕССИЙ ======================
void runSessions() {
  Serial.println(F("\n=== ЦИКЛ ПЕРЕДАЧИ ЗАПУЩЕН ==="));
  digitalWrite(LED_TRANSMIT, HIGH);

  while (true) {

    // ── Проверка заряда перед сессией ──
    if (isBatteryCritical()) {
      currentStatus = "LOW_BATTERY";
      Serial.println(F("Низкий заряд — последний замер"));
      // Пробуем отправить последний замер
      if (openSession()) {
        String data = getMeasurement();
        if (data.length() > 0) sendSingleRequest(data);
        closeSession();
      }
      // Выходим из цикла — НЕ вызываем enterDeepSleep() здесь
      lowBatteryFlag = true;
      return;
    }

    // ── Открыть сессию ──
    if (!openSession()) {
      currentStatus = "MODEM_REINIT";
      full_reinit();
      if (!openSession()) {
        Serial.println(F("Сессия не открылась — пауза 30 сек"));
        delay(30000);
        continue;
      }
    }

    // ── Два замера ──
    Serial.println(F("--- Сессия: 2 замера ---"));

    for (int i = 0; i < 2; i++) {
      // Проверка заряда внутри цикла замеров
      if (isBatteryCritical()) {
        currentStatus = "LOW_BATTERY";
        String data = getMeasurement();
        if (data.length() > 0) sendSingleRequest(data);
        closeSession();
        lowBatteryFlag = true;
        return; // выходим из runSessions()
      }

      String data = getMeasurement();
      if (data.length() > 0) {
        Serial.print(F("Замер ")); Serial.print(i + 1);
        Serial.print(F(": ")); Serial.println(data);

        if (!sendSingleRequest(data)) {
          Serial.println(F("Ошибка — переоткрытие HTTP"));
          sendAT("AT+HTTPTERM", 1000);
          if (sendAT("AT+HTTPINIT", 2000)) {
            sendAT("AT+HTTPPARA=\"CID\",1", 1000);
            currentStatus = "HTTP_REOPEN";
            sendSingleRequest(data);
          } else {
            currentStatus = "MODEM_REINIT";
            Serial.println(F("HTTPINIT failed — пропускаем замер"));
          }
        } else {
          currentStatus = "OK";
        }
      } else {
        Serial.println(F("Нет данных от MPU6050"));
      }

      if (i == 0) delay(2000);
    }

    // ── Закрыть сессию ──
    closeSession();

    // ── Сон 2 минуты ──
    sleepTwoMinutes();
  }
}

// ====================== ОТКРЫТИЕ / ЗАКРЫТИЕ СЕССИИ ======================
bool openSession() {
  blinkNETOPEN();
  if (!sendAT("AT+SAPBR=1,1", 10000)) {
    Serial.println(F("SAPBR=1,1 failed"));
    return false;
  }
  sendAT("AT+HTTPTERM", 1000);
  if (!sendAT("AT+HTTPINIT", 2000)) {
    Serial.println(F("HTTPINIT failed"));
    sendAT("AT+SAPBR=0,1", 3000);
    return false;
  }
  sendAT("AT+HTTPPARA=\"CID\",1", 1000);
  digitalWrite(LED_NETWORK, HIGH);
  Serial.println(F("Сессия открыта"));
  return true;
}

void closeSession() {
  sendAT("AT+HTTPTERM", 2000);
  sendAT("AT+SAPBR=0,1", 3000);
  digitalWrite(LED_NETWORK, LOW);
  Serial.println(F("Сессия закрыта"));
}

// ====================== HTTP ======================
bool waitForOK(unsigned long timeout) {
  char lineBuf[128];
  memset(lineBuf, 0, sizeof(lineBuf));
  uint8_t pos = 0;
  unsigned long deadline = millis() + timeout;
  while (millis() < deadline) {
    if (GSMport.available()) {
      char c = GSMport.read();
      if (c == '\n' || c == '\r') {
        if (pos > 0) {
          lineBuf[pos] = '\0';
          Serial.print(F("GSM< ")); Serial.println(lineBuf);
          if (strstr(lineBuf, "OK")    != NULL) return true;
          if (strstr(lineBuf, "ERROR") != NULL) return false;
        }
        pos = 0; memset(lineBuf, 0, sizeof(lineBuf));
      } else { if (pos < sizeof(lineBuf)-1) lineBuf[pos++] = c; }
    }
    delay(1);
  }
  return false;
}

bool sendSingleRequest(String value) {
  while (GSMport.available()) GSMport.read();
  blinkTRAN();
  GSMport.print(F("AT+HTTPPARA=\"URL\",\"http://a1253108.xsph.ru/proxy.php?id="));
  GSMport.print(DEVICE_ID);
  GSMport.print(F("&a="));
  GSMport.print(value);
  GSMport.println(F("\""));
  Serial.print(F("→ URL: ")); Serial.println(value);

  if (!waitForOK(3000)) { Serial.println(F("HTTPPARA URL failed")); return false; }

  while (GSMport.available()) GSMport.read();
  GSMport.println(F("AT+HTTPACTION=0"));
  Serial.println(F("→ AT+HTTPACTION=0"));

  char lineBuf[48]; memset(lineBuf, 0, sizeof(lineBuf));
  uint8_t pos = 0;
  unsigned long deadline = millis() + 60000UL;
  bool found200 = false;

  while (millis() < deadline) {
    if (GSMport.available()) {
      char c = GSMport.read();
      if (c == '\n' || c == '\r') {
        if (pos > 0) {
          lineBuf[pos] = '\0';
          Serial.print(F("GSM< ")); Serial.println(lineBuf);
          if (strstr(lineBuf, "+HTTPACTION: 0,200") != NULL) { found200 = true; break; }
          if (strstr(lineBuf, "+HTTPACTION:")       != NULL) { Serial.println(F("HTTPACTION error")); break; }
        }
        pos = 0; memset(lineBuf, 0, sizeof(lineBuf));
      } else { if (pos < sizeof(lineBuf)-1) lineBuf[pos++] = c; }
    }
    delay(1);
  }
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH);
  if (!found200) { Serial.println(F("HTTPACTION failed")); return false; }
  return true;
}

// ====================== MPU6050 ======================
String getMeasurement() {
  uint16_t fifoCount = mpu.getFIFOCount();
  if (fifoCount >= 1024 || fifoCount == 0) {
    mpu.resetFIFO();
    Serial.println(F("FIFO reset"));
    delay(10);
  }

  unsigned long start = millis();
  bool packetReady = false;
  while (millis() - start < 800) {
    fifoCount = mpu.getFIFOCount();
    if (fifoCount >= 42) {
      if (mpu.dmpGetCurrentFIFOPacket(fifoBuffer)) { packetReady = true; break; }
    }
    delay(5);
  }

  if (!packetReady) { Serial.println(F("MPU6050: нет пакета")); return ""; }
  int16_t rawTemp = mpu.getTemperature();
  Quaternion q; VectorFloat gravity; float ypr[3];
  mpu.dmpGetQuaternion(&q, fifoBuffer);
  mpu.dmpGetGravity(&gravity, &q);
  mpu.dmpGetYawPitchRoll(ypr, &q, &gravity);
  float temperature = (rawTemp / 340.0) + 36.53;

  return "V:" + String(readBatteryVoltage(), 2)
      + ",C:" + String(readBatteryPercent())
      + ",R:" + String(degrees(ypr[1]), 2)
      + ",P:" + String(degrees(ypr[2]), 2)
      + ",T:" + String(temperature, 2)
      + ",S:" + currentStatus;
}

// ====================== GPRS ======================
void gprs_init() {
  String cmds[] = {
    "AT+SAPBR=3,1,\"CONTYPE\",\"GPRS\"",
    "AT+SAPBR=3,1,\"APN\",\"internet.beeline.ru\"",
    "AT+SAPBR=3,1,\"USER\",\"beeline\"",
    "AT+SAPBR=3,1,\"PWD\",\"beeline\"",
    "AT+SAPBR=1,1", "AT+SAPBR=2,1"
  };
  int waits[] = {1000,1000,1000,1000,10000,5000};
  Serial.println(F("GPRS init..."));
  for (int i = 0; i < 6; i++) {
    if (!sendAT(cmds[i], waits[i])) {
      if (i == 4) { Serial.println(F("SAPBR failed — retry")); delay(2000); i--; }
    }
  }
  sendAT("AT+SAPBR=0,1", 3000);
  delay(2000);
  digitalWrite(LED_NETWORK, HIGH);
  Serial.println(F("GPRS init OK"));
}

void full_reinit() {
  digitalWrite(LED_NETWORK, LOW);
  sendAT("AT+CFUN=1,1", 15000);
  gprs_init();
}

bool sendAT(String cmd, int wait) {
  digitalWrite(LED_POWER, LOW); delay(100); digitalWrite(LED_POWER, HIGH);
  while (GSMport.available()) GSMport.read();
  Serial.print(F("→ ")); Serial.println(cmd);
  GSMport.println(cmd);
  delay(wait);
  String r = "";
  unsigned long t = millis() + 500;
  while (millis() < t) { if (GSMport.available()) r += (char)GSMport.read(); }
  if (r.length() > 0) Serial.println(r);
  return (r.indexOf("OK") != -1) || (r.indexOf("ERROR") == -1);
}

void blinkNET() {
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH); delay(100);
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH); delay(100);
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH);
}
void blinkNETOPEN() {
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH); delay(100);
  digitalWrite(LED_NETWORK, LOW); delay(100); digitalWrite(LED_NETWORK, HIGH);
}
void blinkTRAN() {
  digitalWrite(LED_TRANSMIT, LOW); delay(100); digitalWrite(LED_TRANSMIT, HIGH); delay(100);
  digitalWrite(LED_TRANSMIT, LOW); delay(100); digitalWrite(LED_TRANSMIT, HIGH);
}
