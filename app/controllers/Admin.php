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

    public function auditLogs()
    {
        $this->api->require_method('GET');
        eco_current_user(['admin']);
        $this->api->respond(['logs' => $this->Audit_model->latest(200)]);
    }
}
