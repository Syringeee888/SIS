(() => {
  'use strict';

  const list = document.getElementById('proof-list');
  const refresh = document.getElementById('refresh-proofs');
  const message = document.getElementById('staff-message');
  const modal = document.getElementById('reject-modal');
  const reason = document.getElementById('reject-reason');
  const confirmReject = document.getElementById('confirm-reject-btn');
  let csrfToken = '';
  let rejectingProofId = null;

  if (!list) return;

  async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || body.success === false) {
      throw new Error(body.message || `Request failed (${response.status}).`);
    }
    return body.data;
  }

  function setMessage(text, isError = false) {
    if (!message) return;
    message.textContent = text;
    message.setAttribute('role', isError ? 'alert' : 'status');
  }

  function textElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    element.textContent = text;
    return element;
  }

  function closeRejectModal() {
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    rejectingProofId = null;
  }

  async function review(proofId, decision, note = '') {
    await request('/api/payment/proof/review.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ proof_id: proofId, decision, note }),
    });
    setMessage(decision === 'approve' ? 'Receipt approved.' : 'Receipt rejected.');
    await loadProofs();
  }

  function renderProof(proof) {
    const card = document.createElement('article');
    card.className = 'payment-proof-card';

    const heading = document.createElement('header');
    heading.append(textElement('strong', '', proof.reservation_reference || `Reservation ${proof.reservation_id}`));
    heading.append(textElement('span', '', `${proof.status} · ₱${Number(proof.amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`));
    card.append(heading);

    const image = document.createElement('img');
    image.src = `/api/payment/proof/file.php?id=${encodeURIComponent(proof.id)}`;
    image.alt = `Receipt for ${proof.reservation_reference || 'reservation'}`;
    image.loading = 'lazy';
    image.style.cssText = 'display:block; max-width:100%; max-height:360px; object-fit:contain; margin:0.75rem auto; border:1px solid #d8e0dc; border-radius:8px;';
    image.addEventListener('error', () => {
      image.remove();
      card.append(textElement('p', 'staff-hint', 'The receipt record exists, but its image file could not be loaded.'));
    }, { once: true });
    card.append(image);

    const details = textElement('p', 'staff-hint', `${proof.original_name || 'Receipt image'} · ${proof.created_at || ''}`);
    card.append(details);

    if (proof.status === 'pending') {
      const actions = document.createElement('div');
      actions.className = 'payment-proof-actions';
      const approve = textElement('button', 'btn btn--primary', 'Approve receipt');
      approve.type = 'button';
      approve.addEventListener('click', async () => {
        approve.disabled = true;
        try { await review(proof.id, 'approve'); }
        catch (error) { setMessage(error.message, true); approve.disabled = false; }
      });
      const reject = textElement('button', 'btn btn--ghost', 'Reject receipt');
      reject.type = 'button';
      reject.addEventListener('click', () => {
        rejectingProofId = Number(proof.id);
        if (modal) {
          modal.style.display = 'flex';
          modal.setAttribute('aria-hidden', 'false');
          if (reason) reason.value = '';
          reason?.focus();
        }
      });
      actions.append(approve, reject);
      card.append(actions);
    }

    return card;
  }

  async function loadProofs() {
    list.replaceChildren(textElement('p', '', 'Loading receipts…'));
    try {
      const data = await request('/api/payment/proof/list.php?status=pending');
      const proofs = Array.isArray(data.proofs) ? data.proofs : [];
      if (!proofs.length) {
        list.replaceChildren(textElement('p', 'staff-hint', 'No receipts have been submitted for review yet. Guests can upload a receipt from the Manage page.'));
        return;
      }
      list.replaceChildren(...proofs.map(renderProof));
    } catch (error) {
      list.replaceChildren(textElement('p', 'staff-hint', `Could not load receipts: ${error.message}`));
    }
  }

  refresh?.addEventListener('click', loadProofs);
  document.getElementById('reject-modal-close')?.addEventListener('click', closeRejectModal);
  document.getElementById('cancel-reject-btn')?.addEventListener('click', closeRejectModal);
  modal?.addEventListener('click', (event) => { if (event.target === modal) closeRejectModal(); });
  confirmReject?.addEventListener('click', async () => {
    if (!rejectingProofId) return;
    confirmReject.disabled = true;
    try {
      await review(rejectingProofId, 'reject', reason?.value || '');
      closeRejectModal();
    } catch (error) {
      setMessage(error.message, true);
    } finally {
      confirmReject.disabled = false;
    }
  });

  request('/api/csrf-token.php')
    .then((data) => { csrfToken = data.csrf_token || ''; })
    .catch((error) => setMessage(error.message, true));
  loadProofs();
})();
