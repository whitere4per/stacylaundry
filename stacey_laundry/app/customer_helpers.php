<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../app/bootstrap.php';

if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        return $pdo;
    }
}

if (!function_exists('h')) {
    function h(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('verifyCsrf')) {
    function verifyCsrf(): void {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['csrf'])) {
            http_response_code(403);
            exit('Invalid CSRF token');
        }
    }
}

if (!function_exists('safePost')) {
    function safePost(string $key, string $default = ''): string {
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return $default;
        }
        return trim((string) $_POST[$key]);
    }
}

if (!function_exists('issueFormToken')) {
    function issueFormToken(string $key): string {
        if (empty($_SESSION['form_tokens'][$key])) {
            $_SESSION['form_tokens'][$key] = bin2hex(random_bytes(24));
        }
        return $_SESSION['form_tokens'][$key];
    }
}

if (!function_exists('verifyFormToken')) {
    function verifyFormToken(string $key): void {
        $submitted = safePost('request_token');
        $expected = $_SESSION['form_tokens'][$key] ?? '';
        if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
            throw new InvalidArgumentException('This form was already submitted or has expired. Refresh the page before trying again.');
        }
    }
}

if (!function_exists('rotateFormToken')) {
    function rotateFormToken(string $key): void {
        $_SESSION['form_tokens'][$key] = bin2hex(random_bytes(24));
    }
}

if (!function_exists('preserveFormInput')) {
    function preserveFormInput(string $key, array $fieldNames): void {
        $values = [];
        foreach ($fieldNames as $fieldName) {
            if (isset($_POST[$fieldName]) && is_scalar($_POST[$fieldName])) {
                $values[$fieldName] = trim((string) $_POST[$fieldName]);
            }
        }
        $_SESSION['old_form_input'][$key] = $values;
    }
}

if (!function_exists('takeFormInput')) {
    function takeFormInput(string $key): array {
        $values = $_SESSION['old_form_input'][$key] ?? [];
        unset($_SESSION['old_form_input'][$key]);
        return is_array($values) ? $values : [];
    }
}

function normalizePhone(string $phone): string {
    return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
}

function currentCustomer(): ?array {
    return $_SESSION['customer'] ?? null;
}

/**
 * Ensures the visitor has an active authenticated customer session.
 * Completely isolated from staff/administrator $_SESSION['user'].
 */
function ensureCustomerLogin(): array {
    $sessionCustomer = currentCustomer();
    if (empty($sessionCustomer['account_id']) || empty($sessionCustomer['customer_id'])) {
        bootMessage('information', 'Please sign in to access your Customer Portal.');
        redirect('login.php');
    }

    $stmt = db()->prepare(
        'SELECT ca.id AS account_id, ca.customer_id, ca.email, ca.account_status, ca.profile_picture,
                ca.created_at AS account_created_at, c.full_name, c.contact_number, c.address, c.registration_date
         FROM customer_accounts ca
         JOIN customers c ON c.id = ca.customer_id
         WHERE ca.id = ? LIMIT 1'
    );
    $stmt->execute([(int) $sessionCustomer['account_id']]);
    $row = $stmt->fetch();

    if (!$row || $row['account_status'] !== 'active') {
        unset($_SESSION['customer']);
        bootMessage('error', $row ? 'Your customer account is currently deactivated. Please contact the shop.' : 'Your session has expired. Please sign in again.');
        redirect('login.php');
    }

    $_SESSION['customer'] = $row;
    return $row;
}

function customerOrderStatusBadge(string $status): string {
    $map = [
        'Received' => 'cp-badge-blue',
        'Washing' => 'cp-badge-amber',
        'Drying' => 'cp-badge-amber',
        'Folding' => 'cp-badge-amber',
        'Ready for Pickup' => 'cp-badge-green',
        'Picked Up' => 'cp-badge-gray',
        'Cancelled' => 'cp-badge-red',
    ];
    return $map[$status] ?? 'cp-badge-gray';
}

function customerPaymentStatus(float $total, float $paid): array {
    $remaining = max(0.0, $total - $paid);
    if ($total <= 0 || $remaining <= 0.009) {
        return ['Paid', 'cp-badge-green'];
    }
    if ($paid > 0) {
        return ['Partially Paid', 'cp-badge-amber'];
    }
    return ['Unpaid', 'cp-badge-red'];
}

function customerUnreadNotificationCount(PDO $pdo, int $customerId): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM customer_notifications cn
         LEFT JOIN customer_notification_reads cnr
           ON cnr.notification_id = cn.id AND cnr.customer_id = ?
         WHERE (cn.customer_id = ? AND cn.is_read = 0)
            OR (cn.customer_id IS NULL AND cnr.notification_id IS NULL)'
    );
    $stmt->execute([$customerId, $customerId]);
    return (int) $stmt->fetchColumn();
}

function renderCustomerToasts(array $notifications): void {
    ?>
    <div class="cp-toast-region" aria-live="polite" aria-label="Notifications">
      <?php foreach ($notifications as $n):
          $type = in_array($n['type'] ?? '', ['success', 'error', 'warning', 'information'], true) ? $n['type'] : 'information';
          $critical = !empty($n['critical']);
      ?>
        <div class="cp-toast <?= h($type) ?>" role="<?= $critical ? 'alert' : 'status' ?>" data-critical="<?= $critical ? 'true' : 'false' ?>">
          <span><?= h((string) ($n['message'] ?? '')) ?></span>
          <button type="button" class="cp-toast-close" aria-label="Dismiss">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}

