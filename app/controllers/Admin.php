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

    /** Branded dark-green account email (matches other Eco-Bin notification emails). */
    private function account_mail(string $name, string $heading, string $bodyHtml): string
    {
        $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        return '<div style="font-family:\'Segoe UI\',Arial,sans-serif;max-width:560px;margin:0 auto;background:#0e241a;color:#ffffff;border-radius:16px;overflow:hidden">'
            . '<div style="padding:24px 28px 16px;background:linear-gradient(135deg,#174d37,#0e241a);color:#ffffff">'
            . '<div style="font-size:26px;font-weight:800;letter-spacing:-.02em;color:#ffffff">🌱 EcoBin</div>'
            . '<div style="font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#c8f07e;margin-top:4px">Recycle. Earn. Keep it Green.</div>'
            . '</div>'
            . '<div style="padding:22px 28px 26px;color:#ffffff">'
            . '<h1 style="font-size:19px;margin:0 0 14px;color:#ffffff">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="margin:0 0 14px;color:#ffffff">Hi <strong style="color:#ffffff">' . $safeName . '</strong>,</p>'
            . $bodyHtml
            . '<p style="margin:18px 0 0;font-size:12px;color:#ffffff;border-top:1px solid #203a2c;padding-top:14px">'
            . 'If you did not expect this change or believe this is a mistake, please contact your barangay administrator immediately.</p>'
            . '</div>'
            . '</div>';
    }

    /** Sends the given HTML email best-effort. */
    private function send_account_mail(string $to, string $toName, string $subject, string $html): void
    {
        try {
            eco_send_mail($to, $toName, $subject, $html);
        } catch (\Throwable $e) {
            // A mail failure must never block or undo the admin action.
            error_log('EcoBin admin account email failed for ' . $to . ': ' . $e->getMessage());
        }
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
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->api->respond_error('Name, a valid email and a password are required.', 422);
        }
        if ($err = eco_password_policy_error($password)) {
            $this->api->respond_error($err, 422);
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

        // Notify the account holder about exactly what changed (best-effort).
        $changes = [];
        $oldName = (string) ($user['name'] ?? '');
        $oldEmail = (string) ($user['email'] ?? '');
        $oldPhone = (string) ($user['phone'] ?? '');
        $oldBarangay = (string) ($user['barangay'] ?? '');
        $oldRole = (string) ($user['role'] ?? '');
        if ($name !== $oldName) $changes[] = ['Full name', $oldName, $name];
        if (strtolower($email) !== strtolower($oldEmail)) $changes[] = ['Email address', $oldEmail, $email];
        if ($phone !== $oldPhone) $changes[] = ['Mobile number', $oldPhone !== '' ? $oldPhone : '—', $phone !== '' ? $phone : '—'];
        if ($barangay !== $oldBarangay) $changes[] = ['Barangay', $oldBarangay !== '' ? $oldBarangay : '—', $barangay !== '' ? $barangay : '—'];
        if ($role !== $oldRole) $changes[] = ['Role', ucfirst($oldRole), ucfirst($role)];

        if ($changes) {
            $rows = '';
            foreach ($changes as [$label, $before, $after]) {
                $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #203a2c;color:#ffffff">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #203a2c;color:#ffffff">' . htmlspecialchars((string) $before, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="padding:6px 10px;border-bottom:1px solid #203a2c;color:#ffffff"><strong>' . htmlspecialchars((string) $after, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>';
            }
            $body = '<p style="color:#ffffff">An administrator at Eco-Bin has updated your account information. Here is a summary of the changes:</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:13px;color:#ffffff">'
                . '<thead><tr><th style="text-align:left;padding:6px 10px;color:#ffffff">Field</th><th style="text-align:left;padding:6px 10px;color:#ffffff">Before</th><th style="text-align:left;padding:6px 10px;color:#ffffff">After</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
                . '<p style="color:#ffffff">Your new sign-in credentials (if your email address changed) will use the new email address the next time you sign in.</p>';
            $this->send_account_mail(
                $user['email'],
                $name,
                'EcoBin: Your account details were updated',
                $this->account_mail($name, 'Your Eco-Bin account was updated', $body)
            );
        }

        $this->api->respond(['ok' => true]);
    }

    /** Activate or deactivate a user account (cannot deactivate yourself). */
    public function updateStatus($id)
    {
        $this->api->require_method('PATCH');
        $admin = eco_current_user(['admin']);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $user = $this->User_model->find_by_id((int) $id);
        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $raw = $this->api->body()['is_active'] ?? null;
        $active = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $this->api->respond_error('is_active must be a boolean.', 422);
        }
        if ((int) $id === (int) $admin['id'] && !$active) {
            $this->api->respond_error('You cannot deactivate your own account.', 422);
        }

        $this->User_model->set_active((int) $id, $active);
        eco_audit((int) $admin['id'], $active ? 'user_activated' : 'user_deactivated', "user:$id");

        $when = date('F j, Y \a\t g:i A');
        if (!$active) {
            $body = '<p style="color:#ffffff">An administrator at Eco-Bin has <strong style="color:#ffffff">deactivated</strong> your account on ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p style="color:#ffffff">While your account is deactivated you will not be able to sign in or use Eco-Bin services such as checking points, redeeming rewards or reporting dumping.</p>'
                . '<p style="color:#ffffff">If you believe this is a mistake or you have questions, please contact your barangay administrator or the Eco-Bin team.</p>';
            $this->send_account_mail(
                $user['email'],
                (string) $user['name'],
                'EcoBin: Your account has been deactivated',
                $this->account_mail((string) $user['name'], 'Your Eco-Bin account is deactivated', $body)
            );
        } else {
            $body = '<p style="color:#ffffff">Good news — an administrator at Eco-Bin has <strong style="color:#ffffff">reactivated</strong> your account on ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p style="color:#ffffff">You can now sign in again with your usual email address and password and continue using Eco-Bin.</p>';
            $this->send_account_mail(
                $user['email'],
                (string) $user['name'],
                'EcoBin: Your account has been reactivated',
                $this->account_mail((string) $user['name'], 'Your Eco-Bin account is active again', $body)
            );
        }

        $this->api->respond(['ok' => true, 'is_active' => $active]);
    }

    /** Admin sets a new password for any user (never needs the old password). */
    public function resetPassword($id)
    {
        $this->api->require_method('PATCH');
        $admin = eco_current_user(['admin']);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $user = $this->User_model->find_by_id((int) $id);
        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }

        $password = (string) ($this->api->body()['password'] ?? '');
        if ($password === '') {
            $this->api->respond_error('A new password is required.', 422);
        }
        if ($err = eco_password_policy_error($password)) {
            $this->api->respond_error($err, 422);
        }

        $this->User_model->update_password((int) $id, password_hash($password, PASSWORD_BCRYPT));
        eco_audit((int) $admin['id'], 'user_password_reset', "user:$id");
        $this->api->respond(['ok' => true]);
    }

    /** Permanently delete a user (cannot delete your own account). */
    public function deleteUser($id)
    {
        $this->api->require_method('DELETE');
        $admin = eco_current_user(['admin']);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Invalid user ID.', 422);
        }

        $user = $this->User_model->find_by_id((int) $id);
        if (!$user) {
            $this->api->respond_error('User not found.', 404);
        }
        if ((int) $id === (int) $admin['id']) {
            $this->api->respond_error('You cannot delete your own account.', 422);
        }

        $this->User_model->delete_user((int) $id);
        eco_audit((int) $admin['id'], 'user_deleted', "user:$id", $user['role']);

        $when = date('F j, Y \a\t g:i A');
        $ecoId = (string) ($user['eco_id'] ?? '');
        $body = '<p style="color:#ffffff">An administrator at Eco-Bin has <strong style="color:#ffffff">permanently deleted</strong> your account on ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p style="color:#ffffff">Your Eco-ID' . ($ecoId !== '' ? ' <strong style="color:#ffffff">' . htmlspecialchars($ecoId, ENT_QUOTES, 'UTF-8') . '</strong>' : '') . ' and all associated data have been removed. This action cannot be undone.</p>'
            . '<p style="color:#ffffff">If you believe this was done in error, please contact your barangay administrator or the Eco-Bin team immediately.</p>';
        $this->send_account_mail(
            $user['email'],
            (string) $user['name'],
            'EcoBin: Your account has been permanently deleted',
            $this->account_mail((string) $user['name'], 'Your Eco-Bin account is permanently deleted', $body)
        );

        $this->api->respond(['ok' => true]);
    }

    public function auditLogs()
    {
        $this->api->require_method('GET');
        eco_current_user(['admin']);
        $this->api->respond(['logs' => $this->Audit_model->latest(200)]);
    }
}
