<?php
$pdo = db();
$paymentRequestToken = $_SESSION['payment_request_token'] ?? bin2hex(random_bytes(24));
$_SESSION['payment_request_token'] = $paymentRequestToken;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        verifyCsrf();
        $submittedToken = safePost('request_token');
        if ($submittedToken === '' || !hash_equals($paymentRequestToken, $submittedToken)) {
            throw new InvalidArgumentException('This payment form was already submitted or has expired. Refresh the page before trying again.');
        }
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $amountInput = safePost('amount');
        if ($orderId < 1) {
            throw new InvalidArgumentException('Select a valid order.');
        }
        if (!is_numeric($amountInput) || (float) $amountInput <= 0) {
            throw new InvalidArgumentException('Enter a payment amount greater than zero.');
        }
        $amount = round((float) $amountInput, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be at least ₱0.01.');
        }
        $method = safePost('payment_method', 'Cash');
        if (!in_array($method, ['Cash', 'Bank Transfer', 'G-Cash'], true)) {
            throw new InvalidArgumentException('Select a valid payment method.');
        }
        $pdo->beginTransaction();
        $transactionStarted = true;
        $order = $pdo->prepare('SELECT o.* FROM orders o WHERE o.id = ? FOR UPDATE');
        $order->execute([$orderId]);
        $row = $order->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Order not found. Refresh the list and choose a current order.');
        }
        if ($row['status'] === 'Cancelled') {
            throw new InvalidArgumentException('Payments cannot be recorded for a cancelled order.');
        }
        $paidQuery = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ?');
        $paidQuery->execute([$orderId]);
        $paid = (float) $paidQuery->fetchColumn();
        $outstanding = max(0, (float) $row['total'] - $paid);
        if ($outstanding <= 0) {
            throw new InvalidArgumentException('This order has no outstanding balance.');
        }
        if ($amount > $outstanding) {
            throw new InvalidArgumentException('The payment amount cannot exceed the outstanding balance of ₱' . number_format($outstanding, 2) . '.');
        }
        $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, payment_method, payment_date, notes, created_by) VALUES (?, ?, ?, NOW(), ?, ?)');
        $stmt->execute([$orderId, $amount, $method, safePost('notes', 'Recorded payment'), $_SESSION['user']['id']]);
        $newPaid = $paid + $amount;
        $remainingAfter = max(0, (float) $row['total'] - $newPaid);
        if ($remainingAfter <= 0) {
            notifyCustomer($pdo, (int) $row['customer_id'], $orderId, 'Full payment completed', 'Your payment of ₱' . number_format($amount, 2) . " via {$method} for order {$row['order_number']} has been recorded. Your order is now fully paid.", 'payment');
        } else {
            notifyCustomer($pdo, (int) $row['customer_id'], $orderId, 'Partial payment received', 'A partial payment of ₱' . number_format($amount, 2) . " via {$method} for order {$row['order_number']} was recorded. Remaining balance: ₱" . number_format($remainingAfter, 2) . '.', 'payment');
        }
        logActivity($pdo, 'Payment recorded', 'Order: ' . $row['order_number'] . '; Amount: ' . number_format($amount, 2, '.', '') . '; Method: ' . $method);
        $pdo->commit();
        $transactionStarted = false;
        $_SESSION['payment_request_token'] = bin2hex(random_bytes(24));
        bootMessage('success', $newPaid >= (float) $row['total'] ? 'Payment recorded. The order balance is now fully paid.' : 'Partial payment recorded. Outstanding balance: ₱' . number_format(max(0, (float) $row['total'] - $newPaid), 2) . '.');
        redirect('index.php?page=payments');
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        preserveFormInput('payment', ['order_id', 'amount', 'payment_method', 'notes']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'payment_record');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Payment could not be recorded. Your payment was not confirmed as saved. Please try again.');
        redirect('index.php?page=payments');
    }
}
$oldPayment = takeFormInput('payment');
$orders = $pdo->query('SELECT o.*, c.full_name, COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=o.id),0) AS paid FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC')->fetchAll();
$openOrders = array_filter($orders, static function (array $order): bool {
    return $order['status'] !== 'Cancelled' && (float) $order['total'] - (float) $order['paid'] > 0;
});
?>
<div style="margin-bottom:18px;">
  <h2 style="margin:0 0 4px;">Payments</h2>
  <small style="color:var(--muted);">Record payments and review customer account balances.</small>
</div>
<?php if ($openOrders): ?>
<form method="post" class="confirm-form payment-form" data-confirm="Record this payment?">
  <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
  <input type="hidden" name="request_token" value="<?= h($paymentRequestToken) ?>">
  <div class="row">
    <div style="flex:1"><label>Order</label><select name="order_id" required><?php foreach ($orders as $order): $balance = max(0, (float) $order['total'] - (float) $order['paid']); if ($balance <= 0) continue; ?><option value="<?= h((string)$order['id']) ?>" <?= ($oldPayment['order_id'] ?? '') === (string)$order['id'] ? 'selected' : '' ?>><?= h($order['order_number']) ?> - <?= h($order['full_name']) ?> (Outstanding: ₱<?= h(number_format($balance,2)) ?>)</option><?php endforeach; ?></select></div>
    <div style="flex:1"><label>Amount</label><input type="number" min="0.01" step="0.01" name="amount" value="<?= h($oldPayment['amount'] ?? '') ?>" data-error-message="Enter a payment amount greater than zero." required></div>
  </div>
  <div class="row">
    <div style="flex:1"><label>Payment method</label><select name="payment_method"><?php foreach (['Cash', 'Bank Transfer', 'G-Cash'] as $method): ?><option <?= ($oldPayment['payment_method'] ?? 'Cash') === $method ? 'selected' : '' ?>><?= h($method) ?></option><?php endforeach; ?></select></div>
    <div style="flex:1"><label>Notes</label><input name="notes" value="<?= h($oldPayment['notes'] ?? 'Payment recorded') ?>"></div>
  </div>
  <button type="submit" data-loading-label="Recording...">Record Payment</button>
</form>
<?php else: ?>
  <div class="empty-state"><strong>No pending payments.</strong><p>All existing orders are paid, or no orders have been created yet.</p><a class="btn" href="index.php?page=orders">View orders</a></div>
<?php endif; ?>
<br>
<div class="table-wrap">
  <table>
    <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr></thead>
    <tbody>
      <?php foreach ($orders as $order): $outstanding = max(0,(float)$order['total'] - (float)$order['paid']); ?>
        <tr>
          <td><?= h($order['order_number']) ?></td>
          <td><?= h($order['full_name']) ?></td>
          <td>₱<?= h(number_format((float)$order['total'], 2)) ?></td>
          <td>₱<?= h(number_format((float)$order['paid'], 2)) ?></td>
          <td>₱<?= h(number_format($outstanding, 2)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="5"><div class="empty-state"><strong>No orders are available.</strong><p>Create a laundry order before recording a payment.</p><a class="btn" href="index.php?page=orders">Create order</a></div></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
