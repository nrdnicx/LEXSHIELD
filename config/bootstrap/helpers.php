<?php
declare(strict_types=1);

function lex_storage_assert_child_path(string $baseDir, string $storedName): string
{
    $storedName = trim($storedName);
    if ($storedName === '' || basename($storedName) !== $storedName || str_contains($storedName, "\\0")) {
        throw new RuntimeException('Invalid stored file name.');
    }

    $base = realpath($baseDir);
    if ($base === false) {
        throw new RuntimeException('Storage directory is unavailable.');
    }

    $path = $base . DIRECTORY_SEPARATOR . $storedName;
    $parent = realpath(dirname($path));
    if ($parent === false) {
        throw new RuntimeException('Storage path is unavailable.');
    }

    $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($parent !== $base && strncmp($parent . DIRECTORY_SEPARATOR, $basePrefix, strlen($basePrefix)) !== 0) {
        throw new RuntimeException('Invalid storage path.');
    }

    return $path;
}

class LexAppointmentBookingException extends Exception
{
}

function lex_appointment_session_lock(PDO $pdo, int $lawyerId, string $date, string $session): bool
{
    if ($lawyerId <= 0 || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) || !in_array($session, ['morning', 'afternoon', 'reschedule'], true)) {
        return false;
    }

    $name = 'lexshield_appt_' . $lawyerId . '_' . str_replace('-', '', $date) . '_' . $session;
    $name = substr($name, 0, 64);
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
    $stmt->execute(['lock_name' => $name]);
    return (int) $stmt->fetchColumn() === 1;
}

function lex_appointment_session_unlock(PDO $pdo, int $lawyerId, string $date, string $session): void
{
    if ($lawyerId <= 0 || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) || !in_array($session, ['morning', 'afternoon', 'reschedule'], true)) {
        return;
    }

    $name = 'lexshield_appt_' . $lawyerId . '_' . str_replace('-', '', $date) . '_' . $session;
    $name = substr($name, 0, 64);
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => $name]);
    } catch (Throwable $e) {
        error_log('[APPOINTMENT_LOCK] Failed to release lock: ' . $e->getMessage());
    }
}

