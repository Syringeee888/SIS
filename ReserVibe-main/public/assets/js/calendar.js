document.addEventListener('DOMContentLoaded', function () {
    // Module 2 — availability calendar.
    // The feed is served by public/api/availability/calendar.php, which returns
    // reserved stays and active holds as FullCalendar events.
    var calendarEl = document.getElementById('reservation-calendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') return;

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        selectable: true,
        events: '/api/availability/calendar.php',
        dateClick: function (info) {
            var checkOut = new Date(info.dateStr);
            checkOut.setDate(checkOut.getDate() + 1);

            alert(
                'Selected ' + info.dateStr + '.\n' +
                'Check availability with GET /api/availability/check.php' +
                '?accommodation_id=1&check_in=' + info.dateStr +
                '&check_out=' + checkOut.toISOString().split('T')[0]
            );
        }
    });

    calendar.render();
});
