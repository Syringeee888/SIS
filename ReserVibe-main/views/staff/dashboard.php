<?php
$role = $role ?? 'staff';
$csrfToken = \App\Services\CsrfProtection::token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff desk | ReserVibe</title>
  <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
</head>
<body data-staff-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
  <main class="staff-main container" id="main-content">
    <header class="staff-topbar">
      <div><p class="eyebrow">Verde Royale Resort &middot; ReserVibe school demo</p><h1>Front desk</h1><p>Signed in as <?php echo htmlspecialchars($role); ?>. Records are stored in the local MySQL database.</p></div>
      <div class="staff-topbar__actions"><a class="btn btn--ghost" href="?action=rooms">View guest site</a><button class="btn btn--ghost" type="button" id="staff-logout">Sign out</button></div>
    </header>
    <p class="staff-message" id="staff-message" aria-live="polite" role="status"></p>
    <section class="staff-kpis" id="staff-kpis" aria-label="Reservation summary"><p>Loading summary...</p></section>
    <?php if ($role === 'admin'): ?>
    <section class="staff-panel staff-panel--wide">
      <header class="staff-panel__header"><div><h2>Maintain package catalogue</h2><p class="staff-hint">Changes update the guest catalogue and server-calculated booking rates. Inactive packages stay on existing reservations.</p></div><button class="btn btn--ghost btn--sm" id="refresh-catalog" type="button">Refresh packages</button></header>
      <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Package</th><th>Type</th><th>Capacity</th><th>Nightly rate</th><th>Visibility</th><th></th></tr></thead><tbody id="catalog-admin-list"><tr><td colspan="6">Loading packages...</td></tr></tbody></table></div>
      <form id="catalog-admin-form" class="staff-form catalog-admin-form">
        <p class="staff-hint">Add a new package here, or choose <strong>Edit</strong> in the table to update one.</p>
        <input type="hidden" name="id" id="catalog-id">
        <div class="catalog-admin-fields">
          <label class="form-label">Package name<input class="form-input" name="name" maxlength="120" required></label>
          <label class="form-label">Type<input class="form-input" name="type" maxlength="40" required></label>
          <label class="form-label">Capacity<input class="form-input" name="capacity" type="number" min="1" max="500" required></label>
          <label class="form-label">Rate per night (PHP)<input class="form-input" name="rate" type="number" min="1" max="10000000" step="0.01" required></label>
          <label class="form-label catalog-description">Description<textarea class="form-input" name="description" maxlength="500" rows="2"></textarea></label>
          <label class="catalog-active"><input type="checkbox" name="active" checked> Show for new bookings</label>
        </div>
        <div class="staff-panel__tools"><button class="btn btn--primary" type="submit">Save package</button><button class="btn btn--ghost" id="cancel-catalog-edit" type="button">Clear form</button></div>
      </form>
    </section>
    <?php endif; ?>
    <div class="staff-columns">
      <section class="staff-panel">
        <header class="staff-panel__header"><div><h2>Payment proofs to review</h2></div><button class="btn btn--ghost btn--sm" id="refresh-proofs" type="button">Refresh</button></header>
        <div id="proof-list"><p>Loading receipt queue...</p></div>
      </section>
      <section class="staff-panel">
        <header class="staff-panel__header"><div><h2>Register a walk-in</h2></div></header>
        <form id="walk-in-form" class="staff-form">
          <label class="form-label" for="walkin-package">Package</label><select class="form-input" name="accommodation_id" id="walkin-package" required></select>
          <label class="form-label" for="walkin-name">Guest name</label><input class="form-input" name="guest_name" id="walkin-name" maxlength="120" required>
          <label class="form-label" for="walkin-email">Email (optional)</label><input class="form-input" name="guest_email" id="walkin-email" type="email" maxlength="190">
          <div class="staff-form__fields"><div><label class="form-label" for="walkin-phone">Mobile number</label><input class="form-input" name="guest_phone" id="walkin-phone" type="tel" maxlength="13" required></div><div><label class="form-label" for="walkin-guests">Guests</label><input class="form-input" name="guests" id="walkin-guests" type="number" min="1" value="1" required></div></div><div class="staff-form__dates"><div><label class="form-label" for="walkin-in">Check-in</label><input class="form-input" name="check_in" id="walkin-in" type="date" required></div><div><label class="form-label" for="walkin-out">Check-out</label><input class="form-input" name="check_out" id="walkin-out" type="date" required></div></div>
          <div id="walkin-addons"></div>
          <button class="btn btn--primary" type="submit">Create walk-in reservation</button>
        </form>
      </section>
    </div>
    <section class="staff-panel staff-panel--wide">
      <header class="staff-panel__header"><div><h2>Reservations</h2></div><div class="staff-panel__tools" style="display: flex; gap: 0.5rem; align-items: center;"><input type="search" id="reservation-search" class="form-input form-input--sm" placeholder="Search guests or refs..." style="max-width: 250px; padding: 0.5rem 0.75rem;"><button class="btn btn--ghost btn--sm" id="refresh-reservations" type="button">Refresh</button><button class="btn btn--ghost btn--sm" type="button" onclick="window.print()">Print List</button><?php if ($role === 'admin'): ?><a class="btn btn--primary btn--sm" href="/api/admin/report.csv.php">Download CSV report</a><?php endif; ?></div></header>
      <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Stay</th><th>Guest</th><th>Package</th><th>Status</th><th>Payment</th><th>Front desk</th></tr></thead><tbody id="reservation-list"><tr><td colspan="6">Loading reservations...</td></tr></tbody></table></div>
    </section>
    <section class="staff-panel staff-panel--wide">
      <header class="staff-panel__header"><div><h2>Payment ledger</h2></div></header>
      <div class="staff-table-wrap"><table class="staff-table"><thead><tr><th>Date</th><th>Reference</th><th>Amount</th><th>Method</th><th>Source</th><th>Recorded by</th></tr></thead><tbody id="ledger-list"><tr><td colspan="6">Loading ledger...</td></tr></tbody></table></div>
    </section>
    <?php if ($role === 'admin'): ?>
    <section class="staff-panel staff-panel--wide">
      <header class="staff-panel__header"><div><h2>Google Calendar</h2><p class="staff-hint" id="calendar-status">Checking connection...</p></div><div class="staff-panel__tools"><a class="btn btn--ghost btn--sm" href="?action=google-connect">Connect Google account</a><button class="btn btn--primary btn--sm" id="sync-calendar" type="button">Sync reservations</button></div></header>
      <p class="staff-hint">Calendar sync is optional and only runs after the owner grants Google Calendar access. Events contain reservation references and package names; guest contact details are not sent.</p>
    </section>
    <?php endif; ?>
    <section class="staff-panel staff-panel--wide">
      <header class="staff-panel__header"><div><h2>Record an offline payment</h2></div></header>
      <form id="payment-form" class="staff-inline-form">
        <label class="sr-only" for="payment-reservation">Reservation</label><select class="form-input" id="payment-reservation" name="reservation_id" required><option value="">Choose reservation</option></select>
        <label class="sr-only" for="payment-amount">Amount received</label><input class="form-input" id="payment-amount" name="amount" type="number" min="0.01" step="0.01" placeholder="Amount received" required>
        <label class="sr-only" for="payment-method">Method</label><select class="form-input" id="payment-method" name="method" required><?php foreach (\App\Helpers\Config::get('payment_methods', []) as $method): ?><option value="<?php echo htmlspecialchars($method['code']); ?>"><?php echo htmlspecialchars($method['label']); ?></option><?php endforeach; ?></select>
        <input class="form-input" id="payment-note" name="note" maxlength="300" placeholder="Optional note">
        <button class="btn btn--primary" type="submit">Record payment</button>
      </form>
      <p class="staff-hint">This records an offline payment reported by staff; no card, e-wallet, or bank gateway is connected.</p>
    </section>
    <p class="staff-demo-note">School project demo only. Use test guest details and do not enter real payment credentials.</p>

    <!-- Reject Modal -->
    <div class="modal-overlay" id="reject-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
      <div class="modal-content">
        <header class="modal-header">
          <h2 id="reject-modal-title" class="modal-title">Reject Payment Proof</h2>
          <button class="modal-close" type="button" aria-label="Close modal" id="reject-modal-close">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" x2="6" y1="6" y2="18"></line><line x1="6" x2="18" y1="6" y2="18"></line></svg>
          </button>
        </header>
        <div class="modal-body">
          <p>Please provide a reason for rejecting this receipt (optional):</p>
          <textarea id="reject-reason" class="form-input" rows="3" style="width: 100%; margin-top: 1rem; margin-bottom: 1rem;"></textarea>
          <div class="modal-footer" style="padding-bottom: 0;">
            <button class="btn btn--primary btn--full" id="confirm-reject-btn">Confirm Rejection</button>
            <button class="btn btn--ghost btn--full" id="cancel-reject-btn" type="button">Cancel</button>
          </div>
        </div>
      </div>
    </div>
  </main>
  <script src="<?php echo \App\Helpers\Assets::url('/assets/js/dashboard.js'); ?>"></script>
</body>
</html>
