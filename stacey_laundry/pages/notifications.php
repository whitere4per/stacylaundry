<?php
$pdo = db();
$notificationRequestToken = issueFormToken('notification_create');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transactionStarted = false;
    try {
        verifyCsrf();
        if (isset($_POST['mark_read'])) {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            if ($notificationId < 1) {
                throw new InvalidArgumentException('Choose a valid notification.');
            }
            $pdo->beginTransaction();
            $transactionStarted = true;
            $markRead = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND is_read = 0');
            $markRead->execute([$notificationId]);
            if ($markRead->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM notifications WHERE id = ?');
                $exists->execute([$notificationId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Notification not found. Refresh the page and try again.');
                }
                $pdo->commit();
                $transactionStarted = false;
                bootMessage('information', 'This notification was already marked as read.');
                redirect('index.php?page=notifications');
            }
            logActivity($pdo, 'Notification marked as read', 'Notification ID: ' . $notificationId);
            $pdo->commit();
            $transactionStarted = false;
            bootMessage('success', 'Notification marked as read.');
            redirect('index.php?page=notifications');
        }
        if (isset($_POST['publish_announcement'])) {
            $annTitle = safePost('ann_title');
            $annMessage = safePost('ann_message');
            if ($annTitle === '' || $annMessage === '') {
                throw new InvalidArgumentException('Enter both an announcement title and message.');
            }
            $pdo->beginTransaction();
            $transactionStarted = true;
            notifyCustomer($pdo, null, null, $annTitle, $annMessage, 'general');
            logActivity($pdo, 'Customer announcement published', 'Title: ' . $annTitle);
            $pdo->commit();
            $transactionStarted = false;
            bootMessage('success', 'Shop announcement published to the Customer Portal.');
            redirect('index.php?page=notifications');
        }
        if (isset($_POST['reply_support'])) {
            $ticketId = (int) ($_POST['ticket_id'] ?? 0);
            $replyText = safePost('staff_reply');
            if ($ticketId < 1 || $replyText === '') {
                throw new InvalidArgumentException('Enter a reply for the support inquiry.');
            }
            $pdo->beginTransaction();
            $transactionStarted = true;
            $tStmt = $pdo->prepare('SELECT id, customer_id, order_id, subject FROM customer_support_messages WHERE id = ? FOR UPDATE');
            $tStmt->execute([$ticketId]);
            $ticket = $tStmt->fetch();
            if (!$ticket) {
                throw new InvalidArgumentException('Support inquiry not found.');
            }
            $pdo->prepare('UPDATE customer_support_messages SET staff_reply = ?, replied_by = ?, replied_at = NOW(), status = "resolved" WHERE id = ?')
                ->execute([$replyText, $_SESSION['user']['id'], $ticketId]);
            notifyCustomer($pdo, (int) $ticket['customer_id'], $ticket['order_id'] ? (int) $ticket['order_id'] : null, 'Support Reply: ' . $ticket['subject'], $replyText, 'general');
            logActivity($pdo, 'Customer support inquiry resolved', 'Ticket ID: ' . $ticketId);
            $pdo->commit();
            $transactionStarted = false;
            bootMessage('success', 'Reply sent to customer and ticket marked resolved.');
            redirect('index.php?page=notifications');
        }
        verifyFormToken('notification_create');
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $title = safePost('title');
        $message = safePost('message');
        if ($customerId < 1) {
            throw new InvalidArgumentException('Choose a customer for this notification.');
        }
        if ($title === '' || $message === '') {
            throw new InvalidArgumentException('Enter both a notification title and message.');
        }
        $customerCheck = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $customerCheck->execute([$customerId]);
        if (!$customerCheck->fetchColumn()) {
            throw new InvalidArgumentException('Customer not found. Refresh the page and try again.');
        }
        $pdo->beginTransaction();
        $transactionStarted = true;
        $stmt = $pdo->prepare('INSERT INTO notifications (customer_id, title, message, type, is_read) VALUES (?, ?, ?, "in_system", 0)');
        $stmt->execute([$customerId, $title, $message]);
        logActivity($pdo, 'Internal notification created', 'Customer ID: ' . $customerId . '; Title: ' . $title);
        $pdo->commit();
        $transactionStarted = false;
        rotateFormToken('notification_create');
        bootMessage('success', 'Internal notification created. No SMS or external message was sent.');
        redirect('index.php?page=notifications');
    } catch (Throwable $e) {
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        preserveFormInput('notification', ['customer_id', 'title', 'message']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'notification_create');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to create the notification. Please try again.');
        redirect('index.php?page=notifications');
    }
}
$oldNotification = takeFormInput('notification');
$customers = $pdo->query('SELECT * FROM customers ORDER BY full_name')->fetchAll();
$rows = $pdo->query('SELECT n.*, c.full_name FROM notifications n JOIN customers c ON c.id=n.customer_id ORDER BY n.created_at DESC LIMIT 20')->fetchAll();
$latestOrder = $pdo->query('SELECT o.*, c.full_name FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.created_at DESC LIMIT 1')->fetch();
$unreadCount = (int) $pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
$flow = ['Received' => 0, 'Washing' => 1, 'Drying' => 2, 'Folding' => 3, 'Ready for Pickup' => 4, 'Picked Up' => 5];
$orderStage = !$latestOrder || $latestOrder['status'] === 'Cancelled' ? -1 : ($flow[$latestOrder['status']] ?? 0);
$stageLabels = ['Received', 'Washing', 'Drying', 'Folding', 'Ready for Pickup', 'Picked Up'];
?>

