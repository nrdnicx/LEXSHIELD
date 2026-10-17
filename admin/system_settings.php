<?php
require_once __DIR__ . '/../config/bootstrap.php';

$adminUser = lex_require_role('admin');
$pdo = lex_pdo();
$currentSettings = [];
$rows = $pdo->query('SELECT setting_key, setting_value FROM site_settings ORDER BY setting_key')->fetchAll();
foreach ($rows as $row) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

function lex_admin_security_clear_login_state(PDO $pdo, int $adminId, string ...$emails): void
{
    $pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id')->execute(['user_id' => $adminId]);

    $emails = array_values(array_unique(array_filter(array_map('lex_sanitize_email', $emails))));
    if ($emails) {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE purpose = "login_verification" AND email IN (' . $placeholders . ')');
        $stmt->execute($emails);
    }
}

function lex_admin_security_forget_browser_cookies(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    foreach (['lex_login_otp_verified', 'lex_known_browser'] as $cookieName) {
        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}

function lex_admin_security_user(PDO $pdo, int $adminId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE id = :id AND role = "admin" LIMIT 1');
    $stmt->execute(['id' => $adminId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException('Admin account could not be found.');
    }

    return $user;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('admin_system_settings');
        lex_flash_set('error', 'Invalid security token. Please try again.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save_platform');

    if ($action === 'change_admin_email') {
        try {
            $adminAccount = lex_admin_security_user($pdo, (int) $adminUser['id']);
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newEmail = lex_sanitize_email($_POST['new_email'] ?? '');
            $oldEmail = lex_sanitize_email((string) ($adminAccount['email'] ?? ''));

            if (!password_verify($currentPassword, (string) $adminAccount['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid new admin email address.');
            }
            if ($newEmail === $oldEmail) {
                throw new RuntimeException('The new email is already the current admin email.');
            }

            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $stmt->execute(['email' => $newEmail, 'id' => (int) $adminAccount['id']]);
            if ($stmt->fetchColumn()) {
                throw new RuntimeException('That email is already used by another account.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id AND role = "admin"');
            $stmt->execute(['email' => $newEmail, 'id' => (int) $adminAccount['id']]);
            lex_admin_security_clear_login_state($pdo, (int) $adminAccount['id'], $oldEmail, $newEmail);
            lex_audit('admin_email_change', 'users', (string) $adminAccount['id']);
            lex_notify((int) $adminAccount['id'], 'security', 'Your admin email address was changed.');
            $pdo->commit();

            if ($oldEmail !== '') {
                lex_send_email(
                    $oldEmail,
                    'LEXSHIELD - Admin Email Changed',
                    "Hello " . (string) ($adminAccount['full_name'] ?? 'LEXSHIELD Admin') . ",\n\n"
                    . "Your LEXSHIELD admin email was changed to {$newEmail}.\n\n"
                    . lex_account_activity_context() . "\n\n"
                    . "If this was not you, secure the account immediately."
                );
            }
            lex_admin_security_forget_browser_cookies();
            lex_flash_set('success', 'Admin email updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not update admin email.');
        }
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    if ($action === 'change_admin_password') {
        try {
            $adminAccount = lex_admin_security_user($pdo, (int) $adminUser['id']);
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            if (!password_verify($currentPassword, (string) $adminAccount['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('New password and confirmation do not match.');
            }
            $passwordError = lex_password_policy_error($newPassword, (string) $adminAccount['email'], (string) $adminAccount['full_name']);
            if ($passwordError !== '') {
                throw new RuntimeException($passwordError);
            }
            if (password_verify($newPassword, (string) $adminAccount['password_hash'])) {
                throw new RuntimeException('New password must be different from the current password.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash, failed_login_attempts = 0, locked_until = NULL WHERE id = :id AND role = "admin"');
            $stmt->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => (int) $adminAccount['id'],
            ]);
            lex_admin_security_clear_login_state($pdo, (int) $adminAccount['id'], (string) $adminAccount['email']);
            lex_audit('admin_self_password_change', 'users', (string) $adminAccount['id']);
            lex_notify((int) $adminAccount['id'], 'security', 'Your admin password was changed.');
            $pdo->commit();

            lex_send_account_activity_alert($adminAccount, 'LEXSHIELD - Admin Password Changed', 'Your LEXSHIELD admin password was changed.');
            lex_admin_security_forget_browser_cookies();
            lex_flash_set('success', 'Admin password updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            lex_flash_set('error', $e instanceof RuntimeException ? $e->getMessage() : 'Could not update admin password.');
        }
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }

    $qrUpload = null;
    try {
        $qrUpload = lex_store_payment_qr($_FILES['gcash_qr_file'] ?? []);
        $settings = [
            'site_name' => lex_sanitize_text($_POST['site_name'] ?? ''),
            'session_timeout' => lex_sanitize_text($_POST['session_timeout'] ?? ''),
            'smtp_host' => lex_sanitize_text($_POST['smtp_host'] ?? ''),
            'smtp_port' => lex_sanitize_text($_POST['smtp_port'] ?? ''),
            'smtp_encryption' => in_array((string) ($_POST['smtp_encryption'] ?? 'tls'), ['tls', 'ssl', 'none'], true)
                ? (string) ($_POST['smtp_encryption'] ?? 'tls')
                : 'tls',
            'smtp_user' => lex_sanitize_email($_POST['smtp_user'] ?? ''),
            'smtp_pass' => trim((string) ($_POST['smtp_pass'] ?? '')) !== ''
                ? (string) ($_POST['smtp_pass'] ?? '')
                : (string) ($currentSettings['smtp_pass'] ?? ''),
            'smtp_from_email' => lex_sanitize_email($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name' => lex_sanitize_text($_POST['smtp_from_name'] ?? ''),
            'login_otp_enabled' => isset($_POST['login_otp_enabled']) ? 'true' : 'false',
            'admin_otp_enabled' => isset($_POST['admin_otp_enabled']) ? 'true' : 'false',
            'client_registration_otp_enabled' => isset($_POST['client_registration_otp_enabled']) ? 'true' : 'false',
            'gcash_account_name' => lex_sanitize_text($_POST['gcash_account_name'] ?? ''),
            'gcash_number' => preg_replace('/[^0-9+]/', '', (string) ($_POST['gcash_number'] ?? '')),
            'gcash_instructions' => trim((string) ($_POST['gcash_instructions'] ?? '')),
            'gcash_qr_stored_name' => (string) ($qrUpload['stored_name'] ?? ($currentSettings['gcash_qr_stored_name'] ?? '')),
        ];
        $stmt = $pdo->prepare('REPLACE INTO site_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, NOW())');
        foreach ($settings as $key => $value) {
            $stmt->execute(['setting_key' => $key, 'setting_value' => (string) $value]);
        }
        lex_audit('update_settings', 'site_settings', 'global');
        lex_flash_set('success', 'System settings updated.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    } catch (Throwable $e) {
        if (!empty($qrUpload['path']) && is_file((string) $qrUpload['path'])) {
            @unlink((string) $qrUpload['path']);
        }
        lex_flash_set('error', 'Could not save settings.');
        header('Location: ' . lex_app_url('admin/system_settings.php'));
        exit;
    }
}

$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

lex_page_header('System Settings', 'settings');
?>
<section class="card admin-settings-card" data-system-settings-page>
  <div class="card-head"><h2>Platform Settings</h2></div>
  <form method="post" enctype="multipart/form-data" class="form-grid admin-settings-form">
    <?= lex_csrf_field() ?>
    <input type="hidden" name="action" value="save_platform">
    <label>Site name <input type="text" name="site_name" value="<?= lex_e($settings['site_name'] ?? 'LEXSHIELD') ?>"></label>
    <label>Session timeout (seconds) <input type="number" name="session_timeout" value="<?= lex_e($settings['session_timeout'] ?? '1800') ?>"></label>
    <label>SMTP host <input type="text" name="smtp_host" value="<?= lex_e($settings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com"></label>
    <label>SMTP port <input type="number" name="smtp_port" value="<?= lex_e($settings['smtp_port'] ?? '587') ?>"></label>
    <label>SMTP encryption
      <select name="smtp_encryption">
        <?php $smtpEncryption = (string) ($settings['smtp_encryption'] ?? 'tls'); ?>
        <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
        <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
        <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>None</option>
      </select>
    </label>
    <label>SMTP user <input type="email" name="smtp_user" value="<?= lex_e($settings['smtp_user'] ?? '') ?>" placeholder="no-reply@example.com"></label>
    <label>SMTP password
      <div class="password-field" data-password-toggle>
        <input type="password" name="smtp_pass" value="" placeholder="Leave blank to keep existing value" autocomplete="new-password">
        <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
      </div>
    </label>
    <label>From email <input type="email" name="smtp_from_email" value="<?= lex_e($settings['smtp_from_email'] ?? '') ?>" placeholder="Optional; defaults to SMTP user"></label>
    <label>From name <input type="text" name="smtp_from_name" value="<?= lex_e($settings['smtp_from_name'] ?? '') ?>" placeholder="LEXSHIELD"></label>
    <label class="checkbox-row full">
      <input type="checkbox" name="login_otp_enabled" value="1" <?= lex_bool_setting('login_otp_enabled', 'LOGIN_OTP_ENABLED', false) ? 'checked' : '' ?>>
      Require OTP for normal user login
    </label>
    <label class="checkbox-row full">
      <input type="checkbox" name="admin_otp_enabled" value="1" <?= lex_bool_setting('admin_otp_enabled', 'ADMIN_OTP_ENABLED', false) ? 'checked' : '' ?>>
      Require OTP for admin login
    </label>
    <label class="checkbox-row full">
      <input type="checkbox" name="client_registration_otp_enabled" value="1" <?= lex_bool_setting('client_registration_otp_enabled', 'CLIENT_REGISTRATION_OTP_ENABLED', false) ? 'checked' : '' ?>>
      Require OTP for client registration
    </label>
    <label>GCash account name <input type="text" name="gcash_account_name" value="<?= lex_e($settings['gcash_account_name'] ?? '') ?>" placeholder="Juan Dela Cruz"></label>
    <label>GCash mobile number <input type="text" name="gcash_number" value="<?= lex_e($settings['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX"></label>
    <label class="full">GCash instructions
      <textarea name="gcash_instructions" rows="4" placeholder="Add any reminders for clients before they upload proof."><?= lex_e($settings['gcash_instructions'] ?? '') ?></textarea>
    </label>
    <label class="full">GCash QR image
      <input type="file" name="gcash_qr_file" accept="image/png,image/jpeg,image/webp,image/gif">
    </label>
    <?php if (!empty($settings['gcash_qr_stored_name'])): ?>
      <div class="full profile-upload-row">
        <div class="profile-upload-thumb">
          <img src="<?= lex_e(lex_app_url('payment_qr_image.php')) ?>" alt="Current GCash QR">
        </div>
        <div class="profile-upload-field">
          <strong>Current GCash QR is active</strong>
          <p class="profile-upload-copy">Upload a new image only when you want to replace the current code.</p>
        </div>
      </div>
    <?php endif; ?>
    <button class="button button-primary admin-settings-submit" type="submit">Save Settings</button>
  </form>
</section>

<section class="card admin-settings-card admin-account-security-card">
  <div class="card-head"><h2>Account Security</h2></div>
  <div class="admin-account-security-current">
    <span>Current admin email</span>
    <strong><?= lex_e((string) ($adminUser['email'] ?? '')) ?></strong>
  </div>
  <div class="admin-account-security-grid">
    <form method="post" class="form-grid admin-settings-form admin-account-security-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="change_admin_email">
      <label class="full">New admin email <input type="email" name="new_email" required value="<?= lex_e((string) ($adminUser['email'] ?? '')) ?>" autocomplete="email"></label>
      <label class="full">Current password
        <div class="password-field" data-password-toggle>
          <input type="password" name="current_password" required autocomplete="current-password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <button class="button button-primary" type="submit">Change Email</button>
    </form>

    <form method="post" class="form-grid admin-settings-form admin-account-security-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="change_admin_password">
      <label class="full">Current password
        <div class="password-field" data-password-toggle>
          <input type="password" name="current_password" required autocomplete="current-password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <label class="full">New password
        <div class="password-field" data-password-toggle>
          <input type="password" name="new_password" required autocomplete="new-password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <label class="full">Confirm new password
        <div class="password-field" data-password-toggle>
          <input type="password" name="confirm_password" required autocomplete="new-password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <button class="button button-primary" type="submit">Change Password</button>
    </form>
  </div>
</section>
<?php
$storageStatus = lex_document_storage_status();
$localQuota = (int) ($storageStatus['local_quota_bytes'] ?? 0);
$localUsed = (int) ($storageStatus['local_used_bytes'] ?? 0);
$localThreshold = (int) ($storageStatus['local_threshold_bytes'] ?? 0);
$supabaseQuota = (int) ($storageStatus['supabase_quota_bytes'] ?? 0);
$supabaseUsed = (int) ($storageStatus['supabase_used_bytes'] ?? 0);
$localPercent = $localQuota > 0 ? min(100, ($localUsed / $localQuota) * 100) : 0;
$thresholdPercent = (float) ($storageStatus['local_threshold_percent'] ?? 90);
$supabasePercent = $supabaseQuota > 0 ? min(100, ($supabaseUsed / $supabaseQuota) * 100) : 0;
?>

<section class="card admin-settings-card admin-storage-card">
  <div class="card-head">
    <div>
      <h2>Case Document Storage</h2>
      <p class="muted">InfinityFree/local is primary. Supabase is used automatically when the local safety threshold is reached.</p>
    </div>
    <span class="storage-provider-badge">Primary: InfinityFree</span>
  </div>
  <div class="admin-storage-grid">
    <article class="admin-storage-panel">
      <div class="admin-storage-panel-head">
        <div><span class="admin-storage-kicker">Primary storage</span><strong>InfinityFree</strong></div>
        <span><?= lex_e(number_format($thresholdPercent, 0)) ?>% safety threshold</span>
      </div>
      <div class="storage-progress" aria-label="InfinityFree storage usage"><span style="width: <?= lex_e(number_format($localPercent, 2, '.', '')) ?>%"></span></div>
      <div class="admin-storage-numbers">
        <strong><?= lex_e(lex_human_file_size($localUsed)) ?> / <?= lex_e(lex_human_file_size($localQuota)) ?></strong>
        <span><?= lex_e(lex_human_file_size(max(0, $localThreshold - $localUsed))) ?> until overflow threshold</span>
      </div>
      <p class="muted admin-storage-note">Usage is measured from LexShield's <code>config/storage</code> directory. The threshold is configurable because shared hosting does not reliably expose an account-level quota through PHP.</p>
    </article>

    <article class="admin-storage-panel">
      <div class="admin-storage-panel-head">
        <div><span class="admin-storage-kicker">Automatic overflow</span><strong>Supabase</strong></div>
        <span><?= !empty($storageStatus['supabase_configured']) ? 'Configured' : 'Not configured' ?></span>
      </div>
      <div class="storage-progress" aria-label="Supabase tracked usage"><span style="width: <?= lex_e(number_format($supabasePercent, 2, '.', '')) ?>%"></span></div>
      <div class="admin-storage-numbers">
        <strong><?= lex_e(lex_human_file_size($supabaseUsed)) ?> / <?= lex_e(lex_human_file_size($supabaseQuota)) ?></strong>
        <span>Tracked LexShield case-document usage</span>
      </div>
      <p class="muted admin-storage-note">Supabase usage is tracked from case-document metadata. It does not claim to measure unrelated objects stored in your Supabase project.</p>
    </article>
  </div>
  <div class="admin-storage-routing">
    <div><span>Routing</span><strong>InfinityFree → Supabase overflow</strong></div>
    <div><span>Encryption</span><strong>AES-256-GCM remains enabled</strong></div>
    <div><span>Duplicate policy</span><strong>No automatic duplicate copy</strong></div>
  </div>
</section>

<?php lex_page_footer(); ?>