function renderCustomerLayout(string $title, string $activePage, string $content): void {
    $customer = currentCustomer();
    $unreadCount = $customer ? customerUnreadNotificationCount(db(), (int) $customer['customer_id']) : 0;
    $notifications = flash();
    $fullName = trim((string) ($customer['full_name'] ?? 'Customer'));
    $firstName = explode(' ', $fullName)[0] ?: $fullName;
    $initials = strtoupper(substr($fullName, 0, 1));
    $navItems = [
        ['key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard'],
        ['key' => 'orders', 'href' => 'orders.php', 'label' => 'My Laundry Orders'],
        ['key' => 'tracking', 'href' => 'tracking.php', 'label' => 'Track My Laundry'],
        ['key' => 'notifications', 'href' => 'notifications.php', 'label' => 'Notifications'],
        ['key' => 'payments', 'href' => 'payments.php', 'label' => 'Payments & Billing'],
        ['key' => 'history', 'href' => 'order_history.php', 'label' => 'Order History'],
        ['key' => 'profile', 'href' => 'profile.php', 'label' => 'My Profile'],
        ['key' => 'services', 'href' => 'services.php', 'label' => 'Laundry Information'],
        ['key' => 'support', 'href' => 'support.php', 'label' => 'Help & Support'],
    ];
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= h($title) ?> | <?= h(APP_NAME) ?> Customer Portal</title>
      <link rel="stylesheet" href="../../public/assets/css/customer/customer-portal.css">
    </head>
    <body>
      <div class="cp-shell">
        <aside class="cp-sidebar" aria-label="Customer sidebar">
          <div class="cp-sidebar-brand-row">
            <a href="dashboard.php" class="cp-brand">
              <span class="cp-brand-badge">SL</span>
              <span class="cp-brand-copy"><strong>Stacey</strong><small>Laundry Hub</small></span>
            </a>
            <button type="button" class="cp-sidebar-collapse" aria-label="Collapse sidebar" aria-pressed="false">‹</button>
          </div>

          <nav class="cp-subnav" aria-label="Customer Navigation">
            <div class="cp-subnav-links">
              <?php foreach ($navItems as $item): ?>
                <a class="<?= $activePage === $item['key'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>" title="<?= h($item['label']) ?>">
                  <span class="cp-nav-icon" aria-hidden="true">
                    <?php
                      echo match ($item['key']) {
                        'dashboard' => '⌂',
                        'orders', 'history' => '▦',
                        'tracking' => '◎',
                        'notifications' => '♡',
                        'payments' => '₱',
                        'profile' => '●',
                        'services' => '♧',
                        'support' => '?',
                        default => '•',
                      };
                    ?>
                  </span>
                  <span class="cp-nav-label"><?= h($item['label']) ?><?= ($item['key'] === 'notifications' && $unreadCount > 0) ? ' <b>' . h((string) $unreadCount) . '</b>' : '' ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </nav>

          <div class="cp-sidebar-account">
            <span class="cp-avatar-sm">
              <?php if (!empty($customer['profile_picture'])): ?>
                <img src="../../public/uploads/customers/<?= h($customer['profile_picture']) ?>" alt="Profile">
              <?php else: ?>
                <?= h($initials) ?>
              <?php endif; ?>
            </span>
            <span class="cp-sidebar-account-copy">
              <strong><?= h($fullName) ?></strong>
              <small>Customer</small>
            </span>
            <a class="cp-logout-link cp-confirm-link" href="logout.php" data-confirm-title="Log Out?" data-confirm="Are you sure you want to log out of your account?" data-confirm-label="Yes, Log Out" aria-label="Log out">×</a>
          </div>
        </aside>

        <div class="cp-main">
          <header class="cp-header">
            <button type="button" class="cp-menu-btn" aria-label="Toggle customer navigation" aria-expanded="false">☰</button>
            <span class="cp-header-title"><?= h($title) ?></span>
            <div class="cp-header-actions">
              <a href="notifications.php" class="cp-icon-circle accent" aria-label="Notifications">
                ♡
                <?php if ($unreadCount > 0): ?>
                  <span class="cp-bell-count"><?= h((string) $unreadCount) ?></span>
                <?php endif; ?>
              </a>
              <a href="profile.php" class="cp-user-chip" title="My Profile">
                <span class="cp-avatar-sm">
                  <?php if (!empty($customer['profile_picture'])): ?>
                    <img src="../../public/uploads/customers/<?= h($customer['profile_picture']) ?>" alt="Profile">
                  <?php else: ?>
                    <?= h($initials) ?>
                  <?php endif; ?>
                </span>
                <span><?= h($firstName) ?></span>
              </a>
            </div>
          </header>

          <main class="cp-content">
            <?= $content ?>
          </main>
        </div>
      </div>
      <button type="button" class="cp-sidebar-overlay" aria-label="Close customer navigation"></button>
      <div class="cp-confirm-overlay" hidden>
        <section class="cp-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="cp-confirm-title" aria-describedby="cp-confirm-message">
          <div class="cp-confirm-icon" aria-hidden="true">!</div>
          <h2 id="cp-confirm-title">Confirm action</h2>
          <p id="cp-confirm-message"></p>
          <div class="cp-confirm-actions">
            <button type="button" class="cp-confirm-cancel">Cancel</button>
            <button type="button" class="cp-confirm-accept">Confirm</button>
          </div>
        </section>
      </div>
      <?php renderCustomerToasts($notifications); ?>
      <script src="../../public/assets/js/customer/customer-portal.js"></script>
    </body>
    </html>
    <?php
}
