<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><title>Packages | Verde Royale</title>
    <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
</head>
<body><div class="site-shell"><?php require __DIR__ . '/../partials/site-header.php'; ?><main id="main-content" class="page-content"><div class="page-heading"><p class="eyebrow">Sample booking packages</p><h1>Find your kind of getaway.</h1><p>Choose a package to preview your booking estimate. Rates are demo estimates and require confirmation with the resort.</p><label class="search-field"><span class="sr-only">Search packages</span><input id="package-search" type="search" placeholder="Search packages"></label></div><div class="room-grid">
        <?php foreach ($rooms as $room): ?>
            <article class="room-card" data-package="<?php echo htmlspecialchars($room['name'] . ' ' . $room['description']); ?>"><div class="room-image" style="background-image: url('<?php echo htmlspecialchars(\App\Helpers\Assets::url('/' . ltrim((string) ($room['image'] ?? 'assets/img/hero-pool.jpg'), '/')), ENT_QUOTES, 'UTF-8'); ?>'); background-size: cover; background-position: center; min-height: 200px;"></div><div class="room-card-content"><p class="eyebrow">Sample package</p><h2><?php echo htmlspecialchars($room['name']); ?></h2><p><?php echo htmlspecialchars($room['description']); ?></p><p><strong>₱<?php echo number_format($room['rate']); ?></strong> starting estimate</p><a href="?action=book&id=<?php echo $room['id']; ?>" class="btn">Select package</a></div></article>
        <?php endforeach; ?>
    </div>

</div></main><?php require __DIR__ . '/../partials/site-footer.php'; ?></div></body>
</html>

