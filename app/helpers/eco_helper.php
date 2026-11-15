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

/**
 * Stores an uploaded photo to Cloudinary when configured, otherwise into public/uploads.
 * Validates size and real MIME type. Returns the URL to persist. Exits the request on failure.
 */
function eco_store_photo(array $file): string
{
    $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $api = lava_instance()->api;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $api->respond_error('Photo upload failed.', 422);
    }
    if ($file['size'] > config_item('upload_max_bytes')) {
        $api->respond_error('Photo must be 5 MB or smaller.', 422);
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($imageTypes[$mime])) {
        $api->respond_error('Photo must be a JPEG, PNG or WebP image.', 422);
    }

    $cloud  = config_item('cloudinary_cloud_name');
    $key    = config_item('cloudinary_api_key');
    $secret = config_item('cloudinary_api_secret');
    $preset = config_item('cloudinary_upload_preset');

    if ($cloud && $key && $secret) {
        // Signed upload through the official Cloudinary PHP SDK (composer install).
        $autoload = ROOT_DIR . 'vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists('\\Cloudinary\\Cloudinary')) {
            error_log('Cloudinary SDK not installed. Run composer install inside backend/.');
            $api->respond_error('Photo upload to storage failed. Please try again.', 502);
        }
        try {
            $cloudinary = new \Cloudinary\Cloudinary([
                'cloud' => ['cloud_name' => $cloud, 'api_key' => $key, 'api_secret' => $secret],
            ]);
            $result = $cloudinary->uploadApi()->upload($file['tmp_name'], [
                'folder'          => 'ecobin',
                'resource_type'   => 'image',
                'unique_filename' => true,
                'overwrite'       => false,
            ]);
            $url = $result['secure_url'] ?? null;
            if (!$url) {
                throw new RuntimeException('Cloudinary response did not include a secure_url.');
            }
            return $url;
        } catch (Throwable $e) {
            error_log('Cloudinary upload failed: ' . $e->getMessage());
            $api->respond_error('Photo upload to storage failed. Please try again.', 502);
        }
    }
    if ($cloud && $preset) { // legacy unsigned preset fallback
        $ch = curl_init("https://api.cloudinary.com/v1_1/$cloud/image/upload");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => [
                'file' => new \CURLFile($file['tmp_name'], $mime, 'photo.' . $imageTypes[$mime]),
                'upload_preset' => $preset,
            ],
        ]);
        $body = json_decode((string) curl_exec($ch), true);
        if (!empty($body['secure_url'])) return $body['secure_url'];
        error_log('Cloudinary upload failed: ' . json_encode($body));
        $api->respond_error('Photo upload to storage failed. Please try again.', 502);
    }

    $dir = ROOT_DIR . PUBLIC_DIR . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(12)) . '.' . $imageTypes[$mime];
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        $api->respond_error('Photo upload failed.', 422);
    }
    return "/uploads/$name";
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
        return $code >= 200 && $code < 300;
    }
    return false; // Twilio is not wired up yet
}

/**
 * Sends an HTML email. Uses Brevo's HTTPS API when BREVO_API_KEY is set,
 * otherwise Gmail SMTP through PHPMailer, then PHP mail() as a last resort.
 * Pass attachments as a list of ['name' => filename, 'content' => rawBytes, 'mime' => mimeType].
 * Attachments are only honored on the Brevo and Gmail SMTP paths; the mail() fallback drops them.
 * Returns true when a provider accepted the message.
 */
function eco_send_mail(?string $to, string $toName, string $subject, string $html, array $attachments = []): bool
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
        if ($attachments) {
            $payload['attachment'] = [];
            foreach ($attachments as $a) {
                $payload['attachment'][] = [
                    'name'    => (string) $a['name'],
                    'content' => base64_encode((string) $a['content']),
                ];
            }
        }

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
                if ($attachments) {
                    foreach ($attachments as $a) {
                        $mail->addStringAttachment(
                            (string) $a['content'],
                            (string) $a['name'],
                            \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                            $a['mime'] ?? 'application/octet-stream'
                        );
                    }
                }
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

/**
 * EcoBin Reward Voucher PDF — mirrors the in-app "Download PDF" certificate.
 * Landscape, deep green brand palette, botanical decorations, globe artwork and details strip.
 * Self-contained PDF generator (PDF base-14 fonts only) so it works on any deployment.
 */
