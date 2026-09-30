(function () {
    const config = window.leaveRequestConfig || { leaveTypes: {}, holidays: [], today: '' };
    const holidays = new Set(config.holidays || []);
    const leaveTypeSelect = document.getElementById('leave_type_id');
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    const totalDaysPreview = document.getElementById('totalDaysPreview');
    const summaryLeaveType = document.getElementById('summaryLeaveType');
    const summaryDateRange = document.getElementById('summaryDateRange');
    const summaryPeriod = document.getElementById('summaryPeriod');
    const summaryRemaining = document.getElementById('summaryRemaining');
    const attachmentHelp = document.getElementById('attachmentHelp');
    const periodInputs = Array.from(document.querySelectorAll('input[name="period"]'));
    const laoMonths = ['ມັງກອນ', 'ກຸມພາ', 'ມີນາຄົມ', 'ເມສາ', 'ພຶດສະພາ', 'ມິຖຸນາ', 'ກໍລະກົດ', 'ສິງຫາ', 'ກັນຍາ', 'ຕຸລາ', 'ພະຈິກ', 'ທັນວາ'];
    const laoWeekdays = ['ອາ', 'ຈ', 'ອ', 'ພ', 'ພຫ', 'ສ', 'ສອ'];
    const todayValue = config.today || dateKey(new Date());
    let activePicker = null;

    function parseDate(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null;
    }

    function formatLaoDate(value) {
        const date = parseDate(value);
        return date ? `${date.getDate()} ${laoMonths[date.getMonth()]} ${date.getFullYear()}` : '';
    }

    function dateKey(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function selectedPeriod() {
        return periodInputs.find((input) => input.checked)?.value || 'full_day';
    }

    function selectedPeriodText() {
        return ({ full_day: 'ເຕັມວັນ', morning: 'ເຄິ່ງເຊົ້າ', afternoon: 'ເຄິ່ງບ່າຍ' })[selectedPeriod()] || 'ເຕັມວັນ';
    }

    function businessDays(startValue, endValue) {
        const start = parseDate(startValue);
        const end = parseDate(endValue);
        if (!start || !end || end < start) return 0;
        let count = 0;
        const current = new Date(start);
        while (current <= end) {
            if (current.getDay() !== 0 && current.getDay() !== 6 && !holidays.has(dateKey(current))) count += 1;
            current.setDate(current.getDate() + 1);
        }
        return count;
    }

    function calculatedDays() {
        const days = businessDays(startDateInput.value, endDateInput.value);
        return days > 0 && selectedPeriod() !== 'full_day' && startDateInput.value === endDateInput.value ? 0.5 : days;
    }

    function updateHalfDayAvailability() {
        const type = config.leaveTypes[leaveTypeSelect.value];
        const allowHalfDay = !type || Number(type.allow_half_day) === 1;
        periodInputs.forEach((input) => {
            if (input.value !== 'full_day') {
                input.disabled = !allowHalfDay;
                if (!allowHalfDay && input.checked) document.getElementById('period_full_day').checked = true;
            }
        });
    }

    function updateSummary() {
        updateHalfDayAvailability();
        const type = config.leaveTypes[leaveTypeSelect.value];
        const days = calculatedDays();
        const startValue = startDateInput.value;
        const endValue = endDateInput.value;
        const remaining = type ? Number(type.remaining_days) : null;
        totalDaysPreview.textContent = `${days.toFixed(days % 1 === 0 ? 0 : 1)} ວັນ`;
        summaryLeaveType.textContent = type ? type.name : '-';
        summaryDateRange.textContent = startValue && endValue ? `${formatLaoDate(startValue)} - ${formatLaoDate(endValue)}` : '-';
        summaryPeriod.textContent = selectedPeriodText();
        summaryRemaining.textContent = remaining !== null ? `${remaining.toFixed(1)} ວັນ` : '-';
        totalDaysPreview.classList.toggle('text-danger', remaining !== null && days > remaining);
        attachmentHelp.textContent = type && Number(type.require_attachment) === 1
            ? 'ປະເພດການລາພັກນີ້ຕ້ອງແນບໄຟລ໌ PDF, JPG, JPEG ຫຼື PNG ຂະໜາດບໍ່ເກີນ 5MB'
            : 'ຮອງຮັບ PDF, JPG, JPEG, PNG ຂະໜາດບໍ່ເກີນ 5MB';
    }

    function syncDateDisplays() {
        document.querySelectorAll('[data-date-field]').forEach((field) => {
            const input = field.querySelector('input[type="hidden"]');
            const value = field.querySelector('.lao-date-trigger-value');
            const trigger = field.querySelector('.lao-date-trigger');
            const hasValue = Boolean(input?.value);
            value.textContent = hasValue ? formatLaoDate(input.value) : 'ເລືອກວັນທີ';
            value.classList.toggle('is-placeholder', !hasValue);
            trigger?.classList.toggle('has-value', hasValue);
        });
    }

    function closePicker(picker = activePicker) {
        if (!picker) return;
        picker.popup.hidden = true;
        picker.trigger.setAttribute('aria-expanded', 'false');
        if (activePicker === picker) activePicker = null;
    }

    function changeInputValue(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function renderPicker(picker) {
        const { popup, input } = picker;
        const minValue = input.min || todayValue;
        const firstDay = new Date(picker.viewYear, picker.viewMonth, 1);
        const firstVisible = new Date(picker.viewYear, picker.viewMonth, 1 - firstDay.getDay());
        const days = [];
        for (let index = 0; index < 42; index += 1) {
            const date = new Date(firstVisible);
            date.setDate(firstVisible.getDate() + index);
            const value = dateKey(date);
            const outside = date.getMonth() !== picker.viewMonth;
            const today = value === todayValue;
            const selected = value === input.value;
            const classes = ['lao-calendar-day', outside ? 'is-outside' : '', today ? 'is-today' : '', selected ? 'is-selected' : ''].filter(Boolean).join(' ');
            days.push(`<button type="button" class="${classes}" data-date-value="${value}"${value < minValue ? ' disabled' : ''} aria-label="${date.getDate()} ${laoMonths[date.getMonth()]} ${date.getFullYear()}${today ? ' ມື້ນີ້' : ''}">${date.getDate()}</button>`);
        }
        const canSelectToday = todayValue >= minValue;
        popup.innerHTML = `
            <div class="lao-calendar-header">
                <button type="button" class="lao-calendar-nav" data-calendar-action="previous" aria-label="ເດືອນກ່ອນ"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <div class="lao-calendar-month" aria-live="polite">${laoMonths[picker.viewMonth]} ${picker.viewYear}</div>
                <button type="button" class="lao-calendar-nav" data-calendar-action="next" aria-label="ເດືອນຕໍ່ໄປ"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            </div>
            <div class="lao-calendar-weekdays" aria-hidden="true">${laoWeekdays.map((day) => `<span>${day}</span>`).join('')}</div>
            <div class="lao-calendar-days">${days.join('')}</div>
            <div class="lao-calendar-footer"><button type="button" class="lao-calendar-clear" data-calendar-action="clear">ລ້າງ</button><button type="button" class="lao-calendar-today" data-calendar-action="today"${canSelectToday ? '' : ' disabled'}>ມື້ນີ້</button></div>`;
    }

    function openPicker(picker) {
        if (activePicker && activePicker !== picker) closePicker(activePicker);
        const selected = parseDate(picker.input.value) || parseDate(picker.input.min) || parseDate(todayValue) || new Date();
        picker.viewYear = selected.getFullYear();
        picker.viewMonth = selected.getMonth();
        renderPicker(picker);
        picker.popup.hidden = false;
        picker.trigger.setAttribute('aria-expanded', 'true');
        activePicker = picker;
    }

    function createPicker(field) {
        const input = field.querySelector('input[type="hidden"]');
        const trigger = field.querySelector('.lao-date-trigger');
        if (!input || !trigger) return null;
        const popup = document.createElement('div');
        popup.className = 'lao-datepicker';
        popup.hidden = true;
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', 'ເລືອກວັນທີ');
        field.appendChild(popup);
        const initial = parseDate(input.value) || parseDate(input.min) || parseDate(todayValue) || new Date();
        const picker = { field, input, trigger, popup, viewYear: initial.getFullYear(), viewMonth: initial.getMonth() };
        trigger.addEventListener('click', () => activePicker === picker ? closePicker(picker) : openPicker(picker));
        popup.addEventListener('click', (event) => {
            const action = event.target.closest('[data-calendar-action]')?.dataset.calendarAction;
            if (action === 'previous' || action === 'next') {
                const nextMonth = new Date(picker.viewYear, picker.viewMonth + (action === 'next' ? 1 : -1), 1);
                picker.viewYear = nextMonth.getFullYear();
                picker.viewMonth = nextMonth.getMonth();
                renderPicker(picker);
                return;
            }
            if (action === 'clear') {
                changeInputValue(input, '');
                syncDateDisplays();
                closePicker(picker);
                return;
            }
            if (action === 'today') {
                changeInputValue(input, todayValue);
                syncDateDisplays();
                closePicker(picker);
                return;
            }
            const dayButton = event.target.closest('[data-date-value]');
            if (!dayButton || dayButton.disabled) return;
            changeInputValue(input, dayButton.dataset.dateValue);
            syncDateDisplays();
            closePicker(picker);
        });
        return picker;
    }

    Array.from(document.querySelectorAll('[data-date-field]')).map(createPicker).filter(Boolean);
    [leaveTypeSelect, startDateInput, endDateInput, ...periodInputs].forEach((element) => element?.addEventListener('change', updateSummary));
    startDateInput?.addEventListener('change', () => {
        if (!endDateInput.value || endDateInput.value < startDateInput.value) endDateInput.value = startDateInput.value;
        endDateInput.min = startDateInput.value || todayValue;
        syncDateDisplays();
        updateSummary();
    });
    endDateInput?.addEventListener('change', syncDateDisplays);
    document.addEventListener('click', (event) => {
        // Navigation redraws the calendar markup. Use the original event path so a
        // chevron click is still recognised as an in-calendar click after redraw.
        const eventPath = typeof event.composedPath === 'function' ? event.composedPath() : [];
        if (activePicker && !eventPath.includes(activePicker.field) && !activePicker.field.contains(event.target)) {
            closePicker(activePicker);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activePicker) {
            const picker = activePicker;
            closePicker(picker);
            picker.trigger.focus();
        }
    });
    syncDateDisplays();
    updateSummary();
})();
