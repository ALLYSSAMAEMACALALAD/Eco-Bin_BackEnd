<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Recyclable drop-offs: staff scan a resident's QR eco-ID and credit points, once per transaction. */
class Dropoffs extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('Dropoff_model');
        $this->call->model('Reward_model');
    }

    public function hubs()
    {
        $this->api->require_method('GET');
        eco_current_user(['resident', 'collector', 'admin']);
        $this->api->respond(['hubs' => $this->Dropoff_model->hubs()]);
    }

    /** Residents see their own drop-offs; staff see the latest 100 across all residents. */
    public function index()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'collector', 'admin']);
        $rows = $user['role'] === 'resident'
            ? $this->Dropoff_model->for_resident((int) $user['id'])
            : $this->Dropoff_model->recent(100);
        $this->api->respond(['dropoffs' => $rows]);
    }

    /** Staff look a resident up by eco-ID before logging recyclables. */
    public function lookup($ecoId)
    {
        $this->api->require_method('GET');
        eco_current_user(['collector', 'admin']);
        $u = $this->User_lookup(strtoupper($ecoId));
        if (!$u) $this->api->respond_error('No resident found for this eco-ID.', 404);
        $u['balance'] = $this->Reward_model->balance_for((int) $u['id']);
        $this->api->respond(['resident' => $u]);
    }

    /**
     * Logs a verified recyclable drop-off and credits points.
     * Requires an Idempotency-Key header (a unique transaction reference from the client). A repeated
     * key returns 409 with the original credit, so a retry or double-submit cannot add points twice.
     */
    public function store()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['collector', 'admin']);

        $txn = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($txn === '' || strlen($txn) > 64) $this->api->respond_error('An Idempotency-Key header (max 64 characters) is required.', 422);

        $in = $this->api->body();
        $material = $in['material'] ?? '';
        $weight   = (float) ($in['weight_kg'] ?? 0);
        $rates    = config_item('points_per_kg');
        if (!isset($rates[$material])) $this->api->respond_error('Unknown material.', 422);
        if ($weight <= 0 || $weight > 500) $this->api->respond_error('Weight must be between 0 and 500 kg.', 422);

        $resident = $this->User_lookup(strtoupper(trim($in['eco_id'] ?? '')));
        if (!$resident) $this->api->respond_error('No resident found for this eco-ID.', 404);

        $existing = $this->Dropoff_model->find_by_txn($txn);
        if ($existing) {
            $this->api->respond(['duplicate' => true, 'id' => (int) $existing['id'], 'points' => (int) $existing['points']], 409);
        }

        $points = (int) floor($weight * $rates[$material]);
        $id = $this->Dropoff_model->create([
            'txn_ref' => $txn, 'resident_id' => (int) $resident['id'],
            'hub_id' => !empty($in['hub_id']) ? (int) $in['hub_id'] : null,
            'collector_id' => (int) $user['id'], 'material' => $material, 'weight_kg' => $weight, 'points' => $points,
        ]);
        eco_audit((int) $user['id'], 'dropoff_logged', "dropoff:$id", "resident:{$resident['id']} $material {$weight}kg +$points pts");
        $this->api->respond([
            'id' => $id, 'points' => $points, 'resident' => $resident['name'],
            'balance' => $this->Reward_model->balance_for((int) $resident['id']),
        ], 201);
    }

    private function User_lookup(string $ecoId): ?array
    {
        return eco_db_one('SELECT id, name, eco_id, barangay FROM users WHERE eco_id = ? AND role = ?', [$ecoId, 'resident']);
    }
}
