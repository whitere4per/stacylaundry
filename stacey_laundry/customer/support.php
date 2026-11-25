<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$pdo = db();
$supportToken = issueFormToken('customer_support');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        verifyFormToken('customer_support');

        $orderIdInput = (int) ($_POST['order_id'] ?? 0);
        $subject = safePost('subject');
        $message = safePost('message');

        if ($subject === '' || mb_strlen($subject) > 150) {
            throw new InvalidArgumentException('Enter a subject (max 150 characters).');
        }
        if ($message === '') {
            throw new InvalidArgumentException('Enter your support message.');
        }

        $orderId = null;
        if ($orderIdInput > 0) {
            $check = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND customer_id = ? LIMIT 1');
            $check->execute([$orderIdInput, $customerId]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('Selected order does not belong to your account.');
            }
            $orderId = $orderIdInput;
        }

        $stmt = $pdo->prepare('INSERT INTO customer_support_messages (customer_id, order_id, subject, message, status) VALUES (?, ?, ?, ?, "open")');
        $stmt->execute([$customerId, $orderId, $subject, $message]);

        $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')->execute([
            'Customer support message sent',
            'Customer ID: ' . $customerId . '; Subject: ' . $subject,
        ]);

        rotateFormToken('customer_support');
        bootMessage('success', 'Your support message has been sent. Our staff will review it shortly.');
        redirect('support.php');
    } catch (Throwable $e) {
        preserveFormInput('customer_support', ['order_id', 'subject', 'message']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_support');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to submit support message right now.');
        redirect('support.php');
    }
}

$old = takeFormInput('customer_support');
$ordersStmt = $pdo->prepare('SELECT id, order_number, status FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20');
$ordersStmt->execute([$customerId]);
$myOrders = $ordersStmt->fetchAll();

$ticketsStmt = $pdo->prepare(
    'SELECT m.*, o.order_number
     FROM customer_support_messages m
     LEFT JOIN orders o ON o.id = m.order_id AND o.customer_id = m.customer_id
     WHERE m.customer_id = ?
     ORDER BY m.created_at DESC
     LIMIT 20'
);
$ticketsStmt->execute([$customerId]);
$tickets = $ticketsStmt->fetchAll();

ob_start();
?>
<div class="cp-grid-2">
  <section class="cp-card">
    <div class="cp-card-head">
      <h2>Contact Support</h2>
    </div>
    <form method="post" class="cp-prevent-double cp-confirm-form" data-confirm-title="Send Support Request?" data-confirm="Send this message to the Stacey Laundry Hub staff?" data-confirm-label="Send Request">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="request_token" value="<?= h($supportToken) ?>">
      <div class="cp-form-group">
        <label for="order_id">Related Order (Optional)</label>
        <select class="cp-select" id="order_id" name="order_id">
          <option value="0">General Inquiry (No specific order)</option>
          <?php foreach ($myOrders as $mo): ?>
            <option value="<?= h((string) $mo['id']) ?>" <?= ($old['order_id'] ?? '') === (string) $mo['id'] ? 'selected' : '' ?>>
              <?= h($mo['order_number']) ?> (<?= h($mo['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="cp-form-group">
        <label for="subject">Subject</label>
        <input class="cp-input" id="subject" name="subject" maxlength="150" value="<?= h($old['subject'] ?? '') ?>" placeholder="e.g. Question about pickup time" required>
      </div>
      <div class="cp-form-group">
        <label for="message">Message</label>
        <textarea class="cp-textarea" id="message" name="message" rows="4" required><?= h($old['message'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="cp-btn" data-loading-label="Sending...">Send Message</button>
    </form>
  </section>

  <section class="cp-card">
    <div class="cp-card-head">
      <h2>Frequently Asked Questions &amp; Shop Info</h2>
    </div>
    <div style="font-size:0.9rem; display:grid; gap:12px;">
      <div>
        <strong>When can I pick up my laundry?</strong>
        <p style="margin:4px 0 0; color:var(--cp-muted);">As soon as your order status shows <strong>Ready for Pickup</strong> on your Dashboard or Track My Laundry page. Present your receipt number at the counter.</p>
      </div>
      <div>
        <strong>What payment methods are accepted?</strong>
        <p style="margin:4px 0 0; color:var(--cp-muted);">We accept <strong>Cash</strong>, <strong>Bank Transfer</strong>, and <strong>G-Cash</strong> at the shop. Partial and full payments appear immediately in Payments &amp; Billing.</p>
      </div>
      <div>
        <strong>Operating Hours</strong>
        <p style="margin:4px 0 0; color:var(--cp-muted);">Mon–Sat: 7:30 AM – 7:30 PM · Sun: 9:00 AM – 5:00 PM</p>
      </div>
      <div>
        <strong>Service Policies</strong>
        <p style="margin:4px 0 0; color:var(--cp-muted);">Special care instructions are recorded at drop-off. Please inspect items upon pickup.</p>
      </div>
    </div>
  </section>
</div>

<div class="cp-card">
  <div class="cp-card-head">
    <h2>My Support Inquiries</h2>
    <span class="cp-badge cp-badge-gray"><?= h((string) count($tickets)) ?> messages</span>
  </div>
  <?php if ($tickets): ?>
    <?php foreach ($tickets as $t): ?>
      <div class="cp-notif-item">
        <div style="flex:1;">
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
            <strong><?= h($t['subject']) ?></strong>
            <span class="cp-badge <?= $t['status'] === 'resolved' ? 'cp-badge-green' : 'cp-badge-amber' ?>"><?= h(ucfirst($t['status'])) ?></span>
            <?php if (!empty($t['order_number'])): ?>
              <span class="cp-badge cp-badge-blue"><?= h($t['order_number']) ?></span>
            <?php endif; ?>
          </div>
          <p style="margin:4px 0; font-size:0.9rem;"><?= h($t['message']) ?></p>
          <div style="font-size:0.78rem; color:var(--cp-muted);">Sent <?= h(date('M d, Y g:i A', strtotime($t['created_at']))) ?></div>
          <?php if (!empty($t['staff_reply'])): ?>
            <div style="margin-top:10px; padding:10px 12px; border-radius:8px; background:var(--cp-primary-tint); border:1px solid #bbf7d0; font-size:0.88rem;">
              <strong>Shop Reply:</strong> <?= h($t['staff_reply']) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No support messages yet</strong>
      <p>Use the form above if you need assistance with an order or account question.</p>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Help & Support', 'support', $content);
