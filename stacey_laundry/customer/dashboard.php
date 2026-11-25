<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

$fullName = trim((string) ($customer['full_name'] ?? 'Customer'));
$firstName = explode(' ', $fullName)[0] ?: $fullName;

// Summary KPIs strictly scoped to this customer
$summaryStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN o.status IN ('Received','Washing','Drying','Folding') THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN o.status = 'Ready for Pickup' THEN 1 ELSE 0 END) AS ready_orders,
        SUM(CASE WHEN o.status = 'Picked Up' THEN 1 ELSE 0 END) AS completed_orders,
        COALESCE(SUM(CASE WHEN o.status <> 'Cancelled' THEN GREATEST(0, o.total - COALESCE(p.paid, 0)) ELSE 0 END), 0) AS outstanding_balance
     FROM orders o
     LEFT JOIN (SELECT order_id, SUM(amount) AS paid FROM payments GROUP BY order_id) p ON p.order_id = o.id
     WHERE o.customer_id = ?"
);
$summaryStmt->execute([$customerId]);
$summary = $summaryStmt->fetch() ?: [
    'total_orders' => 0, 'active_orders' => 0, 'ready_orders' => 0, 'completed_orders' => 0, 'outstanding_balance' => 0
];

// Latest Orders for the clean row-card list
$ordersStmt = $pdo->prepare(
    "SELECT o.*,
            COALESCE(GROUP_CONCAT(s.name SEPARATOR ', '), 'Laundry Service') AS service_names,
            COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) AS amount_paid
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     LEFT JOIN services s ON s.id = oi.service_id
     WHERE o.customer_id = ?
     GROUP BY o.id
     ORDER BY o.created_at DESC
     LIMIT 3"
);
$ordersStmt->execute([$customerId]);
$recentOrders = $ordersStmt->fetchAll();

// Recent Notifications
$notifStmt = $pdo->prepare(
    "SELECT cn.*,
            CASE
              WHEN cn.customer_id IS NOT NULL THEN cn.is_read
              WHEN cnr.notification_id IS NOT NULL THEN 1
              ELSE 0
            END AS effective_read
     FROM customer_notifications cn
     LEFT JOIN customer_notification_reads cnr
       ON cnr.notification_id = cn.id AND cnr.customer_id = ?
     WHERE cn.customer_id = ? OR cn.customer_id IS NULL
     ORDER BY cn.created_at DESC
     LIMIT 3"
);
$notifStmt->execute([$customerId, $customerId]);
$recentNotifs = $notifStmt->fetchAll();

ob_start();
?>
<h1 class="cp-greeting">Hey <?= h($firstName) ?>,</h1>

<section class="cp-hero-card">
  <h2>Track Your Laundry</h2>
  <p>Monitor live washing progress, view itemized receipts, or check your remaining balance.</p>
  <a href="tracking.php" class="cp-pill-cta">
    <span><?= !empty($recentOrders) ? 'Track Latest Order (' . h($recentOrders[0]['order_number']) . ')' : 'View Laundry Services' ?></span>
    <span>→</span>
  </a>
</section>

<div class="cp-grid-5">
  <div class="cp-kpi">
    <div class="cp-kpi-label">Total Orders</div>
    <div class="cp-kpi-val"><?= h((string) (int) $summary['total_orders']) ?></div>
  </div>
  <div class="cp-kpi">
    <div class="cp-kpi-label">Active Orders</div>
    <div class="cp-kpi-val"><?= h((string) (int) $summary['active_orders']) ?></div>
  </div>
  <div class="cp-kpi highlight">
    <div class="cp-kpi-label">Ready for Pickup</div>
    <div class="cp-kpi-val"><?= h((string) (int) $summary['ready_orders']) ?></div>
  </div>
  <div class="cp-kpi">
    <div class="cp-kpi-label">Completed</div>
    <div class="cp-kpi-val"><?= h((string) (int) $summary['completed_orders']) ?></div>
  </div>
  <div class="cp-kpi">
    <div class="cp-kpi-label">Balance Due</div>
    <div class="cp-kpi-val">₱<?= h(number_format((float) $summary['outstanding_balance'], 2)) ?></div>
  </div>
