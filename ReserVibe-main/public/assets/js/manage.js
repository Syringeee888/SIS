(() => {
  'use strict';
  const $ = (selector) => document.querySelector(selector);
  const message = $('#manage-message');
  const rescheduleFlashKey = 'reservibe-reschedule-success';
  let csrf = '';
  const say = (text, kind = 'error') => {
    message.textContent = text;
    message.className = `alert alert-${kind}`;
    message.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  try {
    const rescheduleFlash = sessionStorage.getItem(rescheduleFlashKey);
    if (rescheduleFlash) {
      sessionStorage.removeItem(rescheduleFlashKey);
      say(rescheduleFlash, 'success');
    }
  } catch (_) {
    // The in-page confirmation still works if browser storage is unavailable.
  }
  async function fetchData(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || `Request failed (${response.status}).`);
    return body.data;
  }
  fetchData('/api/csrf-token.php').then((data) => { csrf = data.csrf_token; }).catch((error) => say(error.message));

  // FIX: keep the form in a variable. event.currentTarget is null after an await.
  $('#proof-upload-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const result = await fetchData('/api/payment/proof/submit.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: new FormData(form) });
      say(result.message, 'success');
      form.reset();
    }
    catch (error) { say(error.message); }
    finally { button.disabled = false; }
  });

  $('#manage-reservation-form').addEventListener('submit', async (event) => {
    event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    if (!data.check_in || !data.check_out) { say('Choose both new stay dates.'); return; }
    try {
      const result = await fetchData('/api/reservation/manage.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ ...data, action: 'reschedule' }) });
      const successMessage = `Reschedule successful! New estimated amount: ₱${Number(result.quote.total).toLocaleString('en-PH', { minimumFractionDigits: 2 })}.`;
      say(successMessage, 'success');
      try { sessionStorage.setItem(rescheduleFlashKey, successMessage); } catch (_) {}
      window.setTimeout(() => window.location.reload(), 1800);
    }
    catch (error) { say(error.message); }
  });

  $('#guest-cancel').addEventListener('click', async () => {
    const reference = $('#manage-reference').value.trim(); const email = $('#manage-email').value.trim();
    if (!reference || !email) { say('Enter the reservation reference and booking email first.'); return; }
    if (!window.confirm('Cancel this reservation request? The demo will record the cancellation; confirm real resort policies directly.')) return;
    try { const result = await fetchData('/api/reservation/manage.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ action: 'cancel', reservation_reference: reference, guest_email: email }) }); say(result.message, 'success'); }
    catch (error) { say(error.message); }
  });
})();