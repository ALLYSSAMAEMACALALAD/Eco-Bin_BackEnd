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
