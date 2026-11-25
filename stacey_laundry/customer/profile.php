<?php
require_once __DIR__ . '/../app/customer_helpers.php';
$customer = ensureCustomerLogin();
$customerId = (int) $customer['customer_id'];
$accountId = (int) $customer['account_id'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $txStarted = false;
    try {
        verifyCsrf();
        $action = safePost('action');

        if ($action === 'update_profile') {
            $fullName = safePost('full_name');
            $phone = safePost('contact_number');
            $email = strtolower(safePost('email'));
            $address = safePost('address');

            if ($fullName === '' || mb_strlen($fullName) > 120) {
                throw new InvalidArgumentException('Enter your full name (max 120 characters).');
            }
            if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) || !preg_match('/\d/', $phone)) {
                throw new InvalidArgumentException('Enter a valid phone number.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
                throw new InvalidArgumentException('Enter a valid email address.');
            }
            if ($address === '') {
                throw new InvalidArgumentException('Enter your home address.');
            }

            // Duplicate phone check excluding current customer
            $normalized = normalizePhone($phone);
            $dupPhone = $pdo->prepare(
                'SELECT id FROM customers
                 WHERE REPLACE(REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "(", ""), ")", "") = ?
                   AND id <> ?
                 LIMIT 1'
            );
            $dupPhone->execute([$normalized, $customerId]);
            if ($dupPhone->fetchColumn()) {
                throw new InvalidArgumentException('This phone number is already used by another customer profile.');
            }

            // Duplicate email check excluding current account
            $dupEmail = $pdo->prepare('SELECT id FROM customer_accounts WHERE email = ? AND id <> ? LIMIT 1');
            $dupEmail->execute([$email, $accountId]);
            if ($dupEmail->fetchColumn()) {
                throw new InvalidArgumentException('This email address is already registered to another account.');
            }

            // Optional profile picture upload
            $newPicture = $customer['profile_picture'] ?? null;
            if (!empty($_FILES['profile_picture']['name'])) {
                $file = $_FILES['profile_picture'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Failed to upload profile picture.');
                }
                if ($file['size'] > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Profile picture must be 2 MB or smaller.');
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extMap[$mime])) {
                    throw new InvalidArgumentException('Only JPG, PNG, and WebP images are allowed.');
                }
                $uploadDir = __DIR__ . '/../../public/uploads/customers';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = 'cust_' . $customerId . '_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
                $dest = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not save uploaded file.');
                }
                if (!empty($customer['profile_picture'])) {
                    $oldPath = $uploadDir . '/' . basename((string) $customer['profile_picture']);
                    if (is_file($oldPath)) {
                        @unlink($oldPath);
                    }
                }
                $newPicture = $filename;
            }

            $pdo->beginTransaction();
            $txStarted = true;
            $pdo->prepare('UPDATE customers SET full_name = ?, contact_number = ?, address = ? WHERE id = ?')
                ->execute([$fullName, $phone, $address, $customerId]);
            $pdo->prepare('UPDATE customer_accounts SET email = ?, profile_picture = ? WHERE id = ?')
                ->execute([$email, $newPicture, $accountId]);
            $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')
                ->execute(['Customer profile updated', 'Customer ID: ' . $customerId]);
            $pdo->commit();
            $txStarted = false;

            bootMessage('success', 'Your profile has been updated.');
            redirect('profile.php');
        }

        if ($action === 'change_password') {
            $currentPass = (string) ($_POST['current_password'] ?? '');
            $newPass = (string) ($_POST['new_password'] ?? '');
            $confirmPass = (string) ($_POST['confirm_password'] ?? '');

            $accStmt = $pdo->prepare('SELECT password_hash FROM customer_accounts WHERE id = ? LIMIT 1');
            $accStmt->execute([$accountId]);
            $hash = $accStmt->fetchColumn();
            if (!$hash || !password_verify($currentPass, (string) $hash)) {
                throw new InvalidArgumentException('Your current password is incorrect.');
            }
            if (strlen($newPass) < 8) {
                throw new InvalidArgumentException('New password must be at least 8 characters long.');
            }
            if (!hash_equals($newPass, $confirmPass)) {
                throw new InvalidArgumentException('New password and confirmation do not match.');
            }

            $pdo->prepare('UPDATE customer_accounts SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $accountId]);
            $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (NULL, ?, ?)')
                ->execute(['Customer password changed', 'Customer ID: ' . $customerId]);

            bootMessage('success', 'Your password has been changed.');
            redirect('profile.php');
        }
    } catch (Throwable $e) {
        if ($txStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$e instanceof InvalidArgumentException) {
            logApplicationError($e, 'customer_profile');
        }
        bootMessage('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save changes right now.');
        redirect('profile.php');
    }
}

