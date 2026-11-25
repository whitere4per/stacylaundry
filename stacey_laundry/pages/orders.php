<?php
$pdo = db();
$orderRequestToken = $_SESSION['order_request_token'] ?? bin2hex(random_bytes(24));
$_SESSION['order_request_token'] = $orderRequestToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        if (isset($_POST['quick_status'])) {
            verifyCsrf();
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $newStatus = safePost('status');
            if ($orderId <= 0 || $newStatus === '') {
                throw new InvalidArgumentException('Choose a valid order and status.');
            }
            $validStatuses = ['Received', 'Washing', 'Drying', 'Folding', 'Ready for Pickup', 'Picked Up', 'Cancelled'];
            if (!in_array($newStatus, $validStatuses, true)) {
                throw new InvalidArgumentException('The selected order status is invalid.');
            }
            $pdo->beginTransaction();
            $transactionStarted = true;
            $current = $pdo->prepare('SELECT status, customer_id, order_number FROM orders WHERE id = ? FOR UPDATE');
            $current->execute([$orderId]);
            $orderRow = $current->fetch();
            $oldStatus = $orderRow ? $orderRow['status'] : false;
            if ($oldStatus === false) {
                throw new InvalidArgumentException('Order not found. Refresh the list and try again.');
            }
            $transitions = [
                'Received' => ['Washing', 'Cancelled'],
                'Washing' => ['Drying', 'Cancelled'],
                'Drying' => ['Folding', 'Cancelled'],
                'Folding' => ['Ready for Pickup', 'Cancelled'],
                'Ready for Pickup' => ['Picked Up'],
                'Picked Up' => [],
                'Cancelled' => [],
            ];
            if (!in_array($newStatus, $transitions[$oldStatus] ?? [], true)) {
                throw new InvalidArgumentException('That status change is not allowed. Orders must move through the laundry workflow in sequence.');
            }
            $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $orderId]);
            $history = $pdo->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes) VALUES (?, ?, ?, ?, ?)');
            $history->execute([$orderId, $oldStatus, $newStatus, $_SESSION['user']['id'], 'Status updated from order list.']);
            $statusMessages = [
                'Washing' => ['Laundry washing started', "Your order {$orderRow['order_number']} is now being washed."],
                'Drying' => ['Laundry drying started', "Your order {$orderRow['order_number']} is now in the dryer."],
                'Folding' => ['Laundry folding started', "Your order {$orderRow['order_number']} is now being folded."],
                'Ready for Pickup' => ['Laundry ready for pickup', "Great news! Your order {$orderRow['order_number']} is ready for pickup at the counter."],
                'Picked Up' => ['Laundry successfully picked up', "Your order {$orderRow['order_number']} has been picked up. Thank you for choosing Stacey Laundry Hub!"],
                'Cancelled' => ['Laundry order cancelled', "Your order {$orderRow['order_number']} has been cancelled."],
            ];
            if (isset($statusMessages[$newStatus])) {
                notifyCustomer($pdo, (int) $orderRow['customer_id'], $orderId, $statusMessages[$newStatus][0], $statusMessages[$newStatus][1], 'order');
            }
            logActivity($pdo, 'Order status changed', "Order ID: {$orderId}; {$oldStatus} -> {$newStatus}");
            $pdo->commit();
            $transactionStarted = false;
            bootMessage('success', 'Order status updated.');
            redirect('index.php?page=orders');
        }

        verifyCsrf();
        $submittedToken = safePost('request_token');
        if ($submittedToken === '' || !hash_equals($orderRequestToken, $submittedToken)) {
            throw new InvalidArgumentException('This order form was already submitted or has expired. Refresh the page before trying again.');
        }
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $weightInput = safePost('weight_kg');
        $quantityInput = safePost('quantity');
        $discountInput = safePost('discount', '0');
        if (!is_numeric($weightInput) || (float) $weightInput <= 0) {
            throw new InvalidArgumentException('Weight must be greater than zero.');
        }
        if (!ctype_digit($quantityInput) || (int) $quantityInput < 1) {
            throw new InvalidArgumentException('Quantity must be at least one.');
        }
        if (!is_numeric($discountInput) || (float) $discountInput < 0) {
            throw new InvalidArgumentException('Discount must be zero or greater.');
        }
        $newName = '';
        $newPhone = '';
        $newAddress = '';
        if ($customerId === 0) {
            $newName = safePost('new_full_name');
            $newPhone = safePost('new_contact_number');
            $newAddress = safePost('new_address');
            if ($newName === '') {
                throw new InvalidArgumentException("Please enter the new customer's name.");
            }
            if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $newPhone) || !preg_match('/\d/', $newPhone)) {
                throw new InvalidArgumentException('The new customer phone number is invalid.');
            }
            if ($newAddress === '') {
                throw new InvalidArgumentException("Please enter the new customer's address.");
            }
        } elseif ($customerId < 1) {
            throw new InvalidArgumentException('Select a valid customer.');
        } else {
            $customerCheck = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
            $customerCheck->execute([$customerId]);
            if (!$customerCheck->fetchColumn()) {
                throw new InvalidArgumentException('Customer not found. Refresh the page and select a current customer.');
            }
        }
        $completion = safePost('estimated_completion');
        if ($completion !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $completion);
            if (!$date || $date->format('Y-m-d') !== $completion) {
                throw new InvalidArgumentException('Enter a valid expected completion date.');
            }
            if ($date < new DateTime('today')) {
                throw new InvalidArgumentException('The expected completion date cannot be earlier than today.');
            }
        }

        $service = $pdo->prepare('SELECT * FROM services WHERE id = ? AND status = "active" LIMIT 1');
        $service->execute([$serviceId]);
        $serviceRow = $service->fetch();
        if (!$serviceRow) {
            throw new InvalidArgumentException('Select an available laundry service.');
        }
        $weight = (float) $weightInput;
        $quantity = (int) $quantityInput;
        $discount = (float) $discountInput;
        $subtotal = ((float) $serviceRow['price'] * $quantity) + ($weight * 15);
        if ($discount > $subtotal) {
            throw new InvalidArgumentException('Discount cannot exceed the order subtotal.');
        }
        $total = $subtotal - $discount;

        $pdo->beginTransaction();
        $transactionStarted = true;
        if ($customerId === 0) {
            $normalizedNewPhone = preg_replace('/[^0-9+]/', '', $newPhone) ?? '';
            $duplicateCustomer = $pdo->prepare('SELECT id FROM customers WHERE REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", "") = ? LIMIT 1');
            $duplicateCustomer->execute([$normalizedNewPhone]);
            if ($duplicateCustomer->fetchColumn()) {
                throw new InvalidArgumentException('A customer with this phone number is already registered. Select that customer instead.');
            }
            $stmt = $pdo->prepare('INSERT INTO customers (full_name, contact_number, address, registration_date) VALUES (?, ?, ?, CURDATE())');
            $stmt->execute([$newName, $newPhone, $newAddress]);
            $customerId = (int) $pdo->lastInsertId();
        }
        $orderNo = 'LW-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('INSERT INTO orders (order_number, customer_id, status, weight_kg, quantity, subtotal, discount, total, special_instructions, estimated_completion, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderNo, $customerId, 'Received', $weight, $quantity, $subtotal, $discount, $total, safePost('special_instructions'), $completion !== '' ? $completion : null, $_SESSION['user']['id']]);
        $orderId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, service_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)');
        $itemStmt->execute([$orderId, $serviceId, $quantity, $serviceRow['price'], $subtotal]);
        $history = $pdo->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, notes) VALUES (?, ?, ?, ?, ?)');
        $history->execute([$orderId, 'New', 'Received', $_SESSION['user']['id'], 'Order created.']);
        notifyCustomer($pdo, $customerId, $orderId, 'Laundry order received', "Your order {$orderNo} has been received and queued for processing.", 'order');
        logActivity($pdo, 'Order created', "Order: {$orderNo}; Customer ID: {$customerId}; Total: " . number_format($total, 2, '.', ''));
        $pdo->commit();
        $transactionStarted = false;
        $_SESSION['order_request_token'] = bin2hex(random_bytes(24));
        bootMessage('success', 'Laundry order created successfully.');
        redirect('index.php?page=orders');
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        preserveFormInput('order', ['customer_id', 'service_id', 'weight_kg', 'quantity', 'discount', 'estimated_completion', 'special_instructions', 'new_full_name', 'new_contact_number', 'new_address']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'order_save');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Something went wrong while saving this order. Your information has not been confirmed as saved. Please try again.');
        redirect('index.php?page=orders');
    }
}

