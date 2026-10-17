<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src/SMTP.php';

function lex_mail_error(?string $message = null): ?string
{
    if ($message !== null) {
        $GLOBALS['lexLastMailError'] = $message;
    }
    return $GLOBALS['lexLastMailError'] ?? null;
}

function lex_mail_setting(string $settingKey, string $envKey, string $default = ''): string
{
    $envValue = function_exists('lex_env') ? lex_env($envKey) : null;
    if ($envValue !== null && trim($envValue) !== '') {
        return trim((string) $envValue);
    }

    return trim(lex_site_setting($settingKey, $default));
}

function lex_phpmailer_send(string $to, string $subject, string $body): bool
{
    $host = lex_mail_setting('smtp_host', 'SMTP_HOST');
    $port = (int) lex_mail_setting('smtp_port', 'SMTP_PORT', '587');
    $user = lex_mail_setting('smtp_user', 'SMTP_USER');
    $pass = lex_mail_setting('smtp_pass', 'SMTP_PASS');
    $encryption = strtolower(lex_mail_setting('smtp_encryption', 'SMTP_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'));
    $fromEmail = lex_mail_setting('smtp_from_email', 'SMTP_FROM_EMAIL');
    $fromName = lex_mail_setting('smtp_from_name', 'SMTP_FROM_NAME', lex_site_setting('site_name', LEX_APP_NAME));

    if ($host === '') {
        lex_mail_error('SMTP host is missing.');
        return false;
    }

    if ($port <= 0) {
        $port = 587;
    }

    $from = $fromEmail !== '' ? $fromEmail : ($user !== '' ? $user : ('no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost')));

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = $user !== '';
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;

        if ($encryption === 'ssl' || $encryption === 'smtps') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        $mail->isHTML(false);

        $mail->send();
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $message = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        lex_mail_error('PHPMailer SMTP failed: ' . $message);
        error_log('[PHPMailer] ' . $message);
        return false;
    }
}

function lex_send_email(string $to, string $subject, string $body): bool
{
    lex_mail_error(null);
    if (lex_phpmailer_send($to, $subject, $body)) {
        return true;
    }
    if (lex_mail_setting('smtp_host', 'SMTP_HOST') !== '') {
        return false;
    }

    $from = lex_site_setting('smtp_user');
    $headers = [
        'From: ' . ($from !== '' ? $from : (LEX_APP_NAME . ' <no-reply@localhost>')),
        'Reply-To: ' . ($from !== '' ? $from : 'no-reply@localhost'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (@mail($to, $subject, $body, implode("\r\n", $headers))) {
        return true;
    }
    if (!lex_mail_error()) {
        lex_mail_error('The local mail function failed.');
    }
    return false;
}

function lex_account_activity_context(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown IP';
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown browser'), 0, 180);
    return "Time: " . date('Y-m-d H:i:s') . "\nIP address: {$ip}\nBrowser/device: {$userAgent}";
}

function lex_send_account_activity_alert(array $user, string $subject, string $message): bool
{
    $email = lex_sanitize_email((string) ($user['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string) ($user['full_name'] ?? 'LEXSHIELD user'));
    $body = "Hello {$name},\n\n"
        . $message . "\n\n"
        . lex_account_activity_context() . "\n\n"
        . "If this was you, no action is needed. If this was not you, reset your password and contact the LEXSHIELD administrator immediately.";

    return lex_send_email($email, $subject, $body);
}

function lex_send_security_event_notification(array $user, string $subject, string $message, ?string $notificationMessage = null): bool
{
    $sent = lex_send_account_activity_alert($user, $subject, $message);
    $userId = (int) ($user['id'] ?? 0);

    if ($userId > 0 && function_exists('lex_notify')) {
        lex_notify($userId, 'security', $notificationMessage ?: $message);
    }

    return $sent;
}
