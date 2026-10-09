<?php

declare(strict_types=1);

require_once __DIR__ . '/app/helpers.php';

requireLogin();
if (isArsiparis()) {
    setFlash('error', 'Arsiparis hanya dapat mengakses arsip surat.');
    redirect('agenda.php');
}

$pageTitle = 'Kalender Agenda';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.fc-event-main, .fc-event-title, .fc-event-title-container {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.fc-daygrid-event {
    overflow: hidden !important;
}
#calendar { min-height: 400px; }
.fc { border-radius: 0.75rem; overflow: hidden; }

/* FullCalendar Dark Mode */
.dark .fc,
.dark .fc-view,
.dark .fc-view-harness,
.dark .fc-scrollgrid,
.dark .fc-scrollgrid-section,
.dark .fc-scrollgrid-section > td,
.dark .fc-day,
.dark .fc-daygrid-day,
.dark .fc-daygrid-day-frame,
.dark .fc-daygrid-day-events,
.dark .fc-daygrid-event-harness,
.dark .fc-list,
.dark .fc-list-view,
.dark .fc-list-section,
.dark .fc-list-day,
.dark .fc-list-event,
.dark .fc-timegrid,
.dark .fc-timegrid-view,
.dark .fc-timegrid-slot,
.dark .fc-timegrid-axis,
.dark .fc-timegrid-now-indicator-line {
    background-color: #1e293b !important;
}
.dark .fc .fc-scrollgrid {
    border-color: #334155 !important;
}
.dark .fc-daygrid-day.fc-day-today,
.dark .fc .fc-day-today {
    background-color: rgba(79, 70, 229, 0.12) !important;
}
.dark .fc-day-other {
    background-color: #0f172a !important;
}
.dark .fc .fc-col-header-cell-cushion,
.dark .fc .fc-list-day-cushion {
    background-color: #1e293b !important;
}
.dark .fc-event {
    border-color: rgba(255,255,255,0.1) !important;
}
.dark .fc .fc-toolbar-title {
    color: #f8fafc !important;
}
.dark .fc .fc-button-primary {
    background-color: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}
.dark .fc .fc-button-primary:hover {
    background-color: #475569 !important;
}
.dark .fc .fc-button-primary:disabled {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #64748b !important;
}

$pageTitle = 'Kalender Agenda';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.fc-event-main, .fc-event-title, .fc-event-title-container {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}
.fc-daygrid-event {
    overflow: hidden !important;
}
#calendar { min-height: 400px; }
.fc { border-radius: 0.75rem; overflow: hidden; }

/* FullCalendar Dark Mode */
.dark .fc,
.dark .fc-view,
.dark .fc-view-harness,
.dark .fc-scrollgrid,
.dark .fc-scrollgrid-section,
.dark .fc-scrollgrid-section > td,
.dark .fc-day,
.dark .fc-daygrid-day,
.dark .fc-daygrid-day-frame,
.dark .fc-daygrid-day-events,
.dark .fc-daygrid-event-harness,
.dark .fc-list,
.dark .fc-list-view,
.dark .fc-list-section,
.dark .fc-list-day,
.dark .fc-list-event,
.dark .fc-timegrid,
.dark .fc-timegrid-view,
.dark .fc-timegrid-slot,
.dark .fc-timegrid-axis,
.dark .fc-timegrid-now-indicator-line {
    background-color: #1e293b !important;
}
.dark .fc .fc-scrollgrid {
    border-color: #334155 !important;
}
.dark .fc-daygrid-day.fc-day-today,
.dark .fc .fc-day-today {
    background-color: rgba(79, 70, 229, 0.12) !important;
}
.dark .fc-day-other {
    background-color: #0f172a !important;
}
.dark .fc .fc-col-header-cell-cushion,
.dark .fc .fc-list-day-cushion {
    background-color: #1e293b !important;
}
.dark .fc-event {
    border-color: rgba(255,255,255,0.1) !important;
}
.dark .fc .fc-toolbar-title {
    color: #f8fafc !important;
}
.dark .fc .fc-button-primary {
    background-color: #334155 !important;
    border-color: #475569 !important;
    color: #f8fafc !important;
}
.dark .fc .fc-button-primary:hover {
    background-color: #475569 !important;
}
.dark .fc .fc-button-primary:disabled {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    color: #64748b !important;
}
.dark .fc .fc-button-active {
    background-color: #4f46e5 !important;
    border-color: #6366f1 !important;
}
</style>

<div class="mx-auto">

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-extrabold text-slate-900 dark:text-slate-100">Kalender Agenda Kegiatan</h1>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Tampilan visual jadwal kegiatan bulanan dan mingguan (klik tanggal untuk menambah kegiatan manual)</p>
    </div>
    <?php if (!isPimpinan()): ?>
        <a href="agenda_form.php?entry_type=kegiatan&return_to=calendar.php"
           class="inline-flex items-center gap-2 rounded-2xl bg-teal-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-teal-700 transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Kegiatan Manual
        </a>
    <?php endif; ?>
</div>

<!-- Legend -->
<div class="mb-3 flex flex-wrap items-center gap-4">
    <div class="flex items-center gap-2">
        <span class="h-3 w-3 rounded-full" style="background:#0d9488"></span>
        <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Offline</span>
    </div>
    <div class="flex items-center gap-2">
        <span class="h-3 w-3 rounded-full" style="background:#4f46e5"></span>
        <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Online (Zoom)</span>
    </div>
    <div class="flex items-center gap-2">
        <span class="h-3 w-3 rounded-full" style="background:#d97706"></span>
        <span class="text-xs font-semibold text-slate-600 dark:text-slate-400">Hybrid</span>
    </div>
</div>

<section class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 shadow-sm"
         x-data="{
             showModal: false,
             event: { id: 0, title:'', date:'', time:'', location:'', mode:'', zoom_link:'', url:'', edit_url:'' },
             openEvent(info) {
                 let id = info.event.id || 0;
                 this.event = {
                     id:        id,
                     title:     info.event.title,
                     date:      info.event.startStr ? info.event.startStr.substring(0,10) : '',
                     time:      info.event.extendedProps.time || '',
                     location:  info.event.extendedProps.location || '',
                     mode:      info.event.extendedProps.mode || '',
                     zoom_link: info.event.extendedProps.zoom_link || '',
                     url:       info.event.url || '',
                     edit_url:  id ? 'agenda_form.php?id=' + id + '&return_to=calendar.php' : ''
                 };
                 this.showModal = true;
                 info.jsEvent.preventDefault();
             }
         }">

    <div id="calendar"></div>

    <!-- Event Detail Modal -->
    <div x-show="showModal" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
         @click="showModal = false"
         @keydown.escape.window="showModal = false">
        <div class="w-full max-w-md rounded-3xl bg-white dark:bg-slate-800 shadow-2xl p-6"
             @click.stop>
            <div class="mb-4 flex items-start justify-between gap-3">
                <h3 class="text-base font-extrabold text-slate-900 dark:text-slate-100 leading-snug" x-text="event.title"></h3>
                <button @click="showModal = false" type="button" class="shrink-0 rounded-xl p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex gap-3">
                    <dt class="w-20 shrink-0 font-semibold text-slate-500 dark:text-slate-400">Tanggal</dt>
                    <dd class="text-slate-800 dark:text-slate-200" x-text="event.date"></dd>
                </div>
                <div class="flex gap-3" x-show="event.time">
                    <dt class="w-20 shrink-0 font-semibold text-slate-500 dark:text-slate-400">Waktu</dt>
                    <dd class="text-slate-800 dark:text-slate-200" x-text="event.time + ' WIB'"></dd>
                </div>
                <div class="flex gap-3" x-show="event.location">
                    <dt class="w-20 shrink-0 font-semibold text-slate-500 dark:text-slate-400">Lokasi</dt>
                    <dd class="text-slate-800 dark:text-slate-200" x-text="event.location"></dd>
                </div>
                <div class="flex gap-3">
                    <dt class="w-20 shrink-0 font-semibold text-slate-500 dark:text-slate-400">Mode</dt>
                    <dd class="text-slate-800 dark:text-slate-200 capitalize" x-text="event.mode"></dd>
                </div>
                <div class="flex gap-3" x-show="event.zoom_link">
                    <dt class="w-20 shrink-0 font-semibold text-slate-500 dark:text-slate-400">Link Zoom</dt>
                    <dd>
                        <a :href="event.zoom_link" target="_blank" rel="noopener noreferrer"
                           class="text-indigo-600 dark:text-indigo-400 hover:underline break-all" x-text="event.zoom_link"></a>
                    </dd>
                </div>
            </dl>
            <div class="mt-5 flex justify-end gap-2">
                <button @click="showModal = false" type="button"
                        class="rounded-xl border border-slate-200 dark:border-slate-600 px-4 py-2 text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700">
                    Tutup
                </button>
                <?php if (!isPimpinan()): ?>
                    <a :href="event.edit_url" x-show="event.edit_url"
                       class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-bold text-white hover:bg-amber-600">
                        Edit
                    </a>
                <?php endif; ?>
                <a :href="event.url"
                   class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">
                    Detail
                </a>
            </div>
        </div>
    </div>
