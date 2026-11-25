<?php
$pdo = db();
$customerRequestToken = issueFormToken('customer_create');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (isset($_POST['toggle_account_status'])) {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $nextStatus = safePost('next_status');
            if ($accountId < 1 || !in_array($nextStatus, ['active', 'inactive'], true)) {
                throw new InvalidArgumentException('Invalid customer account request.');
            }
            $pdo->beginTransaction();
            $upd = $pdo->prepare('UPDATE customer_accounts SET account_status = ? WHERE id = ?');
            $upd->execute([$nextStatus, $accountId]);
            if ($upd->rowCount() === 0) {
                throw new InvalidArgumentException('Customer portal account not found.');
            }
            logActivity($pdo, 'Customer portal account ' . $nextStatus, 'Account ID: ' . $accountId);
            $pdo->commit();
            bootMessage('success', 'Customer portal account marked as ' . $nextStatus . '.');
            redirect('index.php?page=customers');
        }

        verifyFormToken('customer_create');
        $name = safePost('full_name');
        $phone = safePost('contact_number');
        $address = safePost('address');
        if ($name === '') {
            throw new InvalidArgumentException("Please enter the customer's name.");
        }
        if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) || !preg_match('/\d/', $phone)) {
            throw new InvalidArgumentException('The entered phone number is invalid.');
        }
        if ($address === '') {
            throw new InvalidArgumentException("Please enter the customer's address.");
        }

        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $duplicate = $pdo->prepare('SELECT id FROM customers WHERE REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", "") = ? LIMIT 1');
        $duplicate->execute([$normalizedPhone]);
        if ($duplicate->fetchColumn()) {
            preserveFormInput('customer', ['full_name', 'contact_number', 'address']);
            bootMessage('warning', 'A customer with this phone number is already registered.');
            redirect('index.php?page=customers');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO customers (full_name, contact_number, address, registration_date) VALUES (?, ?, ?, CURDATE())');
        $stmt->execute([$name, $phone, $address]);
        logActivity($pdo, 'Customer created', 'Customer ID: ' . $pdo->lastInsertId() . '; Name: ' . $name);
        $pdo->commit();
        rotateFormToken('customer_create');
        bootMessage('success', 'Customer registered successfully.');
        redirect('index.php?page=customers');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        preserveFormInput('customer', ['full_name', 'contact_number', 'address']);
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_create');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save the customer. Your information was not confirmed as saved. Please try again.');
        redirect('index.php?page=customers');
    }
}
$oldCustomer = takeFormInput('customer');
$rows = $pdo->query('SELECT c.*, ca.id AS portal_account_id, ca.email AS portal_email, ca.account_status FROM customers c LEFT JOIN customer_accounts ca ON ca.customer_id = c.id ORDER BY c.id DESC')->fetchAll();
?>

