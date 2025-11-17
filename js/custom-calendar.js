document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('custom-calendar');
    if (!calendarEl) return;

    // Элементы popup'а
    const modal = document.getElementById('custom-calendar-modal');
    const modalTitle = modal ? modal.querySelector('.custom-calendar-modal__title') : null;
    const modalMeta = modal ? modal.querySelector('.custom-calendar-modal__meta') : null;
    const modalDescription = modal ? modal.querySelector('.custom-calendar-modal__description') : null;
    const modalLink = modal ? modal.querySelector('.custom-calendar-modal__link') : null;

    function openEventModal(event) {
        if (!modal || !modalTitle || !modalDescription) return;

        modalTitle.textContent = event.title || '';

        // Даты
        if (modalMeta) {
            const start = event.start ? event.start.toLocaleString() : '';
            const end = event.end ? event.end.toLocaleString() : '';
            modalMeta.textContent = end ? `${start} — ${end}` : start;
        }

        // Описание
        modalDescription.textContent = event.extendedProps.description || 'Нет описания';

        // Ссылка на запись, если есть
        if (modalLink) {
            if (event.url) {
                modalLink.href = event.url;
                modalLink.textContent = 'Перейти к записи';
                modalLink.style.display = '';
            } else {
                modalLink.href = '#';
                modalLink.textContent = '';
                modalLink.style.display = 'none';
            }
        }

        modal.classList.add('custom-calendar-modal--open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('custom-calendar-modal-open');
    }

    function closeEventModal() {
        if (!modal) return;
        modal.classList.remove('custom-calendar-modal--open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('custom-calendar-modal-open');
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            const target = e.target;
            if (target.hasAttribute('data-calendar-modal-close')) {
                e.preventDefault();
                closeEventModal();
            }
        });

        // Закрытие по Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('custom-calendar-modal--open')) {
                closeEventModal();
            }
        });
    }

    // Получаем настройки из контейнера
    const container = calendarEl.closest('.custom-calendar-container');
    const viewMode = container ? container.dataset.view : 'full';
    const height = container ? container.dataset.height : 'auto';
    
    // Конфиг для FullCalendar
    const calendarConfig = {
        locale: calendar_ajax.locale,
        initialView: viewMode === 'mini' ? 'dayGridMonthMini' : 'dayGridMonth',
        headerToolbar: {
            left: viewMode === 'mini' ? '' : 'prev,next today',
            center: 'title',
            right: viewMode === 'mini' ? '' : 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        views: {
            dayGridMonthMini: {
                type: 'dayGridMonth',
                duration: { months: 1 },
                fixedWeekCount: false,
                headerToolbar: false,
                height: 'auto'
            }
        },
        height: height !== 'auto' ? height : undefined,
        events: function(fetchInfo, successCallback) {
            jQuery.ajax({
                url: calendar_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_events',
                    nonce: calendar_ajax.nonce
                },
                success: function(response) {
                    successCallback(response);
                    calendarEl.classList.add('calendar-loaded');
                }
            });
        },
        datesSet: function() {
            // Небольшая анимация при смене месяца/вида
            calendarEl.classList.add('calendar-view-changing');
            setTimeout(() => {
                calendarEl.classList.remove('calendar-view-changing');
            }, 300);
        },
        eventDidMount: function(info) {
            // Анимация появления события
            info.el.style.opacity = '0';
            info.el.style.transform = 'scale(0.9)';
            setTimeout(() => {
                info.el.style.transition = 'opacity 0.3s, transform 0.3s';
                info.el.style.opacity = '1';
                info.el.style.transform = 'scale(1)';
            }, 50);
            
            // Подсказка с описанием
            if (info.event.extendedProps.description) {
                info.el.setAttribute('title', info.event.extendedProps.description);
            }
        },
        eventClick: function(info) {
            // Анимация при клике
            info.el.style.transform = 'scale(0.95)';
            setTimeout(() => info.el.style.transform = 'scale(1)', 200);

            // Открываем popup с информацией о событии
            info.jsEvent.preventDefault();
            openEventModal(info.event);
        }
    };
    
    // Создаем календарь
    const calendar = new FullCalendar.Calendar(calendarEl, calendarConfig);
    calendar.render();
    
    // Стилизация мини-календаря
    if (viewMode === 'mini') {
        calendarEl.classList.add('mini-calendar');
        
        // Упрощаем заголовок (только месяц и год)
        const titleEl = calendarEl.querySelector('.fc-toolbar-title');
        if (titleEl) {
            const text = titleEl.textContent;
            const parts = text.split(' ');
            if (parts.length > 1) {
                titleEl.textContent = parts[0] + ' ' + parts[parts.length - 1];
            }
        }
    }
});