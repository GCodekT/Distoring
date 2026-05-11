/**
 * Конвертер часовых поясов
 * Все данные в БД хранятся в московском времени (MSK, UTC+3)
 * Этот скрипт конвертирует отображаемое время на уровне сайта
 */

// Словарь часовых поясов с их смещением от МСК
const TIMEZONES = {
    'Europe/Moscow': { offset: 0, name: 'Москва (МСК) UTC+3' },
    'Europe/Kaliningrad': { offset: -1, name: 'Калининград UTC+2' },
    'Europe/Samara': { offset: 1, name: 'Самара UTC+4' },
    'Asia/Yekaterinburg': { offset: 2, name: 'Екатеринбург UTC+5' },
    'Asia/Omsk': { offset: 3, name: 'Омск UTC+6' },
    'Asia/Novosibirsk': { offset: 4, name: 'Новосибирск UTC+7' },
    'Asia/Krasnoyarsk': { offset: 5, name: 'Красноярск UTC+8' },
    'Asia/Irkutsk': { offset: 6, name: 'Иркутск UTC+9' },
    'Asia/Chita': { offset: 7, name: 'Чита UTC+10' },
    'Asia/Vladivostok': { offset: 8, name: 'Владивосток UTC+11' },
    'Asia/Magadan': { offset: 9, name: 'Магадан UTC+12' },
    'Asia/Kamchatka': { offset: 10, name: 'Камчатка UTC+13' }
};

// Текущий выбранный часовой пояс (по умолчанию МСК)
let currentTimezone = 'Europe/Moscow';

/**
 * Инициализация селектора часовых поясов
 * @param {HTMLElement} selectElement - элемент select для выбора часового пояса
 */
function initTimezoneSelector(selectElement) {
    if (!selectElement) return;

    // Восстанавливаем сохраненный часовой пояс из localStorage
    const savedTimezone = localStorage.getItem('selectedTimezone');
    if (savedTimezone && TIMEZONES[savedTimezone]) {
        currentTimezone = savedTimezone;
    }

    // Заполняем селект
    selectElement.innerHTML = '';
    Object.keys(TIMEZONES).forEach(tz => {
        const option = document.createElement('option');
        option.value = tz;
        option.textContent = TIMEZONES[tz].name;
        if (tz === currentTimezone) {
            option.selected = true;
        }
        selectElement.appendChild(option);
    });

    // Обработчик изменения часового пояса
    selectElement.addEventListener('change', function(e) {
        currentTimezone = e.target.value;
        localStorage.setItem('selectedTimezone', currentTimezone);
        
        // Уведомляем другие части приложения об изменении часового пояса
        window.dispatchEvent(new CustomEvent('timezoneChanged', {
            detail: { timezone: currentTimezone }
        }));
    });
}

/**
 * Конвертирует московское время в выбранный часовой пояс
 * @param {string} mskDateString - дата в формате, понятном Date (например, из БД)
 * @returns {Date} объект Date с учетом смещения часового пояса
 */
function convertTimeToTimezone(mskDateString) {
    // Парсим дату из строки БД (предполагаем, что она в московском времени)
    const date = new Date(mskDateString);
    
    // Получаем смещение текущего часового пояса от МСК в часах
    const offset = TIMEZONES[currentTimezone].offset;
    
    // Применяем смещение (конвертируем в миллисекунды)
    const convertedDate = new Date(date.getTime() + (offset * 60 * 60 * 1000));
    
    return convertedDate;
}

/**
 * Форматирует дату в выбранном часовом поясе
 * @param {string} mskDateString - дата в МСК из БД
 * @param {string} format - формат вывода ('time', 'full', 'short')
 * @returns {string} отформатированная дата
 */
function formatDateInTimezone(mskDateString, format = 'full') {
    if (!mskDateString) return '—';
    
    const date = convertTimeToTimezone(mskDateString);
    
    const options = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    };

    switch(format) {
        case 'time':
            return date.toLocaleTimeString('ru-RU', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: false 
            });
        case 'short':
            return date.toLocaleString('ru-RU', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
        case 'full':
        default:
            return date.toLocaleString('ru-RU', options);
    }
}

/**
 * Конвертирует массив меток времени для графиков
 * @param {Array} timestamps - массив строк с датами
 * @returns {Array} массив отформатированных времен в текущем часовом поясе
 */
function convertTimestampsForChart(timestamps) {
    return timestamps.map(ts => formatDateInTimezone(ts, 'time'));
}

/**
 * Получает текущий часовой пояс
 * @returns {string} код часового пояса
 */
function getCurrentTimezone() {
    return currentTimezone;
}

/**
 * Получает информацию о текущем часовом поясе
 * @returns {Object} объект с информацией о часовом поясе
 */
function getCurrentTimezoneInfo() {
    return TIMEZONES[currentTimezone];
}