<div class="customer-shell">
  <div class="customer-header-panel">
    <div class="customer-header-main">
      <div class="customer-title-wrap">
        <span class="section-label">Profiles</span>
        <h2>Customer Profiles</h2>
      </div>
      <a href="#add-customer" class="soft-btn">Add Customer</a>
    </div>
  </div>

  <div class="customer-profile-card">
    <div class="profile-identity">
      <div class="customer-avatar-large">C</div>
      <div class="profile-badge success"><?= h((string) count($rows)) ?> Registered</div>
    </div>
    <div class="profile-meta-grid">
      <div class="meta-field"><span>Customer records</span><strong><?= h((string) count($rows)) ?></strong></div>
      <div class="meta-field"><span>Profile data</span><strong>Up to date</strong></div>
    </div>
  </div>

  <div class="customer-tabs" role="tablist">
    <button class="active" type="button" role="tab" aria-selected="true" data-tab="overview">Overview</button>
    <button type="button" role="tab" aria-selected="false" data-tab="summary">Order Summary</button>
    <button type="button" role="tab" aria-selected="false" data-tab="activities">Activities</button>
  </div>

  <div class="details-grid" data-tab-panel="overview">
    <section class="detail-panel">
      <h3>Customer Details</h3>
      <div class="property-list">
        <?php foreach ($rows as $row): ?>
          <div class="property-row">
            <div class="property-label">Customer</div>
            <div class="property-value"><?= h($row['full_name']) ?></div>
          </div>
          <div class="property-row">
            <div class="property-label">Contact</div>
            <div class="property-value"><?= h($row['contact_number']) ?></div>
          </div>
          <div class="property-row">
            <div class="property-label">Address</div>
            <div class="property-value"><?= h($row['address']) ?></div>
          </div>
          <div class="property-row">
            <div class="property-label">Registration</div>
            <div class="property-value"><?= h($row['registration_date']) ?></div>
          </div>
          <div class="property-row">
            <div class="property-label">Portal Account</div>
            <div class="property-value" style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
              <?php if (!empty($row['portal_account_id'])): ?>
                <span><?= h($row['portal_email']) ?> (<span class="status-badge <?= $row['account_status'] === 'active' ? 'status-ready' : 'status-cancelled' ?>"><?= h($row['account_status']) ?></span>)</span>
                <form method="post" class="confirm-form" data-confirm-title="<?= $row['account_status'] === 'active' ? 'Deactivate Customer?' : 'Activate Customer?' ?>" data-confirm="<?= $row['account_status'] === 'active' ? 'This customer will no longer be able to sign in to the portal.' : 'This customer will regain access to the portal.' ?>" data-confirm-label="<?= $row['account_status'] === 'active' ? 'Deactivate' : 'Activate' ?>" data-destructive="<?= $row['account_status'] === 'active' ? 'true' : 'false' ?>" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                  <input type="hidden" name="toggle_account_status" value="1">
                  <input type="hidden" name="account_id" value="<?= h((string) $row['portal_account_id']) ?>">
                  <input type="hidden" name="next_status" value="<?= $row['account_status'] === 'active' ? 'inactive' : 'active' ?>">
                  <button type="submit" class="tiny-btn"><?= $row['account_status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                </form>
              <?php else: ?>
                <span style="color:var(--muted);">Not registered on portal</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <div class="empty-state"><strong>No customers registered yet.</strong><p>Add your first customer to start tracking orders and payments.</p><a class="btn" href="#add-customer">Add customer</a></div>
        <?php endif; ?>
      </div>
    </section>

    <aside class="detail-panel sidebar-panel">
      <h3>Order Summary</h3>
      <div class="summary-list">
        <?php
        $customerRows = $pdo->query('SELECT c.id, c.full_name, COUNT(o.id) AS total_orders, COALESCE(SUM(o.total),0) AS total_sales FROM customers c LEFT JOIN orders o ON o.customer_id = c.id GROUP BY c.id, c.full_name ORDER BY c.id DESC')->fetchAll();
        foreach ($customerRows as $customer):
        ?>
          <div class="summary-item">
            <div>
              <strong><?= h($customer['full_name']) ?></strong>
              <small><?= h((string)$customer['total_orders']) ?> orders</small>
            </div>
            <span>₱<?= h(number_format((float)$customer['total_sales'], 2)) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$customerRows): ?><div class="empty-state"><p>Customer order summaries will appear here after customers are registered.</p></div><?php endif; ?>
      </div>
    </aside>
  </div>

  <div class="detail-panel" data-tab-panel="summary" hidden>
    <h3>Customer Order Breakdown</h3>
    <div class="summary-list">
      <?php foreach ($customerRows as $customer): ?>
        <div class="summary-item">
          <div>
            <strong><?= h($customer['full_name']) ?></strong>
            <small><?= h((string)$customer['total_orders']) ?> total orders</small>
          </div>
          <span>₱<?= h(number_format((float)$customer['total_sales'], 2)) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$customerRows): ?><div class="empty-state"><p>No customer order data yet.</p></div><?php endif; ?>
    </div>
  </div>

  <div class="detail-panel" data-tab-panel="activities" hidden>
    <h3>Recent Customer Activities</h3>
    <div class="summary-list">
      <?php
      $activityRows = $pdo->query("SELECT action, details, created_at FROM activity_logs WHERE action LIKE '%Customer%' OR action LIKE '%Order%' ORDER BY created_at DESC LIMIT 15")->fetchAll();
      foreach ($activityRows as $act):
      ?>
        <div class="summary-item">
          <div>
            <strong><?= h($act['action']) ?></strong>
            <small><?= h($act['details'] ?: '') ?></small>
          </div>
          <span style="font-size:0.8rem; color:var(--muted); font-weight:normal;"><?= h(date('d M Y, H:i', strtotime($act['created_at']))) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$activityRows): ?><div class="empty-state"><p>No recent activities recorded.</p></div><?php endif; ?>
    </div>
  </div>

  <div class="customer-form-panel" id="add-customer">
    <h3>Add a customer</h3>
    <form method="post" class="customer-form">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="request_token" value="<?= h($customerRequestToken) ?>">
      <div class="row customer-form-grid">
        <div><label>Full name</label><input name="full_name" maxlength="120" value="<?= h($oldCustomer['full_name'] ?? '') ?>" data-error-message="Please enter the customer's name." required></div>
        <div><label>Contact number</label><input type="tel" name="contact_number" maxlength="30" value="<?= h($oldCustomer['contact_number'] ?? '') ?>" data-error-message="The entered phone number is invalid." required></div>
      </div>
      <label>Address</label>
      <textarea name="address" required><?= h($oldCustomer['address'] ?? '') ?></textarea>
      <div class="form-actions"><button type="submit" class="primary-btn">Save Customer</button></div>
    </form>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.customer-tabs button[data-tab]');
    const panels = document.querySelectorAll('[data-tab-panel]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        const target = tab.dataset.tab;
        tabs.forEach(function (t) {
          const isMatch = t === tab;
          t.classList.toggle('active', isMatch);
          t.setAttribute('aria-selected', isMatch ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
          panel.hidden = panel.dataset.tabPanel !== target;
        });
      });
    });
  });
</script>
