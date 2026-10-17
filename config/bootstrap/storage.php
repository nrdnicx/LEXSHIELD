<?php
declare(strict_types=1);

function lex_storage_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_profile_avatars_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_profile_avatar_url(?string $storedName): string
{
    $storedName = basename(trim((string) $storedName));
    if ($storedName === '') {
        return '';
    }
    $avatarPath = lex_profile_avatars_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($avatarPath)) {
        $legacyPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($legacyPath)) {
            @copy($legacyPath, $avatarPath);
        }
    }
    if (!is_file($avatarPath)) {
        return '';
    }
    return lex_app_url('storage/avatars/' . rawurlencode($storedName));
}

function lex_profile_avatar_remove(?string $storedName): void
{
    $storedName = trim((string) $storedName);
    if ($storedName === '') {
        return;
    }
    $path = lex_profile_avatars_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path)) {
        @unlink($path);
    }
}


function lex_messages_base_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'messages';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_messages_attachment_path(string $storedName): string
{
    return lex_messages_base_dir() . DIRECTORY_SEPARATOR . $storedName;
}

class LexStorageUnavailableException extends RuntimeException
{
}

function lex_supabase_storage_config(): array
{
    // Reload the project .env defensively in case this module is reached
    // from a request path that did not load the normal application bootstrap.
    if (function_exists('lex_load_env_file')) {
        lex_load_env_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');
    }

    $readEnv = static function (string $name, string $default = ''): string {
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        $value = $_ENV[$name] ?? null;
        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        $value = $_SERVER[$name] ?? null;
        if ($value !== null && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        return $default;
    };

    $url = rtrim($readEnv('SUPABASE_URL'), '/');
    $secretKey = $readEnv('SUPABASE_SECRET_KEY');
    $bucket = $readEnv('SUPABASE_BUCKET', 'legal-documents');

    if ($url === '' || $secretKey === '' || $bucket === '') {
        throw new RuntimeException('Secure document storage is not configured.');
    }

    if (!preg_match('/^https:\/\/[A-Za-z0-9.-]+$/', $url)) {
        throw new RuntimeException('Secure document storage is not configured.');
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $bucket)) {
        throw new RuntimeException('Secure document storage is not configured.');
    }

    return [
        'url' => $url,
        'secret_key' => $secretKey,
        'bucket' => $bucket,
    ];
}

function lex_supabase_storage_configured(): bool
{
    try {
        lex_supabase_storage_config();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function lex_supabase_storage_clean_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path), '/');
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
    $cleanSegments = [];

    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
            throw new RuntimeException('Invalid storage path.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
            throw new RuntimeException('Invalid storage path.');
        }
        $cleanSegments[] = $segment;
    }

    if ($cleanSegments === []) {
        throw new RuntimeException('Invalid storage path.');
    }

    return implode('/', $cleanSegments);
}

function lex_supabase_storage_encoded_path(string $path): string
{
    $path = lex_supabase_storage_clean_path($path);
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function lex_supabase_storage_request(string $method, string $objectPath = '', ?string $body = null, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        throw new LexStorageUnavailableException('Secure document storage is unavailable.');
    }

    $config = lex_supabase_storage_config();
    $url = $config['url'] . '/storage/v1/object/' . rawurlencode($config['bucket']);
    if ($objectPath !== '') {
        $url .= '/' . lex_supabase_storage_encoded_path($objectPath);
    }

    $requestHeaders = [
        'apikey: ' . $config['secret_key'],
        'Authorization: Bearer ' . $config['secret_key'],
    ];
    foreach ($headers as $header) {
        $requestHeaders[] = $header;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        error_log('[SUPABASE_STORAGE] Request failed: ' . $curlError);
        throw new LexStorageUnavailableException('Secure document storage request failed.');
    }

    return [
        'status' => $status,
        'body' => (string) $responseBody,
    ];
}

function lex_supabase_storage_upload(string $objectPath, string $data, string $contentType = 'application/octet-stream'): void
{
    $response = lex_supabase_storage_request('POST', $objectPath, $data, [
        'Content-Type: ' . $contentType,
        'Content-Length: ' . strlen($data),
        'Cache-Control: no-store',
        'x-upsert: false',
    ]);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        error_log('[SUPABASE_STORAGE] Upload failed with HTTP ' . $response['status']);
        if (lex_supabase_storage_is_fallback_error($response['status'], $response['body'])) {
            throw new LexStorageUnavailableException('Supabase storage cannot accept the encrypted document.');
        }
        throw new RuntimeException('Unable to save encrypted document.');
    }
}

