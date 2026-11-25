<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (isset($_POST['mark_all_read'])) {
            $pdo->prepare('UPDATE customer_notifications SET is_read = 1 WHERE customer_id = ? AND is_read = 0')->execute([$customerId]);
            $pdo->prepare(
                'INSERT IGNORE INTO customer_notification_reads (notification_id, customer_id)
                 SELECT id, ? FROM customer_notifications WHERE customer_id IS NULL'
            )->execute([$customerId]);
            bootMessage('success', 'All notifications marked as read.');
            redirect('notifications.php');
        }

        if (isset($_POST['mark_read'])) {
            $notifId = (int) ($_POST['notification_id'] ?? 0);
            if ($notifId > 0) {
                $check = $pdo->prepare('SELECT id, customer_id FROM customer_notifications WHERE id = ? AND (customer_id = ? OR customer_id IS NULL) LIMIT 1');
                $check->execute([$notifId, $customerId]);
                $nRow = $check->fetch();
                if ($nRow) {
                    if ($nRow['customer_id'] !== null) {
                        $pdo->prepare('UPDATE customer_notifications SET is_read = 1 WHERE id = ? AND customer_id = ?')->execute([$notifId, $customerId]);
                    } else {
                        $pdo->prepare('INSERT IGNORE INTO customer_notification_reads (notification_id, customer_id) VALUES (?, ?)')->execute([$notifId, $customerId]);
                    }
                    bootMessage('success', 'Notification marked as read.');
                }
            }
            redirect('notifications.php');
        }
    } catch (Throwable $e) {
        logApplicationError($e, 'customer_notifications');
        bootMessage('error', 'Unable to update notification status.');
        redirect('notifications.php');
    }
}

// Handle click-to-open & mark read
if (isset($_GET['open'])) {
    $openId = (int) $_GET['open'];
    $check = $pdo->prepare('SELECT id, customer_id, order_id FROM customer_notifications WHERE id = ? AND (customer_id = ? OR customer_id IS NULL) LIMIT 1');
    $check->execute([$openId, $customerId]);
    $nRow = $check->fetch();
    if ($nRow) {
        if ($nRow['customer_id'] !== null) {
            $pdo->prepare('UPDATE customer_notifications SET is_read = 1 WHERE id = ? AND customer_id = ?')->execute([$openId, $customerId]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO customer_notification_reads (notification_id, customer_id) VALUES (?, ?)')->execute([$openId, $customerId]);
        }
        if (!empty($nRow['order_id'])) {
            redirect('order_details.php?id=' . (int) $nRow['order_id']);
        }
    }
    redirect('notifications.php');
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unread', 'order', 'payment', 'general'], true)) {
    $filter = 'all';
}

$sql = "SELECT cn.*, o.order_number,
               CASE
                 WHEN cn.customer_id IS NOT NULL THEN cn.is_read
                 WHEN cnr.notification_id IS NOT NULL THEN 1
                 ELSE 0
               END AS effective_read
        FROM customer_notifications cn
        LEFT JOIN customer_notification_reads cnr
          ON cnr.notification_id = cn.id AND cnr.customer_id = ?
        LEFT JOIN orders o ON o.id = cn.order_id AND o.customer_id = ?
        WHERE (cn.customer_id = ? OR cn.customer_id IS NULL)";

$params = [$customerId, $customerId, $customerId];
if (in_array($filter, ['order', 'payment', 'general'], true)) {
    $sql .= ' AND cn.notification_type = ?';
    $params[] = $filter;
} elseif ($filter === 'unread') {
    $sql .= ' AND ((cn.customer_id IS NOT NULL AND cn.is_read = 0) OR (cn.customer_id IS NULL AND cnr.notification_id IS NULL))';
}
$sql .= ' ORDER BY cn.created_at DESC LIMIT 60';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$unreadCount = customerUnreadNotificationCount($pdo, $customerId);

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <div>
      <h2>Notifications</h2>
      <div style="font-size:0.84rem; color:var(--cp-muted);"><?= h((string) $unreadCount) ?> unread</div>
    </div>
    <?php if ($unreadCount > 0): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="mark_all_read" value="1">
        <button type="submit" class="cp-btn cp-btn-outline cp-btn-sm">Mark All as Read</button>
      </form>
    <?php endif; ?>
  </div>

  <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px;">
    <?php foreach (['all' => 'All', 'unread' => 'Unread', 'order' => 'Orders', 'payment' => 'Payments', 'general' => 'Announcements'] as $key => $label): ?>
      <a href="notifications.php?filter=<?= h($key) ?>" class="cp-btn cp-btn-sm <?= $filter === $key ? '' : 'cp-btn-outline' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($rows): ?>
    <?php foreach ($rows as $n):
        $typeBadge = $n['notification_type'] === 'payment' ? 'cp-badge-green' : ($n['notification_type'] === 'general' ? 'cp-badge-amber' : 'cp-badge-blue');
    ?>
      <div class="cp-notif-item <?= !$n['effective_read'] ? 'unread' : '' ?>">
        <div style="flex:1;">
          <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:4px;">
            <strong><?= h($n['title']) ?></strong>
            <span class="cp-badge <?= h($typeBadge) ?>"><?= h(ucfirst($n['notification_type'])) ?></span>
            <span class="cp-badge <?= !$n['effective_read'] ? 'cp-badge-green' : 'cp-badge-gray' ?>"><?= !$n['effective_read'] ? 'Unread' : 'Read' ?></span>
          </div>
          <div style="font-size:0.9rem; color:var(--cp-text);"><?= h($n['message']) ?></div>
          <div style="font-size:0.78rem; color:var(--cp-muted); margin-top:6px;">
            <?= h(date('M d, Y g:i A', strtotime($n['created_at']))) ?>
            <?php if (!empty($n['order_number'])): ?>
              · Order: <strong><?= h($n['order_number']) ?></strong>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex; gap:6px; align-items:center; flex-shrink:0;">
          <?php if (!empty($n['order_id'])): ?>
            <a href="notifications.php?open=<?= h((string) $n['id']) ?>" class="cp-btn cp-btn-sm">Open Order</a>
          <?php endif; ?>
          <?php if (!$n['effective_read']): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="mark_read" value="1">
              <input type="hidden" name="notification_id" value="<?= h((string) $n['id']) ?>">
              <button type="submit" class="cp-btn cp-btn-outline cp-btn-sm">Mark Read</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No notifications in this category</strong>
      <p>Updates about your laundry orders, payments, and shop announcements will appear here.</p>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Notifications', 'notifications', $content);
