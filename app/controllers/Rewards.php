<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Points, redemptions and PDF certificate data. */
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
     * Resident redeems points. The balance check and insert run in one transaction,
     * with the user row locked so two simultaneous requests cannot spend the same points.
     */
    public function redeem()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['resident']);
        $rewardId = (int) ($this->api->body()['reward_id'] ?? 0);

        eco_db_exec('START TRANSACTION');
        try {
            eco_db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user['id']]);
            $reward = $this->Reward_model->find_reward($rewardId);
            if (!$reward) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('Reward not found.', 404);
            }
            if ($this->Reward_model->balance_for((int) $user['id']) < (int) $reward['points_cost']) {
                eco_db_exec('ROLLBACK');
                $this->api->respond_error('Not enough points for this reward.', 422);
            }
            $code = 'CERT-' . strtoupper(bin2hex(random_bytes(5)));
            $id = $this->Reward_model->create((int) $user['id'], $reward, $code);
            eco_db_exec('COMMIT');
        } catch (\Throwable $e) {
            eco_db_exec('ROLLBACK');
            throw $e;
        }
        eco_audit((int) $user['id'], 'redemption_requested', "redemption:$id", $reward['name']);
        $this->api->respond([
            'id' => $id, 'certificate_code' => $code, 'balance' => $this->Reward_model->balance_for((int) $user['id']),
        ], 201);
    }

    /** Admin approves a redemption at the counter, or rejects it (which refunds the points). */
    public function decide($id)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['admin']);
        $decision = $this->api->body()['decision'] ?? '';
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            $this->api->respond_error('Decision must be approved or rejected.', 422);
        }
        $r = $this->Reward_model->find((int) $id);
        if (!$r) $this->api->respond_error('Redemption not found.', 404);
        if ($r['status'] !== 'pending') $this->api->respond_error('This redemption has already been decided.', 409);

        $this->Reward_model->decide((int) $r['id'], $decision, (int) $user['id']);
        eco_audit((int) $user['id'], "redemption_$decision", "redemption:{$r['id']}");
        $this->api->respond(['ok' => true]);
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
        if ($r['status'] !== 'approved') $this->api->respond_error('The certificate is available after the redemption is approved.', 409);

        $this->api->respond(['certificate' => [
            'code' => $r['certificate_code'], 'resident_name' => $r['resident_name'], 'eco_id' => $r['eco_id'],
            'reward' => $r['reward_name'], 'points_redeemed' => (int) $r['points_cost'],
            'issued_at' => $r['decided_at'], 'impact' => $this->Reward_model->impact_for((int) $r['resident_id']),
        ]]);
    }
}
