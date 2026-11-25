<?php
require_once __DIR__ . '/../app/customer_helpers.php';

if (currentCustomer()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = safePost('identifier');
    try {
        verifyCsrf();
        $password = (string) ($_POST['password'] ?? '');
        if ($identifier === '' || $password === '') {
            $_SESSION['cust_login_id'] = $identifier;
            bootMessage('error', 'Enter your email or phone number and password.');
            redirect('login.php');
        }

        $pdo = db();
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $escapedId = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $identifier);
        $escapedIp = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $clientIp);
        $rateStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'Customer login failed'
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)
               AND (details LIKE ? OR details LIKE ?)"
        );
        $rateStmt->execute(['Identifier: ' . $escapedId . ';%', '%IP: ' . $escapedIp]);
        if ((int) $rateStmt->fetchColumn() >= 5) {
            $_SESSION['cust_login_id'] = $identifier;
            bootMessage('error', 'Too many failed login attempts. Please wait 15 minutes before trying again.');
            redirect('login.php');
        }

        $normalizedPhone = normalizePhone($identifier);
        $stmt = $pdo->prepare(
            'SELECT ca.id AS account_id, ca.customer_id, ca.email, ca.password_hash, ca.account_status,
                    ca.profile_picture, ca.created_at AS account_created_at,
                    c.full_name, c.contact_number, c.address, c.registration_date
             FROM customer_accounts ca
             JOIN customers c ON c.id = ca.customer_id
             WHERE ca.email = ? OR REPLACE(REPLACE(REPLACE(REPLACE(c.contact_number, " ", ""), "-", ""), "(", ""), ")", "") = ?
             LIMIT 1'
        );
        $stmt->execute([$identifier, $normalizedPhone]);
        $row = $stmt->fetch();

        if ($row && $row['account_status'] === 'active' && password_verify($password, $row['password_hash'])) {
            $pdo->prepare('UPDATE customer_accounts SET last_login = NOW() WHERE id = ?')->execute([(int) $row['account_id']]);
            $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')->execute([
                'Customer login successful',
                'Customer ID: ' . $row['customer_id'] . '; IP: ' . $clientIp,
            ]);

            session_regenerate_id(true);
            unset($row['password_hash']);
            $_SESSION['customer'] = $row;
            unset($_SESSION['cust_login_id'], $_SESSION['csrf']);
            bootMessage('success', 'Welcome back, ' . $row['full_name'] . '!');
            redirect('dashboard.php');
        }

        if ($row && $row['account_status'] !== 'active') {
            $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')->execute([
                'Customer login blocked',
                'Inactive Customer ID: ' . $row['customer_id'] . '; IP: ' . $clientIp,
            ]);
            $_SESSION['cust_login_id'] = $identifier;
            bootMessage('error', 'Your customer account is deactivated. Please contact Stacey Laundry Hub.');
            redirect('login.php');
        }

        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')->execute([
            'Customer login failed',
            'Identifier: ' . $identifier . '; IP: ' . $clientIp,
        ]);
        $_SESSION['cust_login_id'] = $identifier;
        bootMessage('error', 'Invalid email/phone number or password.');
        redirect('login.php');
    } catch (Throwable $e) {
        logApplicationError($e, 'customer_login');
        $_SESSION['cust_login_id'] = $identifier;
        bootMessage('error', 'Unable to sign in right now. Please try again.');
        redirect('login.php');
    }
}

$savedIdentifier = (string) ($_SESSION['cust_login_id'] ?? '');
unset($_SESSION['cust_login_id']);
$notifications = flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customer Sign In | <?= h(APP_NAME) ?></title>
  <link rel="stylesheet" href="../../public/assets/css/customer/customer-portal.css">
