<?php
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
lex_messages_table_ensure();
lex_message_deletions_table_ensure();
lex_message_thread_preferences_table_ensure();
$clientId = lex_user_client_id((int) $user['id']);
$currentUserLabel = $user['full_name'] ?? 'You';
$currentUserRole = lex_message_role_label('client');

$cases = lex_recent(
    'SELECT c.id AS case_id, c.case_number, c.title, c.status, c.priority, lu.user_id AS lawyer_user_id, uu.full_name AS lawyer_name, uu.email AS lawyer_email, uu.avatar_stored_name AS lawyer_avatar_stored_name
     FROM cases c
     JOIN lawyers lu ON lu.id = c.lawyer_id
     JOIN users uu ON uu.id = lu.user_id
     WHERE c.client_id = :id
       AND EXISTS (
           SELECT 1
           FROM messages m
           WHERE m.case_id = c.id
             AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer_id)
        )
       ORDER BY c.id DESC',
    ['id' => $clientId, 'viewer_id' => (int) $user['id']]
);

$availableLawyers = lex_recent(
    'SELECT l.id AS lawyer_id, u.id AS user_id, u.full_name, u.email
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
     ORDER BY u.full_name ASC'
);

$caseMap = [];
foreach ($cases as $case) {
    $caseMap[(int) $case['case_id']] = $case;
}
$lawyerMap = [];
$availableRecipients = [];
foreach ($availableLawyers as $lawyer) {
    $lawyerMap[(int) $lawyer['user_id']] = $lawyer;
    $availableRecipients[] = [
        'id' => (int) $lawyer['user_id'],
        'name' => $lawyer['full_name'],
        'email' => $lawyer['email'],
        'role' => 'Lawyer',
    ];
}

$threadInput = (string) ($_GET['thread'] ?? '');
$selectedCaseId = isset($cases[0]) ? (int) $cases[0]['case_id'] : 0;
if (preg_match('/^lawyer:(\d+)$/', $threadInput, $matches)) {
    $selectedCaseId = (int) $matches[1];
    if (!isset($caseMap[$selectedCaseId])) {
        $selectedCaseId = isset($cases[0]) ? (int) $cases[0]['case_id'] : 0;
    }
}

$message = '';
$error = '';
$partnerId = 0;
$partnerName = '';
$caseInfo = $selectedCaseId && isset($caseMap[$selectedCaseId]) ? $caseMap[$selectedCaseId] : null;
$activeCaseNumber = $caseInfo['case_number'] ?? 'No case selected';

$profileName = $partnerName !== '' ? $partnerName : 'Lawyer';
$profileRole = 'Lawyer';
$profileStatus = 'Online';
$profileEmail = (string) ($caseInfo['lawyer_email'] ?? '');
$profileCase = $activeCaseNumber;
$profileNote = 'Use this thread for legal guidance and case updates.';
$profileInitials = strtoupper(substr(preg_replace('/\s+/', '', $profileName) ?: 'PR', 0, 2));

