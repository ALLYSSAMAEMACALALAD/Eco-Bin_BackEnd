<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Administration: user list, staff account creation, audit log. */
class Admin extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('User_model');
        $this->call->model('Audit_model');
    }

    public function users()
    {
        $this->api->require_method('GET');
        eco_current_user(['admin']);
        $role = $_GET['role'] ?? null;
        if ($role !== null && !in_array($role, ['resident', 'collector', 'admin'], true)) {
            $this->api->respond_error('Invalid role filter.', 422);
        }
        $this->api->respond(['users' => $this->User_model->list_users($role)]);
    }

    /** Admin creates collector or admin accounts. Residents sign up themselves. */
    public function createStaff()
    {
        $this->api->require_method('POST');
        $admin = eco_current_user(['admin']);
        $in = $this->api->body();
        $role     = $in['role'] ?? '';
        $email    = strtolower(trim($in['email'] ?? ''));
        $name     = trim($in['name'] ?? '');
        $password = (string) ($in['password'] ?? '');

        if (!in_array($role, ['collector', 'admin'], true)) $this->api->respond_error('Role must be collector or admin.', 422);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $this->api->respond_error('Name, a valid email and a password of at least 8 characters are required.', 422);
        }
        if ($this->User_model->find_by_email($email)) $this->api->respond_error('An account with this email already exists.', 409);

        $id = $this->User_model->create_staff([
            'name' => $name, 'email' => $email, 'role' => $role,
            'phone' => $in['phone'] ?? null, 'barangay' => $in['barangay'] ?? null,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        eco_audit((int) $admin['id'], 'staff_created', "user:$id", $role);
        $this->api->respond(['id' => $id, 'role' => $role], 201);
    }

    public function updateUser($id)
    {
        $this->api->require_method('PUT');
        $admin = eco_current_user(['admin']);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $user = $this->User_model->find_by_id((int) $id);
        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $in = $this->api->body();
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        $barangay = trim((string) ($in['barangay'] ?? ''));
        $role = $in['role'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->api->respond_error('A name and valid email are required.', 422);
        }
        if (!in_array($role, ['resident', 'collector', 'admin'], true)) {
            $this->api->respond_error('Invalid user role.', 422);
        }
        if ($this->User_model->email_in_use($email, (int) $id)) {
            $this->api->respond_error('An account with this email already exists.', 409);
        }
        if ((int) $id === (int) $admin['id'] && $role !== 'admin') {
            $this->api->respond_error('You cannot change your own admin role.', 422);
        }

        $this->User_model->update_profile((int) $id, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'barangay' => $barangay !== '' ? $barangay : null,
            'role' => $role,
        ]);
        eco_audit((int) $admin['id'], 'user_updated', "user:$id", $role);
        $this->api->respond(['ok' => true]);
    }

    public function auditLogs()
    {
        $this->api->require_method('GET');
        eco_current_user(['admin']);
        $this->api->respond(['logs' => $this->Audit_model->latest(200)]);
    }
}
