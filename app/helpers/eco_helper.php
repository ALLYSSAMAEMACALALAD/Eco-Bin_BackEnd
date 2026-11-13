<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
 * Eco-Bin helpers: database shortcuts, role-based access control, audit logging and SMS.
 * Controllers call eco_boot() in before_action() so the database, API helper and settings are ready.
 */

function eco_boot(): void
{
    static $booted = false;
    if ($booted) return;
    $booted = true;
    $LAVA = lava_instance();
    $LAVA->call->database();
    $LAVA->call->library('api');
    $LAVA->config->load('eco');
}

/* ---------- Database shortcuts (prepared statements through the framework's Database class) ---------- */

function eco_db_one(string $sql, array $args = []): ?array
{
    $row = lava_instance()->db->raw($sql, $args)->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function eco_db_all(string $sql, array $args = []): array
{
    return lava_instance()->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
}

/** Runs INSERT and returns the new id. */
function eco_db_insert(string $sql, array $args = []): int
{
    $db = lava_instance()->db;
    $db->raw($sql, $args);
    return (int) $db->last_id();
}

/** Runs UPDATE/DELETE and returns the number of affected rows. */
function eco_db_exec(string $sql, array $args = []): int
{
    return lava_instance()->db->raw($sql, $args)->rowCount();
}

/* ---------- Role-based access control ---------- */

/**
 * Authenticates the bearer token and returns the current user row.
 * If $roles is given and the user's role is not in it, the request is refused with 403 and logged.
 */
function eco_current_user(array $roles = []): array
{
    eco_boot();
    $api = lava_instance()->api;
    $payload = $api->require_jwt(); // responds 401 on a missing or invalid token

    $user = eco_db_one(
        'SELECT id, name, email, phone, barangay, role, eco_id FROM users WHERE id = ? AND is_active = 1',
        [(int) $payload['sub']]
    );
    if (!$user) {
        $api->respond_error('Unauthorized', 401);
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        eco_audit((int) $user['id'], 'access_denied', ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''));
        $api->respond_error('You do not have permission to perform this action.', 403);
    }
    return $user;
}

/** The user fields that are safe to send to the browser (never the password hash). */
function eco_public_user(array $u): array
{
    return [
        'id'       => (int) $u['id'],
        'name'     => $u['name'],
        'email'    => $u['email'],
        'phone'    => $u['phone'] ?? null,
        'barangay' => $u['barangay'] ?? null,
        'role'     => $u['role'],
        'eco_id'   => $u['eco_id'] ?? null,
    ];
}

/* ---------- Audit and notifications ---------- */

function eco_audit(?int $userId, string $action, ?string $target = null, ?string $details = null): void
{
    eco_db_insert('INSERT INTO audit_logs (user_id, action, target, details) VALUES (?, ?, ?, ?)',
        [$userId, $action, $target, $details]);
}

/** Sends an SMS through the configured provider. Returns false (and sends nothing) when no provider is set. */
function eco_send_sms(?string $phone, string $message): bool
{
    $provider = config_item('sms_provider');
    $key      = config_item('sms_api_key');
    if (!$phone || !$provider || !$key) return false;

    if ($provider === 'semaphore') {
        $ch = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => $key, 'number' => $phone, 'message' => $message, 'sendername' => config_item('sms_sender'),
            ]),
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
    return false; // Twilio is not wired up yet
}

/**
 * Sends an HTML email. Uses Brevo's HTTPS API when BREVO_API_KEY is set,
 * otherwise Gmail SMTP through PHPMailer, then PHP mail() as a last resort.
 * Returns true when a provider accepted the message.
 */
function eco_send_mail(?string $to, string $toName, string $subject, string $html): bool
{
    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $senderEmail = trim((string) config_item('brevo_sender_email'));
    $senderName  = trim((string) config_item('brevo_sender_name')) ?: 'Eco-Bin';
    $gmailUser   = trim((string) config_item('gmail_user'));
    $gmailPass   = (string) config_item('gmail_app_password');
    $brevoKey    = trim((string) config_item('brevo_api_key'));
    $brevoUrl    = trim((string) config_item('brevo_api_url')) ?: 'https://api.brevo.com/v3/smtp/email';
    if ($senderEmail === '') $senderEmail = $gmailUser;

    // 1) Brevo HTTPS API. It works even when the host blocks outbound SMTP ports.
    if ($brevoKey !== '' && $senderEmail !== '') {
        $payload = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [['email' => $to, 'name' => $toName !== '' ? $toName : $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];

        $ch = curl_init($brevoUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $brevoKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status >= 200 && $status < 300) return true;

        error_log('EcoBin mail (Brevo) failed: HTTP ' . $status . ' ' . ($error ?: substr((string) $body, 0, 500)));
    }

    // 2) Gmail SMTP (requires a Google App Password, not the normal account password).
    if ($gmailUser !== '' && $gmailPass !== '') {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(APP_DIR) . DIRECTORY_SEPARATOR;
        $autoload = $root . 'vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;

        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $gmailUser;
                $mail->Password   = $gmailPass;
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->CharSet    = 'UTF-8';
                $mail->setFrom($gmailUser, $senderName);
                $mail->addAddress($to, $toName !== '' ? $toName : $to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html;
                $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)));
                $mail->send();
                return true;
            } catch (\Throwable $e) {
                error_log('EcoBin mail (Gmail SMTP) failed: ' . $e->getMessage());
            }
        } else {
            error_log('EcoBin mail: PHPMailer is not installed. Run composer install inside backend/.');
        }
    }

    // 3) PHP mail() fallback for hosts that provide a local sendmail.
    if ($senderEmail === '') return false;
    $fromName = str_replace(['"', "\r", "\n"], '', $senderName);
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $senderEmail . '>',
    ];

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
}
