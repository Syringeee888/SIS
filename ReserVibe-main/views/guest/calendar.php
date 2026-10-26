<?php
// views/guest/calendar.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Availability Calendar | Verde Royale Resort</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="<?php echo \App\Helpers\Assets::url('/assets/js/calendar.js'); ?>"></script>
</head>
<body>
    <div class="site-shell">
        <?php require __DIR__ . '/../partials/site-header.php'; ?>
        <main class="container" id="main-content" style="max-width: 900px; padding-top: 4rem; padding-bottom: 4rem;">
            <p class="eyebrow">Verde Royale Resort</p>
            <h1>Availability Calendar</h1>
            <p style="margin-bottom: 2rem;">This calendar shows reservations recorded in the school-project demo. Confirm actual resort availability directly before making plans.</p>
            <div id="reservation-calendar"></div>
        </main>
        <?php require __DIR__ . '/../partials/site-footer.php'; ?>
    </div>
</body>
</html>
