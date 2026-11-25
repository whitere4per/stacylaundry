<?php
$pdo = db();
$from = isset($_GET['from']) && is_string($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$to = isset($_GET['to']) && is_string($_GET['to']) ? $_GET['to'] : date('Y-m-d');
foreach (['from' => $from, 'to' => $to] as $field => $value) {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        bootMessage('error', 'Enter a valid start and end date for the report.');
        redirect('index.php?page=reports');
    }
}
if ($from > $to) {
    bootMessage('error', 'The report start date cannot be later than the end date.');
    redirect('index.php?page=reports');
}
$report = $pdo->prepare('SELECT o.order_number, c.full_name, o.status, o.total, COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=o.id),0) AS paid FROM orders o JOIN customers c ON c.id=o.customer_id WHERE DATE(o.created_at) BETWEEN ? AND ? ORDER BY o.created_at DESC');
$report->execute([$from, $to]);
$rows = $report->fetchAll();
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $out = fopen('php://output', 'w');
    if ($out === false) {
        logApplicationError(new RuntimeException('Unable to open CSV output stream.'), 'report_export');
        bootMessage('error', 'The CSV report could not be exported. Please try again.');
        redirect('index.php?page=reports&from=' . urlencode($from) . '&to=' . urlencode($to));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laundry-report.csv"');
    fputcsv($out, ['Order', 'Customer', 'Status', 'Total', 'Paid']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['order_number'], $row['full_name'], $row['status'], $row['total'], $row['paid']]);
    }
    fclose($out);
    exit;
}
if (!$rows) {
    bootMessage('information', 'No orders were found for the selected date range.');
}
?>
<div style="margin-bottom:18px;">
  <h2 style="margin:0 0 4px;">Reports</h2>
  <small style="color:var(--muted);">Filter transactions and export financial summaries.</small>
</div>
<form method="get" style="margin-bottom:18px;" class="report-filter">
  <input type="hidden" name="page" value="reports">
  <div class="row">
    <div style="flex:1"><label>From</label><input type="date" name="from" value="<?= h($from) ?>" required></div>
    <div style="flex:1"><label>To</label><input type="date" name="to" value="<?= h($to) ?>" required></div>
    <div style="flex:1; align-self:flex-end;"><button type="submit" data-loading-label="Generating...">Apply</button> <a class="btn secondary" href="index.php?page=reports&from=<?= h(urlencode($from)) ?>&to=<?= h(urlencode($to)) ?>&export=csv">Export CSV</a></div>
  </div>
</form>
<div class="table-wrap">
  <table>
    <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th><th>Paid</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= h($row['order_number']) ?></td>
          <td><?= h($row['full_name']) ?></td>
          <td><span class="status-badge <?= statusClass($row['status']) ?>"><?= h($row['status']) ?></span></td>
          <td>₱<?= h(number_format((float)$row['total'], 2)) ?></td>
          <td>₱<?= h(number_format((float)$row['paid'], 2)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-state"><strong>No report records for this period.</strong><p>Choose a wider date range or check back after orders are created.</p></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
