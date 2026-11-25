<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

// Order billing summary
$ordersStmt = $pdo->prepare(
    'SELECT o.id, o.order_number, o.total, o.status, o.created_at,
            COALESCE(SUM(p.amount), 0) AS total_paid
     FROM orders o
     LEFT JOIN payments p ON p.order_id = o.id
     WHERE o.customer_id = ?
     GROUP BY o.id
     ORDER BY o.created_at DESC'
);
$ordersStmt->execute([$customerId]);
$billingOrders = $ordersStmt->fetchAll();

// Total outstanding balance across non-cancelled orders (or all orders with balance)
$totalBilled = 0.0;
$totalPaidAll = 0.0;
$totalOutstanding = 0.0;
foreach ($billingOrders as $bo) {
    $t = (float) $bo['total'];
    $p = (float) $bo['total_paid'];
    $totalPaidAll += $p;
    if ($bo['status'] !== 'Cancelled') {
        $totalBilled += $t;
        $totalOutstanding += max(0.0, $t - $p);
    }
}

// Detailed payment history
$historyStmt = $pdo->prepare(
    'SELECT p.id, p.order_id, p.amount, p.payment_method, p.payment_date, p.notes, o.order_number
     FROM payments p
     JOIN orders o ON o.id = p.order_id
     WHERE o.customer_id = ?
     ORDER BY p.payment_date DESC, p.id DESC'
);
$historyStmt->execute([$customerId]);
$paymentHistory = $historyStmt->fetchAll();

ob_start();
?>
<div class="cp-grid-3" style="margin-bottom:20px;">
  <div class="cp-kpi">
    <div class="cp-kpi-label">Total Billed</div>
    <div class="cp-kpi-val">₱<?= h(number_format($totalBilled, 2)) ?></div>
  </div>
  <div class="cp-kpi highlight">
    <div class="cp-kpi-label">Total Paid</div>
    <div class="cp-kpi-val">₱<?= h(number_format($totalPaidAll, 2)) ?></div>
  </div>
  <div class="cp-kpi">
    <div class="cp-kpi-label">Outstanding Balance</div>
    <div class="cp-kpi-val" style="color:<?= $totalOutstanding > 0 ? 'var(--cp-danger)' : 'var(--cp-primary-dark)' ?>;">₱<?= h(number_format($totalOutstanding, 2)) ?></div>
  </div>
</div>

<div class="cp-card">
  <div class="cp-card-head">
    <h2>Order Billing Summary</h2>
    <button type="button" class="cp-btn cp-btn-outline cp-btn-sm no-print" data-print-receipt>Print Statement</button>
  </div>
  <?php if ($billingOrders): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead>
          <tr>
            <th>Order #</th>
            <th>Order Date</th>
            <th>Order Cost</th>
            <th>Total Paid</th>
            <th>Remaining Balance</th>
            <th>Payment Status</th>
            <th class="no-print">Receipt</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($billingOrders as $bo):
              $t = (float) $bo['total'];
              $p = (float) $bo['total_paid'];
              $isCancelled = $bo['status'] === 'Cancelled';
              $rem = $isCancelled ? 0.0 : max(0.0, $t - $p);
              [$payLabel, $payClass] = $isCancelled ? ['Cancelled', 'cp-badge-gray'] : customerPaymentStatus($t, $p);
          ?>
            <tr>
              <td><strong><?= h($bo['order_number']) ?></strong></td>
              <td><?= h(date('M d, Y', strtotime($bo['created_at']))) ?></td>
              <td>₱<?= h(number_format($t, 2)) ?></td>
              <td>₱<?= h(number_format($p, 2)) ?></td>
              <td style="font-weight:700; color:<?= $rem > 0 ? 'var(--cp-danger)' : 'var(--cp-primary-dark)' ?>;">₱<?= h(number_format($rem, 2)) ?></td>
              <td><span class="cp-badge <?= h($payClass) ?>"><?= h($payLabel) ?></span></td>
              <td class="no-print"><a href="order_details.php?id=<?= h((string) $bo['id']) ?>" class="cp-btn cp-btn-outline cp-btn-sm">View Receipt</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No billing records yet</strong>
      <p>Your order totals and balances will appear here once an order is created.</p>
    </div>
  <?php endif; ?>
</div>

<div class="cp-card">
  <div class="cp-card-head">
    <h2>Payment History</h2>
    <span class="cp-badge cp-badge-gray"><?= h((string) count($paymentHistory)) ?> transactions</span>
  </div>
  <?php if ($paymentHistory): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead>
          <tr>
            <th>Transaction Ref</th>
            <th>Order #</th>
            <th>Payment Date</th>
            <th>Method</th>
            <th>Amount</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($paymentHistory as $ph): ?>
            <tr>
              <td><strong>#PAY-<?= h(str_pad((string) $ph['id'], 5, '0', STR_PAD_LEFT)) ?></strong></td>
              <td><a href="order_details.php?id=<?= h((string) $ph['order_id']) ?>"><?= h($ph['order_number']) ?></a></td>
              <td><?= h(date('M d, Y g:i A', strtotime($ph['payment_date']))) ?></td>
              <td><?= h($ph['payment_method']) ?></td>
              <td><strong>₱<?= h(number_format((float) $ph['amount'], 2)) ?></strong></td>
              <td><?= h($ph['notes'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No payments recorded yet</strong>
      <p>Accepted payment methods at the shop: Cash, Bank Transfer, and G-Cash.</p>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Payments & Billing', 'payments', $content);
