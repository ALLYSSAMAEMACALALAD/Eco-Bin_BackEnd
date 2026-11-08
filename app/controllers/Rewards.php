<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Points, redemptions, reward inventory and PDF certificate data. */
class Rewards extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('Reward_model');
    }

    public function rewards()
    {
        $this->api->require_method('GET');
        eco_current_user(['resident', 'collector', 'admin']);
        $this->api->respond(['rewards' => $this->Reward_model->active_rewards()]);
    }

    public function balance()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident']);
        $this->api->respond(['balance' => $this->Reward_model->balance_for((int) $user['id'])]);
    }

    /** Residents see their own redemptions; admins see all. */
    public function index()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'admin']);
        $rows = $user['role'] === 'admin'
            ? $this->Reward_model->all()
            : $this->Reward_model->for_resident((int) $user['id']);
        $this->api->respond(['redemptions' => $rows]);
    }

    /**
     * Resident redeems points.
     * Locks the user row and the reward row, checks points and stock,
     * then moves 1 unit from quantity_available to quantity_reserved.
     * Everything runs in one transaction.
     */
    public function redeem()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['resident']);
        $rewardId = (int) ($this->api->body()['reward_id'] ?? 0);

        eco_db_exec('START TRANSACTION');
        try {
            eco_db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user['id']]);

            $reward = eco_db_one('SELECT * FROM rewards WHERE id = ? FOR UPDATE', [$rewardId]);
            if (!$reward) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('Reward not found.', 404);
            }

            if ($this->Reward_model->balance_for((int) $user['id']) < (int) $reward['points_cost']) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('Not enough points for this reward.', 422);
            }

            if ((int) $reward['quantity_available'] <= 0) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('This reward is currently out of stock.', 409);
            }

            eco_db_exec(
                'UPDATE rewards
                 SET quantity_available = quantity_available - 1,
                     quantity_reserved  = quantity_reserved + 1,
                     updated_at = NOW()
                 WHERE id = ?',
                [$rewardId]
            );

            $code = 'CERT-' . strtoupper(bin2hex(random_bytes(5)));
            $id = $this->Reward_model->create((int) $user['id'], $reward, $code);

            eco_db_exec('COMMIT');
        } catch (\Throwable $e) {
            eco_db_exec('ROLLBACK');
            throw $e;
        }

        eco_audit((int) $user['id'], 'redemption_requested', "redemption:$id", $reward['name']);
        $this->api->respond([
            'id' => $id,
            'certificate_code' => $code,
            'balance' => $this->Reward_model->balance_for((int) $user['id']),
        ], 201);
    }

    /**
     * Admin approves or rejects a redemption.
     * Approved: reserved -1, redeemed +1.
     * Rejected: reserved -1, available +1 (stock returned), points refunded by the model.
     */
    public function decide($id)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['admin']);
        $decision = $this->api->body()['decision'] ?? '';

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            $this->api->respond_error('Decision must be approved or rejected.', 422);
        }

        eco_db_exec('START TRANSACTION');
        try {
            $r = eco_db_one('SELECT * FROM redemptions WHERE id = ? FOR UPDATE', [(int) $id]);
            if (!$r) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('Redemption not found.', 404);
            }
            if ($r['status'] !== 'pending') {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('This redemption has already been decided.', 409);
            }

            eco_db_one('SELECT id FROM rewards WHERE id = ? FOR UPDATE', [(int) $r['reward_id']]);

            if ($decision === 'approved') {
                eco_db_exec(
                    'UPDATE rewards
                     SET quantity_reserved = quantity_reserved - 1,
                         quantity_redeemed = quantity_redeemed + 1,
                         updated_at = NOW()
                     WHERE id = ?',
                    [(int) $r['reward_id']]
                );
            } else {
                eco_db_exec(
                    'UPDATE rewards
                     SET quantity_reserved = quantity_reserved - 1,
                         quantity_available = quantity_available + 1,
                         updated_at = NOW()
                     WHERE id = ?',
                    [(int) $r['reward_id']]
                );
            }

            $this->Reward_model->decide((int) $r['id'], $decision, (int) $user['id']);

            eco_db_exec('COMMIT');
        } catch (\Throwable $e) {
            eco_db_exec('ROLLBACK');
            throw $e;
        }

        eco_audit((int) $user['id'], "redemption_$decision", "redemption:{$r['id']}");
        $this->api->respond(['ok' => true]);
    }

    /** Admin adds a new reward item. */
    public function createReward()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $in = $this->api->body();

        $name        = trim($in['name'] ?? '');
        $description = trim($in['description'] ?? '');
        $imageUrl    = trim($in['image_url'] ?? '') ?: null;
        $category    = trim($in['category'] ?? '') ?: null;
        $pointsCost  = (int) ($in['points_cost'] ?? -1);
        $qtyAvail    = (int) ($in['quantity_available'] ?? -1);
        $minStock    = (int) ($in['min_stock'] ?? 5);

        if ($name === '') $this->api->respond_error('Reward name is required.', 422);
        if ($pointsCost < 0) $this->api->respond_error('Points required must be 0 or higher.', 422);
        if ($qtyAvail < 0) $this->api->respond_error('Available stock must be 0 or higher.', 422);
        if ($minStock < 0) $this->api->respond_error('Minimum stock must be 0 or higher.', 422);

        eco_db_exec(
            'INSERT INTO rewards
               (name, description, image_url, category, points_cost,
                quantity_available, quantity_reserved, quantity_redeemed, min_stock,
                is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, 1, NOW(), NOW())',
            [$name, $description, $imageUrl, $category, $pointsCost, $qtyAvail, $minStock]
        );
        $row = eco_db_one('SELECT LAST_INSERT_ID() AS id');
        $id = (int) $row['id'];

        eco_audit((int) $user['id'], 'reward_created', "reward:$id", $name);
        $this->api->respond(['id' => $id], 201);
    }

    /**
     * Admin edits reward details and available stock.
     * quantity_reserved and quantity_redeemed are NOT editable here;
     * they are driven by redemptions.
     */
    public function updateReward($id)
    {
        $this->api->require_method('PUT');
        $user = eco_current_user(['admin']);
        $in = $this->api->body();

        $reward = eco_db_one('SELECT * FROM rewards WHERE id = ?', [(int) $id]);
        if (!$reward) $this->api->respond_error('Reward not found.', 404);

        $name        = trim($in['name'] ?? $reward['name']);
        $description = trim($in['description'] ?? (string) $reward['description']);
        $imageUrl    = array_key_exists('image_url', $in) ? (trim((string) $in['image_url']) ?: null) : $reward['image_url'];
        $category    = array_key_exists('category', $in) ? (trim((string) $in['category']) ?: null) : $reward['category'];
        $pointsCost  = (int) ($in['points_cost'] ?? $reward['points_cost']);
        $qtyAvail    = (int) ($in['quantity_available'] ?? $reward['quantity_available']);
        $minStock    = (int) ($in['min_stock'] ?? $reward['min_stock']);

        if ($name === '') $this->api->respond_error('Reward name is required.', 422);
        if ($pointsCost < 0) $this->api->respond_error('Points required must be 0 or higher.', 422);
        if ($qtyAvail < 0) $this->api->respond_error('Available stock must be 0 or higher.', 422);
        if ($minStock < 0) $this->api->respond_error('Minimum stock must be 0 or higher.', 422);

        eco_db_exec(
            'UPDATE rewards
             SET name = ?, description = ?, image_url = ?, category = ?,
                 points_cost = ?, quantity_available = ?, min_stock = ?, updated_at = NOW()
             WHERE id = ?',
            [$name, $description, $imageUrl, $category, $pointsCost, $qtyAvail, $minStock, (int) $id]
        );

        eco_audit((int) $user['id'], 'reward_updated', "reward:$id", $name);
        $this->api->respond(['ok' => true]);
    }

    /**
     * Admin removes a reward. If it has redemptions, it is deactivated (soft delete)
     * so the history stays intact. Otherwise it is deleted.
     */
    public function deleteReward($id)
    {
        $this->api->require_method('DELETE');
        $user = eco_current_user(['admin']);

        $reward = eco_db_one('SELECT * FROM rewards WHERE id = ?', [(int) $id]);
        if (!$reward) $this->api->respond_error('Reward not found.', 404);

        $used = eco_db_one('SELECT COUNT(*) AS n FROM redemptions WHERE reward_id = ?', [(int) $id]);

        if ((int) $used['n'] > 0) {
            eco_db_exec('UPDATE rewards SET is_active = 0, updated_at = NOW() WHERE id = ?', [(int) $id]);
            $mode = 'deactivated';
        } else {
            eco_db_exec('DELETE FROM rewards WHERE id = ?', [(int) $id]);
            $mode = 'deleted';
        }

        eco_audit((int) $user['id'], "reward_$mode", "reward:$id", $reward['name']);
        $this->api->respond(['ok' => true, 'mode' => $mode]);
    }

    /** Data for the PDF certificate and green-impact report. The PDF itself is rendered in the browser. */
    public function certificate($id)
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'admin']);
        $r = $this->Reward_model->find((int) $id);
        if (!$r) $this->api->respond_error('Redemption not found.', 404);
        if ($user['role'] === 'resident' && (int) $r['owner_id'] !== (int) $user['id']) {
            $this->api->respond_error('Not your redemption.', 403);
        }
        if ($r['status'] !== 'approved') {
            $this->api->respond_error('The certificate is available after the redemption is approved.', 409);
        }

        $this->api->respond(['certificate' => [
            'code' => $r['certificate_code'],
            'resident_name' => $r['resident_name'],
            'eco_id' => $r['eco_id'],
            'reward' => $r['reward_name'],
            'points_redeemed' => (int) $r['points_cost'],
            'issued_at' => $r['decided_at'],
            'impact' => $this->Reward_model->impact_for((int) $r['resident_id']),
        ]]);
    }
}