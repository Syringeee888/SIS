// public/assets/js/booking.js
//
// Drives the guest booking flow against the real API:
//
//   Proceed  -> POST /api/hold/create.php        (atomic slot hold, 90 min)
//   Confirm  -> POST /api/reservation/create.php (hold becomes a reservation)
//   Cancel   -> POST /api/hold/release.php       (slot handed straight back)
//
// Prices shown here are an estimate for the guest's benefit. The server never
// trusts them: it prices the booking itself from the catalogue.

(() => {
  'use strict';

  const SYMBOL = document.body.dataset.currency || 'PHP';
  // PHP renders the configured deposit percentage onto <body>; this is only for
  // the guest's on-screen estimate. The server prices the booking itself.
  const DEPOSIT_RATE = parseFloat(document.body.dataset.depositRate) || 0.5;
  const money = (n) => SYMBOL + Number(n).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  const $ = (id) => document.getElementById(id);

  const dateInput = $('booking-date');
  const checkoutInput = $('booking-checkout');
  const accommodationSelect = $('accommodation');
  const addonBoxes = Array.from(document.querySelectorAll('.addon'));
  const methodRadios = Array.from(document.querySelectorAll('.method'));
  const methodNote = $('method-note');
  const formAlert = $('form-alert');
  const modal = $('payment-modal');
  const modalAlert = $('modal-alert');
  const modalMethod = $('modal-method');
  const modalInstructions = $('modal-instructions');
  const arrivalNote = $('arrival-note');
  const qrBox = $('qr-box');
  const countdownEl = $('hold-countdown');
  const bookBtn = $('book-btn');
  const confirmBtn = $('confirm-btn');
  const cancelBtn = $('cancel-btn');

  let csrfToken = null;
  let activeHold = null;
  let ticker = null;

  // ---------------------------------------------------------------- API glue
  async function token() {
    if (csrfToken) return csrfToken;

    const response = await fetch('/api/csrf-token.php', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const body = await response.json().catch(() => null);
    csrfToken = body && body.data ? body.data.csrf_token : null;

    return csrfToken;
  }

  async function api(path, payload) {
    const headers = { Accept: 'application/json' };

    if (payload) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = await token();
    }

    const response = await fetch(path, {
      method: payload ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers,
      body: payload ? JSON.stringify(payload) : undefined,
    });

    const body = await response.json().catch(() => null);

    return { ok: response.ok, status: response.status, body };
  }

  function errorText(result, fallback) {
    const body = result.body;

    if (body && body.errors && typeof body.errors === 'object') {
      const first = Object.values(body.errors)[0];
      if (typeof first === 'string') return first;
    }

    return (body && body.message) || fallback;
  }

  // ------------------------------------------------------------------- alerts
  function alertBox(el, message, kind) {
    if (!el) return;
    el.textContent = message;
    el.className = 'alert ' + (kind === 'error' ? 'alert-error' : 'alert-success');
  }

  function clearAlert(el) {
    if (!el) return;
    el.textContent = '';
    el.className = 'alert hidden';
  }

  // ------------------------------------------------------------ cost display
  function calculateTotal() {
    if (!accommodationSelect) return;

    const baseCost = parseFloat(accommodationSelect.value) || 0;
    const addonCost = addonBoxes.reduce(
      (sum, box) => sum + (box.checked ? parseFloat(box.value) || 0 : 0),
      0
    );
    const total = baseCost + addonCost;
    const deposit = Math.round(total * DEPOSIT_RATE * 100) / 100;

    if ($('display-base')) $('display-base').textContent = money(baseCost);
    if ($('display-addons')) $('display-addons').textContent = money(addonCost);
    if ($('display-total')) $('display-total').textContent = money(total);
    if ($('display-deposit')) $('display-deposit').textContent = money(deposit);
    if ($('modal-deposit-amount')) $('modal-deposit-amount').textContent = money(deposit);
  }

  // --------------------------------------------------------- payment methods
  function selectedMethod() {
    return methodRadios.find((radio) => radio.checked) || null;
  }

  function syncMethodUi() {
    const method = selectedMethod();

    methodRadios.forEach((radio) => {
      radio.closest('.method-card').classList.toggle('is-selected', radio.checked);
    });

    if (methodNote && method) {
      methodNote.textContent = method.dataset.instructions || '';
    }

    return method;
  }

  methodRadios.forEach((radio) => radio.addEventListener('change', syncMethodUi));

  // -------------------------------------------------------------- hold timer
  function startCountdown(seconds) {
    stopCountdown();
    let remaining = seconds;

    const paint = () => {
      const mins = Math.floor(remaining / 60);
      const secs = remaining % 60;
      if (countdownEl) {
        countdownEl.textContent = mins + ':' + String(secs).padStart(2, '0');
      }
    };

    paint();
    ticker = window.setInterval(() => {
      remaining -= 1;
      paint();

      if (remaining <= 0) {
        stopCountdown();
        if (countdownEl) countdownEl.textContent = 'expired';
        alertBox(modalAlert, 'Your hold has expired. Cancel and start again to re-check availability.', 'error');
        if (confirmBtn) confirmBtn.disabled = true;
      }
    }, 1000);
  }

  function stopCountdown() {
    if (ticker) {
      window.clearInterval(ticker);
      ticker = null;
    }
  }

  // -------------------------------------------------------------------- modal
  function openModal() {
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  function paintMethodPanel() {
    const method = selectedMethod();
    if (!method) return;

    const proof = method.dataset.proof === '1';
    const arrival = method.dataset.arrival === '1';

    if (modalMethod) modalMethod.textContent = method.dataset.label || '';
    if (modalInstructions) modalInstructions.textContent = method.dataset.instructions || '';

    if (qrBox) {
      const qr = method.dataset.qr || '';
      qrBox.textContent = '';
      if (proof && qr) {
        const img = document.createElement('img');
        img.src = qr;
        img.alt = (method.dataset.label || 'Payment') + ' QR code';
        qrBox.appendChild(img);
      }
      qrBox.classList.toggle('hidden', !(proof && qr));
    }

    if (arrivalNote) {
      arrivalNote.textContent = arrival
        ? 'No payment is collected online for this method — the front desk settles it on arrival.'
        : '';
      arrivalNote.classList.toggle('hidden', !arrival);
    }
  }

  // ------------------------------------------------------------------ actions
  function readForm() {
    const method = selectedMethod();

    return {
      accommodation_id: parseInt(accommodationSelect.value && accommodationSelect.selectedOptions[0].dataset.id, 10),
      check_in: dateInput.value,
      check_out: checkoutInput.value,
      addons: addonBoxes.filter((box) => box.checked).map((box) => box.dataset.code),
      guest_name: $('guest-name').value.trim(),
      guest_email: $('guest-email').value.trim(),
      guest_phone: $('guest-phone').value.trim(),
      guests: parseInt($('guest-count') ? $('guest-count').value : '1', 10),
      payment_method: method ? method.value : '',
      agree_terms: $('agree-terms') ? $('agree-terms').checked : false,
    };
  }

  function validate(form) {
    if (!form.check_in) return 'Please pick a check-in date.';
    if (!form.check_out) return 'Please pick a check-out date.';
    if (form.check_out <= form.check_in) return 'Check-out must be after check-in.';
    if (!form.guest_name) return 'Please enter the guest name.';
    if (!form.guest_email) return 'Please enter an email address.';
    if (!form.guest_phone) return 'Please enter a mobile number.';
    if (!form.payment_method) return 'Please choose a payment method.';
    if (!form.agree_terms) return 'You must agree to the House Rules and Cancellation Policy.';

    return null;
  }

  async function onProceed() {
    clearAlert(formAlert);

    // If a hold is already live, reuse it instead of asking for another.
    if (activeHold) {
      paintMethodPanel();
      openModal();
      return;
    }

    const form = readForm();
    const problem = validate(form);

    if (problem) {
      alertBox(formAlert, problem, 'error');
      return;
    }

    bookBtn.disabled = true;
    bookBtn.textContent = 'Checking availability…';

    const result = await api('/api/hold/create.php', {
      accommodation_id: form.accommodation_id,
      check_in: form.check_in,
      check_out: form.check_out,
      guest_name: form.guest_name,
      guest_contact: form.guest_phone,
        guests: form.guests,
    });

    bookBtn.disabled = false;
    bookBtn.textContent = 'Proceed to Payment';

    if (!result.ok) {
      alertBox(formAlert, errorText(result, 'That slot could not be held. Please try other dates.'), 'error');
      return;
    }

    activeHold = result.body.data.hold;

    if (confirmBtn) confirmBtn.disabled = false;
    if (modalAlert) clearAlert(modalAlert);
    if (arrivalNote) arrivalNote.classList.add('hidden');

    paintMethodPanel();
    startCountdown(activeHold.remaining_seconds || 5400);
    openModal();
  }

  async function onConfirm() {
    if (!activeHold) return;

    clearAlert(modalAlert);
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Saving…';

    const form = readForm();

    const result = await api('/api/reservation/create.php', {
      hold_id: activeHold.id,
      guest_name: form.guest_name,
      guest_email: form.guest_email,
      guest_phone: form.guest_phone,
      guests: form.guests,
      addons: form.addons,
      payment_method: form.payment_method,
      agree_terms: form.agree_terms,
      notes: $('notes') ? $('notes').value : '',
    });

    confirmBtn.textContent = 'Confirm Demo Reservation';

    if (!result.ok) {
      confirmBtn.disabled = false;
      alertBox(modalAlert, errorText(result, 'The booking could not be saved.'), 'error');
      return;
    }

    const reservation = result.body.data.reservation;
    stopCountdown();

    window.location.href = '?action=confirmation&ref=' + reservation.reference;

    if (cancelBtn) cancelBtn.classList.add('hidden');
    if (countdownEl) countdownEl.textContent = 'confirmed';
    activeHold = null;
  }

  async function onCancel() {
    if (!activeHold) {
      closeModal();
      return;
    }

    cancelBtn.disabled = true;

    const result = await api('/api/hold/release.php', { hold_id: activeHold.id });

    cancelBtn.disabled = false;
    activeHold = null;
    stopCountdown();

    if (result.ok) {
      closeModal();
      alertBox(formAlert, 'Booking cancelled — the slot has been released.', 'success');
    } else {
      alertBox(modalAlert, errorText(result, 'The hold could not be released.'), 'error');
    }
  }

  // ------------------------------------------------------------------ wiring
  if (dateInput) {
    const today = new Date();
    dateInput.valueAsDate = today;
    dateInput.min = today.toISOString().split('T')[0];

    dateInput.addEventListener('change', () => {
      if (checkoutInput) {
        const next = new Date(dateInput.value);
        next.setDate(next.getDate() + 1);
        checkoutInput.min = next.toISOString().split('T')[0];
        if (!checkoutInput.value || checkoutInput.value <= dateInput.value) {
          checkoutInput.value = next.toISOString().split('T')[0];
        }
      }
    });
  }

  if (checkoutInput && dateInput && dateInput.value) {
    const next = new Date(dateInput.value);
    next.setDate(next.getDate() + 1);
    checkoutInput.value = next.toISOString().split('T')[0];
    checkoutInput.min = checkoutInput.value;
  }

  if (accommodationSelect) accommodationSelect.addEventListener('change', calculateTotal);
  addonBoxes.forEach((box) => box.addEventListener('change', calculateTotal));

  if (bookBtn) bookBtn.addEventListener('click', onProceed);
  if (confirmBtn) confirmBtn.addEventListener('click', onConfirm);
  if (cancelBtn) cancelBtn.addEventListener('click', onCancel);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal && modal.style.display === 'flex') {
      // Closing keeps the hold alive — Cancel is the button that releases it.
      closeModal();
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    calculateTotal();
    syncMethodUi();
  });

  if (document.readyState !== 'loading') {
    calculateTotal();
    syncMethodUi();
  }
})();