function lex_supabase_storage_is_fallback_error(int $status, string $body): bool
{
    if ($status === 408 || $status === 413 || $status === 429 || $status === 507 || $status >= 500) {
        return true;
    }

    if ($status >= 400) {
        $message = strtolower(substr($body, 0, 1000));
        foreach (['quota', 'limit', 'capacity', 'full', 'insufficient storage', 'exceeded'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
    }

    return false;
}

function lex_supabase_storage_download(string $objectPath): string
{
    $response = lex_supabase_storage_request('GET', $objectPath);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        error_log('[SUPABASE_STORAGE] Download failed with HTTP ' . $response['status']);
        throw new RuntimeException('Unable to retrieve encrypted document.');
    }

    return $response['body'];
}

function lex_supabase_storage_delete(string $objectPath): void
{
    $response = lex_supabase_storage_request('DELETE', $objectPath);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        error_log('[SUPABASE_STORAGE] Delete failed with HTTP ' . $response['status']);
        throw new RuntimeException('Unable to delete encrypted document.');
    }
}

function lex_storage_preferred_provider(): string
{
    $preference = strtolower(trim((string) (getenv('STORAGE_PREFERENCE') ?: 'local')));
    return in_array($preference, ['supabase', 'local'], true) ? $preference : 'local';
}

function lex_local_storage_quota_bytes(): int
{
    $quotaGb = (float) (getenv('LOCAL_STORAGE_QUOTA_GB') ?: 5);
    if ($quotaGb <= 0) {
        return 0;
    }
    return (int) round($quotaGb * 1024 * 1024 * 1024);
}

function lex_local_storage_threshold_percent(): float
{
    $percent = (float) (getenv('LOCAL_STORAGE_THRESHOLD_PERCENT') ?: 90);
    return min(100.0, max(1.0, $percent));
}

function lex_local_storage_reserve_bytes(): int
{
    $reserveMb = (int) (getenv('LOCAL_STORAGE_RESERVE_MB') ?: 100);
    return max(0, $reserveMb) * 1024 * 1024;
}

function lex_directory_size_bytes(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    $total = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $size = $item->getSize();
                if ($size > 0) {
                    $total += $size;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[STORAGE] Unable to calculate directory usage: ' . $e->getMessage());
    }

    return $total;
}

function lex_local_storage_root(): string
{
    $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
    }
    return $root;
}

function lex_local_storage_capacity_status(int $additionalBytes = 0): array
{
    $root = lex_local_storage_root();
    $quotaBytes = lex_local_storage_quota_bytes();
    $thresholdPercent = lex_local_storage_threshold_percent();
    $thresholdBytes = $quotaBytes > 0
        ? (int) floor($quotaBytes * ($thresholdPercent / 100))
        : 0;
    $usedBytes = lex_directory_size_bytes($root);
    $reserveBytes = lex_local_storage_reserve_bytes();
    $projectedBytes = max(0, $usedBytes) + max(0, $additionalBytes);
    $withinConfiguredThreshold = $quotaBytes > 0 && $projectedBytes <= max(0, $thresholdBytes - $reserveBytes);

    $filesystemFreeBytes = null;
    if (function_exists('disk_free_space')) {
        $free = @disk_free_space($root);
        if (is_int($free) || is_float($free)) {
            $filesystemFreeBytes = (int) $free;
        }
    }

    return [
        'root' => $root,
        'quota_bytes' => $quotaBytes,
        'used_bytes' => $usedBytes,
        'threshold_percent' => $thresholdPercent,
        'threshold_bytes' => $thresholdBytes,
        'reserve_bytes' => $reserveBytes,
        'projected_bytes' => $projectedBytes,
        'within_threshold' => $withinConfiguredThreshold,
        'filesystem_free_bytes' => $filesystemFreeBytes,
        'filesystem_has_space' => $filesystemFreeBytes === null
            ? true
            : $filesystemFreeBytes >= ($additionalBytes + $reserveBytes),
    ];
}

function lex_local_storage_can_store(string $directory, int $bytes): bool
{
    if ($bytes < 0) {
        return false;
    }
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }
    if (!is_writable($directory)) {
        return false;
    }

    $capacity = lex_local_storage_capacity_status($bytes);
    return $capacity['within_threshold'] && $capacity['filesystem_has_space'];
}

