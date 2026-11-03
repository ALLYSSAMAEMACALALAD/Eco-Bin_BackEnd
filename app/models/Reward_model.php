<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Reward_model extends Model
{
    protected $table = 'redemptions';

    public function active_rewards(): array
    {
        return eco_db_all('SELECT * FROM rewards WHERE is_active = 1 ORDER BY points_cost');
    }

    public function find_reward(int $id): ?array
    {
        return eco_db_one('SELECT * FROM rewards WHERE id = ? AND is_active = 1', [$id]);
    }

    /** Points earned from drop-offs minus points held by pending or approved redemptions (rejected ones are refunded). */
    public function balance_for(int $residentId): int
    {
        $earned = (int) eco_db_one('SELECT COALESCE(SUM(points),0) AS n FROM dropoff_logs WHERE resident_id = ?', [$residentId])['n'];
        $spent  = (int) eco_db_one('SELECT COALESCE(SUM(points_cost),0) AS n FROM redemptions WHERE resident_id = ? AND status <> "rejected"', [$residentId])['n'];
        return $earned - $spent;
    }

    public function for_resident(int $userId): array
    {
        return eco_db_all('SELECT r.*, w.name AS reward_name, u.name AS resident_name FROM redemptions r
            JOIN rewards w ON w.id = r.reward_id JOIN users u ON u.id = r.resident_id
            WHERE r.resident_id = ? ORDER BY r.created_at DESC', [$userId]);
    }

    public function all(): array
    {
        return eco_db_all('SELECT r.*, w.name AS reward_name, u.name AS resident_name FROM redemptions r
            JOIN rewards w ON w.id = r.reward_id JOIN users u ON u.id = r.resident_id ORDER BY r.created_at DESC');
    }

    public function create(int $residentId, array $reward, string $code): int
    {
        return eco_db_insert('INSERT INTO redemptions (resident_id, reward_id, points_cost, certificate_code) VALUES (?, ?, ?, ?)',
            [$residentId, $reward['id'], $reward['points_cost'], $code]);
    }

    public function find(int $id): ?array
    {
        return eco_db_one('SELECT r.*, w.name AS reward_name, u.name AS resident_name, u.eco_id, u.id AS owner_id
            FROM redemptions r JOIN rewards w ON w.id = r.reward_id JOIN users u ON u.id = r.resident_id WHERE r.id = ?', [$id]);
    }

    public function decide(int $id, string $decision, int $by): void
    {
        eco_db_exec('UPDATE redemptions SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?', [$decision, $by, $id]);
    }

    public function impact_for(int $residentId): array
    {
        return eco_db_all('SELECT material, ROUND(SUM(weight_kg),2) AS kg FROM dropoff_logs WHERE resident_id = ? GROUP BY material', [$residentId]);
    }
}
