<?php
declare(strict_types=1);

if (!function_exists('lex_video_env')) {
    function lex_video_env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return (string) $_SERVER[$name];
        }

        return $default;
    }
}

/**
 * LexShield video consultation foundation.
 *
 * Video meetings are authorized by a confirmed appointment. A unique,
 * unpredictable Jitsi room is generated per appointment and is never shared
 * between different client/lawyer consultations.
 */

function lex_video_config_int(string $key, int $default, int $min, int $max): int
{
    $value = lex_video_env($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false) {
        return $default;
    }

    return max($min, min($max, (int) $parsed));
}

function lex_video_join_early_minutes(): int
{
    return lex_video_config_int('LEX_VIDEO_JOIN_EARLY_MINUTES', 15, 0, 1440);
}

function lex_video_join_late_minutes(): int
{
    return lex_video_config_int('LEX_VIDEO_JOIN_LATE_MINUTES', 60, 1, 1440);
}

function lex_video_api_connect_timeout_seconds(): int
{
    return lex_video_config_int('LEX_VIDEO_API_CONNECT_TIMEOUT_SECONDS', 10, 1, 30);
}

function lex_video_api_timeout_seconds(): int
{
    return lex_video_config_int('LEX_VIDEO_API_TIMEOUT_SECONDS', 60, 10, 120);
}

function lex_video_provider(): string
{
    $provider = strtolower(trim((string) (lex_video_env('LEX_VIDEO_PROVIDER') ?: 'jaas')));
    return in_array($provider, ['jitsi', 'jaas'], true) ? $provider : '';
}

function lex_video_jitsi_domain(): string
{
    $domain = trim((string) (getenv('LEX_VIDEO_JITSI_DOMAIN') ?: 'meet.jit.si'));
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = trim((string) $domain, "/ \t\n\r\0\x0B");
    return preg_match('/^[a-z0-9.-]+$/i', $domain) ? strtolower($domain) : 'meet.jit.si';
}

function lex_video_jaas_domain(): string
{
    $domain = trim((string) (lex_video_env('LEX_VIDEO_JAAS_DOMAIN') ?: '8x8.vc'));
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = trim((string) $domain, "/ \t\n\r\0\x0B");
    return preg_match('/^[a-z0-9.-]+$/i', $domain) ? strtolower($domain) : '8x8.vc';
}

function lex_video_domain_for_provider(string $provider): string
{
    return $provider === 'jaas' ? lex_video_jaas_domain() : lex_video_jitsi_domain();
}

function lex_video_jaas_app_id(): string
{
    return trim(lex_video_env('LEX_VIDEO_JAAS_APP_ID') ?: '');
}



function lex_video_token_secret(): string
{
    return trim(lex_video_env('LEX_VIDEO_TOKEN_SECRET') ?: '');
}

function lex_video_remote_token_error(?string $message = null): string
{
    static $lastError = '';
    if ($message !== null) {
        $lastError = $message;
    }
    return $lastError;
}