</head>
<body class="cp-auth-body">
  <main class="cp-pink-wrap">
    <!-- Decorative Top & Bottom Waves -->
    <svg class="cp-pink-wave-top" viewBox="0 0 1440 120" preserveAspectRatio="none" height="90" aria-hidden="true">
      <path d="M0,64 C320,120 620,0 1080,64 C1280,92 1380,48 1440,32 L1440,0 L0,0 Z" fill="rgba(244,63,142,0.18)"/>
    </svg>
    <svg class="cp-pink-wave-bottom" viewBox="0 0 1440 140" preserveAspectRatio="none" height="110" aria-hidden="true">
      <path d="M0,48 C360,130 840,10 1440,84 L1440,140 L0,140 Z" fill="rgba(244,63,142,0.22)"/>
    </svg>

    <!-- Floating Soap Bubbles -->
    <span class="cp-bubble" style="width:92px;height:92px;top:7%;left:72%;" aria-hidden="true"></span>
    <span class="cp-bubble" style="width:48px;height:48px;top:14%;left:26%;" aria-hidden="true"></span>
    <span class="cp-bubble" style="width:68px;height:68px;top:40%;left:69%;" aria-hidden="true"></span>
    <span class="cp-bubble" style="width:74px;height:74px;bottom:10%;left:20%;" aria-hidden="true"></span>
    <span class="cp-bubble" style="width:36px;height:36px;top:30%;left:88%;" aria-hidden="true"></span>

    <!-- Left Brand Showcase -->
    <section class="cp-pink-left" aria-label="Stacey Laundry Hub">
      <svg width="110" height="110" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <ellipse cx="60" cy="64" rx="52" ry="18" transform="rotate(-14 60 64)" stroke="#db2777" stroke-width="3.5" opacity="0.7"/>
        <rect x="28" y="20" width="64" height="80" rx="12" fill="#ffffff" stroke="#be185d" stroke-width="4"/>
        <line x1="28" y1="38" x2="92" y2="38" stroke="#be185d" stroke-width="3"/>
        <circle cx="40" cy="29" r="3.5" fill="#f43f8e"/>
        <circle cx="76" cy="29" r="3" fill="#be185d"/>
        <circle cx="84" cy="29" r="3" fill="#be185d"/>
        <circle cx="60" cy="68" r="20" fill="#fce7f3" stroke="#db2777" stroke-width="3.5"/>
        <path d="M43 68 C50 61, 66 75, 77 66 A17 17 0 0 1 43 68 Z" fill="#f43f8e"/>
        <path d="M100 18 L103 26 L111 29 L103 32 L100 40 L97 32 L89 29 L97 26 Z" fill="#f472b6"/>
      </svg>
      <div class="cp-pink-brand-title">Stacey</div>
      <div class="cp-pink-brand-sub">Laundry Hub</div>
      <div class="cp-pink-tagline">Clean Clothes, Happy You! <span style="color:#db2777;">♡</span></div>
    </section>

    <!-- Center Login Card -->
    <section class="cp-pink-card">
      <div class="cp-pink-badge-row">
        <span class="cp-pink-pill-badge">Customer Portal</span>
      </div>
      <p class="cp-pink-subtitle">Sign in to track your laundry, view billing, and check updates.</p>

      <form method="post" class="cp-prevent-double">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">

        <label class="cp-pink-label" for="identifier">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
          <span>Email or Phone Number</span>
        </label>
        <div class="cp-pink-input-wrap">
          <input class="cp-pink-input" id="identifier" name="identifier" value="<?= h($savedIdentifier) ?>" placeholder="e.g. maria@example.com or 09123456789" required autofocus>
        </div>

        <label class="cp-pink-label" for="password">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
          <span>Password</span>
        </label>
        <div class="cp-pink-input-wrap">
          <input class="cp-pink-input" id="password" type="password" name="password" placeholder="Enter your password" style="padding-right:40px;" required>
          <button type="button" class="cp-pink-eye" data-toggle-password="password" aria-label="Show password" aria-pressed="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <div class="cp-pink-links">
          <a href="forgot_password.php">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2z"/></svg>
            Forgot password?
          </a>
          <a href="register.php">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            Create an account
          </a>
        </div>

        <button type="submit" class="cp-pink-submit" data-loading-label="Signing in...">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          <span>Sign In</span>
        </button>
      </form>

    </section>

    <!-- Right Laundry Basket Illustration -->
    <section class="cp-pink-right" aria-hidden="true">
      <svg width="240" height="220" viewBox="0 0 240 220" fill="none">
        <!-- Folded Towels -->
        <rect x="48" y="64" width="144" height="26" rx="13" fill="#ffffff" stroke="#f9a8d4" stroke-width="2"/>
        <rect x="44" y="88" width="152" height="28" rx="14" fill="#fbcfe8" stroke="#f472b6" stroke-width="2"/>
        <rect x="48" y="114" width="144" height="26" rx="13" fill="#fdf2f8" stroke="#f9a8d4" stroke-width="2"/>
        <!-- Laundry Basket -->
        <path d="M24 134 H216 L196 204 C194 210 189 214 182 214 H58 C51 214 46 210 44 204 Z" fill="#f472b6" stroke="#db2777" stroke-width="3"/>
        <rect x="18" y="124" width="204" height="16" rx="8" fill="#f9a8d4" stroke="#db2777" stroke-width="3"/>
        <!-- Basket Cutouts -->
        <rect x="52" y="152" width="16" height="20" rx="5" fill="#fbcfe8"/>
        <rect x="82" y="152" width="16" height="20" rx="5" fill="#fbcfe8"/>
        <rect x="112" y="152" width="16" height="20" rx="5" fill="#fbcfe8"/>
        <rect x="142" y="152" width="16" height="20" rx="5" fill="#fbcfe8"/>
        <rect x="172" y="152" width="16" height="20" rx="5" fill="#fbcfe8"/>
        <rect x="64" y="182" width="16" height="18" rx="5" fill="#fbcfe8"/>
        <rect x="96" y="182" width="16" height="18" rx="5" fill="#fbcfe8"/>
        <rect x="128" y="182" width="16" height="18" rx="5" fill="#fbcfe8"/>
        <rect x="160" y="182" width="16" height="18" rx="5" fill="#fbcfe8"/>
        <!-- Sparkles -->
        <path d="M208 48 L216 36 M216 60 L228 54 M196 42 L198 30" stroke="#db2777" stroke-width="3.5" stroke-linecap="round"/>
      </svg>
    </section>
  </main>

  <?php renderCustomerToasts($notifications); ?>
  <script src="../../public/assets/js/customer/customer-portal.js"></script>
</body>
</html>
