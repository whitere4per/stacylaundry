<?php
require_once __DIR__ . '/../app/customer_helpers.php';

if (currentCustomer()) {
    redirect('dashboard.php');
}

$regToken = issueFormToken('customer_register');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $txStarted = false;
    try {
        verifyCsrf();
        verifyFormToken('customer_register');

        $fullName = safePost('full_name');
        $phone = safePost('contact_number');
        $email = strtolower(safePost('email'));
        $address = safePost('address');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($fullName === '' || mb_strlen($fullName) > 120) {
            throw new InvalidArgumentException('Enter your full name (max 120 characters).');
        }
        if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) || !preg_match('/\d/', $phone)) {
            throw new InvalidArgumentException('Enter a valid phone number.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if ($address === '') {
            throw new InvalidArgumentException('Enter your home or delivery address.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new InvalidArgumentException('Password and confirmation do not match.');
        }

        $emailCheck = $pdo->prepare('SELECT id FROM customer_accounts WHERE email = ? LIMIT 1');
        $emailCheck->execute([$email]);
        if ($emailCheck->fetchColumn()) {
            throw new InvalidArgumentException('This email address is already registered.');
        }

        $normalized = normalizePhone($phone);
        $pdo->beginTransaction();
        $txStarted = true;

        $existingCustStmt = $pdo->prepare(
            'SELECT id FROM customers
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", "") = ?
             LIMIT 1 FOR UPDATE'
        );
        $existingCustStmt->execute([$normalized]);
        $existingCustomerId = $existingCustStmt->fetchColumn();

        if ($existingCustomerId) {
            $linkedStmt = $pdo->prepare('SELECT id FROM customer_accounts WHERE customer_id = ? LIMIT 1');
            $linkedStmt->execute([(int) $existingCustomerId]);
            if ($linkedStmt->fetchColumn()) {
                throw new InvalidArgumentException('An account with this phone number already exists. Please sign in or reset your password.');
            }
            $customerId = (int) $existingCustomerId;
            // Keep existing profile linked without duplicating customer rows
            $linkedMessage = 'Account created and linked to your existing Stacey Laundry Hub customer profile!';
        } else {
            $insCust = $pdo->prepare('INSERT INTO customers (full_name, contact_number, address, registration_date) VALUES (?, ?, ?, CURDATE())');
            $insCust->execute([$fullName, $phone, $address]);
            $customerId = (int) $pdo->lastInsertId();
            $linkedMessage = 'Your Customer Portal account has been created!';
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insAcc = $pdo->prepare('INSERT INTO customer_accounts (customer_id, email, password_hash, account_status) VALUES (?, ?, ?, "active")');
        $insAcc->execute([$customerId, $email, $hash]);

        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')->execute([
            'Customer account registered',
            'Customer ID: ' . $customerId . '; Email: ' . $email,
        ]);

        $pdo->commit();
        $txStarted = false;
        rotateFormToken('customer_register');
        bootMessage('success', $linkedMessage . ' You can now sign in.');
        redirect('login.php');
    } catch (Throwable $e) {
        if ($txStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        preserveFormInput('customer_register', ['full_name', 'contact_number', 'email', 'address']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_register');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to register right now. Please try again.');
        redirect('register.php');
    }
}

$old = takeFormInput('customer_register');
$notifications = flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Customer Account | <?= h(APP_NAME) ?></title>
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
    <div class="cp-pink-card" style="width:min(520px, 100%);">
      <div class="cp-pink-badge-row">
        <span class="cp-pink-pill-badge">Create Account</span>
      </div>
      <p class="cp-pink-subtitle">Already dropped off laundry before? Use the same phone number to link your existing orders.</p>

      <form method="post" class="cp-prevent-double cp-confirm-form" data-confirm-title="Create Customer Account?" data-confirm="Create this Stacey Laundry Hub customer account with the information entered?" data-confirm-label="Create Account">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="request_token" value="<?= h($regToken) ?>">

        <div class="cp-form-group">
          <label class="cp-pink-label" for="full_name">Full Name</label>
          <input class="cp-pink-input" id="full_name" name="full_name" maxlength="120" value="<?= h($old['full_name'] ?? '') ?>" required>
        </div>
        <div class="cp-grid-2" style="gap:12px;">
          <div class="cp-form-group">
            <label class="cp-pink-label" for="contact_number">Phone Number</label>
            <input class="cp-pink-input" id="contact_number" type="tel" name="contact_number" maxlength="30" value="<?= h($old['contact_number'] ?? '') ?>" placeholder="09123456789" required>
          </div>
          <div class="cp-form-group">
            <label class="cp-pink-label" for="email">Email Address</label>
            <input class="cp-pink-input" id="email" type="email" name="email" maxlength="254" value="<?= h($old['email'] ?? '') ?>" required>
          </div>
        </div>
        <div class="cp-form-group">
          <label class="cp-pink-label" for="address">Home / Delivery Address</label>
          <textarea class="cp-pink-input" id="address" name="address" rows="2" required><?= h($old['address'] ?? '') ?></textarea>
        </div>
        <div class="cp-grid-2" style="gap:12px;">
          <div class="cp-form-group">
            <label class="cp-pink-label" for="password">Password (min 8 chars)</label>
            <input class="cp-pink-input" id="password" type="password" name="password" minlength="8" required>
          </div>
          <div class="cp-form-group">
            <label class="cp-pink-label" for="confirm_password">Confirm Password</label>
            <input class="cp-pink-input" id="confirm_password" type="password" name="confirm_password" minlength="8" required>
          </div>
        </div>
        <button type="submit" class="cp-pink-submit" data-loading-label="Creating account...">Create Account</button>
      </form>

      <div class="cp-pink-footer">
        Already have an account? <a href="login.php">Sign in</a>
      </div>
    </div>
  </main>
  <?php renderCustomerToasts($notifications); ?>
  <script src="../../public/assets/js/customer/customer-portal.js"></script>
</body>
</html>