function lex_video_remote_jaas_token(array $meeting, array $user): ?array
{
    lex_video_remote_token_error('');
    $endpoint = lex_api_url('api/video/jaas-token');
    $secret = lex_video_token_secret();
    if ($endpoint === '' || $secret === '') {
        lex_video_remote_token_error('The Render API URL or video token shared secret is missing.');
        return null;
    }

    $now = time();
    $payload = [
        'meeting' => [
            'room' => (string) ($meeting['meeting_room'] ?? ''),
            'appointment_id' => (int) ($meeting['id'] ?? 0),
            'case_id' => (int) ($meeting['case_id'] ?? 0),
        ],
        'user' => [
            'id' => (string) ($user['id'] ?? ''),
            'name' => trim((string) ($user['full_name'] ?? 'LEXSHIELD User')),
            'email' => trim((string) ($user['email'] ?? '')),
            'role' => (string) ($user['role'] ?? ''),
        ],
        // Tokens are short-lived credentials for the current call session, not
        // credentials bounded by the appointment's scheduled time.
        'expiresAt' => $now + 3600,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        lex_video_remote_token_error('The video token request payload could not be encoded.');
        return null;
    }

    $timestamp = (string) $now;
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Lexshield-Timestamp: ' . $timestamp,
        'X-Lexshield-Signature: ' . $signature,
    ];

    $response = null;
    if (function_exists('curl_init')) {
        $curl = curl_init($endpoint);
        if ($curl !== false) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => lex_video_api_connect_timeout_seconds(),
                CURLOPT_TIMEOUT => lex_video_api_timeout_seconds(),
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($raw !== false && $status >= 200 && $status < 300) {
                $response = (string) $raw;
            } else {
                $curlError = curl_error($curl);
                $message = 'Render token request failed with HTTP status ' . $status . '.';
                if ($curlError !== '') {
                    $message .= ' cURL: ' . $curlError;
                }
                lex_video_remote_token_error($message);
                error_log('Remote JaaS token request failed: ' . $message);
            }
            curl_close($curl);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => lex_video_api_timeout_seconds(),
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($endpoint, false, $context);
        if ($raw !== false) {
            $status = 0;
            foreach (($http_response_header ?? []) as $headerLine) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $headerLine, $matches)) {
                    $status = (int) $matches[1];
                    break;
                }
            }
            if ($status >= 200 && $status < 300) {
                $response = (string) $raw;
            } else {
                $message = 'Render token request failed with HTTP status ' . $status . '.';
                lex_video_remote_token_error($message);
                error_log('Remote JaaS token request failed: ' . $message);
            }
        } else {
            lex_video_remote_token_error('PHP could not connect to the Render token endpoint.');
        }
    }

    if ($response === null) {
        if (lex_video_remote_token_error() === '') {
            lex_video_remote_token_error('No response was received from the Render token endpoint.');
        }
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['jwt'])) {
        $apiMessage = is_array($decoded) && isset($decoded['message']) ? trim((string) $decoded['message']) : '';
        lex_video_remote_token_error($apiMessage !== '' ? $apiMessage : 'Render returned an invalid token response.');
        error_log('Remote JaaS token response was invalid.');
        return null;
    }

    return [
        'jwt' => (string) $decoded['jwt'],
        'app_id' => trim((string) ($decoded['app_id'] ?? lex_video_jaas_app_id())),
        'domain' => trim((string) ($decoded['domain'] ?? lex_video_jaas_domain())),
    ];
}

function lex_video_generate_room(): string
{
    // 192 bits of entropy; the random token is deliberately unrelated to
    // appointment IDs, case IDs, names, emails, or other predictable data.
    return 'LexShield-' . bin2hex(random_bytes(24));
}

function lex_video_prepare_for_confirmed_appointment(int $appointmentId): bool
{
    if ($appointmentId <= 0 || lex_video_provider() === '') {
        return false;
    }

    return (bool) lex_db_retry(static function () use ($appointmentId): bool {
        $pdo = lex_pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, status, meeting_provider, meeting_room, meeting_enabled
                 FROM appointments
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $appointmentId]);
            $appointment = $stmt->fetch();

            if (!$appointment || (string) $appointment['status'] !== 'confirmed') {
                $pdo->rollBack();
                return false;
            }

            $room = trim((string) ($appointment['meeting_room'] ?? ''));
            $provider = trim((string) ($appointment['meeting_provider'] ?? ''));

            if ($room === '' || $provider !== lex_video_provider()) {
                $room = lex_video_generate_room();
                $provider = lex_video_provider();

                $update = $pdo->prepare(
                    'UPDATE appointments
                     SET meeting_provider = :provider,
                         meeting_room = :room,
                         meeting_enabled = 1,
                         meeting_created_at = COALESCE(meeting_created_at, NOW())
                     WHERE id = :id'
                );
                $update->execute([
                    'provider' => $provider,
                    'room' => $room,
                    'id' => $appointmentId,
                ]);
            } elseif ((int) ($appointment['meeting_enabled'] ?? 0) !== 1) {
                $pdo->prepare(
                    'UPDATE appointments
                     SET meeting_enabled = 1
                     WHERE id = :id'
                )->execute(['id' => $appointmentId]);
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Video consultation preparation failed: ' . $e->getMessage());
            return false;
        }
    });
}

/**
 * Return the appointment only when the current user is the assigned client
 * or lawyer, the appointment is confirmed, and the meeting is enabled.
 *
 * The returned array also contains can_join so the caller can decide whether
 * to display/allow the consultation at the current time.
 */
