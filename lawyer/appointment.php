<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('lawyer');
$pdo = lex_pdo();
$lawyerId = lex_user_lawyer_id((int) $user['id']);

function lex_appointment_state_from_request(array $input): array
{
    $q = trim(lex_sanitize_text($input['q'] ?? ''));
    $status = trim(lex_sanitize_text($input['status'] ?? 'all'));
    $page = lex_sanitize_int($input['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }

    return [
        'q' => $q,
        'status' => in_array($status, ['all', 'pending', 'confirmed', 'cancelled'], true) ? $status : 'all',
        'per_page' => 5,
        'page' => $page,
    ];
}

function lex_appointment_state_url(array $state): string
{
    $params = [];
    if (!empty($state['q'])) {
        $params['q'] = (string) $state['q'];
    }
    if (!empty($state['status']) && $state['status'] !== 'all') {
        $params['status'] = (string) $state['status'];
    }
    if (!empty($state['page']) && (int) $state['page'] > 1) {
        $params['page'] = (int) $state['page'];
    }
    $url = lex_app_url('lawyer/appointment.php');
    return $params ? $url . '?' . http_build_query($params) : $url;
}

function lex_appointment_status_class(string $status): string
{
    return match ($status) {
        'confirmed' => 'is-confirmed',
        'pending' => 'is-pending',
        'cancelled' => 'is-cancelled',
        default => 'is-neutral',
    };
}

function lex_appointment_status_label(string $status): string
{
    return match ($status) {
        'confirmed' => 'Confirmed',
        'pending' => 'Pending',
        'cancelled' => 'Cancelled',
        'deleted' => 'Deleted',
        default => ucfirst($status),
    };
}

function lex_appointment_summary_icon(string $type): string
{
    return match ($type) {
        'confirmed' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2.75h1.7V5h6.6V2.75H17V5h1.25A2.75 2.75 0 0 1 21 7.75v10.5A2.75 2.75 0 0 1 18.25 21H5.75A2.75 2.75 0 0 1 3 18.25V7.75A2.75 2.75 0 0 1 5.75 5H7V2.75Zm10.7 7.8-1.2-1.2-5.45 5.45-2.45-2.45-1.2 1.2 3.65 3.65 6.65-6.65Z" fill="currentColor"/></svg>',
        'pending' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm.85 4.5h-1.7v5.05l3.6 2.14.86-1.46-2.76-1.63v-4.1Z" fill="currentColor"/></svg>',
        'cancelled' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.25a8.75 8.75 0 1 1 0 17.5 8.75 8.75 0 0 1 0-17.5Zm3.32 5.23L12 11.8 8.68 8.48 7.48 9.68 10.8 13l-3.32 3.32 1.2 1.2L12 14.2l3.32 3.32 1.2-1.2L13.2 13l3.32-3.32-1.2-1.2Z" fill="currentColor"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2.75h1.7V5h6.6V2.75H17V5h1.25A2.75 2.75 0 0 1 21 7.75v10.5A2.75 2.75 0 0 1 18.25 21H5.75A2.75 2.75 0 0 1 3 18.25V7.75A2.75 2.75 0 0 1 5.75 5H7V2.75Zm11.25 7.05H5.75v8.45c0 .58.47 1.05 1.05 1.05h10.4c.58 0 1.05-.47 1.05-1.05V9.8Z" fill="currentColor"/></svg>',
    };
}

function lex_appointment_render_page_links(int $currentPage, int $totalPages, array $state): array
{
    if ($totalPages <= 1) {
        return [];
    }

    $pages = [1];
    if ($currentPage > 3) {
        $pages[] = 'ellipsis-start';
    }

    $start = max(2, $currentPage - 1);
    $end = min($totalPages - 1, $currentPage + 1);
    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($currentPage < $totalPages - 2) {
        $pages[] = 'ellipsis-end';
    }
    $pages[] = $totalPages;

    $pages = array_values(array_unique($pages, SORT_REGULAR));

    return $pages;
}

function lex_appointment_render_results(array $appointments, array $state, string $currentUrl): string
{
    ob_start();
    ?>
    <div class="appointment-mobile-list" aria-label="Mobile appointment requests">
      <?php if ($appointments): ?>
        <?php foreach ($appointments as $appointment): ?>
          <?php $mobileNotes = trim((string) ($appointment['notes'] ?? '')); ?>
          <article class="appointment-mobile-card">
            <div class="appointment-mobile-card__top">
              <div class="appointment-mobile-card__identity">
                <span class="appointment-mobile-card__eyebrow">CASE / CLIENT</span>
                <strong><?= lex_e((string) ($appointment['title'] ?? 'Appointment')) ?></strong>
                <small>#<?= lex_e((string) ($appointment['case_number'] ?? '')) ?> &bull; <?= lex_e((string) ($appointment['client_name'] ?? '')) ?></small>
              </div>
              <span class="appointment-badge <?= lex_e(lex_appointment_status_class((string) $appointment['status'])) ?>"><?= lex_e(lex_appointment_status_label((string) $appointment['status'])) ?></span>
            </div>
            <div class="appointment-mobile-card__schedule">
              <div><span>DATE</span><strong><?= lex_e(date('M j, Y', strtotime((string) $appointment['scheduled_at']))) ?></strong></div>
              <div><span>TIME</span><strong><?= lex_e(date('g:i A', strtotime((string) $appointment['scheduled_at']))) ?></strong></div>
              <div><span>TYPE</span><strong><?= lex_e((string) ($appointment['appointment_type'] ?? 'Consultation')) ?></strong></div>
            </div>
            <div class="appointment-mobile-card__notes">
              <span>NOTES</span>
              <p><?= lex_e($mobileNotes !== '' ? $mobileNotes : 'No notes were added for this appointment.') ?></p>
            </div>
            <div class="appointment-mobile-card__actions">
              <form method="post" action="<?= lex_e($currentUrl) ?>" class="appointment-mobile-update-form" data-no-loading>
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                <div class="appointment-mobile-form-grid">
                  <label>Status<select name="status"><option value="pending"<?= ($appointment['status'] ?? '') === 'pending' ? ' selected' : '' ?>>Pending</option><option value="confirmed"<?= ($appointment['status'] ?? '') === 'confirmed' ? ' selected' : '' ?>>Confirmed</option><option value="cancelled"<?= ($appointment['status'] ?? '') === 'cancelled' ? ' selected' : '' ?>>Cancelled</option></select></label>
                  <label>Schedule<input type="datetime-local" name="scheduled_at" value="<?= lex_e(date('Y-m-d\TH:i', strtotime((string) $appointment['scheduled_at']))) ?>"></label>
                </div>
                <button class="button button-primary" type="submit">Save appointment</button>
              </form>
              <form method="post" action="<?= lex_e($currentUrl) ?>" data-no-loading>
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                <button class="button button-danger appointment-mobile-delete" type="submit" data-confirm="Delete this appointment request?">Delete</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="appointment-mobile-empty"><strong>No results found.</strong><p>Try a different search term or clear the filters.</p></div>
      <?php endif; ?>
    </div>

    <div class="table-wrap appointment-table-wrap">
      <table class="data-table appointment-table">
        <thead>
          <tr>
            <th>Case / Client</th>
            <th>Scheduled</th>
            <th>Status</th>
            <th>Notes</th>
            <th class="appointment-actions-head">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($appointments): ?>
          <?php foreach ($appointments as $appointment): ?>
            <?php $appointmentNotes = trim((string) ($appointment['notes'] ?? '')); ?>
            <tr class="appointment-row">
              <td data-label="Case / Client">
                <div class="appointment-cell-title"><?= lex_e((string) ($appointment['title'] ?? 'Appointment')) ?></div>
                <div class="appointment-cell-subtitle">#<?= lex_e((string) ($appointment['case_number'] ?? '')) ?> &bull; <?= lex_e((string) ($appointment['client_name'] ?? '')) ?></div>
              </td>
              <td data-label="Scheduled">
                <div class="appointment-schedule-date"><?= lex_e(date('M j, Y', strtotime((string) $appointment['scheduled_at']))) ?></div>
                <div class="appointment-schedule-time"><?= lex_e(date('g:i A', strtotime((string) $appointment['scheduled_at']))) ?></div>
              </td>
              <td data-label="Status">
                <span class="appointment-badge <?= lex_e(lex_appointment_status_class((string) $appointment['status'])) ?>">
                  <?= lex_e(lex_appointment_status_label((string) $appointment['status'])) ?>
                </span>
              </td>
              <td data-label="Notes">
                <button
                  class="appointment-note-button"
                  type="button"
                  data-client-note-open
                  data-note="<?= lex_e($appointmentNotes !== '' ? $appointmentNotes : 'No notes were added for this appointment.') ?>"
                  aria-label="Read appointment notes"
                  title="Read notes"
                >
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M6.75 3.75h10.5A2.25 2.25 0 0 1 19.5 6v12a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 18V6a2.25 2.25 0 0 1 2.25-2.25Zm1.5 4.5a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h7.5a.75.75 0 0 0 0-1.5h-7.5Zm0 3.25a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" fill="currentColor"/>
                  </svg>
                </button>
              </td>
              <td data-label="Actions">
                <div class="appointment-actions">
                  <form method="post" action="<?= lex_e($currentUrl) ?>" class="appointment-inline-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                    <label class="sr-only" for="appointment-status-<?= (int) $appointment['id'] ?>">Appointment status</label>
                    <select id="appointment-status-<?= (int) $appointment['id'] ?>" name="status">
                      <option value="pending"<?= ($appointment['status'] ?? '') === 'pending' ? ' selected' : '' ?>>Pending</option>
                      <option value="confirmed"<?= ($appointment['status'] ?? '') === 'confirmed' ? ' selected' : '' ?>>Confirmed</option>
                      <option value="cancelled"<?= ($appointment['status'] ?? '') === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
                    </select>
                    <label class="sr-only" for="appointment-date-<?= (int) $appointment['id'] ?>">Reschedule appointment</label>
                    <input id="appointment-date-<?= (int) $appointment['id'] ?>" type="datetime-local" name="scheduled_at" value="<?= lex_e(date('Y-m-d\TH:i', strtotime((string) $appointment['scheduled_at']))) ?>">
                    <button class="button button-secondary appointment-action-button" type="submit">Save</button>
                  </form>
                  <form method="post" action="<?= lex_e($currentUrl) ?>" class="appointment-inline-form appointment-inline-delete" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                    <button class="button button-danger appointment-action-button appointment-delete-button" type="submit" aria-label="Delete appointment" title="Delete appointment" data-confirm="Delete this appointment request?">
                      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M9 3.75h6a1.5 1.5 0 0 1 1.5 1.5v.75H20a.75.75 0 0 1 0 1.5h-1.02l-.84 11.13A2.25 2.25 0 0 1 15.9 20.7H8.1a2.25 2.25 0 0 1-2.24-2.07L5.02 7.5H4a.75.75 0 0 1 0-1.5h3.5v-.75A1.5 1.5 0 0 1 9 3.75Zm1.5 2.25h3V5.25h-3V6Zm-2.13 1.5.82 10.98a.75.75 0 0 0 .75.69h5.62a.75.75 0 0 0 .75-.69l.82-10.98H8.37ZM10.5 9a.75.75 0 0 1 .75.75v5.25a.75.75 0 0 1-1.5 0V9.75A.75.75 0 0 1 10.5 9Zm3 0a.75.75 0 0 1 .75.75v5.25a.75.75 0 0 1-1.5 0V9.75A.75.75 0 0 1 13.5 9Z"/>
                      </svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr class="appointment-empty-row">
            <td colspan="5">
              <div class="appointment-empty-state">
                <strong>No results found.</strong>
                <p class="muted">Try a different search term or clear the filters to see more appointments.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

function lex_appointment_render_pagination(array $state, int $currentPage, int $totalPages, int $totalFiltered, int $totalAll): string
{
    if ($totalAll <= 0) {
        return '';
    }

    $start = (($currentPage - 1) * (int) $state['per_page']) + 1;
    $end = min($totalFiltered, $currentPage * (int) $state['per_page']);
    ob_start();
    ?>
    <nav class="appointment-pagination" aria-label="Appointment pagination">
      <div class="appointment-pagination-summary">
        Showing <?= (int) $start ?>-<?= (int) $end ?> of <?= (int) $totalFiltered ?>
      </div>
      <div class="appointment-pagination-strip">
        <?php
        $prevState = $state;
        $prevState['page'] = max(1, $currentPage - 1);
        ?>
        <a class="appointment-pagination-box appointment-pagination-arrow<?= $currentPage <= 1 ? ' is-disabled' : '' ?>" href="<?= lex_e(lex_appointment_state_url($prevState)) ?>" data-appointment-page data-page="<?= max(1, $currentPage - 1) ?>"<?= $currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>&lsaquo;</a>

        <?php for ($page = 1; $page <= $totalPages; $page++): ?>
          <?php
          $pageState = $state;
          $pageState['page'] = $page;
          ?>
          <a class="appointment-pagination-box<?= $currentPage === $page ? ' is-active' : '' ?>" href="<?= lex_e(lex_appointment_state_url($pageState)) ?>" data-appointment-page data-page="<?= $page ?>"<?= $currentPage === $page ? ' aria-current="page"' : '' ?>><?= $page ?></a>
        <?php endfor; ?>

        <?php
        $nextState = $state;
        $nextState['page'] = min($totalPages, $currentPage + 1);
        ?>
        <a class="appointment-pagination-box appointment-pagination-arrow<?= $currentPage >= $totalPages ? ' is-disabled' : '' ?>" href="<?= lex_e(lex_appointment_state_url($nextState)) ?>" data-appointment-page data-page="<?= min($totalPages, $currentPage + 1) ?>"<?= $currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>&rsaquo;</a>
      </div>
    </nav>
    <?php
    return (string) ob_get_clean();
}

$state = lex_appointment_state_from_request($_GET);
$returnUrl = lex_appointment_state_url($state);
$sortSql = 'a.scheduled_at DESC, a.id DESC';

$baseWhere = ' WHERE a.lawyer_id = :lawyer_id AND a.status <> "deleted" ';
$statusWhere = '';
$searchWhere = '';
$params = ['lawyer_id' => $lawyerId];
$searchLike = '';
if ($state['status'] !== 'all') {
    $statusWhere = ' AND a.status = :status_filter ';
    $params['status_filter'] = $state['status'];
}
if ($state['q'] !== '') {
    $searchLike = '%' . (function_exists('mb_strtolower') ? mb_strtolower($state['q']) : strtolower($state['q'])) . '%';
    $searchWhere = ' AND LOWER(CONCAT_WS(" ", c.case_number, c.title, a.appointment_type, u.full_name, u.email, a.status, COALESCE(a.notes, ""), DATE_FORMAT(a.scheduled_at, "%M %e, %Y %l:%i %p"), DATE_FORMAT(a.scheduled_at, "%Y-%m-%d %H:%i"), a.id)) LIKE :search ';
    $params['search'] = $searchLike;
}

$baseTotalAppointments = lex_stats(
    'SELECT COUNT(*) FROM appointments a
     JOIN cases c ON c.id = a.case_id
     JOIN clients cl ON cl.id = c.client_id
     JOIN users u ON u.id = cl.user_id'
    . $baseWhere,
    ['lawyer_id' => $lawyerId]
);

$filteredTotalAppointments = lex_stats(
    'SELECT COUNT(*) FROM appointments a
    JOIN cases c ON c.id = a.case_id
    JOIN clients cl ON cl.id = c.client_id
    JOIN users u ON u.id = cl.user_id'
    . $baseWhere
    . $statusWhere
    . $searchWhere,
    $params
);

$statusCountsStmt = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN a.status = "confirmed" THEN 1 ELSE 0 END) AS confirmed_count,
        SUM(CASE WHEN a.status = "pending" THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN a.status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count
     FROM appointments a
     WHERE a.lawyer_id = :lawyer_id AND a.status <> "deleted"'
);
$statusCountsStmt->execute(['lawyer_id' => $lawyerId]);
$statusCounts = $statusCountsStmt->fetch() ?: ['confirmed_count' => 0, 'pending_count' => 0, 'cancelled_count' => 0];

$totalPages = $filteredTotalAppointments > 0 ? (int) max(1, ceil($filteredTotalAppointments / $state['per_page'])) : 1;
if ($state['page'] > $totalPages) {
    $state['page'] = $totalPages;
    $returnUrl = lex_appointment_state_url($state);
}
$perPage = (int) $state['per_page'];
$offset = max(0, ($state['page'] - 1) * $perPage);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    $postState = $state;
    $returnUrl = lex_appointment_state_url($postState);
    $action = (string) ($_POST['action'] ?? 'update');
    $appointmentId = lex_sanitize_int($_POST['appointment_id'] ?? 0);
    $scheduledAt = str_replace('T', ' ', lex_sanitize_text($_POST['scheduled_at'] ?? ''));
    $notes = lex_sanitize_multiline_text($_POST['notes'] ?? '');

    if ($action === 'delete') {
        $stmt = $pdo->prepare("SELECT id FROM appointments WHERE id = :id AND lawyer_id = :lawyer_id");
        $stmt->execute(['id' => $appointmentId, 'lawyer_id' => $lawyerId]);
        if ($stmt->fetchColumn()) {
            $pdo->prepare('DELETE FROM appointments WHERE id = :id AND lawyer_id = :lawyer_id')->execute([
                'id' => $appointmentId,
                'lawyer_id' => $lawyerId,
            ]);
            lex_audit('delete_appointment', 'appointments', (string) $appointmentId);
            lex_flash_set('success', 'Appointment deleted from the lawyer portal.');
            header('Location: ' . $returnUrl);
            exit;
        }
        lex_flash_set('error', 'Appointment not found.');
        header('Location: ' . $returnUrl);
        exit;
    } elseif ($action === 'update') {
        $status = (string) ($_POST['status'] ?? 'pending');
        $stmt = $pdo->prepare(
            'SELECT a.id, a.scheduled_at, c.user_id AS client_user_id
             FROM appointments a
             JOIN clients c ON c.id = a.client_id
             WHERE a.id = :id AND a.lawyer_id = :lawyer_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $appointmentId, 'lawyer_id' => $lawyerId]);
        $appointment = $stmt->fetch();
        if ($appointment) {
            if (!in_array($status, ['pending', 'confirmed', 'cancelled'], true)) {
                lex_flash_set('error', 'Choose a valid status.');
                header('Location: ' . $returnUrl);
                exit;
            }

            $effectiveScheduledAt = $scheduledAt !== ''
                ? $scheduledAt
                : date('Y-m-d H:i', strtotime((string) $appointment['scheduled_at']));
            $lockSession = null;
            $lockDate = '';
            $lockAcquired = false;
            try {
                if ($status !== 'cancelled') {
                    $validation = lex_validate_lawyer_reschedule($pdo, $lawyerId, $effectiveScheduledAt, $appointmentId);
                    if (!$validation['ok']) {
                        throw new LexAppointmentBookingException((string) $validation['error']);
                    }
                    $lockSession = (string) $validation['session'];
                    $lockDate = (string) $validation['date'];
                    $lockAcquired = lex_appointment_session_lock($pdo, $lawyerId, $lockDate, $lockSession);
                    if (!$lockAcquired) {
                        throw new LexAppointmentBookingException('The appointment system is busy. Please try again.');
                    }
                    $validation = lex_validate_lawyer_reschedule($pdo, $lawyerId, $effectiveScheduledAt, $appointmentId);
                    if (!$validation['ok']) {
                        throw new LexAppointmentBookingException((string) $validation['error']);
                    }
                }

                $pdo->beginTransaction();
                // Lock the lawyer row so concurrent client bookings and lawyer reschedules cannot race this update.
                $lockStmt = $pdo->prepare('SELECT id FROM lawyers WHERE id = :lawyer_id FOR UPDATE');
                $lockStmt->execute(['lawyer_id' => $lawyerId]);
                if (!$lockStmt->fetchColumn()) {
                    throw new LexAppointmentBookingException('The lawyer account is no longer available.');
                }
                if ($status !== 'cancelled') {
                    $validation = lex_validate_lawyer_reschedule($pdo, $lawyerId, $effectiveScheduledAt, $appointmentId);
                    if (!$validation['ok']) {
                        throw new LexAppointmentBookingException((string) $validation['error']);
                    }
                }
                // Use unique PDO placeholders because native MySQL prepares do not allow
                // the same named placeholder to be reused multiple times.
                $pdo->prepare('UPDATE appointments
                    SET status = :status,
                        scheduled_at = CASE WHEN :scheduled_at_check <> "" THEN :scheduled_at_value ELSE scheduled_at END,
                        notes = CASE WHEN :notes_check <> "" THEN :notes_value ELSE notes END
                    WHERE id = :id AND lawyer_id = :lawyer_id'
                )->execute([
                    'status' => $status,
                    'scheduled_at_check' => $scheduledAt,
                    'scheduled_at_value' => $scheduledAt,
                    'notes_check' => $notes,
                    'notes_value' => $notes,
                    'id' => $appointmentId,
                    'lawyer_id' => $lawyerId,
                ]);
                $pdo->commit();
                if ($lockAcquired) {
                    lex_appointment_session_unlock($pdo, $lawyerId, $lockDate, $lockSession);
                    $lockAcquired = false;
                }
                lex_audit('update_appointment', 'appointments', (string) $appointmentId);
                if ($status === 'confirmed') {
                    // Prepare a unique consultation room only after the appointment
                    // is successfully confirmed. Authorization is still enforced
                    // when either participant actually joins the call.
                    lex_video_prepare_for_confirmed_appointment($appointmentId);
                }
                $previousSchedule = date('Y-m-d H:i', strtotime((string) $appointment['scheduled_at']));
                $scheduleChanged = $scheduledAt !== '' && $scheduledAt !== $previousSchedule;
                if ($status === 'cancelled') {
                    $message = 'Appointment cancelled.';
                } elseif ($scheduleChanged) {
                    $message = 'Appointment rescheduled to ' . date('M j, Y g:i A', strtotime($effectiveScheduledAt)) . '.';
                } else {
                    $message = $status === 'confirmed' ? 'Appointment approved.' : 'Appointment updated.';
                }
                lex_notify((int) $appointment['client_user_id'], 'appointment', $message);
                lex_flash_set('success', $message);
                header('Location: ' . $returnUrl);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($lockAcquired) {
                    lex_appointment_session_unlock($pdo, $lawyerId, $lockDate, $lockSession);
                }
                error_log('Lawyer appointment update failed: ' . $e->getMessage());
                lex_flash_set('error', $e instanceof LexAppointmentBookingException ? $e->getMessage() : 'Unable to update the appointment. Please try again later.');
                header('Location: ' . $returnUrl);
                exit;
            }
        } else {
            lex_flash_set('error', 'Appointment not found.');
            header('Location: ' . $returnUrl);
            exit;
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.case_id, a.scheduled_at, a.appointment_type, a.status, a.notes, c.case_number, COALESCE(NULLIF(a.appointment_type, ""), NULLIF(c.title, ""), "Appointment") AS title, u.full_name AS client_name
     FROM appointments a
     JOIN cases c ON c.id = a.case_id
     JOIN clients cl ON cl.id = c.client_id
     JOIN users u ON u.id = cl.user_id'
    . $baseWhere
    . $statusWhere
    . $searchWhere
    . " ORDER BY {$sortSql} LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':lawyer_id', $lawyerId, PDO::PARAM_INT);
if ($state['status'] !== 'all') {
    $stmt->bindValue(':status_filter', $state['status'], PDO::PARAM_STR);
}
if ($state['q'] !== '') {
    $stmt->bindValue(':search', $searchLike, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appointments = $stmt->fetchAll();

$showFilters = true;
$resultsHtml = lex_appointment_render_results($appointments, $state, $returnUrl);
$paginationHtml = lex_appointment_render_pagination($state, $state['page'], $totalPages, $filteredTotalAppointments, $baseTotalAppointments);
$summaryText = $filteredTotalAppointments > 0
    ? sprintf(
        'Showing %d-%d of %d appointment%s',
        ($offset + 1),
        min($filteredTotalAppointments, $offset + count($appointments)),
        $filteredTotalAppointments,
        $filteredTotalAppointments === 1 ? '' : 's'
    )
    : ($baseTotalAppointments > 0
        ? 'No appointments matched your search.'
        : 'No appointments have been scheduled yet.');

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'resultsHtml' => $resultsHtml,
        'paginationHtml' => $paginationHtml,
        'summaryText' => $summaryText,
        'state' => $state,
        'meta' => [
            'total_all' => $baseTotalAppointments,
            'total_filtered' => $filteredTotalAppointments,
            'page' => $state['page'],
            'per_page' => $state['per_page'],
        ],
    ]);
    exit;
}

lex_page_header('Appointments', 'appointments', $user);
?>
<section class="card appointment-card" data-appointment-board data-endpoint="<?= lex_e(lex_app_url('lawyer/appointment.php')) ?>">
  <section class="kpi-grid appointment-kpis" aria-label="Appointment summary">
    <article class="kpi-card appointment-kpi is-total"><span class="appointment-stat-label"><i class="appointment-kpi-dot" aria-hidden="true"><?= lex_appointment_summary_icon('total') ?></i><em>Total</em></span><strong><?= (int) $baseTotalAppointments ?></strong></article>
    <article class="kpi-card appointment-kpi is-confirmed"><span class="appointment-stat-label"><i class="appointment-kpi-dot" aria-hidden="true"><?= lex_appointment_summary_icon('confirmed') ?></i><em>Confirmed</em></span><strong><?= (int) ($statusCounts['confirmed_count'] ?? 0) ?></strong></article>
    <article class="kpi-card appointment-kpi is-pending"><span class="appointment-stat-label"><i class="appointment-kpi-dot" aria-hidden="true"><?= lex_appointment_summary_icon('pending') ?></i><em>Pending</em></span><strong><?= (int) ($statusCounts['pending_count'] ?? 0) ?></strong></article>
    <article class="kpi-card appointment-kpi is-cancelled"><span class="appointment-stat-label"><i class="appointment-kpi-dot" aria-hidden="true"><?= lex_appointment_summary_icon('cancelled') ?></i><em>Cancelled</em></span><strong><?= (int) ($statusCounts['cancelled_count'] ?? 0) ?></strong></article>
  </section>

  <article class="card appointment-card-shell">
  <div class="card-head appointment-head">
    <div>
      <h2>Appointment Requests</h2>
    </div>
    <div class="appointment-head-meta">
      <a class="button button-secondary" href="<?= lex_e(lex_app_url('lawyer/availability.php')) ?>">Manage availability</a>
      <span class="pill"><?= (int) $baseTotalAppointments ?> total</span>
      <span class="pill"><?= (int) $filteredTotalAppointments ?> visible</span>
    </div>
  </div>

  <?php if ($showFilters): ?>
    <form method="get" action="<?= lex_e($returnUrl) ?>" class="appointment-filters" data-appointment-filters data-no-loading>
      <input type="hidden" name="page" value="<?= (int) $state['page'] ?>" data-appointment-page-input>
      <label class="appointment-search appointment-status-filter">
        <span class="sr-only">Filter appointments by status</span>
        <select name="status" data-appointment-status>
          <option value="all"<?= $state['status'] === 'all' ? ' selected' : '' ?>>All statuses</option>
          <option value="confirmed"<?= $state['status'] === 'confirmed' ? ' selected' : '' ?>>Confirmed</option>
          <option value="pending"<?= $state['status'] === 'pending' ? ' selected' : '' ?>>Pending</option>
          <option value="cancelled"<?= $state['status'] === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
        </select>
      </label>
      <label class="appointment-search appointment-search-field">
        <span class="sr-only">Search appointments</span>
        <span class="appointment-search-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
            <path d="M10.5 4a6.5 6.5 0 1 1 4.06 11.56l4.94 4.94-1.41 1.41-4.94-4.94A6.5 6.5 0 0 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/>
          </svg>
        </span>
        <input type="search" name="q" value="<?= lex_e($state['q']) ?>" placeholder="Search case, client, date, status..." autocomplete="off" data-appointment-search>
      </label>
    </form>
  <?php endif; ?>

  <p class="muted appointment-summary-line" data-appointment-summary><?= lex_e($summaryText) ?></p>

  <div class="appointment-results" data-appointment-results aria-live="polite" aria-busy="false">
    <?= $resultsHtml ?>
  </div>

  <div data-appointment-pagination>
    <?= $paginationHtml ?>
  </div>
  </article>
</section>

<div class="modal-overlay appointment-note-modal" data-client-note-modal aria-hidden="true">
  <article class="modal-card appointment-note-card" role="dialog" aria-modal="true" aria-labelledby="appointmentNoteTitle">
    <header class="modal-header">
      <h2 id="appointmentNoteTitle">Appointment Notes</h2>
      <button class="close-button" type="button" data-client-note-close aria-label="Close notes">&times;</button>
    </header>
    <div class="modal-body">
      <p class="appointment-note-text" data-client-note-text></p>
    </div>
  </article>
</div>
<?php lex_page_footer(); ?>
