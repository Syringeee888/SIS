<?php
// Demo staff sign-in. Credentials are intentionally local-project defaults.
$csrfToken = \App\Services\CsrfProtection::token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff sign in | Verde Royale Resort</title>
  <link rel="stylesheet" href="<?php echo \App\Helpers\Assets::url('/assets/css/style.css'); ?>">
</head>
<body>
  <main id="main-content" class="container" style="max-width: 32rem; padding-block: 10vh;">
    <a href="?action=home">&larr; Back to resort site</a>
    <h1 style="margin-top: 2rem;">Staff sign in</h1>
    <p>Authorized personnel only. Please sign in to access the front desk management system.</p>
    <p class="staff-hint">Staff accounts are configured in the private local `.env`; see Step 3 in the README. Passwords are stored as hashes and are not included in the repository.</p>
    <form id="staff-login-form" data-csrf="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <div class="form-group"><label class="form-label" for="staff-username">Username</label><input class="form-input" id="staff-username" name="username" required autocomplete="username"></div>
      <div class="form-group"><label class="form-label" for="staff-password">Password</label><input class="form-input" id="staff-password" name="password" type="password" required autocomplete="current-password"></div>
      <p id="login-message" role="alert" aria-live="polite"></p>
      <button class="btn btn--primary btn--lg" type="submit">Sign in</button>
    </form>
    <script src="<?php echo \App\Helpers\Assets::url('/assets/js/staff.js'); ?>"></script>
  </main>
</body>
</html>
