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
}
