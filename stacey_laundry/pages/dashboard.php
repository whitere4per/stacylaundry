<?php
$pdo = db();
$period = $_GET['period'] ?? 'week';
if (!in_array($period, ['week', 'month', 'all'], true)) {
    $period = 'week';
}

$periodLabels = [
    'week' => 'This week',
    'month' => 'This month',
    'all' => 'All time',
];
$nextPeriod = [
    'week' => 'month',
    'month' => 'all',
    'all' => 'week',
];

$orderDateFilter = $period === 'week'
    ? 'WHERE YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)'
    : ($period === 'month' ? 'WHERE MONTH(o.created_at) = MONTH(CURDATE()) AND YEAR(o.created_at) = YEAR(CURDATE())' : '');

$paymentDateFilter = $period === 'week'
    ? 'WHERE YEARWEEK(p.payment_date, 1) = YEARWEEK(CURDATE(), 1)'
    : ($period === 'month' ? 'WHERE MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE())' : '');

$stats = [
    'orders_total' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'today_orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()')->fetchColumn(),
    'processing' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Received','Washing','Drying','Folding')")->fetchColumn(),
    'ready' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Ready for Pickup'")->fetchColumn(),
    'picked' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Picked Up'")->fetchColumn(),
    'pending' => (float) $pdo->query("SELECT COALESCE(SUM(total - COALESCE((SELECT SUM(amount) FROM payments WHERE payments.order_id = orders.id),0)),0) FROM orders WHERE status <> 'Cancelled' AND total > COALESCE((SELECT SUM(amount) FROM payments WHERE payments.order_id = orders.id),0)")->fetchColumn(),
    'today_collected' => (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(payment_date)=CURDATE()')->fetchColumn(),
    'monthly_revenue' => (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())')->fetchColumn(),
];
$orders = $pdo->query("SELECT o.*, c.full_name FROM orders o JOIN customers c ON c.id=o.customer_id {$orderDateFilter} ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
$payments = $pdo->query("SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id=p.order_id {$paymentDateFilter} ORDER BY p.payment_date DESC LIMIT 5")->fetchAll();

$maxSnapshot = max(1, $stats['today_orders'], $stats['ready'], $stats['picked']);
$receivedPct = max(8, (int) round(($stats['today_orders'] / $maxSnapshot) * 100));
$readyPct = max(8, (int) round(($stats['ready'] / $maxSnapshot) * 100));
$pickedPct = max(8, (int) round(($stats['picked'] / $maxSnapshot) * 100));
?>
<div class="dashboard-shell">
  <div class="dashboard-header">
    <div>
      <div class="eyebrow">Operations overview</div>
      <h1>Administrative Dashboard</h1>
    </div>
    <div class="dashboard-tools">
      <a href="index.php?page=dashboard&period=<?= h($nextPeriod[$period]) ?>" class="soft-btn" style="text-decoration:none; display:inline-flex; align-items:center;" title="Click to switch time range"><?= h($periodLabels[$period]) ?></a>
      <a href="index.php?page=reports" class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center;">Generate Report</a>
    </div>
  </div>

  <div class="kpi-grid">
    <div class="kpi-card blue">
      <div class="kpi-head">
        <span class="kpi-label">Monthly revenue</span>
        <span class="trend-up">+12.4%</span>
      </div>
      <div class="kpi-value">₱<?= h(number_format($stats['monthly_revenue'], 2)) ?></div>
      <div class="kpi-meta">Compared to last month</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-head">
        <span class="kpi-label">Orders today</span>
        <span class="trend-up">+8.6%</span>
      </div>
      <div class="kpi-value"><?= h((string)$stats['today_orders']) ?></div>
      <div class="kpi-meta">Fresh items received</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-head">
        <span class="kpi-label">Processing</span>
        <span class="trend-neutral">Live</span>
      </div>
      <div class="kpi-value"><?= h((string)$stats['processing']) ?></div>
      <div class="kpi-meta">Orders in queue</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-head">
        <span class="kpi-label">Pending balance</span>
        <span class="trend-down">Due</span>
      </div>
      <div class="kpi-value">₱<?= h(number_format($stats['pending'], 2)) ?></div>
      <div class="kpi-meta">Customer receivables</div>
    </div>
  </div>

  <div class="main-grid">
    <section class="panel panel-wide">
      <div class="panel-head">
        <h3>Recent Orders</h3>
        <a href="index.php?page=orders">View all</a>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Customer</th>
              <th>Status</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $order): ?>
              <tr>
                <td><?= h($order['order_number']) ?></td>
                <td><?= h($order['full_name']) ?></td>
                <td><span class="status-badge <?= statusClass($order['status']) ?>"><?= h($order['status']) ?></span></td>
                <td>₱<?= h(number_format((float)$order['total'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?>
              <tr><td colspan="4"><div class="empty-state"><strong>No recent orders for this period.</strong></div></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <aside class="panel panel-side">
      <div class="panel-head">
        <h3>Recent Transactions</h3>
        <span class="soft-badge"><?= h($periodLabels[$period]) ?></span>
      </div>
      <div class="transaction-list">
        <?php foreach ($payments as $payment): ?>
          <div class="transaction-item">
            <div>
              <strong><?= h($payment['order_number']) ?></strong>
              <small><?= h($payment['payment_method']) ?></small>
            </div>
            <span class="amount positive">₱<?= h(number_format((float)$payment['amount'], 2)) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$payments): ?>
          <div class="empty-state"><strong>No transactions for this period.</strong></div>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <div class="bottom-grid">
    <section class="panel">
      <div class="panel-head">
        <h3>Operational Snapshot</h3>
        <span class="soft-badge subtle">Live</span>
      </div>
      <div class="mini-bars">
        <div class="bar-row">
          <span>Received</span>
          <div class="mini-track"><i style="height:<?= h((string)$receivedPct) ?>%"></i></div>
          <strong><?= h((string)$stats['today_orders']) ?></strong>
        </div>
        <div class="bar-row">
          <span>Ready</span>
          <div class="mini-track"><i style="height:<?= h((string)$readyPct) ?>%"></i></div>
          <strong><?= h((string)$stats['ready']) ?></strong>
        </div>
        <div class="bar-row">
          <span>Picked</span>
          <div class="mini-track"><i style="height:<?= h((string)$pickedPct) ?>%"></i></div>
          <strong><?= h((string)$stats['picked']) ?></strong>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <h3>Service Flow</h3>
        <span class="soft-badge subtle">Queue</span>
      </div>
      <div class="status-stack">
        <div class="status-chip"><span class="dot blue"></span> Received <strong><?= h((string)$stats['today_orders']) ?></strong></div>
        <div class="status-chip"><span class="dot amber"></span> Washing <strong><?= h((string)$stats['processing']) ?></strong></div>
        <div class="status-chip"><span class="dot green"></span> Ready <strong><?= h((string)$stats['ready']) ?></strong></div>
        <div class="status-chip"><span class="dot gray"></span> Picked Up <strong><?= h((string)$stats['picked']) ?></strong></div>
      </div>
    </section>
  </div>
</div>