$deleteMessageAttachment = static function (array $message): void {
    if (!empty($message['attachment_stored_name'])) {
        $path = lex_messages_attachment_path((string) $message['attachment_stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

if ($selectedCaseId && $caseInfo) {
    $partnerId = (int) $caseInfo['lawyer_user_id'];
    $partnerName = $caseInfo['lawyer_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && lex_csrf_validate($_POST['csrf_token'] ?? null)) {
    if (!empty($_POST['mark_unread_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $caseRow = $caseMap[$postCaseId];
            $changed = lex_message_thread_mark_unread((int) $user['id'], (int) $caseRow['lawyer_user_id'], $postCaseId);
            lex_flash_set($changed > 0 ? 'success' : 'error', $changed > 0 ? 'Conversation marked as unread.' : 'No received messages were available to mark unread.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $postCaseId) . '&keep_unread=1'));
        exit;
    }
    if (!empty($_POST['toggle_important_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $nextImportant = !lex_message_thread_preferences((int) $user['id'], $postCaseId)['is_important'];
            lex_message_thread_set_important((int) $user['id'], $postCaseId, $nextImportant);
            lex_flash_set('success', $nextImportant ? 'Conversation marked as important.' : 'Conversation removed from important.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        $filterQuery = $nextImportant ? '&filter=important' : '';
        header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $postCaseId) . $filterQuery));
        exit;
    }
    if (!empty($_POST['toggle_mute_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId && isset($caseMap[$postCaseId])) {
            $nextMuted = !lex_message_thread_preferences((int) $user['id'], $postCaseId)['is_muted'];
            lex_message_thread_set_muted((int) $user['id'], $postCaseId, $nextMuted);
            lex_flash_set('success', $nextMuted ? 'Notifications muted for this conversation.' : 'Notifications restored for this conversation.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $postCaseId)));
        exit;
    }
    if (!empty($_POST['restore_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postPartnerId = lex_sanitize_int($_POST['partner_user_id'] ?? 0);
        if ($postCaseId > 0 && isset($caseMap[$postCaseId])) {
            $postPartnerId = (int) $caseMap[$postCaseId]['lawyer_user_id'];
        }
        if ($postPartnerId > 0 && ($postCaseId === 0 || isset($caseMap[$postCaseId]))) {
            $restored = lex_message_thread_set_archived((int) $user['id'], $postCaseId, $postPartnerId, false);
            lex_flash_set($restored > 0 ? 'success' : 'error', $restored > 0 ? 'Conversation restored.' : 'Unable to restore this conversation.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        header('Location: ' . lex_app_url('client/messages.php?filter=archived'));
        exit;
    }
    if (!empty($_POST['archive_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postPartnerId = lex_sanitize_int($_POST['partner_user_id'] ?? 0);
        if ($postCaseId > 0 && isset($caseMap[$postCaseId])) {
            $postPartnerId = (int) $caseMap[$postCaseId]['lawyer_user_id'];
        }
        if ($postPartnerId > 0 && ($postCaseId === 0 || isset($caseMap[$postCaseId]))) {
            $archivedCount = lex_message_thread_set_archived((int) $user['id'], $postCaseId, $postPartnerId, true);
            lex_flash_set($archivedCount > 0 ? 'success' : 'error', $archivedCount > 0 ? 'Conversation archived.' : 'Unable to archive this conversation.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        header('Location: ' . lex_app_url('client/messages.php?filter=archived'));
        exit;
    }
    if (!empty($_POST['delete_conversation_submit'])) {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        if ($postCaseId > 0 && isset($caseMap[$postCaseId])) {
            $partnerUserId = (int) $caseMap[$postCaseId]['lawyer_user_id'];
            $deletedCount = lex_messages_delete_conversation_for_user($postCaseId, (int) $user['id'], $partnerUserId);
            lex_flash_set($deletedCount > 0 ? 'success' : 'error', $deletedCount > 0 ? 'Conversation deleted from your view.' : 'Unable to delete this conversation.');
        } else {
            lex_flash_set('error', 'That conversation is not available.');
        }
        header('Location: ' . lex_app_url('client/messages.php'));
        exit;
    }
    if (!empty($_POST['delete_message_submit'])) {
        $messageId = lex_sanitize_int($_POST['message_id'] ?? 0);
        $messageRow = lex_messages_find_owned_message($pdo, $messageId, $selectedCaseId, (int) $user['id'], (int) $user['id']);
        if ($messageRow) {
            lex_messages_delete_message_for_user($messageId, (int) $user['id']);
            lex_flash_set('success', 'Message deleted for your view.');
            header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $selectedCaseId)));
            exit;
        }
        $error = 'Message not found.';
    }
    if (!empty($_POST['new_message_submit'])) {
        $postReceiverId = lex_sanitize_int($_POST['new_receiver_id'] ?? 0);
        $text = lex_sanitize_multiline_text($_POST['new_message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['new_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim($text);
        $selectedLawyer = $lawyerMap[$postReceiverId] ?? null;
        if (!$postReceiverId || !$selectedLawyer || ($payload === '' && !$attachment)) {
            $error = $error ?: 'Choose a lawyer and write a message before sending.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('
                    SELECT c.id
                    FROM cases c
                    JOIN lawyers l ON l.id = c.lawyer_id
                    JOIN users u ON u.id = l.user_id
                    WHERE c.client_id = :client_id
                      AND u.id = :lawyer_user_id
                    ORDER BY CASE WHEN c.status IN ("open", "ongoing") THEN 0 ELSE 1 END, c.id DESC
                    LIMIT 1
                ');
                $stmt->execute([
                    'client_id' => $clientId,
                    'lawyer_user_id' => $postReceiverId,
                ]);
                $caseId = (int) ($stmt->fetchColumn() ?: 0);

                if (!$caseId) {
                    do {
                        $caseNumber = 'INTAKE-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
                        $stmt = $pdo->prepare('SELECT 1 FROM cases WHERE case_number = :case_number LIMIT 1');
                        $stmt->execute(['case_number' => $caseNumber]);
                    } while ($stmt->fetchColumn());

                    $pdo->prepare(
                        'INSERT INTO cases (case_number, title, description, lawyer_id, client_id, status, priority, filed_date, closed_date)
                         VALUES (:case_number, :title, :description, :lawyer_id, :client_id, "open", "normal", CURDATE(), NULL)'
                    )->execute([
                        'case_number' => $caseNumber,
                        'title' => 'Client Intake Consultation',
                        'description' => 'Client message thread created automatically from the message composer.',
                        'lawyer_id' => (int) $selectedLawyer['lawyer_id'],
                        'client_id' => $clientId,
                    ]);
                    $caseId = (int) $pdo->lastInsertId();
                }

                if ($payload === '' && $attachment) {
                    $payload = 'Attachment: ' . $attachment['original_name'];
                }
                lex_messages_send_secure($pdo, (int) $user['id'], $postReceiverId, $caseId, $payload, $attachment);
                if (!lex_message_thread_is_muted($postReceiverId, $caseId)) {
                    lex_notify($postReceiverId, 'message', 'You received a secure message in LEXSHIELD.');
                }
                $pdo->commit();
                header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $caseId)));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = 'Unable to send the message right now.';
            }
        }
    } else {
        $postCaseId = lex_sanitize_int($_POST['case_id'] ?? 0);
        $postReceiverId = lex_sanitize_int($_POST['receiver_id'] ?? 0);
        $text = lex_sanitize_multiline_text($_POST['message_text'] ?? '');
        try {
            $attachment = lex_store_message_attachment($_FILES['message_attachment'] ?? []);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
            $attachment = null;
        }
        $payload = trim($text);

        if (!$postCaseId || !$postReceiverId || ($payload === '' && !$attachment)) {
            $error = $error ?: 'Complete the case and message before sending.';
        } elseif (!isset($caseMap[$postCaseId])) {
            $error = 'That case is not available.';
        } elseif ((int) $caseMap[$postCaseId]['lawyer_user_id'] !== $postReceiverId) {
            $error = 'That conversation is not available.';
        } else {
            if ($payload === '' && $attachment) {
                $payload = 'Attachment: ' . $attachment['original_name'];
            }
            try {
                lex_messages_send_secure($pdo, (int) $user['id'], $postReceiverId, $postCaseId, $payload, $attachment);
                if (!lex_message_thread_is_muted($postReceiverId, $postCaseId)) {
                    lex_notify($postReceiverId, 'message', 'You received a secure message in LEXSHIELD.');
                }
                header('Location: ' . lex_app_url('client/messages.php?thread=' . urlencode('lawyer:' . $postCaseId)));
                exit;
            } catch (Throwable $exception) {
                if (!empty($attachment['path']) && is_file($attachment['path'])) {
                    @unlink($attachment['path']);
                }
                $error = 'Unable to send the message right now.';
            }
        }
    }
}

$threadPreferences = $selectedCaseId
    ? lex_message_thread_preferences((int) $user['id'], $selectedCaseId)
    : ['is_important' => false, 'is_muted' => false, 'is_archived' => false];
$isThreadImportant = (bool) ($threadPreferences['is_important'] ?? false);
$isThreadMuted = (bool) ($threadPreferences['is_muted'] ?? false);
$isThreadArchived = $selectedCaseId && $partnerId
    ? lex_message_thread_is_archived((int) $user['id'], $selectedCaseId, $partnerId)
    : false;
$canUseThreadPreferences = $selectedCaseId > 0;

$sidebarGroups = ['Lawyers' => []];
foreach ($cases as $case) {
    $caseId = (int) $case['case_id'];
    $conversationPreferences = lex_message_thread_preferences((int) $user['id'], $caseId);
    $conversationArchived = lex_message_thread_is_archived((int) $user['id'], $caseId, (int) $case['lawyer_user_id']);
    $unread = lex_stats(
        'SELECT COUNT(*) FROM messages WHERE case_id = :case_id AND sender_id = :sender_id AND receiver_id = :receiver_id AND is_read = 0 AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)',
        ['case_id' => $caseId, 'sender_id' => (int) $case['lawyer_user_id'], 'receiver_id' => (int) $user['id'], 'viewer_id' => (int) $user['id']]
    );
    if (!empty($conversationPreferences['is_unread'])) {
        $unread = max(1, $unread);
    }
    $last = lex_recent(
        'SELECT m.message_text, m.is_encrypted, m.sent_at FROM messages m
         WHERE m.case_id = ? AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
         ORDER BY m.sent_at DESC LIMIT 1',
        [$caseId, (int) $user['id'], (int) $case['lawyer_user_id'], (int) $case['lawyer_user_id'], (int) $user['id'], (int) $user['id']]
    );
    $lastRow = $last[0] ?? null;
    $preview = $lastRow ? lex_message_excerpt(lex_message_display_text($lastRow)) : 'No messages yet';
    $time = $lastRow ? lex_message_timestamp((string) $lastRow['sent_at']) : '';
    $sidebarGroups['Lawyers'][] = [
        'key' => 'lawyer:' . $caseId,
        'case_id' => $caseId,
        'title' => $case['lawyer_name'],
        'subtitle' => $case['case_number'] . ' - ' . $case['title'],
        'role' => 'Lawyer',
        'unread' => $unread,
        'preview' => $preview,
        'time' => $time,
        'important' => !empty($conversationPreferences['is_important']) ? '1' : '0',
        'archived' => $conversationArchived ? '1' : '0',
        'case_id' => $caseId,
        'thread_kind' => 'lawyer',
        'partner_user_id' => (int) $case['lawyer_user_id'],
    ];
}

if (!$selectedCaseId && !empty($sidebarGroups['Lawyers'][0])) {
    $selectedCaseId = (int) $sidebarGroups['Lawyers'][0]['case_id'];
    $caseInfo = $caseMap[$selectedCaseId];
    $partnerId = (int) $caseInfo['lawyer_user_id'];
    $partnerName = $caseInfo['lawyer_name'];
    $activeCaseNumber = $caseInfo['case_number'];
}

if ($selectedCaseId && $partnerId && empty($_GET['keep_unread'])) {
    $pdo->prepare(
        'UPDATE messages
         SET is_read = 1
         WHERE case_id = :case_id AND receiver_id = :receiver_id AND sender_id = :sender_id AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :viewer_id)'
    )->execute([
        'case_id' => $selectedCaseId,
        'receiver_id' => (int) $user['id'],
        'sender_id' => $partnerId,
        'viewer_id' => (int) $user['id'],
    ]);
    lex_message_thread_set_unread((int) $user['id'], $selectedCaseId, false);
}

$threadMessages = [];
if ($selectedCaseId && $partnerId) {
    $stmt = $pdo->prepare(
        'SELECT m.*, su.full_name AS sender_name, su.role AS sender_role, ru.full_name AS receiver_name, ru.role AS receiver_role
         FROM messages m
         JOIN users su ON su.id = m.sender_id
         JOIN users ru ON ru.id = m.receiver_id
         WHERE m.case_id = ?
           AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = ?)
           AND ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
         ORDER BY m.sent_at ASC'
    );
    $stmt->execute([
        $selectedCaseId,
        (int) $user['id'],
        (int) $user['id'],
        $partnerId,
        $partnerId,
        (int) $user['id'],
    ]);
    $threadMessages = $stmt->fetchAll();
}

$videoMeeting = ($selectedCaseId && $partnerId)
    ? lex_video_thread_for_user($selectedCaseId, (int) $user['id'], $partnerId)
    : null;

$activeThread = 'lawyer:' . $selectedCaseId;
$threadLabel = $partnerName ?: 'Lawyer';
$onlineLabel = 'Online';
$caseStatus = $caseInfo['status'] ?? 'open';
$casePriority = $caseInfo['priority'] ?? 'normal';
$sendDisabled = !$selectedCaseId || !$partnerId;
$totalConversations = count($sidebarGroups['Lawyers']);
$unreadConversations = count(array_filter($sidebarGroups['Lawyers'], static fn (array $item): bool => (int) $item['unread'] > 0));
$importantConversations = count(array_filter($sidebarGroups['Lawyers'], static fn (array $item): bool => (string) $item['important'] === '1'));
$conversationCountWord = $totalConversations === 1 ? 'Case' : 'Cases';

lex_page_header('Messages', 'messages', $user);
$initialMessageFilter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($initialMessageFilter, ['all', 'unread', 'important', 'archived'], true)) {
    $initialMessageFilter = 'all';
}
?>
<section class="messages-page" data-chat-shell data-initial-filter="<?= lex_e((string) ($_GET['filter'] ?? 'all')) ?>" data-partner-name="<?= lex_e($threadLabel) ?>">
  <div class="messages-layout">
    <aside class="chat-sidebar">
      <div class="card-head" style="margin-bottom:0.8rem;">
        <h2>Conversations</h2>
        <span class="pill conversation-count-pill"><strong><?= number_format($totalConversations) ?></strong><span><?= lex_e($conversationCountWord) ?></span></span>
      </div>
      <input class="conversation-search" type="search" placeholder="Search users or conversations" data-conversation-search>
      <div class="filter-tabs">
        <button class="filter-tab is-active" type="button" data-filter-tab="all">All</button>
        <button class="filter-tab" type="button" data-filter-tab="unread">Unread</button>
        <button class="filter-tab" type="button" data-filter-tab="important">Important</button>
        <button class="filter-tab" type="button" data-filter-tab="archived">Archived</button>
      </div>
      <div class="conversation-list-scroll" data-conversation-list>
      <?php foreach ($sidebarGroups as $groupName => $items): ?>
        <div class="conversation-group">
          <h3><?= lex_e($groupName) ?></h3>
          <?php foreach ($items as $item): ?>
            <div class="conversation-item<?= $activeThread === $item['key'] ? ' is-active' : '' ?>" data-conversation-item data-unread="<?= (int) ($item['unread'] > 0 ? 1 : 0) ?>" data-important="<?= lex_e($item['important']) ?>" data-archived="<?= lex_e((string) ($item['archived'] ?? '0')) ?>"<?= $initialMessageFilter === 'archived' && (string) ($item['archived'] ?? '0') !== '1' ? ' hidden' : '' ?>>
              <a class="conversation-item-link" href="?thread=<?= urlencode($item['key']) ?>">
              <div class="conversation-item-top">
                <div class="conversation-item-title">
                  <strong><?= lex_e($item['title']) ?></strong>
                  <span class="conversation-role"><?= lex_e($item['role']) ?></span>
                </div>
                <span class="conversation-flags">
                  <?php if ((string) $item['important'] === '1'): ?><span class="conversation-state-pill">Important</span><?php endif; ?>
                  <?php if ((int) $item['unread'] > 0): ?><span class="badge"><?= (int) $item['unread'] ?></span><?php endif; ?>
                </span>
              </div>
              <div class="conversation-item-bottom">
                <span class="muted"><?= lex_e($item['subtitle']) ?></span>
                <small><?= lex_e($item['time']) ?></small>
              </div>
              <small><?= lex_e($item['preview']) ?></small>
              </a>
              <div class="conversation-more-menu" data-conversation-more>
                <button class="conversation-more-toggle" type="button" aria-label="Conversation actions" aria-expanded="false" data-conversation-more-toggle>
                  <span aria-hidden="true">&#8942;</span>
                </button>
                <div class="conversation-more-dropdown" data-conversation-more-menu hidden>
                  <form method="post" class="conversation-menu-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="<?= ((string) ($item['archived'] ?? '0') === '1') ? 'restore_conversation_submit' : 'archive_conversation_submit' ?>" value="1">
                    <input type="hidden" name="case_id" value="<?= (int) $item['case_id'] ?>">
                    <?php if (isset($item['partner_user_id'])): ?><input type="hidden" name="partner_user_id" value="<?= (int) $item['partner_user_id'] ?>"><?php endif; ?>
                    <?php if (isset($item['thread_kind'])): ?><input type="hidden" name="thread_kind" value="<?= lex_e((string) $item['thread_kind']) ?>"><?php endif; ?>
                    <button class="conversation-menu-item" type="submit">
                      <span class="conversation-menu-icon archive" aria-hidden="true"><?= ((string) ($item['archived'] ?? '0') === '1') ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h5l2 2h11v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 11v6"/><path d="m9 14 3 3 3-3"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h5l2 2h11v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 17v-6"/><path d="m9 14 3-3 3 3"/></svg>' ?></span>
                      <span><strong><?= ((string) ($item['archived'] ?? '0') === '1') ? 'Unarchive conversation' : 'Archive conversation' ?></strong><small><?= ((string) ($item['archived'] ?? '0') === '1') ? 'Restore to your main list' : 'Hide from your main list' ?></small></span>
                    </button>
                  </form>
                  <form method="post" class="conversation-menu-form conversation-delete-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="delete_conversation_submit" value="1">
                    <input type="hidden" name="case_id" value="<?= (int) $item['case_id'] ?>">
                    <?php if (isset($item['thread_kind'])): ?><input type="hidden" name="thread_kind" value="<?= lex_e((string) $item['thread_kind']) ?>"><?php endif; ?>
                    <?php if (isset($item['partner_user_id'])): ?><input type="hidden" name="partner_user_id" value="<?= (int) $item['partner_user_id'] ?>"><?php endif; ?>
                    <button class="conversation-menu-item is-danger" type="submit">
                      <span class="conversation-menu-icon delete" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg></span>
                      <span><strong>Delete conversation</strong><small>Remove it from your view</small></span>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$items): ?>
            <div class="conversation-empty">
              <strong>No conversations yet</strong>
              <span>Start a secure message with a lawyer when you need guidance.</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </aside>

    <section class="chat-panel">
      <div class="chat-header">
        <div>
          <h2><?= lex_e($threadLabel) ?></h2>
          <div class="status-line"><?= lex_e($activeCaseNumber) ?> &middot; <?= lex_e($onlineLabel) ?></div>
        </div>
        <div class="header-actions">
          <div class="role-badge"><?= lex_e(lex_message_role_label('lawyer')) ?></div>
          <?php if ($isThreadMuted): ?><div class="role-badge">Muted</div><?php endif; ?>
          <?php if ($videoMeeting): ?>
            <a class="button button-primary chat-video-call" href="<?= lex_e(lex_app_url('video/consultation.php?appointment=' . (int) $videoMeeting['id'])) ?>" aria-label="Join video consultation anytime">
              <span class="chat-video-call-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5.75 6.25h8.5A2.75 2.75 0 0 1 17 9v6a2.75 2.75 0 0 1-2.75 2.75h-8.5A2.75 2.75 0 0 1 3 15V9a2.75 2.75 0 0 1 2.75-2.75Zm12.5 3.14 2.35-1.53A.9.9 0 0 1 22 8.61v6.78a.9.9 0 0 1-1.4.75l-2.35-1.53V9.39Z" fill="currentColor"/></svg></span>
              <span>Join Call</span>
            </a>
          <?php endif; ?>
          <button
            class="icon-chip"
            type="button"
            data-modal-open="conversationInfoModal"
            data-info-name="<?= lex_e($threadLabel) ?>"
            data-info-role="Lawyer"
            data-info-status="<?= lex_e($onlineLabel) ?>"
            data-info-case="<?= lex_e($activeCaseNumber) ?>"
            data-info-id="MSG-<?= (int) $selectedCaseId ?>"
            data-info-created="<?= lex_e(date('M j, Y')) ?>"
            data-info-activity="<?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?>"
            data-info-avatar-url="<?= lex_e(lex_profile_avatar_url((string) ($caseInfo['lawyer_avatar_stored_name'] ?? ''))) ?>"
            aria-label="Conversation Info"
          >Info</button>
        </div>
      </div>

      <div class="chat-feed" data-chat-scroll>
        <?php if (!$selectedCaseId || !$partnerId): ?>
          <div class="info-card">
            <strong>No messages yet</strong>
            <p class="muted">Select an existing conversation or create a new secure message.</p>
            <button class="button button-primary" type="button" data-modal-open="newMessageModal">Create New Message</button>
          </div>
        <?php else: ?>
          <?php foreach ($threadMessages as $msg): ?>
            <?php
              $plain = lex_message_display_text($msg);
              $displayMime = strtolower((string) ($msg['attachment_mime_type'] ?? ''));
              $displayExt = strtolower(pathinfo((string) ($msg['attachment_original_name'] ?? ''), PATHINFO_EXTENSION));
              if (str_starts_with($displayMime, 'audio/') || in_array($displayExt, ['mp3', 'wav', 'webm', 'ogg', 'm4a'], true)) {
                $plain = 'Voice message';
              }
              $bubbleClass = lex_message_bubble_class((int) $msg['sender_id'], (int) $user['id']);
              $statusText = (int) $msg['sender_id'] === (int) $user['id'] ? ((int) $msg['is_read'] === 1 ? 'Seen' : 'Delivered') : 'Received';
            ?>
            <div class="message-row <?= lex_e($bubbleClass) ?>">
              <article class="chat-bubble <?= lex_e($bubbleClass) ?> role-<?= lex_e((string) $msg['sender_role']) ?>">
                <?php if ((int) $msg['sender_id'] === (int) $user['id']): ?>
                  <form method="post" class="message-delete-form" data-no-loading>
                    <?= lex_csrf_field() ?>
                    <input type="hidden" name="delete_message_submit" value="1">
                    <input type="hidden" name="message_id" value="<?= (int) $msg['id'] ?>">
                    <button class="message-delete-button" type="submit" aria-label="Delete message" data-confirm="Delete this message?">&times;</button>
                  </form>
                <?php endif; ?>
                <div class="bubble-meta">
                  <div class="bubble-meta" style="gap:0.45rem;">
                    <strong><?= lex_e($msg['sender_name']) ?></strong>
                    <span class="sender-pill"><?= lex_e(lex_message_role_label((string) $msg['sender_role'])) ?></span>
                  </div>
                  <span><?= lex_e(lex_message_timestamp((string) $msg['sent_at'])) ?></span>
                </div>
                <p class="bubble-text"><?= lex_e($plain) ?></p>
                <?php if (!empty($msg['attachment_stored_name'])): ?>
                  <?php
                    $attachmentMime = strtolower((string) ($msg['attachment_mime_type'] ?? ''));
                    $attachmentExtension = strtolower(pathinfo((string) ($msg['attachment_original_name'] ?? ''), PATHINFO_EXTENSION));
                    $isAudioAttachment = str_starts_with($attachmentMime, 'audio/') || in_array($attachmentExtension, ['mp3', 'wav', 'webm', 'ogg', 'm4a'], true);
                    $attachmentViewUrl = lex_app_url('message_attachment.php?id=' . (int) $msg['id'] . '&view=1');
                    $attachmentDownloadUrl = lex_app_url('message_attachment.php?id=' . (int) $msg['id']);
                  ?>
                  <div class="attachment-card<?= $isAudioAttachment ? ' voice-message-card' : '' ?>">
                    <?php if ($isAudioAttachment): ?>
                      <div class="voice-message-label"><span class="voice-message-icon" aria-hidden="true">🎙</span><strong>Voice message</strong></div>
                      <audio class="voice-message-player" controls preload="metadata" src="<?= lex_e($attachmentViewUrl) ?>">Your browser does not support audio playback.</audio>
                    <?php else: ?>
                      <strong><?= lex_e((string) ($msg['attachment_original_name'] ?? $msg['attachment_stored_name'])) ?></strong>
                      <span class="muted"><?= lex_e(lex_human_file_size((int) ($msg['attachment_size'] ?? 0))) ?> &middot; Attached file</span>
                    <?php endif; ?>
                    <div class="attachment-card-actions">
                      <?php if (!$isAudioAttachment): ?><a class="button button-primary" href="<?= lex_e($attachmentViewUrl) ?>" target="_blank" rel="noopener">View File</a><?php endif; ?>
                      <a class="button button-secondary" href="<?= lex_e($attachmentDownloadUrl) ?>">Download</a>
                    </div>
                  </div>
                <?php endif; ?>
                <div class="bubble-meta">
                  <span class="bubble-status"><?= lex_e($statusText) ?></span>
                  <span class="bubble-status"><?= lex_e(ucfirst((string) $msg['sender_role'])) ?></span>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="conversation-actions">
        <button
          class="button button-secondary"
          type="button"
          data-modal-open="newMessageModal"
          data-default-case="<?= (int) $selectedCaseId ?>"
          data-default-recipient="<?= (int) $partnerId ?>"
          data-default-role="lawyer"
        >Create New Message</button>
      </div>

      <div class="chat-composer">
        <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form" data-chat-composer data-loading-text="Sending..." enctype="multipart/form-data">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="receiver_id" value="<?= (int) $partnerId ?>">
          <input type="hidden" name="thread_kind" value="lawyer">
          <div class="composer-row">
            <div class="composer-tools">
              <label class="icon-button ghost" style="display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;">
                &#128206;
                <input type="file" hidden accept=".pdf,.docx,.png,.jpg,.jpeg,.webp,.mp3,.wav,.webm,.ogg,.m4a" data-attachment-input name="message_attachment">
              </label>
            </div>
            <textarea class="composer-input" name="message_text" rows="3" placeholder="<?= $sendDisabled ? 'Select a conversation first' : 'Write a secure message. Attachments are allowed.' ?>" <?= $sendDisabled ? 'disabled' : '' ?> data-chat-input></textarea>
            <button class="button button-primary" type="submit" data-loading-text="Sending..." <?= $sendDisabled ? 'disabled' : '' ?>>Send</button>
          </div>
          <div class="attachment-preview" data-attachment-preview hidden></div>
        </form>
      </div>
    </section>

  </div>
</section>

<div class="modal-overlay" id="newMessageModal" data-modal aria-hidden="true">
  <div class="modal-card wide" role="dialog" aria-modal="true" aria-labelledby="newMessageTitle">
    <div class="modal-header">
      <h2 id="newMessageTitle">Create New Message</h2>
      <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      <div class="error-text" data-modal-errors></div>
      <form method="post" class="modal-grid" data-new-message-form data-no-loading enctype="multipart/form-data">
        <?= lex_csrf_field() ?>
        <input type="hidden" name="new_message_submit" value="1">
        <input type="hidden" name="new_case_id" value="<?= (int) $selectedCaseId ?>" data-default-case-input>
        <label>Recipient Name
          <select class="modal-select" name="new_receiver_id" data-recipient-select required>
            <option value="">Select a lawyer</option>
            <?php foreach ($availableRecipients as $recipient): ?>
              <option value="<?= (int) $recipient['id'] ?>" data-role="lawyer" <?= (int) $recipient['id'] === (int) $partnerId ? 'selected' : '' ?>><?= lex_e($recipient['name']) ?><?= !empty($recipient['email']) ? ' - ' . lex_e($recipient['email']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="hidden" name="new_recipient_role" value="lawyer" data-recipient-role>
        <label>Sender
          <select class="modal-select" disabled>
            <option selected><?= lex_e($currentUserLabel) ?> (<?= lex_e($currentUserRole) ?>)</option>
          </select>
        </label>
        <label>Message <span class="muted">(optional if attaching a file)</span>
          <textarea class="modal-textarea" name="new_message_text" rows="6" data-message-input placeholder="Type your message here..."></textarea>
        </label>
        <div>
          <label class="button button-secondary file-button" data-modal-attachment-button for="clientNewMessageAttachment">Attach File</label>
          <input id="clientNewMessageAttachment" type="file" hidden accept=".pdf,.docx,.png,.jpg,.jpeg,.webp,.mp3,.wav,.webm,.ogg,.m4a" data-modal-attachment-input name="new_attachment">
          <div class="attachment-preview" data-modal-attachment-name>No file selected</div>
        </div>
        <div class="modal-actions">
          <button class="button button-secondary" type="button" data-modal-close onclick="document.getElementById('newMessageModal')?.classList.remove('is-open');document.getElementById('newMessageModal')?.setAttribute('aria-hidden','true');">Cancel</button>
          <button class="button button-primary" type="submit">Send Message</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-overlay" id="conversationInfoModal" data-modal aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="conversationInfoTitle">
    <div class="modal-header">
      <h2 id="conversationInfoTitle">Conversation Info</h2>
      <button class="close-button" type="button" data-modal-close aria-label="Close" onclick="document.getElementById('conversationInfoModal')?.classList.remove('is-open');document.getElementById('conversationInfoModal')?.setAttribute('aria-hidden','true');">&times;</button>
    </div>
    <div class="modal-body">
      <div class="conversation-info-layout">
          <div class="info-card conversation-info-card">
            <div class="conversation-info-person">
              <?php $infoAvatarUrl = lex_profile_avatar_url((string) ($caseInfo['lawyer_avatar_stored_name'] ?? '')); ?>
              <div class="avatar-placeholder conversation-info-avatar" data-info-avatar>
                <img src="<?= lex_e($infoAvatarUrl) ?>" alt="" data-info-avatar-img<?= $infoAvatarUrl === '' ? ' hidden' : '' ?>>
                <span data-info-avatar-text<?= $infoAvatarUrl !== '' ? ' hidden' : '' ?>>LA</span>
              </div>
              <div>
                <strong data-info-name><?= lex_e($threadLabel) ?></strong>
                <div class="modal-note"><span data-info-role>Lawyer</span> &middot; <span data-info-status><?= lex_e($onlineLabel) ?></span></div>
              </div>
            </div>
          <div class="info-row">
            <span>Important</span>
            <strong><?= $isThreadImportant ? 'On' : 'Off' ?></strong>
          </div>
          <div class="info-row">
            <span>Notifications</span>
            <strong><?= $isThreadMuted ? 'Muted' : 'Active' ?></strong>
          </div>
          <div class="info-row">
            <span>Case Name</span>
            <strong data-info-case><?= lex_e($activeCaseNumber) ?></strong>
          </div>
          <div class="info-row">
            <span>Conversation ID</span>
            <strong data-info-id>MSG-<?= (int) $selectedCaseId ?></strong>
          </div>
          <div class="info-row">
            <span>Created Date</span>
            <strong data-info-created><?= lex_e(date('M j, Y')) ?></strong>
          </div>
          <div class="info-row">
            <span>Last Activity</span>
            <strong data-info-activity><?= lex_e($threadMessages ? lex_message_timestamp((string) end($threadMessages)['sent_at']) : 'No activity yet') ?></strong>
          </div>
        </div>
      </div>
      <div class="modal-actions">
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="mark_unread_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <button class="button button-secondary" type="submit" <?= !$selectedCaseId ? 'disabled' : '' ?>>Mark as Unread</button>
        </form>
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="toggle_important_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <button class="button button-secondary" type="submit" <?= !$selectedCaseId ? 'disabled' : '' ?>><?= $isThreadImportant ? 'Remove Important' : 'Mark as Important' ?></button>
        </form>
        <form method="post" data-no-loading class="conversation-action-form conversation-archive-action-form">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="<?= $isThreadArchived ? 'restore_conversation_submit' : 'archive_conversation_submit' ?>" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <input type="hidden" name="thread_kind" value="lawyer">
          <button class="button button-secondary conversation-archive-action" type="submit" <?= !$selectedCaseId ? 'disabled' : '' ?> data-confirm="<?= $isThreadArchived ? 'Restore this conversation?' : 'Archive this conversation?' ?>">&#128230; <?= $isThreadArchived ? 'Restore Conversation' : 'Archive Conversation' ?></button>
        </form>
        <form method="post" data-no-loading>
          <?= lex_csrf_field() ?>
          <input type="hidden" name="toggle_mute_submit" value="1">
          <input type="hidden" name="case_id" value="<?= (int) $selectedCaseId ?>">
          <button class="button button-secondary" type="submit" <?= !$selectedCaseId ? 'disabled' : '' ?>><?= $isThreadMuted ? 'Unmute Notifications' : 'Mute Notifications' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php lex_page_footer(); ?>
