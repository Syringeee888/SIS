<?php
declare(strict_types=1);

$reference = $_GET['ref'] ?? '';
$reservation = $_SESSION['last_confirmation'] ?? null;
if (!is_string($reference) || !preg_match('/^RSV-\d{4,}$/i', $reference)
    || !is_array($reservation) || !hash_equals((string) ($reservation['reference'] ?? ''), $reference)) {
    http_response_code(404);
    echo 'This confirmation is available only in the browser session that created it.';
    exit;
}
$currency = (string) \App\Helpers\Config::get('currency_symbol', 'PHP');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservation received | Verde Royale Resort</title>
    <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
    <style>
        @media print { .site-header, .site-footer, .btn, .no-print { display: none !important; } body { background: white; color: black; } }
    </style>
</head>
<body>
<div class="site-shell">
    <?php require __DIR__ . '/../partials/site-header.php'; ?>
    <main class="container" id="main-content" style="max-width: 640px; padding: 4rem 2rem; margin: 0 auto;">
        <p class="eyebrow">School-project booking demo</p>
        <h1>Reservation request received</h1>
        <p>This demo saved your reservation. Its current status is <strong><?php echo htmlspecialchars(str_replace('_', ' ', (string) $reservation['status'])); ?></strong>; it is not a confirmed resort booking and no payment was processed.</p>
        <section class="staff-panel" aria-label="Reservation details">
            <p><strong>Reference:</strong> <?php echo htmlspecialchars((string) $reservation['reference']); ?></p>
            <p><strong>Guest:</strong> <?php echo htmlspecialchars((string) $reservation['guest_name']); ?></p>
            <p><strong>Package:</strong> <?php echo htmlspecialchars((string) $reservation['accommodation_name']); ?></p>
            <p><strong>Dates:</strong> <?php echo htmlspecialchars((string) $reservation['check_in']); ?> to <?php echo htmlspecialchars((string) $reservation['check_out']); ?></p>
            <p><strong>Guests:</strong> <?php echo (int) ($reservation['guests'] ?? 1); ?></p>
            <p><strong>Estimated total:</strong> <?php echo htmlspecialchars($currency); ?><?php echo number_format((float) $reservation['total'], 2); ?></p>
            <p><strong>Demo deposit amount:</strong> <?php echo htmlspecialchars($currency); ?><?php echo number_format((float) $reservation['deposit_due'], 2); ?></p>
            <p><strong>Selected method:</strong> <?php echo htmlspecialchars((string) $reservation['payment_method_label']); ?></p>
        </section>
        <div class="no-print" style="margin-top: 1.5rem; display: flex; gap: .75rem; flex-wrap: wrap;">
            <button type="button" class="btn btn--primary" onclick="window.print()">Print this page</button>
            <a class="btn btn--ghost" href="?action=manage">Manage reservation</a>
            <a class="btn btn--ghost" href="?action=home">Return home</a>
        </div>
    </main>
    <?php require __DIR__ . '/../partials/site-footer.php'; ?>
</div>
</body>
</html>