function lex_validate_appointment_schedule(PDO $pdo, int $lawyerId, string $scheduledAt, int $excludeAppointmentId = 0): array
{
    try {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $scheduledAt);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dateTime || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return ['ok' => false, 'error' => 'Choose a valid appointment date and time.', 'session' => null];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Choose a valid appointment date and time.', 'session' => null];
    }

    if ($dateTime <= new DateTimeImmutable()) {
        return ['ok' => false, 'error' => 'Choose a future date and time.', 'session' => null];
    }

    $date = $dateTime->format('Y-m-d');
    $time = $dateTime->format('H:i');
    $weekday = (int) $dateTime->format('w');

    $stmt = $pdo->prepare('SELECT morning_start, morning_end, afternoon_start, afternoon_end, morning_capacity, afternoon_capacity FROM lawyer_availability WHERE lawyer_id = :lawyer_id AND day_of_week = :day_of_week AND is_available = 1 LIMIT 1');
    $stmt->execute(['lawyer_id' => $lawyerId, 'day_of_week' => $weekday]);
    $schedule = $stmt->fetch();
    if (!$schedule) {
        return ['ok' => false, 'error' => 'The lawyer is not available on this day.', 'session' => null];
    }

    $stmt = $pdo->prepare('SELECT 1 FROM lawyer_unavailable_dates WHERE lawyer_id = :lawyer_id AND unavailable_date = :unavailable_date LIMIT 1');
    $stmt->execute(['lawyer_id' => $lawyerId, 'unavailable_date' => $date]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'The lawyer is unavailable on this date. Please choose another date.', 'session' => null];
    }

    $toMinutes = static function (string $value): int {
        [$hours, $minutes] = array_map('intval', explode(':', substr($value, 0, 5)));
        return ($hours * 60) + $minutes;
    };
    $selectedMinutes = $toMinutes($time);
    $session = null;
    foreach (['morning', 'afternoon'] as $candidate) {
        $start = $schedule[$candidate . '_start'] ?? null;
        $end = $schedule[$candidate . '_end'] ?? null;
        if ($start && $end) {
            $startMinutes = $toMinutes((string) $start);
            $endMinutes = $toMinutes((string) $end);
            if ($selectedMinutes >= $startMinutes && $selectedMinutes < $endMinutes && (($selectedMinutes - $startMinutes) % 30 === 0)) {
                $session = $candidate;
                break;
            }
        }
    }
    if ($session === null) {
        return ['ok' => false, 'error' => 'Choose a consultation time within the lawyer\'s available hours at a 30-minute interval.', 'session' => null];
    }

    $sessionStart = substr((string) $schedule[$session . '_start'], 0, 8);
    $sessionEnd = substr((string) $schedule[$session . '_end'], 0, 8);
    $capacity = max(0, (int) $schedule[$session . '_capacity']);
    $params = ['lawyer_id' => $lawyerId, 'date' => $date, 'start' => $sessionStart, 'end' => $sessionEnd];
    $excludeSql = '';
    if ($excludeAppointmentId > 0) {
        $excludeSql = ' AND a.id <> :exclude_id';
        $params['exclude_id'] = $excludeAppointmentId;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments a WHERE a.lawyer_id = :lawyer_id AND DATE(a.scheduled_at) = :date AND TIME(a.scheduled_at) >= :start AND TIME(a.scheduled_at) < :end AND a.status IN ("pending", "confirmed")' . $excludeSql);
    $stmt->execute($params);
    $sessionCount = (int) $stmt->fetchColumn();

    $slotParams = ['lawyer_id' => $lawyerId, 'date' => $date, 'time' => $time . ':00'];
    $slotExcludeSql = '';
    if ($excludeAppointmentId > 0) {
        $slotExcludeSql = ' AND a.id <> :exclude_id';
        $slotParams['exclude_id'] = $excludeAppointmentId;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments a WHERE a.lawyer_id = :lawyer_id AND DATE(a.scheduled_at) = :date AND TIME(a.scheduled_at) = :time AND a.status IN ("pending", "confirmed")' . $slotExcludeSql);
    $stmt->execute($slotParams);
    $slotTaken = (int) $stmt->fetchColumn() > 0;

    if ($slotTaken) {
        return ['ok' => false, 'error' => 'That time slot is already booked. Please choose another time.', 'session' => $session];
    }
    if ($capacity <= 0 || $sessionCount >= $capacity) {
        return ['ok' => false, 'error' => ucfirst($session) . ' consultation capacity is full for this date. Please choose another session or date.', 'session' => $session];
    }

    return ['ok' => true, 'error' => '', 'session' => $session, 'date' => $date, 'time' => $time, 'session_start' => $sessionStart, 'session_end' => $sessionEnd, 'capacity' => $capacity];
}

/**
 * Validate a lawyer-selected reschedule. Lawyers can choose any future time,
 * independent of public booking availability, while existing appointments
 * for that lawyer may not overlap the 30-minute consultation slot.
 */
function lex_validate_lawyer_reschedule(PDO $pdo, int $lawyerId, string $scheduledAt, int $excludeAppointmentId = 0): array
{
    try {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $scheduledAt);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dateTime || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return ['ok' => false, 'error' => 'Choose a valid appointment date and time.', 'session' => null];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Choose a valid appointment date and time.', 'session' => null];
    }

    if ($dateTime <= new DateTimeImmutable()) {
        return ['ok' => false, 'error' => 'Choose a future date and time.', 'session' => null];
    }

    $start = $dateTime->format('Y-m-d H:i:s');
    $end = $dateTime->modify('+30 minutes')->format('Y-m-d H:i:s');
    $sql = 'SELECT COUNT(*) FROM appointments
            WHERE lawyer_id = :lawyer_id
              AND status IN ("pending", "confirmed")
              AND scheduled_at < :end_at
              AND DATE_ADD(scheduled_at, INTERVAL 30 MINUTE) > :start_at';
    $params = ['lawyer_id' => $lawyerId, 'start_at' => $start, 'end_at' => $end];
    if ($excludeAppointmentId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params['exclude_id'] = $excludeAppointmentId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'That time overlaps another appointment. Please choose another time.', 'session' => 'reschedule'];
    }

    return [
        'ok' => true,
        'error' => '',
        'session' => 'reschedule',
        'date' => $dateTime->format('Y-m-d'),
        'time' => $dateTime->format('H:i'),
    ];
}

function lex_audit(string $action, string $table, ?string $targetId = null, ?int $userId = null): void
{
    try {
        $userId = $userId ?? (lex_current_user()['id'] ?? null);
        $stmt = lex_pdo()->prepare('INSERT INTO audit_logs (user_id, action, target_table, target_id, ip_address, user_agent, performed_at) VALUES (:user_id, :action, :target_table, :target_id, :ip_address, :user_agent, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'target_table' => $table,
            'target_id' => $targetId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250),
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[AUDIT] %s on %s failed: %s', $action, $table, $e->getMessage()));
    }
}

function lex_audit_security_event(string $action, string $targetTable = 'security_events', ?string $targetId = null): void
{
    lex_audit($action, $targetTable, $targetId);
}

function lex_audit_csrf_failure(string $context = ''): void
{
    lex_audit_security_event('csrf_failure', 'security_events', $context !== '' ? $context : null);
}

function lex_audit_rejected_upload(string $context, string $reason): void
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    $targetId = $context . ($reason !== '' ? ': ' . substr($reason, 0, 160) : '');
    lex_audit_security_event('rejected_upload', 'uploads', $targetId);
}

