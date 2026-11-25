<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

$orderId = (int) ($_GET['id'] ?? 0);
$orderStmt = $pdo->prepare(
    'SELECT o.*,
            COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) AS amount_paid
     FROM orders o
     WHERE o.id = ? AND o.customer_id = ?
     LIMIT 1'
);
$orderStmt->execute([$orderId, $customerId]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    $content = '<div class="cp-card"><div class="cp-empty"><strong>Order not found</strong><p>The requested order does not exist or does not belong to your account.</p><a href="orders.php" class="cp-btn" style="margin-top:8px;">Back to My Orders</a></div></div>';
    renderCustomerLayout('Order Not Found', 'orders', $content);
    exit;
}

$itemsStmt = $pdo->prepare(
    'SELECT oi.*, s.name AS service_name, s.unit
     FROM order_items oi
     JOIN services s ON s.id = oi.service_id
     WHERE oi.order_id = ?'
);
$itemsStmt->execute([$orderId]);
$items = $itemsStmt->fetchAll();

$historyStmt = $pdo->prepare(
    'SELECT old_status, new_status, changed_at
     FROM order_status_history
     WHERE order_id = ?
     ORDER BY changed_at ASC, id ASC'
);
$historyStmt->execute([$orderId]);
$history = $historyStmt->fetchAll();

$paymentsStmt = $pdo->prepare(
    'SELECT id, amount, payment_method, payment_date, notes
     FROM payments
     WHERE order_id = ?
     ORDER BY payment_date DESC'
);
$paymentsStmt->execute([$orderId]);
$payments = $paymentsStmt->fetchAll();

$total = (float) $order['total'];
$paid = (float) $order['amount_paid'];
$isCancelled = $order['status'] === 'Cancelled';
$remaining = $isCancelled ? 0.0 : max(0.0, $total - $paid);
[$payLabel, $payClass] = $isCancelled ? ['Cancelled', 'cp-badge-gray'] : customerPaymentStatus($total, $paid);

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <div>
      <h2 style="font-size:1.25rem;"><?= h($order['order_number']) ?></h2>
      <div style="font-size:0.84rem; color:var(--cp-muted);">Placed on <?= h(date('M d, Y g:i A', strtotime($order['created_at']))) ?></div>
    </div>
    <div style="display:flex; gap:8px; align-items:center;" class="no-print">
      <span class="cp-badge <?= h(customerOrderStatusBadge($order['status'])) ?>"><?= h($order['status']) ?></span>
      <a href="tracking.php?order_id=<?= h((string) $order['id']) ?>" class="cp-btn cp-btn-sm">Track Laundry</a>
      <button type="button" class="cp-btn cp-btn-outline cp-btn-sm" data-print-receipt>Print Receipt</button>
    </div>
  </div>

  <div class="cp-grid-2">
    <div>
      <h3 style="margin-top:0;">Order Summary</h3>
      <table class="cp-table">
        <tbody>
          <tr><th>Weight</th><td><?= h(number_format((float) $order['weight_kg'], 2)) ?> kg</td></tr>
          <tr><th>Quantity</th><td><?= h((string) (int) $order['quantity']) ?></td></tr>
          <tr><th>Subtotal</th><td>₱<?= h(number_format((float) $order['subtotal'], 2)) ?></td></tr>
          <tr><th>Discount</th><td>₱<?= h(number_format((float) $order['discount'], 2)) ?></td></tr>
          <tr><th>Total Amount</th><td><strong>₱<?= h(number_format($total, 2)) ?></strong></td></tr>
          <tr><th>Total Paid</th><td>₱<?= h(number_format($paid, 2)) ?></td></tr>
          <tr><th>Remaining Balance</th><td><strong style="color:<?= $remaining > 0 ? 'var(--cp-danger)' : 'var(--cp-primary-dark)' ?>;">₱<?= h(number_format($remaining, 2)) ?></strong> <span class="cp-badge <?= h($payClass) ?>"><?= h($payLabel) ?></span></td></tr>
          <tr><th>Est. Completion</th><td><?= h($order['estimated_completion'] ? date('M d, Y', strtotime($order['estimated_completion'])) : 'To be confirmed') ?></td></tr>
          <?php if (!empty($order['special_instructions'])): ?>
            <tr><th>Special Instructions</th><td><?= h($order['special_instructions']) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div>
      <h3 style="margin-top:0;">Services Included</h3>
      <?php if ($items): ?>
        <table class="cp-table">
          <thead><tr><th>Service</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead>
          <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= h($item['service_name']) ?></td>
                <td><?= h((string) (int) $item['quantity']) ?> (<?= h($item['unit']) ?>)</td>
                <td>₱<?= h(number_format((float) $item['unit_price'], 2)) ?></td>
                <td>₱<?= h(number_format((float) $item['line_total'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h3 style="margin-top:22px;">Status History</h3>
      <?php if ($history): ?>
        <table class="cp-table">
          <thead><tr><th>Stage</th><th>Recorded At</th></tr></thead>
          <tbody>
            <?php foreach ($history as $hItem): ?>
              <tr>
                <td><span class="cp-badge <?= h(customerOrderStatusBadge($hItem['new_status'])) ?>"><?= h($hItem['new_status']) ?></span></td>
                <td><?= h(date('M d, Y g:i A', strtotime($hItem['changed_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p style="color:var(--cp-muted); font-size:0.88rem;">Status history will appear as staff process your order.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="cp-card">
  <div class="cp-card-head">
    <h3>Payment Records</h3>
  </div>
  <?php if ($payments): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead><tr><th>Reference</th><th>Date</th><th>Method</th><th>Amount</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td>#PAY-<?= h(str_pad((string) $p['id'], 5, '0', STR_PAD_LEFT)) ?></td>
              <td><?= h(date('M d, Y g:i A', strtotime($p['payment_date']))) ?></td>
              <td><?= h($p['payment_method']) ?></td>
              <td><strong>₱<?= h(number_format((float) $p['amount'], 2)) ?></strong></td>
              <td><?= h($p['notes'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No payments recorded yet</strong>
      <p>Payments recorded at the shop counter will appear here automatically.</p>
    </div>
  <?php endif; ?>
</div>

<div class="no-print">
  <a href="orders.php" class="cp-btn cp-btn-outline">← Back to My Orders</a>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Order Details', 'orders', $content);
