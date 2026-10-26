(() => {
  'use strict';
  const form = document.getElementById('staff-login-form');
  if (!form) return;
  const message = document.getElementById('login-message');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    message.textContent = 'Signing in…';
    const data = new FormData(form);
    try {
      const response = await fetch('/api/auth/login.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': form.dataset.csrf },
        body: JSON.stringify({ username: data.get('username'), password: data.get('password') }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body.message || 'Sign-in failed.');
      window.location.assign(body.data.redirect);
    } catch (error) {
      message.textContent = error.message || 'Could not contact the server.';
    }
  });
})();