<div class="tracking-shell">
  <?php if ($latestOrder): ?>
  <div class="tracking-card">
    <div class="tracking-header">
      <div class="tracking-badge"><?= h($latestOrder['status']) ?></div>
      <div class="tracking-title"><?= h($latestOrder['status'] === 'Cancelled' ? 'This order was cancelled.' : ($latestOrder['status'] === 'Picked Up' ? 'This order has been picked up.' : 'Laundry progress for ' . $latestOrder['full_name'])) ?></div>
    </div>

    <div class="progress-track">
      <?php foreach ($stageLabels as $index => $label): ?>
        <div class="step <?= $index < $orderStage ? 'done' : ($index === $orderStage ? 'active' : '') ?>" title="<?= h($label) ?>"><span></span></div>
      <?php endforeach; ?>
    </div>
    <div class="progress-labels">
      <?php foreach ($stageLabels as $index => $label): ?><span class="<?= $index === $orderStage ? 'current' : '' ?>"><?= h($label) ?></span><?php endforeach; ?>
    </div>

    <div class="tracking-meta">
      <div class="meta-label">Order</div>
      <div class="meta-value"><strong>#<?= h($latestOrder['order_number']) ?></strong></div>
    </div>

    <div class="tracking-details">
      <div class="detail-row">
        <span class="icon" aria-hidden="true">🧺</span>
        <span>Received <?= h(date('d M Y, g:i a', strtotime($latestOrder['created_at']))) ?></span>
      </div>
      <div class="detail-row">
        <span class="icon" aria-hidden="true">📅</span>
        <span>Estimated completion <?= h($latestOrder['estimated_completion'] ?: 'not set') ?></span>
      </div>
      <div class="detail-row">
        <span class="icon" aria-hidden="true">👤</span>
        <span><?= h($latestOrder['full_name']) ?></span>
      </div>
    </div>

    <div class="tracking-summary">
      <div class="summary-tag">Internal updates</div>
      <div class="summary-counter"><?= h((string)$unreadCount) ?> <span>Unread</span></div>
      <div class="summary-icon" aria-hidden="true">💬</div>
    </div>
  </div>
  <?php else: ?>
    <div class="tracking-card empty-state"><h3>No laundry orders to track yet.</h3><p>Create an order to see its current progress here.</p><a class="btn" href="index.php?page=orders">Create an order</a></div>
  <?php endif; ?>

  <div class="tracking-action">
    <a class="new-order-btn" href="index.php?page=orders">+ New Order</a>
  </div>

  <div class="notification-panel">
    <h3>Send status update</h3>
    <?php if ($customers): ?>
    <form method="post" class="notification-form">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="request_token" value="<?= h($notificationRequestToken) ?>">
      <div class="row">
        <div style="flex:1"><label>Customer</label><select name="customer_id" required><option value="" disabled <?= empty($oldNotification['customer_id']) ? 'selected' : '' ?>>Choose a customer</option><?php foreach ($customers as $customer): ?><option value="<?= h((string)$customer['id']) ?>" <?= ($oldNotification['customer_id'] ?? '') === (string)$customer['id'] ? 'selected' : '' ?>><?= h($customer['full_name']) ?></option><?php endforeach; ?></select></div>
        <div style="flex:1"><label>Title</label><input name="title" value="<?= h($oldNotification['title'] ?? 'Pickup Update') ?>" required></div>
      </div>
      <label>Message</label><textarea name="message" required><?= h($oldNotification['message'] ?? '') ?></textarea>
      <button type="submit" data-loading-label="Saving...">Create internal notification</button>
    </form>
    <?php else: ?>
      <div class="empty-state"><strong>No customers registered.</strong><p>Register a customer before creating an internal update.</p><a class="btn" href="index.php?page=customers">Add customer</a></div>
    <?php endif; ?>

    <div class="notification-list">
      <?php foreach ($rows as $row): ?>
        <div class="notification-item">
          <div class="notification-tag <?= $row['is_read'] ? 'read' : 'unread' ?>"><?= $row['is_read'] ? 'Read' : 'New' ?></div>
          <div class="notification-body">
            <strong><?= h($row['title']) ?></strong>
            <small><?= h($row['full_name']) ?></small>
            <p><?= h($row['message']) ?></p>
          </div>
          <?php if (!$row['is_read']): ?>
            <form method="post" class="notification-read-form">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="mark_read" value="1">
              <input type="hidden" name="notification_id" value="<?= h((string)$row['id']) ?>">
              <button type="submit" class="btn secondary">Mark read</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$rows): ?><div class="empty-state"><strong>No notifications yet.</strong><p>Internal customer updates will appear here after you create one.</p></div><?php endif; ?>
    </div>
  </div>

  <div class="notification-panel">
    <h3>Publish Customer Portal Announcement</h3>
    <form method="post" class="notification-form">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="publish_announcement" value="1">
      <label>Announcement Title</label>
      <input name="ann_title" maxlength="150" placeholder="e.g. Holiday Operating Hours" required>
      <label>Message</label>
      <textarea name="ann_message" placeholder="Write an announcement visible to all Customer Portal users..." required></textarea>
      <button type="submit" data-loading-label="Publishing...">Publish Announcement</button>
    </form>
  </div>

  <div class="notification-panel">
    <h3>Customer Support Inquiries</h3>
    <?php
    $supportTickets = $pdo->query(
        'SELECT m.*, c.full_name, c.contact_number, o.order_number
         FROM customer_support_messages m
         JOIN customers c ON c.id = m.customer_id
         LEFT JOIN orders o ON o.id = m.order_id
         ORDER BY FIELD(m.status, "open", "resolved"), m.created_at DESC
         LIMIT 20'
    )->fetchAll();
    ?>
    <div class="notification-list">
      <?php foreach ($supportTickets as $st): ?>
        <div class="notification-item" style="flex-direction:column; align-items:stretch;">
          <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
            <div>
              <strong><?= h($st['subject']) ?></strong>
              <small style="color:var(--muted);"> · <?= h($st['full_name']) ?> (<?= h($st['contact_number']) ?>)<?= !empty($st['order_number']) ? ' · Order ' . h($st['order_number']) : '' ?></small>
            </div>
            <span class="notification-tag <?= $st['status'] === 'resolved' ? 'read' : 'unread' ?>"><?= h(ucfirst($st['status'])) ?></span>
          </div>
          <p style="margin:6px 0;"><?= h($st['message']) ?></p>
          <?php if ($st['status'] === 'open'): ?>
            <form method="post" style="display:flex; gap:8px; margin-top:8px;">
              <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
              <input type="hidden" name="reply_support" value="1">
              <input type="hidden" name="ticket_id" value="<?= h((string) $st['id']) ?>">
              <input name="staff_reply" placeholder="Write reply to customer..." required style="flex:1; margin:0;">
              <button type="submit" class="tiny-btn">Send Reply</button>
            </form>
          <?php elseif (!empty($st['staff_reply'])): ?>
            <small style="color:var(--muted);">Replied: <?= h($st['staff_reply']) ?></small>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$supportTickets): ?>
        <div class="empty-state"><strong>No customer support inquiries.</strong><p>Messages submitted from the Customer Portal Help &amp; Support page will appear here.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
