/**
 * Утилиты для работы с часовыми поясами
 * Используется на всех страницах
 */

// Константы
const SRC_OFFSET = 3;  // UTC+3 (МСК) — смещение источника данных

// Инициализация часового пояса
let currentTZOffset = localStorage.getItem('distoring_tz') !== null
    ? parseInt(localStorage.getItem('distoring_tz'))
    : 7;  // UTC+7 (Новосибирск) по умолчанию

// Установка часового пояса из селекта
function initTimezoneSelector(selectElement) {
    if (!selectElement) return;
    
    selectElement.value = (currentTZOffset >= 0 ? '+' : '') + currentTZOffset;
    selectElement.addEventListener('change', function() {
        currentTZOffset = parseInt(this.value);
        localStorage.setItem('distoring_tz', currentTZOffset);
        
        // Генерируем событие для обновления всех компонентов
        window.dispatchEvent(new CustomEvent('timezoneChanged', { 
            detail: { offset: currentTZOffset } 
        }));
    });
}

// Преобразование времени между часовыми поясами
function shiftTimestamp(str, fromOffset, toOffset) {
    if (!str) return '—';
    
    const [datePart, timePart] = str.split(' ');
    if (!datePart || !timePart) return str;
    
    const [y, mo, d] = datePart.split('-').map(Number);
    const [h, mi, s] = timePart.split(':').map(Number);
    
    // Создаем дату в UTC
    const utc = new Date(Date.UTC(y, mo - 1, d, h - fromOffset, mi, s));
    
    // Применяем целевое смещение
    utc.setUTCHours(utc.getUTCHours() + toOffset);
    
    const p = n => String(n).padStart(2, '0');
    return `${utc.getUTCFullYear()}-${p(utc.getUTCMonth() + 1)}-${p(utc.getUTCDate())} ${p(utc.getUTCHours())}:${p(utc.getUTCMinutes())}:${p(utc.getUTCSeconds())}`;
}

// Конвертация временной метки в текущий ЧП
function formatTimeToCurrentTZ(timestamp) {
    return shiftTimestamp(timestamp, SRC_OFFSET, currentTZOffset);
}

// Конвертация массива временных меток
function convertTimestamps(timestamps) {
    return timestamps.map(t => shiftTimestamp(t, SRC_OFFSET, currentTZOffset));
}

// Фильтрация данных по периоду (в минутах)
function filterByPeriod(timestamps, minutes) {
    if (minutes === 'all' || !minutes) {
        return Array.from({ length: timestamps.length }, (_, i) => i);
    }
    
    const now = new Date();
    const cutoffUTC = new Date(now.getTime() - minutes * 60000);
    
    return timestamps.reduce((acc, t, i) => {
        const [datePart, timePart] = t.split(' ');
        if (!datePart || !timePart) return acc;
        
        const [y, mo, d] = datePart.split('-').map(Number);
        const [h, mi, s] = timePart.split(':').map(Number);
        
        // Преобразуем в UTC (вычитаем смещение источника)
        const dateUTC = new Date(Date.UTC(y, mo - 1, d, h - SRC_OFFSET, mi, s));
        
        // Сравниваем с границей
        if (dateUTC.getTime() >= cutoffUTC.getTime()) {
            acc.push(i);
        }
        return acc;
    }, []);
}