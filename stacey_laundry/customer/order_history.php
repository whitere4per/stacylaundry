<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT o.*,
            COALESCE(GROUP_CONCAT(s.name SEPARATOR ', '), 'Laundry Service') AS service_names,
            COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) AS amount_paid
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     LEFT JOIN services s ON s.id = oi.service_id
     WHERE o.customer_id = ?
     GROUP BY o.id
     ORDER BY o.created_at DESC"
);
$stmt->execute([$customerId]);
$orders = $stmt->fetchAll();

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <h2>Complete Order History</h2>
    <span class="cp-badge cp-badge-gray"><?= h((string) count($orders)) ?> total</span>
  </div>
  <?php if ($orders): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead>
          <tr>
            <th>Receipt #</th>
            <th>Date</th>
            <th>Service</th>
            <th>Weight</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
              $paid = (float) $o['amount_paid'];
              $total = (float) $o['total'];
          ?>
            <tr>
              <td><strong><?= h($o['order_number']) ?></strong></td>
              <td><?= h(date('M d, Y', strtotime($o['created_at']))) ?></td>
              <td><?= h($o['service_names']) ?></td>
              <td><?= h(number_format((float) $o['weight_kg'], 2)) ?> kg</td>
              <td>₱<?= h(number_format($total, 2)) ?></td>
              <td>₱<?= h(number_format($paid, 2)) ?></td>
              <td><span class="cp-badge <?= h(customerOrderStatusBadge($o['status'])) ?>"><?= h($o['status']) ?></span></td>
              <td><a href="order_details.php?id=<?= h((string) $o['id']) ?>" class="cp-btn cp-btn-outline cp-btn-sm">View History</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No order history yet</strong>
      <p>Your completed and past laundry orders will appear here.</p>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Order History', 'history', $content);