</section>
</div>

<!-- FullCalendar v6 (lokal — CSS di-inject oleh JS; link stylesheet CDN versi lama selalu 404) -->
<script defer src="assets/js/fullcalendar.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var calendarEl = document.getElementById('calendar');
    if (!calendarEl) return;

    // Detect mobile
    var isMobile = window.innerWidth < 768;

    // Hitung tinggi kalender = sisa viewport (topbar + title + legend + padding)
    function calcCalendarHeight() {
        var vh = window.innerHeight;
        var topbar = 64;       // topbar header sticky
        var title = 60;        // title + subtitle
        var legend = 32;       // legend row
        var padding = 48;      // main padding (p-4/p-6) + section padding + margin
        var h = vh - topbar - title - legend - padding;
        return Math.max(isMobile ? 300 : 400, h);
    }

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: isMobile ? 'listMonth' : 'dayGridMonth',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,listMonth'
        },
        locale: 'id',
        buttonText: {
            today:   'Hari Ini',
            month:   'Bulan',
            week:    'Minggu',
            list:    'Daftar'
        },
        eventDisplay: 'list-item',
        height: calcCalendarHeight(),
        expandRows: true,
        selectable: true,
        dateClick: function (info) {
            <?php if (!isPimpinan()): ?>
            window.location.href = 'agenda_form.php?entry_type=kegiatan&agenda_date=' + info.dateStr + '&return_to=calendar.php';
            <?php endif; ?>
        },
        eventSources: [
            {
                url: 'calendar_events.php',
                method: 'GET',
                failure: function () {
                    console.warn('Gagal memuat event kalender.');
                }
            }
        ],
        eventClick: function (info) {
            // Dispatch Alpine event
            var section = calendarEl.closest('[x-data]');
            if (section && section._x_dataStack) {
                var alpineComp = section._x_dataStack[0];
                if (alpineComp && typeof alpineComp.openEvent === 'function') {
                    alpineComp.openEvent(info);
                    return;
                }
            }
            // Fallback: navigate to detail page
            if (info.event.url) {
                window.location.href = info.event.url;
            }
            info.jsEvent.preventDefault();
        },
        // Responsive: switch to list view on small screens + recalc height
        windowResize: function (view) {
            if (window.innerWidth < 768 && calendar.view.type !== 'listMonth') {
                calendar.changeView('listMonth');
            }
            calendar.setOption('height', calcCalendarHeight());
        }
    });

    calendar.render();
});
</script>

<!-- Add calendar link to sidebar via JS (menu already in header.php) -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
