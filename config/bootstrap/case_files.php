<?php
declare(strict_types=1);

function lex_case_files_base_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'case_files';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_case_files_slug(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)) ?? '';
    $value = trim($value, '_');
    return strtoupper($value !== '' ? $value : 'CASE');
}

function lex_case_files_folder_name(string $fullName, string $caseFileTitle, string $identifier = ''): string
{
    $parts = ['CF', date('YmdHis'), lex_case_files_slug($fullName), lex_case_files_slug($caseFileTitle)];
    if ($identifier !== '') {
        $parts[] = lex_case_files_slug($identifier);
    }
    return implode('_', $parts);
}

function lex_case_files_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_db_retry(static function () use (&$done): void {
        lex_pdo()->exec(
            "CREATE TABLE IF NOT EXISTS `case_files` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `full_name` VARCHAR(150) NOT NULL,
              `case_identifier` VARCHAR(80) NOT NULL,
              `case_file_title` VARCHAR(180) NOT NULL,
              `description` TEXT NULL,
              `date_created` DATE NOT NULL,
              `client_user_id` INT UNSIGNED NOT NULL,
              `assigned_lawyer_user_id` INT UNSIGNED DEFAULT NULL,
              `status` ENUM('open','ongoing','closed') NOT NULL DEFAULT 'open',
              `folder_name` VARCHAR(180) NOT NULL,
              `attachments_json` LONGTEXT NULL,
              `created_by_user_id` INT UNSIGNED NOT NULL,
              `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_files_identifier` (`case_identifier`),
              UNIQUE KEY `uq_case_files_folder` (`folder_name`),
              KEY `idx_case_files_fullname` (`full_name`),
              KEY `idx_case_files_title` (`case_file_title`),
              KEY `idx_case_files_client` (`client_user_id`),
              KEY `idx_case_files_lawyer` (`assigned_lawyer_user_id`),
              CONSTRAINT `fk_case_files_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_files_assigned_lawyer_user` FOREIGN KEY (`assigned_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `fk_case_files_created_by_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
              CONSTRAINT `fk_case_files_updated_by_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    });
}

function lex_case_file_vault_table_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    lex_case_files_table_ensure();
    lex_db_retry(static function () use (&$done): void {
        $pdo = lex_pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `case_file_folders` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `case_file_id` INT UNSIGNED NOT NULL,
              `parent_folder_id` INT UNSIGNED DEFAULT NULL,
              `name` VARCHAR(150) NOT NULL,
              `slug` VARCHAR(170) NOT NULL,
              `created_by_user_id` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_file_folder_slug` (`case_file_id`, `slug`),
              KEY `idx_case_file_folders_case` (`case_file_id`),
              KEY `idx_case_file_folders_parent` (`parent_folder_id`),
              CONSTRAINT `fk_case_file_folders_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_folders_parent` FOREIGN KEY (`parent_folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_folders_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `case_file_documents` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `case_file_id` INT UNSIGNED NOT NULL,
              `folder_id` INT UNSIGNED NOT NULL,
              `original_name` VARCHAR(255) NOT NULL,
              `stored_name` VARCHAR(255) NOT NULL,
              `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local',
              `storage_path` VARCHAR(500) DEFAULT NULL,
              `mime_type` VARCHAR(120) DEFAULT NULL,
              `file_size` INT UNSIGNED DEFAULT NULL,
              `file_hash` CHAR(64) DEFAULT NULL,
              `encryption_algorithm` VARCHAR(40) DEFAULT NULL,
              `encryption_iv` VARCHAR(64) DEFAULT NULL,
              `encryption_tag` VARCHAR(64) DEFAULT NULL,
              `encrypted_at` DATETIME DEFAULT NULL,
              `upload_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
              `uploaded_by_user_id` INT UNSIGNED NOT NULL,
              `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
              `approved_at` DATETIME DEFAULT NULL,
              `rejection_reason` TEXT DEFAULT NULL,
              `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_case_file_documents_stored` (`case_file_id`, `stored_name`),
              KEY `idx_case_file_documents_case` (`case_file_id`, `upload_status`),
              KEY `idx_case_file_documents_folder` (`folder_id`),
              KEY `idx_case_file_documents_uploaded_by` (`uploaded_by_user_id`),
              CONSTRAINT `fk_case_file_documents_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
              CONSTRAINT `fk_case_file_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
              CONSTRAINT `fk_case_file_documents_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $columns = [];
        $columnStmt = $pdo->query('SHOW COLUMNS FROM case_file_documents');
        if ($columnStmt) {
            $columns = array_map(static fn (array $row): string => (string) $row['Field'], $columnStmt->fetchAll());
        }
        if (!in_array('storage_provider', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local' AFTER `stored_name`");
            $columns[] = 'storage_provider';
        }
        if (!in_array('storage_path', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `storage_path` VARCHAR(500) DEFAULT NULL AFTER `storage_provider`");
            $columns[] = 'storage_path';
        }
        if (!in_array('encryption_algorithm', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `encryption_algorithm` VARCHAR(40) DEFAULT NULL AFTER `file_hash`");
        }
        if (!in_array('encryption_iv', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `encryption_iv` VARCHAR(64) DEFAULT NULL AFTER `encryption_algorithm`");
        }
        if (!in_array('encryption_tag', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `encryption_tag` VARCHAR(64) DEFAULT NULL AFTER `encryption_iv`");
        }
        if (!in_array('encrypted_at', $columns, true)) {
            $pdo->exec("ALTER TABLE `case_file_documents` ADD COLUMN `encrypted_at` DATETIME DEFAULT NULL AFTER `encryption_tag`");
        }
        $done = true;
    });
}

function lex_case_files_assert_local_path(string $path, bool $allowBase = false): string
{
    $base = realpath(lex_case_files_base_dir());
    if ($base === false) {
        throw new RuntimeException('Local case-file storage is unavailable.');
    }

    $target = realpath($path);
    if ($target === false) {
        $parent = realpath(dirname($path));
        if ($parent === false) {
            throw new RuntimeException('Invalid local case-file storage path.');
        }
        $target = $parent . DIRECTORY_SEPARATOR . basename($path);
    }

    $base = rtrim($base, DIRECTORY_SEPARATOR);
    $basePrefix = $base . DIRECTORY_SEPARATOR;
    $insideBase = strncmp($target, $basePrefix, strlen($basePrefix)) === 0;
    if (($allowBase && $target === $base) || $insideBase) {
        return $path;
    }

    throw new RuntimeException('Invalid local case-file storage path.');
}

function lex_case_files_folder_path(string $folderName): string
{
    $path = lex_case_files_base_dir() . DIRECTORY_SEPARATOR . $folderName;
    return lex_case_files_assert_local_path($path);
}

function lex_case_files_metadata_path(string $folderName): string
{
    $path = lex_case_files_folder_path($folderName) . DIRECTORY_SEPARATOR . 'metadata.json';
    return lex_case_files_assert_local_path($path);
}

function lex_case_files_ensure_folders(string $folderName): void
{
    $root = lex_case_files_folder_path($folderName);
    $subfolders = ['DOCUMENTS', 'PHOTOS', 'EVIDENCE', 'COURT_FILINGS', 'CORRESPONDENCE', 'CLIENT_UPLOADS'];
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
    }
    foreach ($subfolders as $subfolder) {
        $path = lex_case_files_assert_local_path($root . DIRECTORY_SEPARATOR . $subfolder);
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}

function lex_case_file_vault_default_folders(): array
{
    return [
        'Documents',
        'Evidence',
        'Court Filings',
        'Photos',
        'Correspondence',
        'Client Uploads',
    ];
}

function lex_case_file_vault_slug(string $value): string
{
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', trim($value)) ?? '';
    $slug = trim($slug, '_');
    return strtoupper($slug !== '' ? $slug : 'FOLDER');
}

function lex_case_file_vault_folder_dir(array $caseFile, array $folder): string
{
    $slug = lex_case_file_vault_slug((string) ($folder['slug'] ?? $folder['name'] ?? 'DOCUMENTS'));
    return lex_case_files_assert_local_path(
        lex_case_files_folder_path((string) $caseFile['folder_name']) . DIRECTORY_SEPARATOR . $slug
    );
}

function lex_case_file_vault_storage_path(int $caseFileId, int $documentId, string $storedName): string
{
    return 'case-files/' . $caseFileId . '/' . $documentId . '/' . basename($storedName);
}

function lex_case_file_vault_local_document_path(array $caseFile, array $document): string
{
    $path = lex_case_files_folder_path((string) $caseFile['folder_name'])
        . DIRECTORY_SEPARATOR
        . lex_case_file_vault_slug((string) ($document['folder_slug'] ?? $document['folder_name'] ?? 'DOCUMENTS'))
        . DIRECTORY_SEPARATOR
        . basename((string) $document['stored_name']);

    return lex_case_files_assert_local_path($path);
}

function lex_case_file_vault_document_storage_provider(array $document): string
{
    $provider = strtolower(trim((string) ($document['storage_provider'] ?? '')));
    return $provider !== '' ? $provider : 'local';
}

function lex_case_file_vault_document_storage_path(array $document): string
{
    return trim((string) ($document['storage_path'] ?? ''));
}

function lex_case_file_vault_verified_storage_path(array $document): string
{
    $caseFileId = (int) ($document['case_file_id'] ?? 0);
    $documentId = (int) ($document['id'] ?? 0);
    $storedName = basename((string) ($document['stored_name'] ?? ''));
    if ($caseFileId <= 0 || $documentId <= 0 || $storedName === '') {
        throw new RuntimeException('Invalid document storage metadata.');
    }

    $storagePath = lex_supabase_storage_clean_path(lex_case_file_vault_document_storage_path($document));
    $expectedPath = lex_case_file_vault_storage_path($caseFileId, $documentId, $storedName);
    if (!hash_equals($expectedPath, $storagePath)) {
        throw new RuntimeException('Invalid document storage path.');
    }

    return $storagePath;
}

function lex_case_file_vault_delete_document_object(array $caseFile, array $document): void
{
    $provider = lex_case_file_vault_document_storage_provider($document);

    if ($provider === 'supabase') {
        lex_supabase_storage_delete(lex_case_file_vault_verified_storage_path($document));
    }

    $localPath = lex_case_file_vault_local_document_path($caseFile, $document);
    if (is_file($localPath)) {
        @unlink($localPath);
    }
}

function lex_case_file_vault_provider_order(int $fileSize = 0): array
{
    $preferred = lex_storage_preferred_provider();
    
    // InfinityFree/local is the primary provider. If the configured quota threshold
    // would be exceeded, skip the local write and use Supabase as overflow.
    $capacity = lex_local_storage_capacity_status($fileSize);
    if ($preferred === 'local' && $capacity['within_threshold'] && $capacity['filesystem_has_space']) {
        return ['local', 'supabase'];
    }
    if ($preferred === 'supabase') {
        return ['supabase', 'local'];
    }
    return ['supabase', 'local'];
}

function lex_case_file_vault_store_encrypted_data(
    string $provider,
    array $caseFile,
    array $folder,
    int $documentId,
    string $storedName,
    string $encryptedData
): array {
    if ($provider === 'supabase') {
        $storagePath = lex_case_file_vault_storage_path((int) $caseFile['id'], $documentId, $storedName);
        lex_supabase_storage_upload($storagePath, $encryptedData, 'application/octet-stream');
        error_log('[CASE_FILE_STORAGE] Stored encrypted document using Supabase.');

        return [
            'provider' => 'supabase',
            'storage_path' => $storagePath,
            'local_path' => '',
        ];
    }

    if ($provider === 'local') {
        $targetDir = lex_case_file_vault_folder_dir($caseFile, $folder);
        if (!lex_local_storage_can_store($targetDir, strlen($encryptedData))) {
            throw new LexStorageUnavailableException('Local encrypted document storage does not have enough available space.');
        }

        $document = [
            'stored_name' => $storedName,
            'folder_slug' => (string) ($folder['slug'] ?? $folder['name'] ?? 'DOCUMENTS'),
        ];
        $targetPath = lex_case_file_vault_local_document_path($caseFile, $document);
        if (file_put_contents($targetPath, $encryptedData, LOCK_EX) === false) {
            throw new LexStorageUnavailableException('Unable to write encrypted document to local storage.');
        }
        error_log('[CASE_FILE_STORAGE] Stored encrypted document using local storage.');

        return [
            'provider' => 'local',
            'storage_path' => null,
            'local_path' => $targetPath,
        ];
    }

    throw new RuntimeException('Invalid document storage provider.');
}

function lex_case_file_vault_cleanup_stored_data(array $storage): void
{
    try {
        if (($storage['provider'] ?? '') === 'supabase' && !empty($storage['storage_path'])) {
            lex_supabase_storage_delete((string) $storage['storage_path']);
        } elseif (($storage['provider'] ?? '') === 'local' && !empty($storage['local_path']) && is_file((string) $storage['local_path'])) {
            @unlink((string) $storage['local_path']);
        }
    } catch (Throwable $cleanupError) {
        error_log('[CASE_FILE_STORAGE] Cleanup after failed document metadata update failed.');
    }
}

function lex_case_file_vault_ensure_defaults(int $caseFileId, int $createdByUserId = 1): void
{
    lex_case_file_vault_table_ensure();
    $pdo = lex_pdo();
    $stmt = $pdo->prepare('SELECT folder_name FROM case_files WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $caseFileId]);
    $folderName = (string) ($stmt->fetchColumn() ?: '');
    if ($folderName === '') {
        return;
    }
    lex_case_files_ensure_folders($folderName);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO case_file_folders (case_file_id, parent_folder_id, name, slug, created_by_user_id)
         VALUES (:case_file_id, NULL, :name, :slug, :created_by_user_id)'
    );
    $exists = $pdo->prepare('SELECT id FROM case_file_folders WHERE case_file_id = :case_file_id AND slug = :slug LIMIT 1');
    foreach (lex_case_file_vault_default_folders() as $name) {
        $slug = lex_case_file_vault_slug($name);
        $path = lex_case_files_folder_path($folderName) . DIRECTORY_SEPARATOR . $slug;
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $exists->execute(['case_file_id' => $caseFileId, 'slug' => $slug]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insert->execute([
            'case_file_id' => $caseFileId,
            'name' => $name,
            'slug' => $slug,
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}

function lex_case_file_vault_fetch(int $caseFileId, array $viewer): array
{
    lex_case_file_vault_ensure_defaults($caseFileId, (int) ($viewer['id'] ?? 1));
    $pdo = lex_pdo();
    $folders = lex_recent(
        'SELECT f.*,
                (SELECT COUNT(*) FROM case_file_documents ad WHERE ad.folder_id = f.id AND ad.upload_status = "approved") AS approved_count,
                (SELECT COUNT(*) FROM case_file_documents pd WHERE pd.folder_id = f.id AND pd.upload_status = "pending") AS pending_count
         FROM case_file_folders f
         WHERE f.case_file_id = :case_file_id
         ORDER BY f.parent_folder_id IS NOT NULL, f.name ASC',
        ['case_file_id' => $caseFileId]
    );
    $params = ['case_file_id' => $caseFileId];
    if ((string) ($viewer['role'] ?? '') === 'lawyer') {
        $statusClause = '1 = 1';
    } elseif ((string) ($viewer['role'] ?? '') === 'client') {
        $statusClause = '(d.upload_status = "approved" OR d.uploaded_by_user_id = :viewer_user_id)';
        $params['viewer_user_id'] = (int) ($viewer['id'] ?? 0);
    } else {
        $statusClause = 'd.upload_status = "approved"';
    }
    $documents = lex_recent(
        'SELECT d.*, f.name AS folder_name, f.slug AS folder_slug, u.full_name AS uploaded_by_name, au.full_name AS approved_by_name
         FROM case_file_documents d
         JOIN case_file_folders f ON f.id = d.folder_id
         JOIN users u ON u.id = d.uploaded_by_user_id
         LEFT JOIN users au ON au.id = d.approved_by_user_id
         WHERE d.case_file_id = :case_file_id AND ' . $statusClause . '
         ORDER BY d.upload_status = "pending" DESC, d.created_at DESC',
        $params
    );
    return ['folders' => $folders, 'documents' => $documents];
}

function lex_case_file_vault_access(array $caseFile, array $user): string
{
    $role = (string) ($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    if ($role === 'lawyer' && $userId === (int) ($caseFile['assigned_lawyer_user_id'] ?? 0)) {
        return 'manage';
    }
    if ($role === 'client' && $userId === (int) ($caseFile['client_user_id'] ?? 0)) {
        return 'client';
    }
    return 'none';
}

function lex_case_file_document_encrypt(string $plainData): array
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required to encrypt case documents.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipherData = openssl_encrypt($plainData, 'aes-256-gcm', lex_case_file_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipherData === false || $tag === '') {
        throw new RuntimeException('Unable to encrypt case document.');
    }

    return [
        'data' => $cipherData,
        'algorithm' => 'AES-256-GCM',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}

function lex_case_file_document_decrypt(string $cipherData, array $document): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to decrypt case documents.');
    }

    $iv = base64_decode((string) ($document['encryption_iv'] ?? ''), true);
    $tag = base64_decode((string) ($document['encryption_tag'] ?? ''), true);
    if ($iv === false || $tag === false || $iv === '' || $tag === '') {
        throw new RuntimeException('Case document encryption metadata is missing.');
    }

    $plainData = openssl_decrypt($cipherData, 'aes-256-gcm', lex_case_file_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plainData === false) {
        throw new RuntimeException('Unable to decrypt case document.');
    }

    return $plainData;
}

function lex_case_file_crypto_key(): string
{
    global $lexCaseFileEncryptionKey;
    return substr(hash('sha256', (string) $lexCaseFileEncryptionKey, true), 0, 32);
}

function lex_case_file_vault_store_document(array $caseFile, int $folderId, array $file, array $user, string $status): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        lex_reject_upload('case_file_document', 'Select a document to upload.');
    }
    if (($user['role'] ?? '') !== 'lawyer') {
        $limit = lex_rate_limit_hit('upload_case_file_document', lex_rate_limit_key(lex_rate_limit_client_ip(), lex_rate_limit_user_part()), 20, 3600, 900, lex_rate_limit_user_part());
        if (!$limit['allowed']) {
            lex_reject_upload('case_file_document', lex_rate_limit_message((int) $limit['retry_after']));
        }
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        lex_reject_upload('case_file_document', 'Unable to upload document.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        lex_reject_upload('case_file_document', 'Document is empty.');
    }
    if ($size > 25 * 1024 * 1024) {
        lex_reject_upload('case_file_document', 'Document is too large. The limit is 25 MB.');
    }
    $folderStmt = lex_pdo()->prepare('SELECT * FROM case_file_folders WHERE id = :id AND case_file_id = :case_file_id LIMIT 1');
    $folderStmt->execute(['id' => $folderId, 'case_file_id' => (int) $caseFile['id']]);
    $folder = $folderStmt->fetch();
    if (!$folder) {
        lex_reject_upload('case_file_document', 'Select a valid vault folder.');
    }
    $originalName = trim((string) ($file['name'] ?? 'document'));
    $originalName = $originalName !== '' ? $originalName : 'document';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !isset(lex_allowed_upload_types()[$extension])) {
        lex_reject_upload('case_file_document', 'Unsupported document type. Allowed types: PDF, JPG, PNG, WEBP, DOCX.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.enc';
    $tmpPath = (string) $file['tmp_name'];
    $validatedType = lex_validate_allowed_upload_type($tmpPath, $originalName, 'case_file_document');
    lex_scan_upload_for_malware($tmpPath, 'case_file_document', $originalName);
    $plainData = file_get_contents($tmpPath);
    if ($plainData === false) {
        lex_reject_upload('case_file_document', 'Unable to read uploaded document.');
    }
    $hash = hash('sha256', $plainData);
    $encrypted = lex_case_file_document_encrypt($plainData);

    $approvedBy = $status === 'approved' ? (int) $user['id'] : null;
    $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
    $pdo = lex_pdo();
    $documentId = 0;
    $storagePath = null;
    $storage = [];

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO case_file_documents
                (case_file_id, folder_id, original_name, stored_name, storage_provider, storage_path, mime_type, file_size, file_hash, encryption_algorithm, encryption_iv, encryption_tag, encrypted_at, upload_status, uploaded_by_user_id, approved_by_user_id, approved_at)
             VALUES
                (:case_file_id, :folder_id, :original_name, :stored_name, :storage_provider, :storage_path, :mime_type, :file_size, :file_hash, :encryption_algorithm, :encryption_iv, :encryption_tag, NOW(), :upload_status, :uploaded_by_user_id, :approved_by_user_id, :approved_at)'
        )->execute([
            'case_file_id' => (int) $caseFile['id'],
            'folder_id' => $folderId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'storage_provider' => 'local',
            'storage_path' => $storagePath,
            'mime_type' => $validatedType['mime_type'],
            'file_size' => $size,
            'file_hash' => $hash,
            'encryption_algorithm' => $encrypted['algorithm'],
            'encryption_iv' => $encrypted['iv'],
            'encryption_tag' => $encrypted['tag'],
            'upload_status' => $status,
            'uploaded_by_user_id' => (int) $user['id'],
            'approved_by_user_id' => $approvedBy,
            'approved_at' => $approvedAt,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $fallbackReasons = [];
        foreach (lex_case_file_vault_provider_order(strlen($encrypted['data'])) as $provider) {
            try {
                error_log('[CASE_FILE_STORAGE] Trying encrypted document storage provider: ' . $provider);
                $storage = lex_case_file_vault_store_encrypted_data($provider, $caseFile, $folder, $documentId, $storedName, $encrypted['data']);
                break;
            } catch (LexStorageUnavailableException $storageError) {
                $fallbackReasons[] = $provider . ': ' . $storageError->getMessage();
                lex_storage_last_fallback_reason($provider . ': ' . $storageError->getMessage());
                error_log('[CASE_FILE_STORAGE] Provider fallback: ' . $provider . ' unavailable.');
                continue;
            }
        }

        if ($storage === []) {
            error_log('[CASE_FILE_STORAGE] No document storage provider accepted encrypted upload.');
            throw new RuntimeException('Unable to save encrypted document.');
        }

        $pdo->prepare(
            'UPDATE case_file_documents
             SET storage_provider = :storage_provider,
                 storage_path = :storage_path
             WHERE id = :id'
        )->execute([
            'storage_provider' => $storage['provider'],
            'storage_path' => $storage['storage_path'],
            'id' => $documentId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($storage !== []) {
            lex_case_file_vault_cleanup_stored_data($storage);
        }
        throw $e;
    }

    return [
        'id' => $documentId,
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'storage_provider' => (string) $storage['provider'],
        'storage_path' => $storage['storage_path'],
        'path' => (string) ($storage['storage_path'] ?? $storage['local_path'] ?? ''),
        'mime_type' => $validatedType['mime_type'],
        'size' => $size,
    ];
}

function lex_case_files_write_metadata(array $record): void
{
    if (empty($record['folder_name'])) {
        return;
    }
    lex_case_files_ensure_folders((string) $record['folder_name']);
    $payload = [
        'FULLNAME' => $record['full_name'] ?? '',
        'CASE_IDENTIFIER' => $record['case_identifier'] ?? '',
        'CASE_FILE' => $record['case_file_title'] ?? '',
        'DESCRIPTION' => $record['description'] ?? '',
        'DATE_CREATED' => $record['date_created'] ?? '',
        'ASSIGNED_LAWYER' => [
            'id' => (int) ($record['assigned_lawyer_user_id'] ?? 0),
            'name' => $record['assigned_lawyer_name'] ?? '',
        ],
        'STATUS' => strtoupper((string) ($record['status'] ?? 'open')),
        'CLIENT_USER_ID' => (int) ($record['client_user_id'] ?? 0),
        'ATTACHMENTS' => json_decode((string) ($record['attachments_json'] ?? '[]'), true) ?: [],
        'UPDATED_AT' => $record['updated_at'] ?? date('c'),
    ];
    file_put_contents(lex_case_files_metadata_path((string) $record['folder_name']), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function lex_case_files_recursive_delete(string $path): void
{
    $path = lex_case_files_assert_local_path($path);
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}
