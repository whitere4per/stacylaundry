<?php
require_once __DIR__ . '/../app/helpers.php';

if (currentUser() || restoreRememberedUser()) {
    redirect('index.php');
}

$identifier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $identifierInput = $_POST['identifier'] ?? '';
        $passwordInput = $_POST['password'] ?? '';
        $identifier = is_string($identifierInput) ? trim($identifierInput) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';
        if ($identifier === '' || $password === '') {
            $_SESSION['login_identifier'] = $identifier;
            bootMessage('error', 'Enter your username or email and password.');
            redirect('login.php');
        }

        $authDb = db();
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $escapedId = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $identifier);
        $escapedIp = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $clientIp);
        $rateStmt = $authDb->prepare(
            "SELECT COUNT(*) FROM activity_logs
             WHERE action = 'Login failed'
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)
               AND (details LIKE ? OR details LIKE ?)"
        );
        $rateStmt->execute(['Identifier: ' . $escapedId . ';%', '%IP: ' . $escapedIp]);
        if ((int) $rateStmt->fetchColumn() >= 5) {
            $_SESSION['login_identifier'] = $identifier;
            bootMessage('error', 'Too many failed login attempts. Please wait 15 minutes before trying again.');
            redirect('login.php');
        }

        $stmt = $authDb->prepare('SELECT id, username, full_name, role, status, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
            $remember = isset($_POST['remember']) && $_POST['remember'] === '1';
            $rememberToken = null;
            $rememberExpiresAt = null;
            $authDb->beginTransaction();
            try {
                $authDb->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
                logActivity($authDb, 'Login successful', 'User: ' . $user['username'], (int) $user['id']);
                if ($remember) {
                    [$rememberToken, $rememberExpiresAt] = createRememberToken($authDb, (int) $user['id']);
                } else {
                    $existingToken = $_COOKIE['stacey_remember'] ?? '';
                    if (is_string($existingToken) && preg_match('/^[a-f0-9]{64}$/', $existingToken)) {
                        $authDb->prepare('DELETE FROM user_remember_tokens WHERE token_hash = ?')->execute([hash('sha256', $existingToken)]);
                    }
                }
                $authDb->commit();
            } catch (Throwable $e) {
                if ($authDb->inTransaction()) {
                    $authDb->rollBack();
                }
                throw $e;
            }

            session_regenerate_id(true);
            unset($user['password_hash'], $user['status']);
            $_SESSION['user'] = $user;
            unset($_SESSION['csrf'], $_SESSION['login_identifier']);
            if ($rememberToken !== null && $rememberExpiresAt !== null) {
                setRememberCookie($rememberToken, $rememberExpiresAt);
            } else {
                clearRememberCookie();
            }
            bootMessage('success', 'Welcome back.');
            redirect('index.php');
        }

        if ($user && $user['status'] !== 'active') {
            logActivity(db(), 'Login blocked', 'Inactive account: ' . $user['username'] . '; IP: ' . $clientIp, (int) $user['id']);
        } else {
            logActivity(db(), 'Login failed', 'Identifier: ' . $identifier . '; IP: ' . $clientIp, $user ? (int) $user['id'] : null);
        }
        $_SESSION['login_identifier'] = $identifier;
        bootMessage('error', 'Invalid username/email or password.');
        redirect('login.php');
    } catch (Throwable $e) {
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'login');
        }
        $_SESSION['login_identifier'] = $identifier;
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to sign in right now. Please try again.');
        redirect('login.php');
    }
}

$identifier = (string) ($_SESSION['login_identifier'] ?? '');
unset($_SESSION['login_identifier']);
$notifications = flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#102a43">
  <title>Sign in | Stacey Laundry Hub</title>
  <link rel="stylesheet" href="../../public/assets/css/style.css">
