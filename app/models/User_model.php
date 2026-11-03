<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';

    public function find_by_id(int $id): ?array
    {
        return eco_db_one('SELECT id, name, email, phone, barangay, role, eco_id, password_hash AS password FROM users WHERE id = ?', [$id]);
    }

    public function find_by_email(string $email): ?array
    {
        return eco_db_one('SELECT id, name, email, phone, barangay, role, eco_id, password_hash AS password FROM users WHERE email = ?', [$email]);
    }

    public function find_resident_by_eco_id(string $ecoId): ?array
    {
        return eco_db_one('SELECT id, name, eco_id, barangay FROM users WHERE eco_id = ? AND role = "resident"', [$ecoId]);
    }

    public function create_resident(array $d): int
    {
        $ecoId = 'ECO-' . strtoupper(bin2hex(random_bytes(4)));
        return eco_db_insert(
            'INSERT INTO users (username, name, email, password_hash, role, phone, barangay, eco_id) VALUES (?, ?, ?, ?, "resident", ?, ?, ?)',
            [$d['email'], $d['name'], $d['email'], $d['password_hash'], $d['phone'], $d['barangay'], $ecoId]
        );
    }

    public function create_staff(array $d): int
    {
        return eco_db_insert(
            'INSERT INTO users (username, name, email, password_hash, role, phone, barangay) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$d['email'], $d['name'], $d['email'], $d['password_hash'], $d['role'], $d['phone'], $d['barangay']]
        );
    }

    public function list_users(?string $role = null): array
    {
        if ($role) {
            return eco_db_all('SELECT id, name, email, phone, barangay, role, eco_id, created_at FROM users WHERE role = ? ORDER BY name', [$role]);
        }
        return eco_db_all('SELECT id, name, email, phone, barangay, role, eco_id, created_at FROM users ORDER BY role, name');
    }

    public function residents_in_zone(string $zone): array
    {
        return eco_db_all('SELECT id, phone FROM users WHERE role = "resident" AND barangay = ?', [$zone]);
    }
}