$oldOrder = takeFormInput('order');
$statusFilter = $_GET['status'] ?? 'all';
$allowedStatuses = ['all','Received','Washing','Drying','Folding','Ready for Pickup','Picked Up','Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$counts = [
    'all' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'Received' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Received'")->fetchColumn(),
    'Washing' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Washing'")->fetchColumn(),
    'Drying' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Drying'")->fetchColumn(),
    'Folding' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Folding'")->fetchColumn(),
    'Ready for Pickup' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Ready for Pickup'")->fetchColumn(),
    'Picked Up' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Picked Up'")->fetchColumn(),
    'Cancelled' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Cancelled'")->fetchColumn(),
];

$where = '';
$params = [];
if ($statusFilter !== 'all') {
    $where = ' WHERE o.status = ?';
    $params[] = $statusFilter;
}

$query = 'SELECT o.*, c.full_name, c.address FROM orders o JOIN customers c ON c.id=o.customer_id' . $where . ' ORDER BY o.created_at DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();
$customers = $pdo->query('SELECT * FROM customers ORDER BY full_name')->fetchAll();
$services = $pdo->query('SELECT * FROM services WHERE status = "active" ORDER BY name')->fetchAll();
?>

<div class="order-management-shell">
  <div class="order-toolbar">
    <div class="filter-pills">
      <?php foreach ($allowedStatuses as $status): ?>
        <a class="filter-pill <?= $statusFilter === $status ? 'active' : '' ?>" href="index.php?page=orders&status=<?= h(urlencode($status)) ?>"><?= h(ucfirst($status === 'all' ? 'All Orders' : $status)) ?> <span><?= h((string)$counts[$status]) ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="order-form-panel">
    <h3>Create new order</h3>
    <form method="post" class="order-form confirm-form" data-confirm-title="Create Laundry Order?" data-confirm="Create this laundry order and notify the customer?" data-confirm-label="Create Order">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="request_token" value="<?= h($orderRequestToken) ?>">
      <div class="row order-form-grid">
        <div><label>Select customer</label><select name="customer_id"><option value="0" <?= ($oldOrder['customer_id'] ?? '0') === '0' ? 'selected' : '' ?>>Create new customer</option><?php foreach ($customers as $customer): ?><option value="<?= h((string)$customer['id']) ?>" <?= ($oldOrder['customer_id'] ?? '') === (string)$customer['id'] ? 'selected' : '' ?>><?= h($customer['full_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Service</label><select name="service_id" required><?php foreach ($services as $service): ?><option value="<?= h((string)$service['id']) ?>" <?= ($oldOrder['service_id'] ?? '') === (string)$service['id'] ? 'selected' : '' ?>><?= h($service['name']) ?> (₱<?= h(number_format((float)$service['price'], 2)) ?>)</option><?php endforeach; ?></select></div>
      </div>
      <div class="row order-form-grid">
        <div><label>Weight (kg)</label><input type="number" min="0.01" step="0.01" name="weight_kg" value="<?= h($oldOrder['weight_kg'] ?? '1') ?>" data-error-message="Weight must be greater than zero." required></div>
        <div><label>Quantity</label><input type="number" min="1" step="1" name="quantity" value="<?= h($oldOrder['quantity'] ?? '1') ?>" data-error-message="Quantity must be at least one." required></div>
        <div><label>Discount</label><input type="number" min="0" step="0.01" name="discount" value="<?= h($oldOrder['discount'] ?? '0') ?>" data-error-message="Discount must be zero or greater."></div>
      </div>
      <div class="row order-form-grid">
        <div><label>Estimated completion</label><input type="date" min="<?= h(date('Y-m-d')) ?>" name="estimated_completion" value="<?= h($oldOrder['estimated_completion'] ?? '') ?>"></div>
        <div><label>Special instructions</label><input name="special_instructions" value="<?= h($oldOrder['special_instructions'] ?? '') ?>"></div>
      </div>
      <div class="row order-form-grid compact-grid">
        <div><label>New customer name</label><input name="new_full_name" maxlength="120" value="<?= h($oldOrder['new_full_name'] ?? '') ?>" data-error-message="Please enter the new customer's name."></div>
        <div><label>New contact</label><input type="tel" name="new_contact_number" maxlength="30" value="<?= h($oldOrder['new_contact_number'] ?? '') ?>" data-error-message="The new customer phone number is invalid."></div>
        <div><label>New address</label><textarea name="new_address" rows="2" data-error-message="Please enter the new customer's address."><?= h($oldOrder['new_address'] ?? '') ?></textarea></div>
      </div>
      <div class="order-form-actions">
        <button type="submit" class="primary-btn">Create Order</button>
      </div>
    </form>
  </div>

  <div class="order-table-panel">
    <div class="panel-head">
      <h3>Current Orders</h3>
      <span class="soft-badge subtle"><?= h((string)$counts[$statusFilter]) ?> records</span>
    </div>

    <div class="table-wrap">
      <table class="order-table">
        <thead>
          <tr>
            <th>Re-No</th>
            <th>Date &amp; Time</th>
            <th>Customer</th>
            <th>Address</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td>
                <div class="mono"><?= h($order['order_number']) ?></div>
              </td>
              <td>
                <div class="date-block">
                  <strong><?= h(date('d M Y', strtotime($order['created_at']))) ?></strong>
                  <small><?= h(date('H:i', strtotime($order['created_at']))) ?></small>
                </div>
              </td>
              <td>
                <div class="customer-cell">
                  <span class="customer-avatar"><?= h(strtoupper(substr($order['full_name'], 0, 1))) ?></span>
                  <div>
                    <strong><?= h($order['full_name']) ?></strong>
                    <small>Customer</small>
                  </div>
                </div>
              </td>
              <td><?= h($order['address'] ?: 'N/A') ?></td>
              <td><strong>₱<?= h(number_format((float)$order['total'], 2)) ?></strong></td>
              <td><span class="status-badge <?= statusClass($order['status']) ?>"><?= h($order['status']) ?></span></td>
              <td>
                <form method="post" class="inline-action-form order-status-form" data-order-number="<?= h($order['order_number']) ?>">
                  <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                  <input type="hidden" name="quick_status" value="1">
                  <input type="hidden" name="order_id" value="<?= h((string)$order['id']) ?>">
                  <select name="status" class="quick-status">
                    <?php foreach (['Received','Washing','Drying','Folding','Ready for Pickup','Picked Up','Cancelled'] as $status): ?>
                      <option value="<?= h($status) ?>" <?= $status === $order['status'] ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="tiny-btn">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="7"><div class="empty-state"><strong>No laundry orders found.</strong><p>Create a new order to get started or choose another status filter.</p></div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const customerSelect = document.querySelector('.order-form select[name="customer_id"]');
    const newCustomerFields = document.querySelector('.order-form .compact-grid');
    if (!customerSelect || !newCustomerFields) return;
    const toggleNewCustomer = function () {
      const isNew = customerSelect.value === '0';
      newCustomerFields.hidden = !isNew;
      newCustomerFields.querySelectorAll('input, textarea').forEach(function (field) {
        field.disabled = !isNew;
      });
    };
    customerSelect.addEventListener('change', toggleNewCustomer);
    toggleNewCustomer();
  });
</script>