function lex_video_consultation_for_user(int $appointmentId, int $userId): ?array
{
    if ($appointmentId <= 0 || $userId <= 0) {
        return null;
    }

    try {
        return lex_db_retry(static function () use ($appointmentId, $userId): ?array {
            $stmt = lex_pdo()->prepare(
            'SELECT
                a.id,
                a.case_id,
                a.client_id,
                a.lawyer_id,
                a.scheduled_at,
                a.appointment_type,
                a.status,
                a.meeting_provider,
                a.meeting_room,
                a.meeting_enabled,
                a.meeting_created_at,
                c.case_number,
                cl.user_id AS client_user_id,
                lu.user_id AS lawyer_user_id
             FROM appointments a
             JOIN cases c ON c.id = a.case_id
             JOIN clients cl ON cl.id = a.client_id
             JOIN lawyers lu ON lu.id = a.lawyer_id
             WHERE a.id = :appointment_id
               AND a.status = "confirmed"
               AND a.meeting_enabled = 1
               AND a.meeting_provider IN ("jitsi", "jaas")
               AND a.meeting_room IS NOT NULL
               AND a.meeting_room <> ""
               AND (cl.user_id = :client_user_id OR lu.user_id = :lawyer_user_id)
             LIMIT 1'
        );
        $stmt->execute([
            'appointment_id' => $appointmentId,
            'client_user_id' => $userId,
            'lawyer_user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $scheduledAt = strtotime((string) $row['scheduled_at']);
        if ($scheduledAt === false) {
            return null;
        }

        // Confirmed appointments are available for an on-demand call at any time.
        // Keep can_join for existing callers while authorization remains tied to
        // the confirmed appointment and its assigned client/lawyer.
        $row['can_join'] = true;
        $row['video_domain'] = lex_video_domain_for_provider((string) $row['meeting_provider']);

            return $row;
        });
    } catch (Throwable $e) {
        error_log('Video consultation lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Find the confirmed video consultation associated with a case conversation
 * for the current user and their conversation partner.
 */
function lex_video_thread_for_user(int $caseId, int $userId, int $partnerUserId): ?array
{
    if ($caseId <= 0 || $userId <= 0 || $partnerUserId <= 0) {
        return null;
    }

    try {
        return lex_db_retry(static function () use ($caseId, $userId, $partnerUserId): ?array {
            $stmt = lex_pdo()->prepare(
            'SELECT
                a.id, a.case_id, a.scheduled_at, a.appointment_type, a.status,
                a.meeting_provider, a.meeting_room, a.meeting_enabled,
                c.case_number, cl.user_id AS client_user_id, lu.user_id AS lawyer_user_id
             FROM appointments a
             JOIN cases c ON c.id = a.case_id
             JOIN clients cl ON cl.id = a.client_id
             JOIN lawyers lu ON lu.id = a.lawyer_id
             WHERE a.case_id = :case_id
               AND a.status = "confirmed"
               AND a.meeting_enabled = 1
               AND a.meeting_provider IN ("jitsi", "jaas")
               AND a.meeting_room IS NOT NULL
               AND a.meeting_room <> ""
               AND ((cl.user_id = :current_user_id AND lu.user_id = :partner_user_id_a)
                    OR (lu.user_id = :current_user_id_b AND cl.user_id = :partner_user_id_b))
             ORDER BY CASE WHEN a.scheduled_at >= NOW() THEN 0 ELSE 1 END, a.scheduled_at ASC, a.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'current_user_id' => $userId,
            'partner_user_id_a' => $partnerUserId,
            'current_user_id_b' => $userId,
            'partner_user_id_b' => $partnerUserId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $scheduledAt = strtotime((string) $row['scheduled_at']);
        if ($scheduledAt === false) {
            return null;
        }

        // Confirmed appointments are available for an on-demand call at any time.
        $row['can_join'] = true;
        $row['video_domain'] = lex_video_domain_for_provider((string) $row['meeting_provider']);
            return $row;
        });
    } catch (Throwable $e) {
        error_log('Video thread lookup failed: ' . $e->getMessage());
        return null;
    }
}
