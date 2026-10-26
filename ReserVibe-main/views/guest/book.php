<?php
// views/guest/book.php
//
// Rendered by ReservationController::showForm(). Every price on this page is
// read from the catalogue/config — no rate is hardcoded in the markup.

$selectedId = $room['id'] ?? ($rooms[0]['id'] ?? null);
$symbol = $settings['currency_symbol'] ?? 'PHP';
$depositRate = (float) ($settings['deposit_rate'] ?? 0.5);
$holdMinutes = (int) ($settings['hold_minutes'] ?? 90);
$paymentMethods = $paymentMethods ?? [];

$selected = null;
foreach ($rooms as $candidate) {
    if ((int) $candidate['id'] === (int) $selectedId) {
        $selected = $candidate;
        break;
    }
}

$selectedRate = (float) ($selected['rate'] ?? 0);
$selectedDeposit = round($selectedRate * $depositRate, 2);
$format = static fn (float $amount): string => number_format($amount, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verde Royale Resort - Quick Booking</title>
  <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
</head>
<body
  data-currency="<?php echo htmlspecialchars($symbol); ?>"
  data-deposit-rate="<?php echo $depositRate; ?>"
>

  <div class="booking-shell">
  <div class="booking-card">
    <div class="header">
      <h2>Verde Royale Resort</h2>
      <p>Estimate Cost &amp; Reserve Cottage / Room</p>
    </div>

    <div id="form-alert" class="alert alert-error hidden" role="alert"></div>

    <div class="form-group">
      <label for="booking-date">Check-in Date</label>
      <input type="date" id="booking-date" required />
    </div>

    <div class="form-group">
      <label for="booking-checkout">Check-out Date</label>
      <input type="date" id="booking-checkout" required />
    </div>

    <!-- Tier selection, driven by the MySQL accommodation catalogue -->
    <div class="form-group">
      <label for="guest-count">Number of Guests</label><input type="number" id="guest-count" min="1" value="1" required style="width: 100%; padding: 0.75rem; border: 1px solid var(--color-border); border-radius: 4px; margin-bottom: 1rem;" /></div><div class="form-group"><label for="accommodation">Accommodation</label>
      <select id="accommodation">
        <?php foreach ($rooms as $option): ?>
          <option
            value="<?php echo (float) $option['rate']; ?>"
            data-id="<?php echo (int) $option['id']; ?>"
            <?php echo ((int) $option['id'] === (int) $selectedId) ? 'selected' : ''; ?>
          ><?php echo htmlspecialchars($option['name']); ?> — <?php echo $symbol . $format((float) $option['rate']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Add-ons, driven by the 'addons' config -->
    <div class="form-group">
      <label>Add-ons</label>
      <div class="addons-group">
        <?php foreach ($addons as $addon): ?>
          <div class="addon-item">
            <label>
              <input
                type="checkbox"
                class="addon"
                value="<?php echo (float) $addon['rate']; ?>"
                data-code="<?php echo htmlspecialchars((string) $addon['code']); ?>"
              />
              <?php echo htmlspecialchars((string) $addon['label']); ?> (+<?php echo $symbol . $format((float) $addon['rate']); ?>)
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Guest details -->
    <div class="form-group">
      <label for="guest-name">Guest Name</label>
      <input type="text" id="guest-name" maxlength="120" placeholder="Juan Dela Cruz" />
    </div>

    <div class="form-group">
      <label for="guest-email">Email</label>
      <input type="email" id="guest-email" maxlength="190" placeholder="juan@example.com" />
    </div>

    <div class="form-group">
      <label for="guest-phone">Mobile Number</label>
      <input type="tel" id="guest-phone" maxlength="13" placeholder="09171234567" />
    </div>

    <!-- Payment methods, driven by the 'payment_methods' config -->
    <div class="form-group">
      <label>Payment Method</label>
      <div class="method-grid" id="method-grid">
        <?php foreach ($paymentMethods as $index => $method):
                $qrImage = !empty($method['qr_image']) ? \App\Helpers\Assets::url((string) $method['qr_image']) : '';
        ?>
          <label class="method-card<?php echo $index === 0 ? ' is-selected' : ''; ?>">
            <input
              type="radio"
              class="method"
              name="payment_method"
              value="<?php echo htmlspecialchars((string) $method['code']); ?>"
              data-label="<?php echo htmlspecialchars((string) $method['label']); ?>"
              data-instructions="<?php echo htmlspecialchars((string) $method['instructions']); ?>"
              data-proof="<?php echo !empty($method['requires_proof']) ? '1' : '0'; ?>"
              data-arrival="<?php echo !empty($method['settles_on_arrival']) ? '1' : '0'; ?>"
              data-qr="<?php echo htmlspecialchars($qrImage); ?>"
              <?php echo $index === 0 ? 'checked' : ''; ?>
            />
            <span class="method-name"><?php echo htmlspecialchars((string) $method['label']); ?></span>
            <span class="method-group"><?php echo htmlspecialchars((string) $method['group']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="method-note" id="method-note"></p>
    </div>

    <!-- Live bill calculation -->
    <div class="summary-box">
      <div class="summary-row">
        <span>Base Rate:</span>
        <span id="display-base"><?php echo $symbol . $format($selectedRate); ?></span>
      </div>
      <div class="summary-row">
        <span>Add-ons:</span>
        <span id="display-addons"><?php echo $symbol ?>0.00</span>
      </div>
      <div class="summary-row summary-total">
        <span>Total Estimated:</span>
        <span id="display-total"><?php echo $symbol . $format($selectedRate); ?></span>
      </div>
      <div class="summary-row" style="font-size: 0.8rem; color: #15803d; margin-top: 4px;">
        <span>Required <?php echo (int) round($depositRate * 100); ?>% Deposit:</span>
        <span id="display-deposit" style="font-weight: 600;"><?php echo $symbol . $format($selectedDeposit); ?></span>
      </div>
    </div>

    <div class="button-row">
      <div style="margin-bottom: 1rem;"><label style="display: flex; gap: 0.5rem; align-items: center;"><input type="checkbox" id="agree-terms" required> <span>I understand the <a href="?action=terms" target="_blank" style="text-decoration: underline;">school-demo terms and privacy note</a></span></label></div><button class="btn" id="book-btn">Proceed to Payment</button>
      <a class="btn btn-ghost" id="menu-btn" href="?action=rooms">Return to Menu</a>
    </div>
  </div>
  </div>

  <!-- Hold + payment modal -->
  <div class="modal-overlay" id="payment-modal" aria-hidden="true">
    <div class="modal-content">
      <h3 style="font-size: 1.1rem; color: #166534;">Reserve Your Slot</h3>
      <p style="font-size: 0.8rem; color: #64748b; margin-top: 0.25rem;" id="modal-method">GCash</p>

      <div class="hold-panel">
        <div class="hold-row">
          <span>Deposit due now</span>
          <span id="modal-deposit-amount"><?php echo $symbol . $format($selectedDeposit); ?></span>
        </div>
        <div class="hold-row">
          <span>Slot held for</span>
          <span class="countdown" id="hold-countdown"><?php echo $holdMinutes; ?>:00</span>
        </div>
      </div>

      <div class="qr-box hidden" id="qr-box"></div>

      <p class="method-note" id="modal-instructions"></p>
      <p class="alert alert-success hidden" id="arrival-note"></p>
      <div class="alert hidden" id="modal-alert" role="status"></div>

      <div class="button-row">
        <button class="btn" id="confirm-btn">Confirm Demo Reservation</button>
      </div>
      <div class="button-row">
        <button class="btn btn-ghost" id="cancel-btn" type="button">Cancel Booking</button>
        <a class="btn btn-ghost" id="modal-menu-btn" href="?action=rooms">Return to Menu</a>
      </div>
    </div>
  </div>

  <script src="<?php echo \App\Helpers\Assets::url('/assets/js/booking.js'); ?>"></script>
</body>
</html>