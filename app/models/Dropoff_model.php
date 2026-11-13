<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dropoff_model extends Model
{
    protected $table = 'dropoff_logs';

    public function hubs(): array
    {
        return eco_db_all('SELECT * FROM hubs ORDER BY name');
    }

    public function for_resident(int $userId): array
    {
        return eco_db_all('SELECT d.*, h.name AS hub_name FROM dropoff_logs d LEFT JOIN hubs h ON h.id = d.hub_id WHERE d.resident_id = ? ORDER BY d.created_at DESC', [$userId]);
    }

    public function recent(int $limit = 100): array
    {
        return eco_db_all('SELECT d.*, h.name AS hub_name, u.name AS resident_name, u.eco_id
            FROM dropoff_logs d LEFT JOIN hubs h ON h.id = d.hub_id JOIN users u ON u.id = d.resident_id
            ORDER BY d.created_at DESC LIMIT ' . (int) $limit);
    }

    public function find_by_txn(string $txn): ?array
    {
        return eco_db_one('SELECT id, points FROM dropoff_logs WHERE txn_ref = ?', [$txn]);
    }

    public function create(array $d): int
    {
        return eco_db_insert('INSERT INTO dropoff_logs (txn_ref, resident_id, hub_id, collector_id, material, unit, quantity, weight_kg, points, photo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['txn_ref'], $d['resident_id'], $d['hub_id'], $d['collector_id'], $d['material'], $d['unit'], $d['quantity'], $d['weight_kg'], $d['points'], $d['photo_url'] ?? null]);
    }
}