</head>
<body class="auth-page">
  <main class="auth-layout">
    <section class="auth-brand-panel" aria-label="Stacey Laundry Hub">
      <a class="auth-brand" href="login.php" aria-label="Stacey Laundry Hub home">
        <span class="auth-brand-mark" aria-hidden="true"><span></span></span>
        <span class="auth-brand-name">Stacey <strong>Laundry Hub</strong></span>
      </a>

      <div class="auth-brand-copy">
        <span class="auth-kicker"><span></span> LAUNDRY OPERATIONS</span>
        <h1>Every load,<br>in good hands.</h1>
        <p>Keep orders moving, care for every customer, and stay on top of the day — all from one calm, clear workspace.</p>
      </div>

      <div class="laundry-art" aria-hidden="true">
        <div class="art-orbit art-orbit-one"></div>
        <div class="art-orbit art-orbit-two"></div>
        <div class="art-spark art-spark-one">✦</div>
        <div class="art-spark art-spark-two">✧</div>
        <div class="art-towel art-towel-back"></div>
        <div class="art-towel art-towel-front"></div>
        <div class="art-machine">
          <div class="machine-top">
            <span class="machine-brand-dot"></span>
            <span class="machine-display"></span>
            <span class="machine-knob"></span>
          </div>
          <div class="machine-door"><span></span></div>
        </div>
        <div class="art-bubble art-bubble-one"></div>
        <div class="art-bubble art-bubble-two"></div>
      </div>

      <div class="auth-brand-footer">
        <span class="footer-rule"></span>
        <span>Thoughtful care. Seamless operations.</span>
      </div>
    </section>

    <section class="auth-form-panel">
      <div class="auth-form-wrap">
        <div class="auth-mobile-brand">
          <span class="auth-brand-mark" aria-hidden="true"><span></span></span>
          <span class="auth-brand-name">Stacey <strong>Laundry Hub</strong></span>
        </div>
        <div class="auth-form-heading">
          <h2>Welcome back</h2>
          <p>Sign in to manage today's laundry operations.</p>
        </div>

        <?php renderToastNotifications($notifications); ?>

        <form class="auth-form" method="post" autocomplete="on">
          <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
          <div class="auth-field field-group">
            <label for="identifier">Username or email</label>
            <input id="identifier" type="text" name="identifier" value="<?= h($identifier) ?>" placeholder="Enter your username or email" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="254" data-error-message="Enter your username or email." required>
          </div>

          <div class="auth-field field-group">
            <label for="password">Password</label>
            <div class="auth-password-wrap">
              <input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" data-error-message="Enter your password." required>
              <button class="auth-password-toggle" type="button" aria-label="Show password" aria-pressed="false">Show</button>
            </div>
          </div>

          <div class="auth-options">
            <label class="auth-remember" for="remember">
              <input id="remember" type="checkbox" name="remember" value="1">
              <span>Remember this device</span>
            </label>
          </div>

          <button class="auth-submit" type="submit" data-loading-label="Signing in...">
            <span>Sign in</span>
            <span class="auth-submit-arrow" aria-hidden="true">→</span>
          </button>
        </form>
        <p style="margin-top:18px; text-align:center; font-size:0.86rem; color:#64748b;">
          Are you a laundry customer? <a href="../customer/login.php" style="color:#15803d; font-weight:600;">Go to Customer Portal →</a>
        </p>
      </div>
      <p class="auth-copyright">© <?= date('Y') ?> Stacey Laundry Hub</p>
    </section>
  </main>

  <script>
    document.querySelectorAll('.toast').forEach(function (toast) {
      const close = toast.querySelector('.toast-close');
      if (close) close.addEventListener('click', function () { toast.remove(); });
      if (toast.dataset.critical !== 'true') {
        window.setTimeout(function () { toast.remove(); }, 5000);
      }
    });

    const passwordInput = document.getElementById('password');
    const passwordToggle = document.querySelector('.auth-password-toggle');
    passwordToggle.addEventListener('click', function () {
      const showPassword = passwordInput.type === 'password';
      passwordInput.type = showPassword ? 'text' : 'password';
      passwordToggle.textContent = showPassword ? 'Hide' : 'Show';
      passwordToggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
      passwordToggle.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
    });

    const form = document.querySelector('.auth-form');
    form.noValidate = true;
    const validate = function () {
      let valid = true;
      form.querySelectorAll('input[required]').forEach(function (field) {
        const errorMessage = field.value.trim() ? '' : (field.dataset.errorMessage || 'This field is required.');
        let error = field.parentElement.querySelector('.field-error[data-for="' + field.name + '"]');
        if (errorMessage) {
          valid = false;
          field.setAttribute('aria-invalid', 'true');
          if (!error) {
            error = document.createElement('span');
            error.className = 'field-error';
            error.dataset.for = field.name;
            error.id = 'auth-validation-' + field.name;
            field.parentElement.appendChild(error);
          }
          error.textContent = errorMessage;
          field.setAttribute('aria-describedby', error.id);
        } else {
          field.removeAttribute('aria-invalid');
          field.removeAttribute('aria-describedby');
          if (error) error.remove();
        }
      });
      return valid;
    };
    form.addEventListener('input', validate);
    form.addEventListener('submit', function (event) {
      if (!validate()) {
        event.preventDefault();
        const invalidField = form.querySelector('[aria-invalid="true"]');
        if (invalidField) invalidField.focus();
        return;
      }
      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.querySelector('span').textContent = button.dataset.loadingLabel || 'Signing in...';
        button.querySelector('.auth-submit-arrow').hidden = true;
      }
    });
  </script>
</body>
</html>
