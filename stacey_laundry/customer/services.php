<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$pdo = db();

$services = $pdo->query("SELECT id, name, price, unit FROM services WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$announcements = $pdo->query("SELECT title, message, created_at FROM customer_notifications WHERE customer_id IS NULL AND notification_type = 'general' ORDER BY created_at DESC LIMIT 5")->fetchAll();

ob_start();
?>
<div class="cp-card">
  <div class="cp-card-head">
    <h2>Available Laundry Services &amp; Pricing</h2>
    <span class="cp-badge cp-badge-green">Live Rates</span>
  </div>
  <?php if ($services): ?>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead>
          <tr><th>Service Name</th><th>Base Rate</th><th>Billing Unit</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($services as $svc): ?>
            <tr>
              <td><strong><?= h($svc['name']) ?></strong></td>
              <td><strong>₱<?= h(number_format((float) $svc['price'], 2)) ?></strong></td>
              <td>per <?= h($svc['unit']) ?></td>
              <td style="color:var(--cp-muted); font-size:0.86rem;">Plus ₱15.00 per kg weight processing fee as calculated at intake.</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="cp-empty">
      <strong>No active services listed</strong>
      <p>Please contact our shop counter for current rates.</p>
    </div>
  <?php endif; ?>
</div>

<div class="cp-grid-2">
  <section class="cp-card">
    <div class="cp-card-head">
      <h3>Shop Hours, Turnaround &amp; Policies</h3>
    </div>
    <table class="cp-table">
      <tbody>
        <tr><th>Operating Hours</th><td>Monday – Saturday: 7:30 AM – 7:30 PM<br>Sunday: 9:00 AM – 5:00 PM</td></tr>
        <tr><th>Estimated Turnaround</th><td>Wash &amp; Dry / Fold: 24–48 hours<br>Dry Cleaning / Ironing: 2–3 business days</td></tr>
        <tr><th>Additional Charges</th><td>Weight processing rate: ₱15.00 per kg (applied automatically during order creation).</td></tr>
        <tr><th>Laundry Policies</th><td>Please declare delicate fabrics, stains, or special instructions at drop-off. Unclaimed laundry after 30 days may incur storage review.</td></tr>
        <tr><th>Shop Contact</th><td>Stacey Laundry Hub Front Desk · Walk-in &amp; Pickup Counter</td></tr>
      </tbody>
    </table>
  </section>

  <section class="cp-card">
    <div class="cp-card-head">
      <h3>Shop Announcements</h3>
    </div>
    <?php if ($announcements): ?>
      <?php foreach ($announcements as $a): ?>
        <div class="cp-notif-item">
          <div>
            <strong><?= h($a['title']) ?></strong>
            <div style="font-size:0.88rem; color:var(--cp-muted); margin-top:4px;"><?= h($a['message']) ?></div>
            <div style="font-size:0.76rem; color:var(--cp-muted); margin-top:4px;"><?= h(date('M d, Y g:i A', strtotime($a['created_at']))) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="cp-empty">
        <strong>No current announcements</strong>
        <p>Holiday schedules and service updates will be posted here.</p>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('Laundry Information', 'services', $content);
