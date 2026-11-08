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

    /** Sends a 6-digit reset code to the email, if the account exists. Always responds the same way. */
 public  function forgotPassword()
    {
        $this->api->require_method('POST');
        $email = strtolower(trim($this->api->body()['email'] ?? ''));
        $generic = ['ok' => true, 'message' => 'Kung may account na naka-rehistro sa email na ito, may ipinadala kaming code.'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->api->respond($generic);

        $user = eco_db_one('SELECT id, name FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user) $this->api->respond($generic);

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

        eco_db_exec(
            'UPDATE users SET password_hash = ? WHERE email = ?',
            [password_hash($password, PASSWORD_BCRYPT), $email]
        );
        eco_db_exec('UPDATE password_resets SET used = 1 WHERE email = ?', [$email]);

        $this->api->respond(['ok' => true]);
    }
}