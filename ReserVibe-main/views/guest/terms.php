<?php
// Example rules for this school project only; confirm actual terms with the resort.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo booking terms and privacy note | Verde Royale Resort</title>
    <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
</head>
<body>
<div class="site-shell">
    <?php require __DIR__ . '/../partials/site-header.php'; ?>
    <main class="container" id="main-content" style="max-width: 800px; padding-top: 4rem; padding-bottom: 4rem;">
        <p class="eyebrow">Academic project</p>
        <h1>Demo terms and privacy note</h1>
        <p class="staff-hint">These are example rules configured for ReserVibe's school demo. They are not the resort's official terms. Confirm real policies with the resort before relying on them.</p>
        <section>
            <h2>Booking rules used by this demo</h2>
            <ul>
                <li>Stays are limited to 30 nights and check-in dates can be booked up to 365 days ahead.</li>
                <li>Displayed check-in is 14:00 and check-out is 11:00.</li>
                <li>Date changes are allowed at least 7 days before arrival.</li>
                <li>The demo marks the deposit non-refundable when cancellation is within 3 days of arrival. No refunds are processed by this application.</li>
                <li>Prices, payment choices, and availability are sample records. No money is collected or transferred.</li>
            </ul>
        </section>
        <section style="margin-top: 2rem;">
            <h2>Privacy note</h2>
            <p>The demo asks for a guest name, email address, phone number, stay dates, and guest count so it can demonstrate booking workflows. Records are stored in the project's local MySQL database.</p>
            <p>Use test details only. Do not upload real identity documents, payment credentials, or sensitive receipts. This note is project documentation, not a legal privacy notice; the resort must provide its own approved notice before using real guest data.</p>
        </section>
        <section style="margin-top: 2rem;">
            <h2>Resort house rules</h2>
            <p>Official rules about noise, outside food, pets, no-shows, and other resort policies have not been verified or configured in this school project. Contact the resort to confirm them.</p>
        </section>
    </main>
    <?php require __DIR__ . '/../partials/site-footer.php'; ?>
</div>
</body>
</html>