function eco_render_voucher_pdf(array $c): string
{
    $name    = trim((string) ($c['resident_name'] ?? 'EcoBin Resident'));
    $ecoId   = trim((string) ($c['eco_id'] ?? 'N/A'));
    $reward  = trim((string) ($c['reward'] ?? ($c['reward_name'] ?? 'Eco Reward')));
    $points  = (int) ($c['points_redeemed'] ?? 0);
    $code    = trim((string) ($c['code'] ?? ($c['certificate_code'] ?? 'N/A')));
    $issued  = trim((string) ($c['issued_at'] ?? date('Y-m-d')));

    $W = 1000; $H = 440;
    $green   = [39 / 255, 83 / 255, 51 / 255];
    $dark    = [24 / 255, 65 / 255, 43 / 255];
    $cream   = [250 / 255, 248 / 255, 232 / 255];
    $creamIn = [255 / 255, 254 / 255, 246 / 255];
    $sage    = [220 / 255, 231 / 255, 193 / 255];
    $sageDk  = [181 / 255, 204 / 255, 151 / 255];
    $sageMd  = [99 / 255, 143 / 255, 91 / 255];
    $white   = [255 / 255, 255 / 255, 255 / 255];
    $muted   = [112 / 255, 139 / 255, 103 / 255];
    $panel   = [246 / 255, 247 / 255, 226 / 255];

    // ---- Adobe AFM widths (units per 1000 em) for printable ASCII ----
    $helv = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    $bold = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    $widthOf = static function (string $s, array $table, int $size): float {
        $w = 0.0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c2 = ord($s[$i]);
            $w += ($c2 >= 32 && $c2 <= 126) ? $table[$c2 - 32] : 500;
        }
        return $w * $size / 1000.0;
    };

    $pdfStr = static function (string $s): string {
        $t = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        if ($t === false) $t = $s;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    };

    $rgbColor = static fn(array $c2, bool $stroke): string => sprintf("%.4f %.4f %.4f %s\n", $c2[0], $c2[1], $c2[2], $stroke ? 'RG' : 'rg');

    // PDF coordinates are bottom-up; Y($topY) converts from a top-down layout value.
    $Y = static fn(float $top): float => $H - $top;

    $content = '';
    $fillRgb = static function (array $c2) use (&$content): void { $content .= sprintf("%.4f %.4f %.4f rg\n", $c2[0], $c2[1], $c2[2]); };
    $strokeRgb = static function (array $c2) use (&$content): void { $content .= sprintf("%.4f %.4f %.4f RG\n", $c2[0], $c2[1], $c2[2]); };

    // Filled rectangle with optional stroke (no rounding).
    $rect = static function (float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $strokeW = 1.0) use (&$content, $fillRgb, $strokeRgb, $Y): void {
        if ($fill !== null) { $fillRgb($fill); $content .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $Y($y + $h), $w, $h); }
        if ($stroke !== null) { $strokeRgb($stroke); $content .= sprintf("%.2f w\n%.2f %.2f %.2f %.2f re S\n", $strokeW, $x, $Y($y + $h), $w, $h); }
    };

    // Filled ellipse (circles/leaves). PDF Bezier approximation.
    $ellipse = static function (float $cx, float $cy, float $rx, float $ry, ?array $fill = null, ?array $stroke = null, float $strokeW = 1.0) use (&$content, $fillRgb, $strokeRgb, $Y): void {
        $k = 0.552284749831;
        $by = $Y;
        if ($fill !== null) $fillRgb($fill);
        elseif ($stroke !== null) $strokeRgb($stroke);
        if ($stroke !== null) $content .= sprintf("%.2f w\n", $strokeW);
        $cyTop = $by($cy);
        $content .= sprintf("%.4f %.4f m %.4f %.4f %.4f %.4f %.4f %.4f c %.4f %.4f %.4f %.4f %.4f %.4f c %.4f %.4f %.4f %.4f %.4f %.4f c %.4f %.4f %.4f %.4f %.4f %.4f c h %s\n",
            $cx, $by($cy - $ry),          // moveto at top anchor
            $cx + $rx * $k, $by($cy - $ry), $cx + $rx, $by($cy - $ry * $k), $cx + $rx, $cyTop,   // c1 -> right
            $cx + $rx, $by($cy + $ry * $k), $cx + $rx * $k, $by($cy + $ry), $cx, $by($cy + $ry), // c2 -> bottom
            $cx - $rx * $k, $by($cy + $ry), $cx - $rx, $by($cy + $ry * $k), $cx - $rx, $cyTop,    // c3 -> left
            $cx - $rx, $by($cy - $ry * $k), $cx - $rx * $k, $by($cy - $ry), $cx, $by($cy - $ry),  // c4 back to top
            $stroke !== null ? ($fill !== null ? 'B' : 'S') : 'f'
        );
    };

    // Filled triangle (3 vertices).
    $triangle = static function (float $ax, float $ay, float $bx, float $by, float $bx2, float $by2, array $fill) use (&$content, $fillRgb, $Y): void {
        $fillRgb($fill);
        $content .= sprintf("%.2f %.2f m %.2f %.2f l %.2f %.2f l h f\n",
            $ax, $Y($ay),
            $bx, $Y($by),
            $bx2, $Y($by2));
    };

    // Straight line between two points (optional dashed).
    $line = static function (float $x1, float $y1, float $x2, float $y2, array $color, float $w = 1.0, float $dash = 0.0) use (&$content, $strokeRgb, $Y): void {
        $strokeRgb($color);
        $content .= sprintf("%.2f w\n", $w);
        if ($dash > 0) $content .= "[8 7] 0 d\n"; else $content .= "[] 0 d\n";
        $content .= sprintf("%.2f %s m %.2f %s l S\n", $x1, $Y($y1), $x2, $Y($y2));
    };

    // Text. align: 'l' left, 'c' center, 'r' right. charSpace in PDF units.
    $text = static function (float $x, float $y, int $fontId, float $size, array $color, string $s, string $align = 'l', float $charSpace = 0.0) use (&$content, $bold, $helv, $widthOf, $pdfStr, $Y): float {
        $table = ($fontId === 1 || $fontId === 3) ? $bold : ($fontId === 4 ? null : $helv); // 1=Bold, 2=Regular, 3=BoldOblique(≈Bold widths), 4=Courier (0.6em)
        $textW = $fontId === 4 ? 0.6 * $size * strlen($s) : $widthOf($s, $table, (int) round($size));
        $dx = match ($align) {
            'c' => $x - $textW / 2.0,
            'r' => $x - $textW,
            default => $x,
        };
        $content .= sprintf("BT /F%d %.1f Tf %.4f %.4f %.4f rg %.2f %.2f Td %.2f Tc (%s) Tj ET\n",
            $fontId, $size, $color[0], $color[1], $color[2], $dx, $Y($y) - $size * 0.35, $charSpace, $pdfStr($s));
        return $textW;
    };

    // ---- Background ----
    $fillRgb($cream);
    $content .= sprintf("0 0 %d %d re f\n", $W, $H);
    $rect(8, 8, 984, 424, $creamIn, null, 0);
    // outer border (square, no rounding)
    $strokeRgb($green);
    $content .= "2 w\n";
    $content .= "5 5 990 430 re S\n";

    // ---- Botanical leaf helper ----
    $leaf = static function (float $cx, float $cy, float $rx, float $ry, array $color) use ($ellipse, $line, $green): void {
        $ellipse($cx, $cy, $rx, $ry, $color, null, 0);
        $line($cx - $rx * 0.6, $cy + $ry * 0.5, $cx + $rx * 0.6, $cy - $ry * 0.5, [235 / 255, 239 / 255, 219 / 255], 1.0);
    };

    $leaf(25, 20, 55, 19, [124 / 255, 157 / 255, 99 / 255]);
    $leaf(65, 12, 45, 15, [157 / 255, 183 / 255, 127 / 255]);
    $leaf(965, 18, 55, 19, [124 / 255, 157 / 255, 99 / 255]);
    $leaf(925, 12, 45, 15, [157 / 255, 183 / 255, 127 / 255]);
    $leaf(28, 335, 48, 17, [101 / 255, 143 / 255, 82 / 255]);
    $leaf(52, 385, 38, 15, [127 / 255, 158 / 255, 98 / 255]);
    $leaf(82, 350, 34, 13, [145 / 255, 174 / 255, 112 / 255]);
    $leaf(950, 370, 45, 15, [115 / 255, 151 / 255, 89 / 255]);

    // ---- Right panel + vertical dashed divider ----
    $rect(792, 10, 198, 420, $panel, null, 0);
    $line(790, 10, 790, 430, $muted, 3.0, 1.0);

    // ---- Eco-Bin logo ----
    $ellipse(105, 57, 34, 34, $green, null, 0);
    $leaf(96, 52, 11, 5, [91 / 255, 183 / 255, 121 / 255]);
    $leaf(111, 52, 11, 5, [111 / 255, 196 / 255, 137 / 255]);
    $leaf(104, 67, 10, 5, [76 / 255, 151 / 255, 105 / 255]);

    $text(168, 48, 1, 43, $dark, 'Eco-Bin', 'l');
    $text(171, 72, 1, 15, $dark, 'REWARDS SYSTEM', 'l');

    // Tagline header
    $line(410, 42, 500, 42, $green, 1.5);
    $text(510, 46, 2, 12, $green, "REDEEM  \xE2\x80\xA2  REWARD  \xE2\x80\xA2  GO GREEN", 'l');

    // ---- Main title banner ----
    $rect(80, 88, 680, 72, $green, null, 0);
    $text(420, 139, 1, 44, $white, 'REWARD VOUCHER', 'c', 3);
    $leaf(718, 123, 27, 11, [166 / 255, 194 / 255, 128 / 255]);

    // ---- Recipient block ----
    $text(420, 193, 1, 20, $green, 'THIS VOUCHER IS PRESENTED TO:', 'c', 2);
    $rect(80, 202, 680, 67, $sage, null, 0);

    $fitName = function (string $s) use ($widthOf, $bold): int {
        $size = 48;
        while ($size > 23) {
            if ($widthOf($s, $bold, $size) <= 630) return $size;
            $size--;
        }
        return $size;
    };
    $text(420, 252, 1, $fitName($name), $dark, $name, 'c');
    $text(420, 294, 1, 20, $green, '(ECO-ID: ' . $ecoId . ')', 'c');

    // Horizontal divider
    $line(80, 308, 760, 308, $muted, 1.5);

    // ---- Redeemed points ----
    $leaf(130, 355, 17, 8, [65 / 255, 125 / 255, 75 / 255]);
    $text(158, 351, 1, 17, $green, 'REDEEMED POINTS', 'l');
    $text(235, 395, 1, 39, $dark, number_format($points), 'c');

    // Vertical divider
    $line(375, 325, 375, 408, $green, 2.0);

    // ---- Reward trophy + label ----
    $rect(414, 333, 36, 27, $green, null, 0);
    $rect(422, 338, 20, 15, $cream, null, 0);
    $rect(428, 352, 8, 8, $cream, null, 0);
    $rect(423, 359, 18, 3, $cream, null, 0);
    $text(470, 351, 1, 17, $green, 'REWARD', 'l');
    $fitReward = function (string $s) use ($widthOf, $bold): int {
        $size = 25;
        while ($size > 15) {
            if ($widthOf($s, $bold, $size) <= 290) return $size;
            $size--;
        }
        return $size;
    };
    $text(470, 386, 1, $fitReward($reward), $dark, $reward, 'l');

    // ---- Globe illustration ----
    $ellipse(890, 151, 61, 61, $green, null, 0);
    $ellipse(890, 151, 51, 51, [236 / 255, 239 / 255, 210 / 255], null, 0);
    $ellipse(876, 139, 13, 23, $green, null, 0);
    $ellipse(900, 166, 17, 25, $green, null, 0);
    $ellipse(913, 128, 10, 13, $green, null, 0);
    $ellipse(869, 180, 8, 13, $green, null, 0);
    $ellipse(890, 151, 51, 51, null, $green, 1.5);
    $ellipse(890, 151, 24, 51, null, $green, 1.5);
    $ellipse(890, 151, 51, 19, null, $green, 1.5);
    $leaf(870, 77, 13, 5, [55 / 255, 112 / 255, 65 / 255]);
    $leaf(899, 73, 13, 5, [55 / 255, 112 / 255, 65 / 255]);
    $line(884, 91, 884, 105, $green, 2.0);

    // Slogan
    $text(890, 240, 3, 23, $green, 'Small actions.', 'c');
    $text(890, 267, 3, 23, $green, 'Big impact.', 'c');
    $line(842, 293, 884, 278, $green, 2.0);
    $line(884, 278, 931, 293, $green, 2.0);

    // ---- Bottom landscape decorations ----
    $triangle(0, 440, 250, 425, 470, 440, $sageDk);
    $triangle(790, 440, 920, 425, 1000, 440, $sageDk);
    $triangle(0, 440, 250, 433, 490, 440, $sageMd);
    $triangle(790, 440, 920, 433, 1000, 440, $sageMd);
    $triangle(0, 440, 300, 437, 600, 440, $green);
    $triangle(790, 440, 925, 437, 1000, 440, $green);

    // ---- Footer details strip ----
    $text(80, 428, 2, 9, $white, 'CERTIFICATE CODE: ' . $code, 'l');
    $text(430, 428, 2, 9, $white, 'ISSUED: ' . $issued, 'l');

    // ---- Side cutouts ----
    $ellipse(5, 220, 19, 19, $cream, null, 0);
    $ellipse(995, 220, 19, 19, $cream, null, 0);
    $ellipse(5, 220, 19, 19, null, $green, 2.0);
    $ellipse(995, 220, 19, 19, null, $green, 2.0);

    // ---- Assemble the PDF ----
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] '
           . '/Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R /F4 7 0 R >> >> /Contents 8 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-BoldOblique /Encoding /WinAnsiEncoding >>',
        7 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
        8 => "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 " . $count . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $id => $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    return $pdf;
}

/**
 * Password policy used by register, reset and staff-creation endpoints.
 * Must be at least 8 characters and include an uppercase letter,
 * a lowercase letter and a special (non-alphanumeric) character.
 * Returns an error message, or null when the password passes.
 */
function eco_password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include a lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include an uppercase letter.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include a special character (e.g. $, #, !).';
    }
    return null;
}
