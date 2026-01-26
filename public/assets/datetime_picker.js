(function () {
    const MONTHS = {
        Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5,
        Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11,
    };

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function parseISOToLocal(iso) {
        if (!iso) return null;
        const trimmed = String(iso).trim().replace(' ', 'T');
        const parts = trimmed.split('T');
        if (parts.length < 2) return null;
        const dateParts = parts[0].split('-').map((v) => parseInt(v, 10));
        const timeParts = parts[1].split(':').map((v) => parseInt(v, 10));
        if (dateParts.length < 3 || timeParts.length < 2) return null;
        const year = dateParts[0];
        const month = dateParts[1] - 1;
        const day = dateParts[2];
        const hours = timeParts[0];
        const minutes = timeParts[1];
        const seconds = timeParts.length > 2 ? timeParts[2] : 0;
        const date = new Date(year, month, day, hours, minutes, seconds);
        if (Number.isNaN(date.getTime())) return null;
        return date;
    }

    function dateToISO(date, timeFormat) {
        const includeSeconds = timeFormat && timeFormat.indexOf(':s') !== -1;
        const datePart = [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-');
        const timePart = [
            pad(date.getHours()),
            pad(date.getMinutes()),
        ];
        if (includeSeconds) {
            timePart.push(pad(date.getSeconds()));
        }
        return datePart + 'T' + timePart.join(':');
    }

    function formatDate(date, format) {
        const day = date.getDate();
        const month = date.getMonth() + 1;
        const year = date.getFullYear();
        const monthName = Object.keys(MONTHS).find((key) => MONTHS[key] === date.getMonth());

        switch (format) {
            case 'd/m/Y':
                return pad(day) + '/' + pad(month) + '/' + year;
            case 'm/d/Y':
                return pad(month) + '/' + pad(day) + '/' + year;
            case 'Y-m-d':
                return year + '-' + pad(month) + '-' + pad(day);
            case 'Y.m.d':
                return year + '.' + pad(month) + '.' + pad(day);
            case 'd.m.Y':
                return pad(day) + '.' + pad(month) + '.' + year;
            case 'd-m-Y':
                return pad(day) + '-' + pad(month) + '-' + year;
            case 'Y/m/d':
                return year + '/' + pad(month) + '/' + pad(day);
            case 'j M Y':
                return day + ' ' + monthName + ' ' + year;
            case 'M j, Y':
                return monthName + ' ' + day + ', ' + year;
            default:
                return year + '-' + pad(month) + '-' + pad(day);
        }
    }

    function formatTime(date, format) {
        const hours = date.getHours();
        const minutes = date.getMinutes();
        const seconds = date.getSeconds();
        const is12 = format.indexOf('h') !== -1;
        const includeSeconds = format.indexOf(':s') !== -1;

        if (is12) {
            const suffix = hours >= 12 ? 'PM' : 'AM';
            const hour12 = hours % 12 || 12;
            let result = pad(hour12) + ':' + pad(minutes);
            if (includeSeconds) {
                result += ':' + pad(seconds);
            }
            return result + ' ' + suffix;
        }

        let result = pad(hours) + ':' + pad(minutes);
        if (includeSeconds) {
            result += ':' + pad(seconds);
        }
        return result;
    }

    function formatDateTime(date, dateFormat, timeFormat) {
        return formatDate(date, dateFormat) + ' ' + formatTime(date, timeFormat);
    }

    function parseDatePart(text, format) {
        let match;
        switch (format) {
            case 'd/m/Y':
                match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text);
                return match ? { day: +match[1], month: +match[2], year: +match[3] } : null;
            case 'm/d/Y':
                match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text);
                return match ? { month: +match[1], day: +match[2], year: +match[3] } : null;
            case 'Y-m-d':
                match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(text);
                return match ? { year: +match[1], month: +match[2], day: +match[3] } : null;
            case 'Y.m.d':
                match = /^(\d{4})\.(\d{1,2})\.(\d{1,2})$/.exec(text);
                return match ? { year: +match[1], month: +match[2], day: +match[3] } : null;
            case 'd.m.Y':
                match = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(text);
                return match ? { day: +match[1], month: +match[2], year: +match[3] } : null;
            case 'd-m-Y':
                match = /^(\d{1,2})-(\d{1,2})-(\d{4})$/.exec(text);
                return match ? { day: +match[1], month: +match[2], year: +match[3] } : null;
            case 'Y/m/d':
                match = /^(\d{4})\/(\d{1,2})\/(\d{1,2})$/.exec(text);
                return match ? { year: +match[1], month: +match[2], day: +match[3] } : null;
            case 'j M Y':
                match = /^(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})$/.exec(text);
                if (!match || !(match[2] in MONTHS)) return null;
                return { day: +match[1], month: MONTHS[match[2]] + 1, year: +match[3] };
            case 'M j, Y':
                match = /^([A-Za-z]{3})\s+(\d{1,2}),\s*(\d{4})$/.exec(text);
                if (!match || !(match[1] in MONTHS)) return null;
                return { month: MONTHS[match[1]] + 1, day: +match[2], year: +match[3] };
            default:
                return null;
        }
    }

    function parseTimePart(text, format) {
        let match;
        if (format === 'H:i') {
            match = /^(\d{1,2}):(\d{2})$/.exec(text);
            return match ? { hours: +match[1], minutes: +match[2], seconds: 0 } : null;
        }
        if (format === 'H:i:s') {
            match = /^(\d{1,2}):(\d{2}):(\d{2})$/.exec(text);
            return match ? { hours: +match[1], minutes: +match[2], seconds: +match[3] } : null;
        }
        if (format === 'h:i A') {
            match = /^(\d{1,2}):(\d{2})\s*([AP]M)$/i.exec(text);
            if (!match) return null;
            let hours = +match[1] % 12;
            if (match[3].toUpperCase() === 'PM') hours += 12;
            return { hours, minutes: +match[2], seconds: 0 };
        }
        if (format === 'h:i:s A') {
            match = /^(\d{1,2}):(\d{2}):(\d{2})\s*([AP]M)$/i.exec(text);
            if (!match) return null;
            let hours = +match[1] % 12;
            if (match[4].toUpperCase() === 'PM') hours += 12;
            return { hours, minutes: +match[2], seconds: +match[3] };
        }
        return null;
    }

    function parseDisplayToDate(text, dateFormat, timeFormat) {
        if (!text) return null;
        const trimmed = String(text).trim();
        if (!trimmed) return null;

        let timeMatch;
        if (timeFormat === 'H:i') {
            timeMatch = /(\d{1,2}:\d{2})$/.exec(trimmed);
        } else if (timeFormat === 'H:i:s') {
            timeMatch = /(\d{1,2}:\d{2}:\d{2})$/.exec(trimmed);
        } else if (timeFormat === 'h:i A') {
            timeMatch = /(\d{1,2}:\d{2}\s*[AP]M)$/i.exec(trimmed);
        } else if (timeFormat === 'h:i:s A') {
            timeMatch = /(\d{1,2}:\d{2}:\d{2}\s*[AP]M)$/i.exec(trimmed);
        }
        if (!timeMatch) return null;

        const timeText = timeMatch[1].trim();
        const dateText = trimmed.slice(0, timeMatch.index).trim().replace(/,$/, '');
        const datePart = parseDatePart(dateText, dateFormat);
        const timePart = parseTimePart(timeText, timeFormat);
        if (!datePart || !timePart) return null;

        const date = new Date(
            datePart.year,
            datePart.month - 1,
            datePart.day,
            timePart.hours,
            timePart.minutes,
            timePart.seconds
        );
        if (
            date.getFullYear() !== datePart.year ||
            date.getMonth() !== datePart.month - 1 ||
            date.getDate() !== datePart.day
        ) {
            return null;
        }
        return date;
    }

    function getFormats(root) {
        const source = root && root.dataset ? root : document.body;
        return {
            dateFormat: source.dataset.dateFormat || 'Y-m-d',
            timeFormat: source.dataset.timeFormat || 'H:i',
        };
    }

    function initReservationWindow(form) {
        if (!form) return null;
        if (form._reservationWindowCtx) {
            return form._reservationWindowCtx;
        }
        const { dateFormat, timeFormat } = getFormats(form);
        const startDisplay = form.querySelector('[data-role="start-display"]');
        const endDisplay = form.querySelector('[data-role="end-display"]');
        const startIso = form.querySelector('[data-role="start-iso"]');
        const endIso = form.querySelector('[data-role="end-iso"]');

        function syncDisplayToIso(displayInput, isoInput) {
            if (!displayInput || !isoInput) return null;
            const parsed = parseDisplayToDate(displayInput.value, dateFormat, timeFormat);
            if (!parsed) {
                isoInput.value = '';
                return null;
            }
            isoInput.value = dateToISO(parsed, timeFormat);
            return parsed;
        }

        function syncIsoToDisplay(isoInput, displayInput) {
            if (!isoInput || !displayInput) return;
            if (!isoInput.value) {
                displayInput.value = '';
                return;
            }
            const parsed = parseISOToLocal(isoInput.value);
            displayInput.value = parsed ? formatDateTime(parsed, dateFormat, timeFormat) : '';
        }

        function normalizeEndIfNeeded() {
            if (!startDisplay || !endDisplay) return;
            const startDate = syncDisplayToIso(startDisplay, startIso);
            const endDate = syncDisplayToIso(endDisplay, endIso);
            if (!startDate || !endDate) return;
            if (endDate.getTime() <= startDate.getTime()) {
                const nextDay = new Date(startDate);
                nextDay.setDate(startDate.getDate() + 1);
                nextDay.setHours(9, 0, 0, 0);
                endDisplay.value = formatDateTime(nextDay, dateFormat, timeFormat);
                endIso.value = dateToISO(nextDay, timeFormat);
            }
        }

        function setTodayWindow() {
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(now.getDate() + 1);
            tomorrow.setHours(9, 0, 0, 0);
            if (startDisplay) {
                startDisplay.value = formatDateTime(now, dateFormat, timeFormat);
            }
            if (endDisplay) {
                endDisplay.value = formatDateTime(tomorrow, dateFormat, timeFormat);
            }
            syncDisplayToIso(startDisplay, startIso);
            syncDisplayToIso(endDisplay, endIso);
        }

        if (startIso && startDisplay) {
            syncIsoToDisplay(startIso, startDisplay);
        }
        if (endIso && endDisplay) {
            syncIsoToDisplay(endIso, endDisplay);
        }

        if (startDisplay && endDisplay) {
            ['change', 'blur'].forEach((evt) => {
                startDisplay.addEventListener(evt, normalizeEndIfNeeded);
                endDisplay.addEventListener(evt, normalizeEndIfNeeded);
            });
        }

        form.addEventListener('submit', () => {
            normalizeEndIfNeeded();
            syncDisplayToIso(startDisplay, startIso);
            syncDisplayToIso(endDisplay, endIso);
        });

        const ctx = {
            dateFormat,
            timeFormat,
            startDisplay,
            endDisplay,
            startIso,
            endIso,
            setTodayWindow,
            normalizeEndIfNeeded,
            syncDisplayToIso,
        };

        form._reservationWindowCtx = ctx;
        return ctx;
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-reservation-window="1"]').forEach((form) => {
            initReservationWindow(form);
        });
    });

    window.SnipeSchedulerDateTime = {
        initReservationWindow,
        parseDisplayToDate,
        formatDateTime,
        dateToISO,
        parseISOToLocal,
    };
})();