</div>

<h2 class="cp-section-title">Your Orders</h2>
<?php if ($recentOrders): ?>
  <div class="cp-order-rows">
    <?php foreach ($recentOrders as $idx => $ro):
        $paid = (float) $ro['amount_paid'];
        $total = (float) $ro['total'];
        $remaining = $ro['status'] === 'Cancelled' ? 0.0 : max(0.0, $total - $paid);
    ?>
      <div class="cp-order-row">
        <div class="cp-order-main">
          <div class="cp-order-icon" aria-hidden="true"><?= $idx % 2 === 0 ? '🧺' : '👕' ?></div>
          <div class="cp-order-meta">
            <small><?= h($ro['order_number']) ?> · <?= h(number_format((float) $ro['weight_kg'], 2)) ?> kg</small>
            <strong><?= h($ro['service_names']) ?></strong>
          </div>
        </div>
        <div class="cp-order-col">
          <small>Sent on</small>
          <strong><?= h(date('d M Y · H:i', strtotime($ro['created_at']))) ?></strong>
        </div>
        <div class="cp-order-col">
          <small>Status · ₱<?= h(number_format($total, 2)) ?></small>
          <span class="cp-badge <?= h(customerOrderStatusBadge($ro['status'])) ?>"><?= h($ro['status']) ?></span>
          <?php if ($remaining > 0): ?>
            <small style="color:var(--cp-danger); margin-top:2px;">Bal: ₱<?= h(number_format($remaining, 2)) ?></small>
          <?php endif; ?>
        </div>
        <div>
          <a href="order_details.php?id=<?= h((string) $ro['id']) ?>" class="cp-arrow-circle" aria-label="View order <?= h($ro['order_number']) ?>">→</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <a href="orders.php" class="cp-pill-muted">See All Orders <span>→</span></a>
<?php else: ?>
  <div class="cp-empty" style="margin-bottom:24px;">
    <strong>No laundry orders yet</strong>
    <p>When you drop off laundry at Stacey Laundry Hub, your orders will appear here.</p>
  </div>
<?php endif; ?>

<?php if ($recentNotifs): ?>
  <h2 class="cp-section-title">Recent Updates</h2>
  <div style="margin-bottom:28px;">
    <?php foreach ($recentNotifs as $n): ?>
      <div class="cp-notif-item <?= !$n['effective_read'] ? 'unread' : '' ?>">
        <div>
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:3px;">
            <strong><?= h($n['title']) ?></strong>
            <span class="cp-badge <?= !$n['effective_read'] ? 'cp-badge-green' : 'cp-badge-gray' ?>"><?= !$n['effective_read'] ? 'New' : 'Read' ?></span>
          </div>
          <div style="font-size:0.86rem; color:var(--cp-muted);"><?= h($n['message']) ?></div>
          <div style="font-size:0.75rem; color:var(--cp-muted); margin-top:4px;"><?= h(date('M d, Y g:i A', strtotime($n['created_at']))) ?></div>
        </div>
        <?php if (!empty($n['order_id'])): ?>
          <a href="notifications.php?open=<?= h((string) $n['id']) ?>" class="cp-arrow-circle" aria-label="Open notification">→</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 class="cp-section-title">Quick Links</h2>
<div class="cp-quick-links">
  <a href="profile.php" class="cp-quick-pill">📍 My Address &amp; Profile</a>
  <a href="orders.php" class="cp-quick-pill">☰ My Orders</a>
  <a href="payments.php" class="cp-quick-pill">💳 Payments &amp; Billing</a>
  <a href="services.php" class="cp-quick-pill">🏪 Laundry Info &amp; Rates</a>
  <a href="support.php" class="cp-quick-pill">❓ Get Help</a>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Dashboard', 'dashboard', $content);
