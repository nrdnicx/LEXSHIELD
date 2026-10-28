<?php
require_once __DIR__ . '/../config/bootstrap.php';

$pdo = lex_pdo();
$error = '';
$success = '';
$loginOtpEnabled = lex_bool_setting('login_otp_enabled', 'LOGIN_OTP_ENABLED', false);
$adminOtpEnabled = lex_bool_setting('admin_otp_enabled', 'ADMIN_OTP_ENABLED', false);
$otpStep = !empty($_SESSION['pending_login_otp']);
$otpEmail = $otpStep ? (string) ($_SESSION['pending_login_otp']['email'] ?? '') : '';
$otpExpiresAt = $otpStep ? (int) ($_SESSION['pending_login_otp']['expires_at'] ?? 0) : 0;
$otpRemainingSeconds = $otpExpiresAt > time() ? $otpExpiresAt - time() : 0;

if (!$loginOtpEnabled && !$adminOtpEnabled && $otpStep) {
    unset($_SESSION['pending_login_otp']);
    $otpStep = false;
    $otpEmail = '';
    $otpExpiresAt = 0;
    $otpRemainingSeconds = 0;
}

function lex_login_clear_pending_otp(): void
{
    unset($_SESSION['pending_login_otp']);
}

function lex_login_requires_otp(array $user): bool
{
    global $loginOtpEnabled, $adminOtpEnabled;

    if (($user['role'] ?? '') === 'admin') {
        return $adminOtpEnabled;
    }

    return $loginOtpEnabled;
}

function lex_login_otp_cookie_name(): string
{
    return 'lex_login_otp_verified';
}

function lex_login_browser_cookie_name(): string
{
    return 'lex_known_browser';
}

function lex_login_otp_signature(string $payload): string
{
    global $lexEncryptionKey;
    return hash_hmac('sha256', $payload, (string) $lexEncryptionKey);
}

function lex_login_otp_cookie_payload(int $userId, int $expiresAt): string
{
    $payload = $userId . '|' . $expiresAt;
    return base64_encode($payload . '|' . lex_login_otp_signature($payload));
}

function lex_login_otp_cookie_valid(int $userId): bool
{
    $cookie = (string) ($_COOKIE[lex_login_otp_cookie_name()] ?? '');
    if ($cookie === '') {
        return false;
    }

    $decoded = base64_decode($cookie, true);
    if (!is_string($decoded) || $decoded === '') {
        return false;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) {
        return false;
    }

    [$cookieUserId, $expiresAt, $signature] = $parts;
    $payload = $cookieUserId . '|' . $expiresAt;
    if (!hash_equals(lex_login_otp_signature($payload), $signature)) {
        return false;
    }

    return (int) $cookieUserId === $userId && (int) $expiresAt > time();
}