function lex_reject_upload(string $context, string $message): never
{
    lex_audit_rejected_upload($context, $message);
    throw new RuntimeException($message);
}

function lex_notify(int $userId, string $type, string $message): void
{
    try {
        lex_notifications_table_ensure();
        $stmt = lex_pdo()->prepare('INSERT INTO notifications (user_id, type, message, is_read, created_at) VALUES (:user_id, :type, :message, 0, NOW())');
        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'message' => $message,
        ]);
    } catch (Throwable $e) {
        error_log(sprintf('[NOTIFY] %s for user %d failed: %s', $type, $userId, $e->getMessage()));
    }
}

function lex_notifications_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    lex_db_retry(static function () use (&$done): void {
        lex_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS `notifications` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `type` VARCHAR(60) NOT NULL,
              `message` VARCHAR(255) NOT NULL,
              `is_read` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_notifications_user_read` (`user_id`, `is_read`),
              CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_notifications_for_user(int $userId, int $limit = 8): array
{
    if ($userId <= 0) {
        return ['items' => [], 'unread_count' => 0];
    }

    try {
        lex_notifications_table_ensure();
        $countStmt = lex_pdo()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $countStmt->execute(['user_id' => $userId]);
        $itemStmt = lex_pdo()->prepare(
            'SELECT id, type, message, is_read, created_at
             FROM notifications
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, min(20, $limit))
        );
        $itemStmt->execute(['user_id' => $userId]);
        return [
            'items' => $itemStmt->fetchAll(),
            'unread_count' => (int) $countStmt->fetchColumn(),
        ];
    } catch (Throwable $e) {
        error_log(sprintf('[NOTIFY] Fetch for user %d failed: %s', $userId, $e->getMessage()));
        return ['items' => [], 'unread_count' => 0];
    }
}

function lex_admin_password_change_email_body(array $user, string $temporaryPassword): string
{
    $name = trim((string) ($user['full_name'] ?? 'LEXSHIELD user'));

    return "Hello {$name},\n\n"
        . "Your LEXSHIELD account password has been changed by an administrator.\n\n"
        . "Your new temporary password is:\n\n"
        . $temporaryPassword . "\n\n"
        . "Please log in to your LEXSHIELD account and change your password after logging in.\n\n"
        . "If you did not expect this password change, please contact the LEXSHIELD administrator immediately.\n\n"
        . "Regards,\n"
        . "LEXSHIELD Administration";
}

function lex_admin_change_user_password(int $targetUserId, string $expectedRole, string $newPassword): bool
{
    if (!in_array($expectedRole, ['lawyer', 'client'], true)) {
        throw new RuntimeException('Only lawyer and client passwords can be changed here.');
    }

    if (strlen($newPassword) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    $pdo = lex_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, role, is_active
         FROM users
         WHERE id = :id AND role = :role AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $targetUserId,
        'role' => $expectedRole,
    ]);
    $targetUser = $stmt->fetch();
    if (!$targetUser) {
        throw new RuntimeException('The selected active ' . $expectedRole . ' account could not be found.');
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = :role AND is_active = 1');
        $update->execute([
            'password_hash' => $passwordHash,
            'id' => $targetUserId,
            'role' => $expectedRole,
        ]);
        if ($update->rowCount() < 1) {
            throw new RuntimeException('Password could not be changed for the selected account.');
        }

        lex_audit('admin_password_change', 'users', (string) $targetUserId);
        lex_notify($targetUserId, 'security', 'Your LEXSHIELD account password was changed by an administrator.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $email = lex_sanitize_email((string) ($targetUser['email'] ?? ''));
    if ($email === '') {
        error_log('[ADMIN_PASSWORD_CHANGE] Email notification skipped for user ' . $targetUserId . ': missing email address.');
        return false;
    }

    $sent = lex_send_email(
        $email,
        'LEXSHIELD - Your Password Has Been Changed',
        lex_admin_password_change_email_body($targetUser, $newPassword)
    );
    if (!$sent) {
        $mailError = lex_mail_error() ?: 'Unknown mail error.';
        error_log('[ADMIN_PASSWORD_CHANGE] Email notification failed for user ' . $targetUserId . ': ' . $mailError);
    }

    return $sent;
}

function lex_message_role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'lawyer' => 'Lawyer',
        'client' => 'Client',
        default => ucfirst($role),
    };
}

function lex_message_excerpt(string $value, int $limit = 72): string
{
    $text = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($text === '') {
        return 'No message yet';
    }
    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, max(0, $limit - 3))) . '...';
}

function lex_message_display_text(array $message): string
{
    $isEncrypted = !empty($message['is_encrypted']);
    $plain = $isEncrypted
        ? lex_decrypt_string((string) ($message['message_text'] ?? ''))
        : (string) ($message['message_text'] ?? '');
    $plain = trim($plain);
    if ($plain === '') {
        if (!empty($message['attachment_original_name'])) {
            $plain = 'Attachment: ' . (string) $message['attachment_original_name'];
        } elseif (!empty($message['attachment_stored_name'])) {
            $plain = 'Attachment: ' . (string) $message['attachment_stored_name'];
        }
    }
    return $plain !== '' ? $plain : (string) ($message['message_text'] ?? '');
}

function lex_message_timestamp(string $value): string
{
    $time = strtotime($value);
    if ($time === false) {
        return $value;
    }
    return date('M j, g:i A', $time);
}

function lex_message_bubble_class(int $senderId, int $currentUserId): string
{
    return $senderId === $currentUserId ? 'sent' : 'received';
}

function lex_case_count_for_role(array $user): int
{
    return lex_db_retry(static function () use ($user): int {
        if ($user['role'] === 'admin') {
            return lex_stats('SELECT COUNT(*) FROM cases');
        }
        if ($user['role'] === 'lawyer') {
            $stmt = lex_pdo()->prepare('SELECT id FROM lawyers WHERE user_id = :uid');
            $stmt->execute(['uid' => $user['id']]);
            $lawyerId = (int) ($stmt->fetchColumn() ?: 0);
            return $lawyerId ? lex_stats('SELECT COUNT(*) FROM cases WHERE lawyer_id = :lid', ['lid' => $lawyerId]) : 0;
        }
        $stmt = lex_pdo()->prepare('SELECT id FROM clients WHERE user_id = :uid');
        $stmt->execute(['uid' => $user['id']]);
        $clientId = (int) ($stmt->fetchColumn() ?: 0);
        return $clientId ? lex_stats('SELECT COUNT(*) FROM cases WHERE client_id = :cid', ['cid' => $clientId]) : 0;
    }, 0);
}

function lex_crypto_key(): string
{
    global $lexEncryptionKey;
    return substr(hash('sha256', $lexEncryptionKey, true), 0, 32);
}

function lex_encrypt_string(string $plaintext): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', lex_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

function lex_decrypt_string(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', lex_crypto_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function lex_user_lawyer_id(int $userId): int
{
    return (int) lex_db_retry(static function () use ($userId): int {
        $stmt = lex_pdo()->prepare('SELECT id FROM lawyers WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }, 0);
}

function lex_user_client_id(int $userId): int
{
    return (int) lex_db_retry(static function () use ($userId): int {
        $stmt = lex_pdo()->prepare('SELECT id FROM clients WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }, 0);
}

function lex_admin_pagination(string $path, array $queryParams, int $totalItems, int $currentPage, int $perPage = 10): string
{
    $totalItems = max(0, $totalItems);
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $currentPage = min(max(1, $currentPage), $totalPages);
    $start = $totalItems === 0 ? 0 : (($currentPage - 1) * $perPage) + 1;
    $end = min($totalItems, $currentPage * $perPage);

    $urlForPage = static function (int $page) use ($path, $queryParams): string {
        $params = $queryParams;
        if ($page > 1) {
            $params['page'] = $page;
        } else {
            unset($params['page']);
        }
        $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== null && $value !== 'all');
        $query = http_build_query($params);
        return lex_app_url($path) . ($query !== '' ? '?' . $query : '');
    };

    $links = [];
    $previousDisabled = $currentPage <= 1 ? ' is-disabled" aria-disabled="true' : '';
    $nextDisabled = $currentPage >= $totalPages ? ' is-disabled" aria-disabled="true' : '';
    $links[] = '<a class="admin-pagination-link admin-pagination-arrow' . $previousDisabled . '" href="' . lex_e($urlForPage(max(1, $currentPage - 1))) . '">Prev</a>';

    $windowStart = max(1, $currentPage - 2);
    $windowEnd = min($totalPages, $windowStart + 4);
    $windowStart = max(1, $windowEnd - 4);
    for ($page = $windowStart; $page <= $windowEnd; $page++) {
        $active = $page === $currentPage ? ' is-active" aria-current="page' : '';
        $links[] = '<a class="admin-pagination-link' . $active . '" href="' . lex_e($urlForPage($page)) . '">' . (int) $page . '</a>';
    }

    $links[] = '<a class="admin-pagination-link admin-pagination-arrow' . $nextDisabled . '" href="' . lex_e($urlForPage(min($totalPages, $currentPage + 1))) . '">Next</a>';

    return '<nav class="admin-pagination" aria-label="Table pages">'
        . '<span class="admin-pagination-summary">Showing ' . number_format($start) . '-' . number_format($end) . ' of ' . number_format($totalItems) . '</span>'
        . '<div class="admin-pagination-links">' . implode('', $links) . '</div>'
        . '</nav>';
}