function lex_supabase_storage_tracked_usage_bytes(): int
{
    try {
        $pdo = lex_pdo();
        $stmt = $pdo->query(
            "SELECT COALESCE(SUM(file_size), 0)
             FROM case_file_documents
             WHERE storage_provider = 'supabase'"
        );
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

function lex_supabase_storage_quota_bytes(): int
{
    $quotaGb = (float) (getenv('SUPABASE_STORAGE_QUOTA_GB') ?: 1);
    return $quotaGb > 0 ? (int) round($quotaGb * 1024 * 1024 * 1024) : 0;
}

function lex_document_storage_status(int $fileSize = 0): array
{
    $capacity = lex_local_storage_capacity_status($fileSize);
    $supabaseUsed = lex_supabase_storage_tracked_usage_bytes();
    $supabaseQuota = lex_supabase_storage_quota_bytes();
    $supabaseConfigured = lex_supabase_storage_configured();

    return [
        'supabase_configured' => $supabaseConfigured,
        'local_storage_available' => $capacity['within_threshold'] && $capacity['filesystem_has_space'],
        'preferred_provider' => lex_storage_preferred_provider(),
        'local_has_space_for_file' => $capacity['within_threshold'] && $capacity['filesystem_has_space'],
        'local_quota_bytes' => $capacity['quota_bytes'],
        'local_used_bytes' => $capacity['used_bytes'],
        'local_threshold_bytes' => $capacity['threshold_bytes'],
        'local_threshold_percent' => $capacity['threshold_percent'],
        'local_remaining_to_threshold_bytes' => max(0, $capacity['threshold_bytes'] - $capacity['used_bytes']),
        'supabase_quota_bytes' => $supabaseQuota,
        'supabase_used_bytes' => $supabaseUsed,
        'supabase_remaining_bytes' => $supabaseQuota > 0 ? max(0, $supabaseQuota - $supabaseUsed) : 0,
        'last_fallback_reason' => lex_storage_last_fallback_reason(),
    ];
}

function lex_storage_last_fallback_reason(?string $reason = null): string
{
    static $lastReason = '';
    if ($reason !== null) {
        $lastReason = substr($reason, 0, 180);
    }
    return $lastReason;
}

function lex_human_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return $unit === 0 ? sprintf('%d %s', (int) $size, $units[$unit]) : sprintf('%.1f %s', $size, $units[$unit]);
}

function lex_allowed_upload_types(): array
{
    return [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'mp3' => ['audio/mpeg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'webm' => ['audio/webm', 'video/webm'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'm4a' => ['audio/mp4'],
    ];
}

function lex_clamscan_path(): string
{
    $configured = trim((string) (getenv('CLAMSCAN_PATH') ?: ''));
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }

    $candidates = [
        'C:\\Program Files\\ClamAV\\clamscan.exe',
        'C:\\Program Files (x86)\\ClamAV\\clamscan.exe',
        'C:\\ClamAV\\clamscan.exe',
        '/usr/bin/clamscan',
        '/usr/local/bin/clamscan',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function lex_clamav_database_path(): string
{
    $configured = trim((string) (getenv('CLAMAV_DATABASE_PATH') ?: ''));
    if ($configured !== '') {
        return $configured;
    }

    return '';
}

function lex_upload_scanning_enabled(): bool
{
    return filter_var(getenv('MALWARE_SCAN_UPLOADS') ?: 'true', FILTER_VALIDATE_BOOL);
}

function lex_upload_scanner_required(): bool
{
    return filter_var(getenv('MALWARE_SCAN_REQUIRED') ?: 'false', FILTER_VALIDATE_BOOL);
}

function lex_scan_upload_for_malware(string $path, string $context, string $originalName = ''): void
{
    if (!lex_upload_scanning_enabled()) {
        return;
    }

    if (!is_file($path) || !is_readable($path)) {
        lex_reject_upload($context, 'Unable to scan uploaded file.');
    }

    $scanner = lex_clamscan_path();
    if ($scanner === '') {
        lex_audit_security_event('malware_scanner_unavailable', 'uploads', $context);
        if (lex_upload_scanner_required()) {
            lex_reject_upload($context, 'Malware scanner is not available. Please contact the administrator.');
        }
        return;
    }

    $output = [];
    $exitCode = 2;
    $database = lex_clamav_database_path();
    $command = escapeshellarg($scanner) . ' --no-summary --infected';
    if ($database !== '') {
        $command .= ' --database=' . escapeshellarg($database);
    }
    $command .= ' ' . escapeshellarg($path);
    @exec($command, $output, $exitCode);

    if ($exitCode === 0) {
        lex_audit_security_event('malware_scan_clean', 'uploads', $context);
        return;
    }

    $scanResult = trim(implode(' ', $output));
    $targetId = trim($context . ($originalName !== '' ? ': ' . $originalName : ''));
    if ($exitCode === 1) {
        lex_audit_security_event('malware_detected', 'uploads', substr($targetId . ($scanResult !== '' ? ' | ' . $scanResult : ''), 0, 180));
        lex_reject_upload($context, 'Malware detected. Upload rejected.');
    }

    error_log('[MALWARE_SCAN] Scanner error for ' . $context . ': ' . $scanResult);
    lex_audit_security_event('malware_scan_error', 'uploads', substr($targetId, 0, 180));
    if (lex_upload_scanner_required()) {
        lex_reject_upload($context, 'Unable to complete malware scan. Upload rejected.');
    }
}

function lex_validate_allowed_upload_type(string $path, string $originalName, string $context): array
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = lex_allowed_upload_types();
    if ($extension === '' || !isset($allowed[$extension])) {
        lex_reject_upload($context, 'Unsupported file type. Allowed types: PDF, JPG, PNG, WEBP, DOCX, MP3, WAV, WEBM, OGG, M4A.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }

    $mimeAccepted = in_array($mime, $allowed[$extension], true);
    if (!$mimeAccepted && $extension === 'doc' && $mime === 'application/octet-stream') {
        $handle = @fopen($path, 'rb');
        $header = $handle ? @fread($handle, 8) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        // Legacy .doc files are OLE Compound File containers. Some hosts report
        // those files as application/octet-stream instead of application/msword.
        $mimeAccepted = is_string($header) && $header === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }
    if (!$mimeAccepted) {
        lex_reject_upload($context, 'The uploaded file does not match its file type.');
    }

    // Browser MediaRecorder commonly creates an audio-only WebM container that
    // PHP fileinfo identifies as video/webm. Treat that container as audio for
    // message playback after the extension/MIME allow-list check above.
    if ($extension === 'webm' && $mime === 'video/webm') {
        $mime = 'audio/webm';
    }

    return [
        'extension' => $extension,
        'mime_type' => $mime,
    ];
}

function lex_store_profile_avatar(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = lex_rate_limit_hit('upload_profile_avatar', lex_rate_limit_key(lex_rate_limit_client_ip(), lex_rate_limit_user_part()), 10, 3600, 900, lex_rate_limit_user_part());
    if (!$limit['allowed']) {
        lex_reject_upload('profile_avatar', lex_rate_limit_message((int) $limit['retry_after']));
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        lex_reject_upload('profile_avatar', 'Unable to upload the avatar.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        lex_reject_upload('profile_avatar', 'Avatar image is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        lex_reject_upload('profile_avatar', 'Avatar image is too large. The limit is 5 MB.');
    }
    $tmpPath = (string) $file['tmp_name'];
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($allowed[$imageType])) {
        lex_reject_upload('profile_avatar', 'Avatar must be a JPG, PNG, GIF, or WEBP image.');
    }
    lex_scan_upload_for_malware($tmpPath, 'profile_avatar', (string) ($file['name'] ?? 'avatar'));
    $extension = $allowed[$imageType];
    $storedName = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_profile_avatars_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        lex_reject_upload('profile_avatar', 'Unable to save the avatar image.');
    }
    return [
        'stored_name' => $storedName,
        'path' => $targetPath,
        'mime_type' => image_type_to_mime_type($imageType),
        'size' => $size,
        'original_name' => (string) ($file['name'] ?? 'avatar'),
    ];
}

function lex_store_message_attachment(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = lex_rate_limit_hit('upload_message_attachment', lex_rate_limit_key(lex_rate_limit_client_ip(), lex_rate_limit_user_part()), 20, 3600, 900, lex_rate_limit_user_part());
    if (!$limit['allowed']) {
        lex_reject_upload('message_attachment', lex_rate_limit_message((int) $limit['retry_after']));
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        lex_reject_upload('message_attachment', 'Unable to upload attachment.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        lex_reject_upload('message_attachment', 'Attachment is empty.');
    }
    if ($size > 25 * 1024 * 1024) {
        lex_reject_upload('message_attachment', 'Attachment is too large. The limit is 25 MB.');
    }
    $originalName = trim((string) ($file['name'] ?? 'attachment'));
    $originalName = $originalName !== '' ? $originalName : 'attachment';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !isset(lex_allowed_upload_types()[$extension])) {
        lex_reject_upload('message_attachment', 'Unsupported attachment type. Allowed types: PDF, JPG, PNG, WEBP, DOCX, MP3, WAV, WEBM, OGG, M4A.');
    }
    lex_validate_allowed_upload_type((string) $file['tmp_name'], $originalName, 'message_attachment');
    lex_scan_upload_for_malware((string) $file['tmp_name'], 'message_attachment', $originalName);
    $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    $targetPath = lex_messages_attachment_path($storedName);
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        lex_reject_upload('message_attachment', 'Unable to save attachment.');
    }
    $validatedType = lex_validate_allowed_upload_type($targetPath, $originalName, 'message_attachment');
    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $validatedType['mime_type'],
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_payment_proofs_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'payment_proofs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_payment_qr_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'payment_qr';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function lex_payment_proof_path(string $storedName): string
{
    return lex_payment_proofs_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function lex_payment_qr_path(string $storedName): string
{
    return lex_payment_qr_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function lex_store_payment_proof(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        lex_reject_upload('payment_proof', 'Upload your payment screenshot or PDF proof.');
    }
    $limit = lex_rate_limit_hit('upload_payment_proof', lex_rate_limit_key(lex_rate_limit_client_ip(), lex_rate_limit_user_part()), 10, 3600, 900, lex_rate_limit_user_part());
    if (!$limit['allowed']) {
        lex_reject_upload('payment_proof', lex_rate_limit_message((int) $limit['retry_after']));
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        lex_reject_upload('payment_proof', 'Unable to upload payment proof.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        lex_reject_upload('payment_proof', 'Payment proof is empty.');
    }
    if ($size > 8 * 1024 * 1024) {
        lex_reject_upload('payment_proof', 'Payment proof is too large. The limit is 8 MB.');
    }

    $tmpPath = (string) $file['tmp_name'];
    $originalName = trim((string) ($file['name'] ?? 'payment-proof'));
    $originalName = $originalName !== '' ? $originalName : 'payment-proof';

    $mime = '';
    $extension = '';
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowedImages = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    if (isset($allowedImages[$imageType])) {
        [$extension, $mime] = $allowedImages[$imageType];
    } else {
        $detectedMime = function_exists('mime_content_type') ? (string) @mime_content_type($tmpPath) : '';
        if ($detectedMime === 'application/pdf') {
            $extension = 'pdf';
            $mime = 'application/pdf';
        }
    }

    if ($extension === '' || $mime === '') {
        lex_reject_upload('payment_proof', 'Payment proof must be a JPG, PNG, GIF, WEBP, or PDF file.');
    }
    lex_scan_upload_for_malware($tmpPath, 'payment_proof', $originalName);

    $storedName = 'proof_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_payment_proof_path($storedName);
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        lex_reject_upload('payment_proof', 'Unable to save payment proof.');
    }

    return [
        'original_name' => $originalName,
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_store_payment_qr(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $limit = lex_rate_limit_hit('upload_payment_qr', lex_rate_limit_key(lex_rate_limit_client_ip(), lex_rate_limit_user_part()), 10, 3600, 900, lex_rate_limit_user_part());
    if (!$limit['allowed']) {
        lex_reject_upload('payment_qr', lex_rate_limit_message((int) $limit['retry_after']));
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        lex_reject_upload('payment_qr', 'Unable to upload the GCash QR image.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        lex_reject_upload('payment_qr', 'GCash QR image is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        lex_reject_upload('payment_qr', 'GCash QR image is too large. The limit is 5 MB.');
    }

    $tmpPath = (string) $file['tmp_name'];
    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($tmpPath) : false;
    $allowed = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_GIF => ['gif', 'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];
    if (!isset($allowed[$imageType])) {
        lex_reject_upload('payment_qr', 'GCash QR must be a JPG, PNG, GIF, or WEBP image.');
    }
    lex_scan_upload_for_malware($tmpPath, 'payment_qr', (string) ($file['name'] ?? 'gcash-qr'));

    [$extension, $mime] = $allowed[$imageType];
    $storedName = 'gcash_qr_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = lex_payment_qr_path($storedName);
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        lex_reject_upload('payment_qr', 'Unable to save the GCash QR image.');
    }

    return [
        'original_name' => trim((string) ($file['name'] ?? 'gcash-qr')),
        'stored_name' => $storedName,
        'mime_type' => $mime,
        'size' => $size,
        'path' => $targetPath,
    ];
}

function lex_case_files_sync_record(array $record): void
{
    if (empty($record['folder_name'])) {
        return;
    }
    lex_case_files_write_metadata($record);
}

function lex_encrypt_file_contents(string $contents): string
{
    return lex_encrypt_string($contents);
}

function lex_decrypt_file_contents(string $payload): string
{
    return lex_decrypt_string($payload);
}
