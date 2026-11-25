<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

$ordersStmt = $pdo->prepare('SELECT id, order_number, status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
$ordersStmt->execute([$customerId]);
$customerOrders = $ordersStmt->fetchAll();

$selectedOrderId = (int) ($_GET['order_id'] ?? ($customerOrders[0]['id'] ?? 0));
$order = null;
$stageTimes = [];

if ($selectedOrderId > 0) {
    $stmt = $pdo->prepare(
        "SELECT o.*,
                COALESCE(GROUP_CONCAT(s.name SEPARATOR ', '), 'Laundry Service') AS service_names
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         LEFT JOIN services s ON s.id = oi.service_id
         WHERE o.id = ? AND o.customer_id = ?
         GROUP BY o.id
         LIMIT 1"
    );
    $stmt->execute([$selectedOrderId, $customerId]);
    $order = $stmt->fetch();

    if ($order) {
        $histStmt = $pdo->prepare('SELECT new_status, changed_at FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC, id ASC');
        $histStmt->execute([(int) $order['id']]);
        foreach ($histStmt->fetchAll() as $hRow) {
            $stageTimes[$hRow['new_status']] = $hRow['changed_at'];
        }
        if (!isset($stageTimes['Received'])) {
            $stageTimes['Received'] = $order['created_at'];
        }
    }
}

$stages = ['Received', 'Washing', 'Drying', 'Folding', 'Ready for Pickup', 'Picked Up'];
$cancelledOrders = array_filter($customerOrders, static function (array $o): bool {
    return $o['status'] === 'Cancelled';
});

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <h2>Track My Laundry</h2>
    <?php if ($customerOrders): ?>
      <form method="get" style="display:flex; gap:8px; align-items:center;">
        <select name="order_id" class="cp-select" style="width:auto; min-width:220px;" onchange="this.form.submit()">
          <?php foreach ($customerOrders as $co): ?>
            <option value="<?= h((string) $co['id']) ?>" <?= (int) $co['id'] === $selectedOrderId ? 'selected' : '' ?>>
              <?= h($co['order_number']) ?> (<?= h($co['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($order): ?>
    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:14px; border-radius:12px; background:var(--cp-primary-tint); border:1px solid #bbf7d0;">
      <div>
        <div style="font-size:0.78rem; text-transform:uppercase; color:var(--cp-muted); font-weight:700;">Receipt Number</div>
        <strong style="font-size:1.15rem;"><?= h($order['order_number']) ?></strong>
        <span style="margin-left:8px; color:var(--cp-muted); font-size:0.88rem;"><?= h($order['service_names']) ?></span>
      </div>
      <div style="text-align:right;">
        <div style="font-size:0.78rem; text-transform:uppercase; color:var(--cp-muted); font-weight:700;">Estimated Completion</div>
        <strong><?= h($order['estimated_completion'] ? date('M d, Y', strtotime($order['estimated_completion'])) : 'To be confirmed') ?></strong>
      </div>
    </div>

    <?php if ($order['status'] === 'Cancelled'): ?>
      <div style="margin-top:20px; padding:18px; border-radius:12px; background:var(--cp-danger-soft); border:1px solid #fecaca; color:#991b1b;">
        <strong style="font-size:1.05rem;">Order Cancelled</strong>
        <p style="margin:6px 0 0;">This laundry order was cancelled<?= isset($stageTimes['Cancelled']) ? ' on ' . h(date('M d, Y g:i A', strtotime($stageTimes['Cancelled']))) : '' ?>. Please contact our counter if you have questions.</p>
      </div>
    <?php else:
        $currentIndex = array_search($order['status'], $stages, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }
    ?>
      <div class="cp-timeline">
        <?php foreach ($stages as $idx => $stageLabel):
            $stateClass = $idx < $currentIndex ? 'done' : ($idx === $currentIndex ? 'active' : '');
            $recordedAt = $stageTimes[$stageLabel] ?? null;
        ?>
          <div class="cp-stage <?= h($stateClass) ?>">
            <div class="cp-stage-num"><?= $idx < $currentIndex ? '✓' : h((string) ($idx + 1)) ?></div>
            <div class="cp-stage-label"><?= h($stageLabel) ?></div>
            <div class="cp-stage-time">
              <?= $recordedAt ? h(date('M d, g:i A', strtotime($recordedAt))) : ($idx <= $currentIndex ? 'Recorded' : 'Pending') ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($order['special_instructions'])): ?>
      <div style="margin-top:14px; padding:12px 14px; border-radius:10px; background:#f8fafc; border:1px solid var(--cp-line); font-size:0.9rem;">
        <strong>Special Instructions:</strong> <?= h($order['special_instructions']) ?>
      </div>
    <?php endif; ?>

    <div style="margin-top:16px;">
      <a href="order_details.php?id=<?= h((string) $order['id']) ?>" class="cp-btn cp-btn-outline cp-btn-sm">View Full Order &amp; Payment Details</a>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No laundry orders to track</strong>
      <p>Once you have an order at Stacey Laundry Hub, you can track each stage here in real time.</p>
    </div>
  <?php endif; ?>
</div>

<?php if ($cancelledOrders): ?>
  <div class="cp-card">
    <div class="cp-card-head">
      <h3>Cancelled Orders</h3>
      <span class="cp-badge cp-badge-red"><?= h((string) count($cancelledOrders)) ?></span>
    </div>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead><tr><th>Receipt #</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($cancelledOrders as $co): ?>
            <tr>
              <td><strong><?= h($co['order_number']) ?></strong></td>
              <td><?= h(date('M d, Y g:i A', strtotime($co['created_at']))) ?></td>
              <td><span class="cp-badge cp-badge-red">Cancelled</span></td>
              <td><a href="order_details.php?id=<?= h((string) $co['id']) ?>" class="cp-btn cp-btn-outline cp-btn-sm">Details</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
renderCustomerLayout('Track My Laundry', 'tracking', $content);