$customer = ensureCustomerLogin();

ob_start();
?>
<div class="cp-grid-2">
  <section class="cp-card">
    <div class="cp-card-head">
      <h2>Personal Information</h2>
      <span class="cp-badge cp-badge-green">ID #<?= h((string) $customerId) ?></span>
    </div>

    <div style="display:flex; align-items:center; gap:14px; margin-bottom:18px; padding:12px; border-radius:12px; background:var(--cp-primary-tint); border:1px solid #bbf7d0;">
      <div class="cp-avatar-sm" style="width:52px; height:52px; font-size:1.25rem;">
        <?php if (!empty($customer['profile_picture'])): ?>
          <img src="../../public/uploads/customers/<?= h($customer['profile_picture']) ?>" alt="Profile">
        <?php else: ?>
          <?= h(strtoupper(substr((string) $customer['full_name'], 0, 1))) ?>
        <?php endif; ?>
      </div>
      <div>
        <strong><?= h($customer['full_name']) ?></strong>
        <div style="font-size:0.82rem; color:var(--cp-muted);">Account created <?= h(date('M d, Y', strtotime($customer['account_created_at']))) ?></div>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="cp-prevent-double cp-confirm-form" data-confirm-title="Save Profile Changes?" data-confirm="Save these personal information updates to your customer account?" data-confirm-label="Save Changes">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="update_profile">
      <div class="cp-form-group">
        <label for="full_name">Full Name</label>
        <input class="cp-input" id="full_name" name="full_name" maxlength="120" value="<?= h($customer['full_name']) ?>" required>
      </div>
      <div class="cp-form-group">
        <label for="contact_number">Phone Number</label>
        <input class="cp-input" id="contact_number" type="tel" name="contact_number" maxlength="30" value="<?= h($customer['contact_number']) ?>" required>
      </div>
      <div class="cp-form-group">
        <label for="email">Email Address</label>
        <input class="cp-input" id="email" type="email" name="email" maxlength="254" value="<?= h($customer['email']) ?>" required>
      </div>
      <div class="cp-form-group">
        <label for="address">Home Address</label>
        <textarea class="cp-textarea" id="address" name="address" rows="3" required><?= h($customer['address']) ?></textarea>
      </div>
      <div class="cp-form-group">
        <label for="profile_picture">Profile Picture (JPG, PNG, WebP · max 2MB)</label>
        <input class="cp-input" id="profile_picture" type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp">
      </div>
      <button type="submit" class="cp-btn" data-loading-label="Saving...">Save Profile</button>
    </form>
  </section>

  <section class="cp-card">
    <div class="cp-card-head">
      <h2>Change Password</h2>
    </div>
    <form method="post" class="cp-prevent-double cp-confirm-form" data-confirm-title="Change Password?" data-confirm="Your current password will be replaced with the new password." data-confirm-label="Update Password" data-confirm-type="warning">
      <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="cp-form-group">
        <label for="current_password">Current Password</label>
        <input class="cp-input" id="current_password" type="password" name="current_password" required>
      </div>
      <div class="cp-form-group">
        <label for="new_password">New Password (min 8 characters)</label>
        <input class="cp-input" id="new_password" type="password" name="new_password" minlength="8" required>
      </div>
      <div class="cp-form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input class="cp-input" id="confirm_password" type="password" name="confirm_password" minlength="8" required>
      </div>
      <button type="submit" class="cp-btn" data-loading-label="Updating...">Update Password</button>
    </form>
  </section>
</div>
<?php
$content = ob_get_clean();
renderCustomerLayout('My Profile', 'profile', $content);
