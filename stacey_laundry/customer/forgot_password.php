<?php
require_once __DIR__ . '/../app/customer_helpers.php';

if (currentCustomer()) {
    redirect('dashboard.php');
}

$generatedResetUrl = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $email = strtolower(safePost('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, account_status FROM customer_accounts WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $acc = $stmt->fetch();

        if ($acc && $acc['account_status'] === 'active') {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

            $pdo->prepare('DELETE FROM customer_password_resets WHERE customer_account_id = ? AND used_at IS NULL')->execute([(int) $acc['id']]);
            $pdo->prepare('INSERT INTO customer_password_resets (customer_account_id, token_hash, expires_at) VALUES (?, ?, ?)')->execute([
                (int) $acc['id'],
                $tokenHash,
                $expiresAt,
            ]);
            // Note: External SMTP mail delivery is not configured in local XAMPP.
            // Store the test reset link in flash session for local verification only.
            $_SESSION['local_reset_preview'] = 'reset_password.php?token=' . urlencode($token);
        }

        bootMessage('information', 'If an active account matches that email, a password reset link has been generated (valid for 30 minutes).');
        redirect('forgot_password.php');
    } catch (Throwable $e) {
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_forgot_password');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to process request right now.');
        redirect('forgot_password.php');
    }
}

$resetPreview = $_SESSION['local_reset_preview'] ?? null;
unset($_SESSION['local_reset_preview']);
$notifications = flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | <?= h(APP_NAME) ?></title>
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
        <span class="cp-pink-pill-badge">Reset Password</span>
      </div>
      <p class="cp-pink-subtitle">Enter your registered email address to request a password reset.</p>

      <?php if ($resetPreview): ?>
        <div style="padding:12px 14px; border-radius:10px; background:#fdf2f8; border:1px solid #f9a8d4; margin-bottom:16px; font-size:0.85rem;">
          <strong>Local Dev Mode (No SMTP Configured):</strong><br>
          <a href="<?= h($resetPreview) ?>" style="color:#be185d; font-weight:700;">Click here to proceed to Password Reset</a>
        </div>
      <?php endif; ?>

      <form method="post" class="cp-prevent-double cp-confirm-form" data-confirm-title="Send Password Reset?" data-confirm="Send a password reset link request for this email address?" data-confirm-label="Send Reset Link">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <div class="cp-form-group">
          <label class="cp-pink-label" for="email">Registered Email Address</label>
          <input class="cp-pink-input" id="email" type="email" name="email" required autofocus>
        </div>
        <button type="submit" class="cp-pink-submit" data-loading-label="Generating link...">Send Reset Link</button>
      </form>

      <div class="cp-pink-footer">
        <a href="login.php">Back to Sign In</a>
      </div>
    </div>
  </main>
  <?php renderCustomerToasts($notifications); ?>
  <script src="../../public/assets/js/customer/customer-portal.js"></script>
</body>
</html>
