<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('User_model');
    }

    /** Public sign-up always creates a resident. Collector and admin accounts are created by an admin. */
    public function register()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 20, 3600);

        $in = $this->api->body();
        $name     = trim($in['name'] ?? '');
        $email    = strtolower(trim($in['email'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $barangay = trim($in['barangay'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('Name and a valid email are required.', 422);
        }
        if (strlen($password) < 8) $this->api->respond_error('Password must be at least 8 characters.', 422);
        if ($barangay === '') $this->api->respond_error('Barangay is required.', 422);
        if ($this->User_model->find_by_email($email)) $this->api->respond_error('An account with this email already exists.', 409);

        $id = $this->User_model->create_resident([
            'name' => $name, 'email' => $email, 'barangay' => $barangay,
            'phone' => $in['phone'] ?? null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        eco_audit($id, 'user_registered', "user:$id");
        $this->respond_session($this->User_model->find_by_id($id), 201);
    }

    public function login()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 900);

        $in = $this->api->body();
        $user = $this->User_model->find_by_email(strtolower(trim($in['email'] ?? '')));
        if (!$user || !password_verify((string) ($in['password'] ?? ''), $user['password'])) {
            $this->api->respond_error('Invalid email or password.', 401);
        }
        eco_audit((int) $user['id'], 'login');
        $this->respond_session($user);
    }

    /** Exchanges a refresh token for a new access/refresh pair (the old refresh token is revoked). */
    public function refresh()
    {
        $this->api->require_method('POST');
        $in = $this->api->body();
        $this->api->refresh_access_token((string) ($in['refresh_token'] ?? ''));
    }

    public function logout()
    {
        $this->api->require_method('POST');
        $in = $this->api->body();
        if (!empty($in['refresh_token'])) {
            $this->api->revoke_refresh_token((string) $in['refresh_token']);
        }
        $this->api->respond(['ok' => true]);
    }

    public function me()
    {
        $this->api->require_method('GET');
        $user = eco_current_user();
        $this->api->respond(['user' => eco_public_user($user)]);
    }

    private function respond_session(array $user, int $status = 200): void
    {
        $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => $user['role']]);
        $this->api->respond(['tokens' => $tokens, 'user' => eco_public_user($user)], $status);
    }

    /* ---------- Continue with Facebook (OAuth 2.0 authorization-code flow) ---------- */

    /** Sends the browser to Facebook's consent screen. */
    public function facebook()
    {
        $this->api->require_method('GET');

        if ($this->facebook_app_id() === '' || $this->facebook_app_secret() === '') {
            $this->facebook_fail('Hindi pa naka-configure ang Facebook login. Idagdag ang FACEBOOK_APP_ID at FACEBOOK_APP_SECRET sa backend .env.');
        }

        $state = $this->facebook_sign_state([
            'n' => bin2hex(random_bytes(16)),
            't' => time(),
            'f' => $this->facebook_frontend_origin(),
        ]);

        $query = http_build_query([
            'client_id'     => $this->facebook_app_id(),
            'redirect_uri'  => $this->facebook_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'email,public_profile',
            'state'         => $state,
        ]);

        header('Location: https://www.facebook.com/' . $this->facebook_graph_version() . '/dialog/oauth?' . $query);
        exit;
    }

    /** Facebook redirects here with a code; we trade it for a profile and log the user in. */
    public function facebookCallback()
    {
        $this->api->require_method('GET');

        $payload = $this->facebook_verify_state((string) ($_GET['state'] ?? ''));
        if (!$payload) {
            $this->facebook_fail('Expired o invalid ang Facebook login request. Subukan ulit.');
        }

        if (!empty($_GET['error'])) {
            $this->facebook_fail('Kinansela ang Facebook login.');
        }

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            $this->facebook_fail('Walang authorization code mula sa Facebook.');
        }

        $token = $this->facebook_get_json('https://graph.facebook.com/' . $this->facebook_graph_version() . '/oauth/access_token?' . http_build_query([
            'client_id'     => $this->facebook_app_id(),
            'client_secret' => $this->facebook_app_secret(),
            'redirect_uri'  => $this->facebook_redirect_uri(),
            'code'          => $code,
        ]));

        if (empty($token['access_token'])) {
            $this->facebook_fail('Hindi nakuha ang Facebook access token. Subukan ulit.');
        }

        $profile = $this->facebook_get_json('https://graph.facebook.com/' . $this->facebook_graph_version() . '/me?' . http_build_query([
            'fields'       => 'id,name,email',
            'access_token' => $token['access_token'],
        ]));

        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $name  = trim((string) ($profile['name'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->facebook_fail('Kailangan i-allow ang email permission sa Facebook para makapag-login.');
        }

        $user = $this->User_model->find_by_email($email);
        if (!$user) {
            // Facebook users have no EcoBin password yet; they can set one via Forgot password.
            $id = $this->User_model->create_resident([
                'name'          => $name !== '' ? $name : strtok($email, '@'),
                'email'         => $email,
                'phone'         => null,
                'barangay'      => 'Unassigned',
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
            ]);
            $user = $this->User_model->find_by_id($id);
            eco_audit($id, 'user_registered', "user:$id", 'via facebook');
        }

        eco_audit((int) $user['id'], 'login', null, 'via facebook');
        $tokens = $this->api->issue_tokens(['id' => (int) $user['id'], 'role' => $user['role']]);

        $frontend = rtrim((string) ($payload['f'] ?? ''), '/');
        if ($frontend === '') $frontend = $this->facebook_frontend_origin();

        header('Location: ' . $frontend . '/login#' . http_build_query([
            'fb_access_token'  => $tokens['access_token'],
            'fb_refresh_token' => $tokens['refresh_token'],
        ]));
        exit;
    }

    private function facebook_app_id(): string
    {
        return trim((string) config_item('facebook_app_id'));
    }

    private function facebook_app_secret(): string
    {
        return trim((string) config_item('facebook_app_secret'));
    }

    private function facebook_graph_version(): string
    {
        $version = trim((string) config_item('facebook_graph_version'));
        return $version !== '' ? $version : 'v23.0';
    }

    private function facebook_is_https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function facebook_redirect_uri(): string
    {
        $configured = trim((string) config_item('facebook_redirect_uri'));
        if ($configured !== '') return $configured;

        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($this->facebook_is_https() ? 'https://' : 'http://') . $host . '/api/auth/facebook/callback';
    }

    /** Frontend origins allowed by CORS; entries without a scheme are treated as https. */
    private function facebook_frontend_origins(): array
    {
        $origins = [];
        foreach (explode(',', (string) getenv('CORS_ORIGIN')) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') continue;
            if (!preg_match('~^https?://~i', $origin)) $origin = 'https://' . $origin;
            $origins[] = $origin;
        }
        return array_values(array_unique($origins));
    }

    private function facebook_frontend_origin(): string
    {
        $configured = rtrim(trim((string) config_item('frontend_url')), '/');
        if ($configured !== '') return $configured;

        $origins = $this->facebook_frontend_origins();

        // The browser sends the login page as the referrer, which tells us whether
        // to return to localhost during development or the deployed frontend.
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
                foreach ($origins as $allowed) {
                    if (strtolower($allowed) === $origin) return $allowed;
                }
            }
        }

        // No usable referrer: pick an origin that fits the current environment.
        $local = [];
        $remote = [];
        foreach ($origins as $origin) {
            if (preg_match('~^https?://(localhost|127\.0\.0\.1)(:\d+)?$~i', $origin)) {
                $local[] = $origin;
            } else {
                $remote[] = $origin;
            }
        }
        $preferred = $this->facebook_is_https() ? $remote : $local;

        return $preferred[0] ?? $origins[0] ?? 'http://localhost:5173';
    }

    private function facebook_state_secret(): string
    {
        $secret = trim((string) config_item('facebook_state_secret'));
        if ($secret === '') $secret = (string) getenv('REFRESH_TOKEN_KEY');
        if ($secret === '') $secret = (string) getenv('JWT_SECRET');
        return $secret;
    }

    private function facebook_sign_state(array $data): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $this->facebook_state_secret());
    }

    private function facebook_verify_state(string $state): ?array
    {
        $parts = explode('.', $state);
        if (count($parts) !== 2) return null;

        [$payload, $signature] = $parts;
        $expected = hash_hmac('sha256', $payload, $this->facebook_state_secret());
        if (!hash_equals($expected, $signature)) return null;

        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['t']) || (time() - (int) $data['t']) > 600) return null;

        return $data;
    }

    /** Returns an error to the login page instead of leaving the user on a blank API response. */
    private function facebook_fail(string $message): void
    {
        header('Location: ' . $this->facebook_frontend_origin() . '/login#fb_error=' . rawurlencode($message));
        exit;
    }

    private function facebook_get_json(string $url): array
    {
        if (!function_exists('curl_init')) return [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) return [];

        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /** Sends a 6-digit reset code to the email, if the account exists. Always responds the same way. */
 public  function forgotPassword()
    {
        $this->api->require_method('POST');
        $email = strtolower(trim($this->api->body()['email'] ?? ''));
        $generic = ['ok' => true, 'message' => 'Kung may account na naka-rehistro sa email na ito, may ipinadala kaming code.'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->api->respond($generic);

        $user = eco_db_one('SELECT id, name, role FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user) $this->api->respond($generic);

        // Admin accounts: hindi pwedeng i-reset ang password sa paraang ito.
        // Same generic response pa rin para hindi malaman ng nag-request kung admin ang email.
        if (($user['role'] ?? '') === 'admin') {
            eco_audit(null, 'admin_password_reset_blocked', 'user:' . $user['id'], 'forgot-password request');
            $this->api->respond($generic);
        }

        // Cooldown: hindi pwedeng humiling ulit sa loob ng 60 segundo
        $recent = eco_db_one(
            'SELECT id FROM password_resets WHERE email = ? AND created_at > NOW() - INTERVAL 60 SECOND LIMIT 1',
            [$email]
        );
        if ($recent) $this->api->respond($generic);

        // Pawalang-bisa ang mga lumang code
        eco_db_exec('UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0', [$email]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        eco_db_exec(
            'INSERT INTO password_resets (email, code_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)',
            [$email, password_hash($code, PASSWORD_BCRYPT)]
        );

        $html = '<p>Hi ' . htmlspecialchars($user['name']) . ',</p>'
              . '<p>Ang code para i-reset ang password mo ay:</p>'
              . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . $code . '</p>'
              . '<p>Mag-e-expire ito sa loob ng 10 minuto. Kung hindi ikaw ang humiling nito, balewalain lang ang email.</p>';

        eco_send_mail($email, $user['name'], 'EcoBin: Code para i-reset ang password', $html);
        $this->api->respond($generic);
    }

    /** Verifies the code and sets the new password. */
    public function resetPassword()
    {
        $this->api->require_method('POST');
        $in = $this->api->body();
        $email    = strtolower(trim($in['email'] ?? ''));
        $code     = trim((string) ($in['code'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if (strlen($password) < 8) $this->api->respond_error('Dapat at least 8 characters ang password.', 422);

        $row = eco_db_one(
            'SELECT * FROM password_resets
             WHERE email = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [$email]
        );
        if (!$row || (int) $row['attempts'] >= 5) {
            $this->api->respond_error('Invalid o expired na ang code. Humiling ng bago.', 422);
        }

        if (!password_verify($code, $row['code_hash'])) {
            eco_db_exec('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);
            $this->api->respond_error('Mali ang code.', 422);
        }

        // I-block din dito kahit may lumang code na naipadala bago pa ang guard sa forgotPassword.
        $user = eco_db_one('SELECT id, role FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user) $this->api->respond_error('Invalid o expired na ang code. Humiling ng bago.', 422);
        if (($user['role'] ?? '') === 'admin') {
            eco_db_exec('UPDATE password_resets SET used = 1 WHERE email = ?', [$email]);
            eco_audit(null, 'admin_password_reset_blocked', 'user:' . $user['id'], 'reset-password attempt');
            $this->api->respond_error('Hindi pwedeng baguhin ang password ng admin account sa paraang ito. Makipag-ugnayan sa system owner.', 403);
        }

        // Extra guard: kahit may makalusot, hindi talaga mababago ang admin password.
        $changed = eco_db_exec(
            'UPDATE users SET password_hash = ? WHERE email = ? AND role <> ?',
            [password_hash($password, PASSWORD_BCRYPT), $email, 'admin']
        );
        if ($changed < 1) {
            $this->api->respond_error('Hindi pwedeng baguhin ang password ng admin account sa paraang ito. Makipag-ugnayan sa system owner.', 403);
        }
        eco_db_exec('UPDATE password_resets SET used = 1 WHERE email = ?', [$email]);

        $this->api->respond(['ok' => true]);
    }
}