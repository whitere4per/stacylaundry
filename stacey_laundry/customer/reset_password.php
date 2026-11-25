<?php
require_once __DIR__ . '/../app/customer_helpers.php';

if (currentCustomer()) {
    redirect('dashboard.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenValid = false;
$resetRow = null;

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare(
        'SELECT cpr.id, cpr.customer_account_id, cpr.expires_at, cpr.used_at, ca.account_status
         FROM customer_password_resets cpr
         JOIN customer_accounts ca ON ca.id = cpr.customer_account_id
         WHERE cpr.token_hash = ? LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $resetRow = $stmt->fetch();
    if ($resetRow && $resetRow['used_at'] === null && $resetRow['account_status'] === 'active' && strtotime($resetRow['expires_at']) > time()) {
        $tokenValid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $txStarted = false;
    try {
        verifyCsrf();
        if (!$tokenValid || !$resetRow) {
            throw new InvalidArgumentException('This password reset link is invalid or has expired.');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters long.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new InvalidArgumentException('Password and confirmation do not match.');
        }

        $pdo->beginTransaction();
        $txStarted = true;
        $pdo->prepare('UPDATE customer_accounts SET password_hash = ? WHERE id = ?')->execute([
            password_hash($password, PASSWORD_DEFAULT),
            (int) $resetRow['customer_account_id'],
        ]);
        $pdo->prepare('UPDATE customer_password_resets SET used_at = NOW() WHERE id = ?')->execute([
            (int) $resetRow['id'],
        ]);
        $pdo->commit();
        $txStarted = false;

        bootMessage('success', 'Your password has been updated. You may now sign in.');
        redirect('login.php');
    } catch (Throwable $e) {
        if ($txStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_reset_password');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to reset password right now.');
        redirect('reset_password.php?token=' . urlencode($token));
    }
}

$notifications = flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set New Password | <?= h(APP_NAME) ?></title>
  <link rel="stylesheet" href="../../public/assets/css/customer/customer-portal.css">
</head>
<body class="cp-auth-body">
  <main class="cp-pink-wrap" style="grid-template-columns:1fr; justify-items:center;">
    <svg class="cp-pink-wave-top" viewBox="0 0 1440 120" preserveAspectRatio="none" height="90" aria-hidden="true">
      <path d="M0,64 C320,120 620,0 1080,64 C1280,92 1380,48 1440,32 L1440,0 L0,0 Z" fill="rgba(244,63,142,0.18)"/>
    </svg>
    <svg class="cp-pink-wave-bottom" viewBox="0 0 1440 140" preserveAspectRatio="none" height="110" aria-hidden="true">
      <path d="M0,48 C360,130 840,10 1440,84 L1440,140 L0,140 Z" fill="rgba(244,63,142,0.22)"/>
    </svg>
    <div class="cp-pink-card" style="width:min(440px, 100%);">
      <div class="cp-pink-badge-row">
        <span class="cp-pink-pill-badge">Set New Password</span>
      </div>
      <p class="cp-pink-subtitle">Choose a strong password (at least 8 characters).</p>

      <?php if (!$tokenValid): ?>
        <div class="cp-empty">
          <strong>Invalid or Expired Link</strong>
          <p>Please request a new password reset link.</p>
          <a href="forgot_password.php" class="cp-pink-submit" style="margin-top:8px;">Request New Link</a>
        </div>
      <?php else: ?>
        <form method="post" class="cp-prevent-double cp-confirm-form" data-confirm-title="Reset Password?" data-confirm="Replace the account password with the new password entered?" data-confirm-label="Reset Password" data-confirm-type="warning">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="cp-form-group">
            <label class="cp-pink-label" for="password">New Password</label>
            <input class="cp-pink-input" id="password" type="password" name="password" minlength="8" required autofocus>
          </div>
          <div class="cp-form-group">
            <label class="cp-pink-label" for="confirm_password">Confirm New Password</label>
            <input class="cp-pink-input" id="confirm_password" type="password" name="confirm_password" minlength="8" required>
          </div>
          <button type="submit" class="cp-pink-submit" data-loading-label="Updating...">Update Password</button>
        </form>
      <?php endif; ?>

      <div class="cp-pink-footer">
        <a href="login.php">Back to Sign In</a>
      </div>
    </div>
  </main>
  <?php renderCustomerToasts($notifications); ?>
  <script src="../../public/assets/js/customer/customer-portal.js"></script>
</body>
</html>
