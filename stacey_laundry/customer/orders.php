<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();

$search = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'all'));
$dateFilter = trim((string) ($_GET['date'] ?? ''));
$sort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';

$allowedStatuses = ['all', 'Received', 'Washing', 'Drying', 'Folding', 'Ready for Pickup', 'Picked Up', 'Cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}
if ($dateFilter !== '') {
    $dt = DateTime::createFromFormat('!Y-m-d', $dateFilter);
    if (!$dt || $dt->format('Y-m-d') !== $dateFilter) {
        $dateFilter = '';
    }
}

$where = ['o.customer_id = ?'];
$params = [$customerId];

if ($search !== '') {
    $where[] = 'o.order_number LIKE ?';
    $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
}
if ($status !== 'all') {
    $where[] = 'o.status = ?';
    $params[] = $status;
}
if ($dateFilter !== '') {
    $where[] = 'DATE(o.created_at) = ?';
    $params[] = $dateFilter;
}

$sql = "SELECT o.*,
               COALESCE(GROUP_CONCAT(s.name SEPARATOR ', '), 'Laundry Service') AS service_names,
               COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = o.id), 0) AS amount_paid
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN services s ON s.id = oi.service_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY o.id
        ORDER BY o.created_at {$sort}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <h2>My Laundry Orders</h2>
    <span class="cp-badge cp-badge-green"><?= h((string) count($orders)) ?> found</span>
  </div>

  <form method="get" class="cp-filter-bar">
    <div>
      <label for="q">Receipt / Job Number</label>
      <input class="cp-input" id="q" name="q" value="<?= h($search) ?>" placeholder="e.g. LW-2025001">
    </div>
    <div>
      <label for="status">Status</label>
      <select class="cp-select" id="status" name="status">
        <?php foreach ($allowedStatuses as $st): ?>
          <option value="<?= h($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= h($st === 'all' ? 'All Statuses' : $st) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="date">Order Date</label>
      <input class="cp-input" id="date" type="date" name="date" value="<?= h($dateFilter) ?>">
    </div>
    <div>
      <label for="sort">Sort</label>
      <select class="cp-select" id="sort" name="sort">
        <option value="newest" <?= $sort === 'DESC' ? 'selected' : '' ?>>Newest First</option>
        <option value="oldest" <?= $sort === 'ASC' ? 'selected' : '' ?>>Oldest First</option>
      </select>
    </div>
    <div style="flex:0 0 auto; display:flex; gap:8px;">
      <button type="submit" class="cp-btn">Filter</button>
      <a href="orders.php" class="cp-btn cp-btn-outline">Reset</a>
    </div>
  </form>

  <?php if ($orders): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead>
          <tr>
            <th>Receipt #</th>
            <th>Date</th>
            <th>Service</th>
            <th>Weight / Qty</th>
            <th>Discount</th>
            <th>Total</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Est. Completion</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o):
              $paid = (float) $o['amount_paid'];
              $total = (float) $o['total'];
              $bal = $o['status'] === 'Cancelled' ? 0.0 : max(0.0, $total - $paid);
          ?>
            <tr>
              <td><strong><?= h($o['order_number']) ?></strong></td>
              <td><?= h(date('M d, Y', strtotime($o['created_at']))) ?></td>
              <td>
                <?= h($o['service_names']) ?>
                <?php if (!empty($o['special_instructions'])): ?>
                  <div style="font-size:0.78rem; color:var(--cp-muted);">Note: <?= h($o['special_instructions']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= h(number_format((float) $o['weight_kg'], 2)) ?> kg / <?= h((string) (int) $o['quantity']) ?></td>
              <td>₱<?= h(number_format((float) $o['discount'], 2)) ?></td>
              <td><strong>₱<?= h(number_format($total, 2)) ?></strong></td>
              <td>₱<?= h(number_format($paid, 2)) ?></td>
              <td style="color:<?= $bal > 0 ? 'var(--cp-danger)' : 'var(--cp-primary-dark)' ?>; font-weight:700;">₱<?= h(number_format($bal, 2)) ?></td>
              <td><?= h($o['estimated_completion'] ? date('M d, Y', strtotime($o['estimated_completion'])) : '—') ?></td>
              <td><span class="cp-badge <?= h(customerOrderStatusBadge($o['status'])) ?>"><?= h($o['status']) ?></span></td>
              <td style="white-space:nowrap;">
                <a href="order_details.php?id=<?= h((string) $o['id']) ?>" class="cp-btn cp-btn-outline cp-btn-sm">Details</a>
                <a href="tracking.php?order_id=<?= h((string) $o['id']) ?>" class="cp-btn cp-btn-sm">Track</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No laundry orders match your filters</strong>
      <p>Try clearing your search or status filters to see all your laundry transactions.</p>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('My Laundry Orders', 'orders', $content);