function lex_login_remember_otp_verified(int $userId): void
{
    $expiresAt = time() + 43200;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(lex_login_otp_cookie_name(), lex_login_otp_cookie_payload($userId, $expiresAt), [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function lex_login_browser_cookie_valid(int $userId): bool
{
    $cookie = (string) ($_COOKIE[lex_login_browser_cookie_name()] ?? '');
    if ($cookie === '') {
        return false;
    }

    $decoded = base64_decode($cookie, true);
    if (!is_string($decoded) || $decoded === '') {
        return false;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) {
        return false;
    }

    [$cookieUserId, $expiresAt, $signature] = $parts;
    $payload = $cookieUserId . '|' . $expiresAt . '|' . substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    if (!hash_equals(lex_login_otp_signature($payload), $signature)) {
        return false;
    }

    return (int) $cookieUserId === $userId && (int) $expiresAt > time();
}

function lex_login_remember_browser(int $userId): void
{
    $expiresAt = time() + (86400 * 30);
    $fingerprint = substr(hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    $payload = $userId . '|' . $expiresAt . '|' . $fingerprint;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(lex_login_browser_cookie_name(), base64_encode($userId . '|' . $expiresAt . '|' . lex_login_otp_signature($payload)), [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function lex_login_finish(PDO $pdo, array $user): void
{
    $isKnownBrowser = lex_login_browser_cookie_valid((int) $user['id']);
    lex_record_login_attempt($pdo, (int) $user['id'], true);
    lex_login_clear_pending_otp();
    lex_login_user($user);
    lex_audit('login', 'users', (string) $user['id'], (int) $user['id']);
    if (($user['role'] ?? '') === 'admin') {
        lex_audit('admin_login', 'users', (string) $user['id'], (int) $user['id']);
    }
    lex_send_account_activity_alert($user, 'New LEXSHIELD login', 'A successful login was made to your LEXSHIELD account.');
    if (!$isKnownBrowser) {
        lex_audit('new_browser_login', 'users', (string) $user['id'], (int) $user['id']);
        lex_send_account_activity_alert($user, 'New browser login on LEXSHIELD', 'Your LEXSHIELD account was accessed from a browser or device we have not seen before.');
    }
    lex_login_remember_browser((int) $user['id']);
    lex_notify((int) $user['id'], 'security', 'You have successfully logged in to LEXSHIELD.');
    header('Location: ../' . $user['role'] . '/index.php');
    exit;
}

function lex_login_send_otp(PDO $pdo, array $user): bool
{
    $email = lex_sanitize_email((string) ($user['email'] ?? ''));
    if ($email === '') {
        lex_mail_error('Login account email is missing.');
        return false;
    }

    $otp = (string) random_int(1000, 9999);
    $otpHash = password_hash($otp, PASSWORD_BCRYPT);
    $expiresAtTimestamp = time() + 180;
    $expiresAt = date('Y-m-d H:i:s', $expiresAtTimestamp);

    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE email = :email AND purpose = "login_verification" AND is_used = 0')
        ->execute(['email' => $email]);
    $stmt = $pdo->prepare(
        'INSERT INTO email_otps (email, otp_hash, purpose, expires_at)
         VALUES (:email, :otp_hash, "login_verification", :expires_at)'
    );
    $stmt->execute([
        'email' => $email,
        'otp_hash' => $otpHash,
        'expires_at' => $expiresAt,
    ]);
    $otpId = (int) $pdo->lastInsertId();

    $body = "Your LEXSHIELD login verification code is: {$otp}\n\n"
        . "This 4-digit code expires in 3 minutes. Login verification is remembered on this browser for 12 hours. If you did not try to sign in, please secure your account.";

    if (lex_send_email($email, 'Your LEXSHIELD login code', $body)) {
        lex_audit('login_otp_requested', 'email_otps', (string) $otpId, (int) $user['id']);
        lex_notify((int) $user['id'], 'security', 'A login verification code was requested for your LEXSHIELD account.');
        $_SESSION['pending_login_otp'] = [
            'user_id' => (int) $user['id'],
            'email' => $email,
            'created_at' => time(),
            'expires_at' => $expiresAtTimestamp,
        ];
        return true;
    }

    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => $otpId]);
    return false;
}

function lex_login_latest_otp(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM email_otps
         WHERE email = :email
           AND purpose = "login_verification"
           AND is_used = 0
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('auth/login.php');
        $error = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? 'login');

        if ($action === 'verify_otp') {
            $pending = $_SESSION['pending_login_otp'] ?? null;
            $submittedOtp = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? '')) ?? '';
            $pendingEmail = is_array($pending) ? lex_sanitize_email((string) ($pending['email'] ?? '')) : '';
            $limit = lex_rate_limit_hit(
                'login_otp_verify',
                lex_rate_limit_key(lex_rate_limit_client_ip(), $pendingEmail),
                8,
                600,
                600,
                $pendingEmail !== '' ? $pendingEmail : lex_rate_limit_client_ip()
            );

            if (!$limit['allowed']) {
                $error = lex_rate_limit_message((int) $limit['retry_after']);
                $otpStep = is_array($pending);
                $otpEmail = $pendingEmail;
            } elseif (!is_array($pending)) {
                $error = 'Login verification expired. Please sign in again.';
                lex_login_clear_pending_otp();
                $otpStep = false;
            } elseif ($submittedOtp === '' || strlen($submittedOtp) !== 4) {
                $error = 'Enter the 4-digit verification code.';
                $otpStep = true;
                $otpEmail = (string) ($pending['email'] ?? '');
            } else {
                $email = lex_sanitize_email((string) ($pending['email'] ?? ''));
                $userId = lex_sanitize_int($pending['user_id'] ?? 0);
                $otpRow = lex_login_latest_otp($pdo, $email);

                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND email = :email LIMIT 1');
                $stmt->execute(['id' => $userId, 'email' => $email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Login verification expired. Please sign in again.';
                    lex_login_clear_pending_otp();
                    $otpStep = false;
                } elseif (!$otpRow) {
                    lex_audit('expired_otp', 'email_otps', 'login_verification:' . $email, (int) $user['id']);
                    $error = 'Verification code expired. Please request a new code.';
                    $otpStep = true;
                    $otpEmail = $email;
                } elseif (strtotime((string) $otpRow['expires_at']) < time()) {
                    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('expired_otp', 'email_otps', (string) $otpRow['id'], (int) $user['id']);
                    $error = 'Verification code expired. Please request a new code.';
                    $otpStep = true;
                    $otpEmail = $email;
                } elseif ((int) $otpRow['attempts'] >= 5) {
                    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('failed_otp_locked', 'email_otps', (string) $otpRow['id'], (int) $user['id']);
                    $error = 'Too many incorrect attempts. Please request a new code.';
                    $otpStep = true;
                    $otpEmail = $email;
                } elseif (!password_verify($submittedOtp, (string) $otpRow['otp_hash'])) {
                    $pdo->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    lex_audit('failed_otp', 'email_otps', (string) $otpRow['id'], (int) $user['id']);
                    $error = 'Invalid verification code.';
                    $otpStep = true;
                    $otpEmail = $email;
                } elseif ((int) $user['is_active'] !== 1) {
                    $error = 'Account is inactive.';
                    lex_login_clear_pending_otp();
                    $otpStep = false;
                } else {
                    $pdo->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id')->execute(['id' => (int) $otpRow['id']]);
                    if (($user['role'] ?? '') !== 'admin') {
                        lex_login_remember_otp_verified((int) $user['id']);
                    }
                    lex_login_finish($pdo, $user);
                }
            }
        } elseif ($action === 'resend_otp') {
            $pending = $_SESSION['pending_login_otp'] ?? null;
            if (!is_array($pending)) {
                $error = 'Login verification expired. Please sign in again.';
                lex_login_clear_pending_otp();
                $otpStep = false;
            } else {
                $pendingEmail = lex_sanitize_email((string) ($pending['email'] ?? ''));
                $limit = lex_rate_limit_hit(
                    'login_otp_resend',
                    lex_rate_limit_key(lex_rate_limit_client_ip(), $pendingEmail),
                    3,
                    600,
                    600,
                    $pendingEmail
                );
                if (!$limit['allowed']) {
                    $error = lex_rate_limit_message((int) $limit['retry_after']);
                    $otpStep = true;
                    $otpEmail = $pendingEmail;
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND email = :email LIMIT 1');
                    $stmt->execute([
                        'id' => lex_sanitize_int($pending['user_id'] ?? 0),
                        'email' => lex_sanitize_email((string) ($pending['email'] ?? '')),
                    ]);
                    $user = $stmt->fetch();
                    if ($user && lex_login_send_otp($pdo, $user)) {
                        $success = 'A new 4-digit verification code was sent to your email.';
                        $otpStep = true;
                        $otpEmail = (string) $user['email'];
                    } else {
                        $mailError = lex_mail_error();
                        $error = 'Unable to send the login verification code. Please check the SMTP settings.'
                            . ($mailError ? ' Mail error: ' . $mailError : '');
                        $otpStep = true;
                        $otpEmail = (string) ($pending['email'] ?? '');
                    }
                }
            }
        } elseif ($action === 'cancel_otp') {
            lex_login_clear_pending_otp();
            $otpStep = false;
        } else {
            $email = lex_sanitize_email($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $limit = lex_rate_limit_hit(
                'login_password',
                lex_rate_limit_key(lex_rate_limit_client_ip(), $email),
                10,
                900,
                900,
                $email !== '' ? $email : lex_rate_limit_client_ip()
            );

            if (!$limit['allowed']) {
                $error = lex_rate_limit_message((int) $limit['retry_after']);
            } else {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Invalid credentials.';
                } elseif ((int) $user['is_active'] !== 1) {
                    $error = 'Account is inactive.';
                } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                    $error = 'Account locked due to failed attempts. Please try again later.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    lex_record_login_attempt($pdo, (int) $user['id'], false);
                    lex_audit('failed_login', 'users', (string) $user['id'], (int) $user['id']);
                    if (($user['role'] ?? '') === 'admin') {
                        lex_audit('failed_admin_login', 'users', (string) $user['id'], (int) $user['id']);
                        lex_send_account_activity_alert($user, 'Failed admin login attempt', 'Someone tried to sign in to your LEXSHIELD admin account with an incorrect password.');
                    }
                    $error = 'Invalid credentials.';
                } elseif (!lex_login_requires_otp($user)) {
                    lex_login_finish($pdo, $user);
                } elseif (($user['role'] ?? '') !== 'admin' && lex_login_otp_cookie_valid((int) $user['id'])) {
                    lex_login_finish($pdo, $user);
                } elseif (lex_login_send_otp($pdo, $user)) {
                    $success = 'A 4-digit verification code was sent to your email.';
                    $otpStep = true;
                    $otpEmail = (string) $user['email'];
                } else {
                    $mailError = lex_mail_error();
                    $error = 'Unable to send the login verification code. Please check the SMTP settings.'
                        . ($mailError ? ' Mail error: ' . $mailError : '');
                }
            }
        }
    }
}

if ($otpStep && $otpEmail === '' && !empty($_SESSION['pending_login_otp']['email'])) {
    $otpEmail = (string) $_SESSION['pending_login_otp']['email'];
}
$otpExpiresAt = $otpStep ? (int) ($_SESSION['pending_login_otp']['expires_at'] ?? 0) : 0;
$otpRemainingSeconds = $otpExpiresAt > time() ? $otpExpiresAt - time() : 0;
$otpInputInvalid = $otpStep && $error !== '';

lex_auth_page_header('Secure Login');
?>
<section class="auth-card auth-card-split">
  <div class="auth-copy auth-copy-dark">
    <a class="auth-home-link" href="<?= lex_e(lex_app_url('#home')) ?>" aria-label="Back to home" title="Back to home">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M4.75 10.95 12 4.6l7.25 6.35v8.3a.75.75 0 0 1-.75.75h-4.25v-5.25h-4.5V20H5.5a.75.75 0 0 1-.75-.75v-8.3Z" fill="currentColor"/>
      </svg>
    </a>
    <h1>Secure access for legal teams and clients.</h1>
    <p>Sign in with session security, CSRF protection, and audit logging.</p>
    <ul class="feature-list">
      <li>Role-based portal access</li>
      <li>Encrypted case documents</li>
      <li>Full auditability</li>
    </ul>
  </div>
  <div class="auth-panel auth-panel-form">
    <div class="auth-switch" aria-label="Authentication pages">
      <span class="auth-switch__item is-active">Sign in</span>
      <a class="auth-switch__item" href="register.php">New client</a>
    </div>
   
    <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= lex_e($success) ?></div><?php endif; ?>
    <form method="post" class="stack-form" autocomplete="off">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label>Email
        <input type="email" name="email" required placeholder="user@gmail.com">
      </label>
      <label>Password
        <div class="password-field" data-password-toggle>
          <input type="password" name="password" required placeholder="Enter your password">
          <button type="button" class="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password"><span class="sr-only">Show password</span></button>
        </div>
      </label>
      <a class="auth-forgot-link" href="forgot_password.php">Forgot password?</a>
      <button class="button button-primary auth-submit" type="submit">Login</button>
    </form>
    <p class="muted auth-footnote">New client? <a href="register.php">Create an account</a></p>
  </div>
</section>

<div class="modal-overlay auth-otp-overlay<?= $otpStep ? ' is-open' : '' ?>" id="loginOtpModal" aria-hidden="<?= $otpStep ? 'false' : 'true' ?>">
  <div class="modal-card auth-otp-modal" role="dialog" aria-modal="true" aria-labelledby="loginOtpTitle">
    <div class="modal-header">
      <div>
        <h2 id="loginOtpTitle">Login Verification</h2>
        <p class="modal-note">Enter the 4-digit code sent to <?= lex_e($otpEmail ?: 'your email') ?>. This browser will be remembered for 12 hours.</p>
      </div>
    </div>
    <div class="modal-body">
      <div class="auth-otp-timer" data-otp-timer data-seconds="<?= (int) $otpRemainingSeconds ?>" aria-live="polite">
        Time remaining: <strong data-otp-timer-value>03:00</strong>
      </div>
      <form method="post" class="stack-form auth-otp-form" autocomplete="off">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="action" value="verify_otp">
        <label>Verification code
          <input type="text" name="otp_code" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="1234" autocomplete="one-time-code" autofocus<?= $otpInputInvalid ? ' class="is-invalid" aria-invalid="true" aria-describedby="loginOtpError"' : '' ?>>
        </label>
        <?php if ($otpInputInvalid): ?><p class="auth-otp-error" id="loginOtpError" role="alert"><?= lex_e($error) ?></p><?php endif; ?>
        <button class="button button-primary auth-submit" type="submit">Verify &amp; Login</button>
      </form>
      <div class="auth-otp-actions">
        <form method="post">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="action" value="resend_otp">
          <button class="button button-secondary" type="submit">Resend Code</button>
        </form>
        <form method="post">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="action" value="cancel_otp">
          <button class="button button-secondary" type="submit">Use Different Login</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
  const timer = document.querySelector('[data-otp-timer]');
  if (!timer) return;

  const value = timer.querySelector('[data-otp-timer-value]');
  const form = document.querySelector('.auth-otp-form');
  const verifyButton = form ? form.querySelector('button[type="submit"]') : null;
  const input = form ? form.querySelector('input[name="otp_code"]') : null;
  let remaining = Number.parseInt(timer.dataset.seconds || '0', 10);
  let intervalId = 0;

  const render = () => {
    const safeRemaining = Math.max(0, remaining);
    const minutes = String(Math.floor(safeRemaining / 60)).padStart(2, '0');
    const seconds = String(safeRemaining % 60).padStart(2, '0');
    if (value) value.textContent = `${minutes}:${seconds}`;
    if (safeRemaining <= 0) {
      timer.classList.add('is-expired');
      timer.firstChild.textContent = 'Code expired: ';
      if (verifyButton) verifyButton.disabled = true;
      if (input) input.disabled = true;
      window.clearInterval(intervalId);
    }
  };

  render();
  intervalId = window.setInterval(() => {
    remaining -= 1;
    render();
  }, 1000);
})();
</script>
<?php lex_auth_page_footer(); ?>
