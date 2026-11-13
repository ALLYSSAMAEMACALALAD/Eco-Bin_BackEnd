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

    /** Staff look a resident up by eco-ID before logging recyclables. Step 2 of the flow: registered and active accounts only. */
    public function lookup($ecoId)
    {
        $this->api->require_method('GET');
        eco_current_user(['collector', 'admin']);
        $u = $this->User_lookup(strtoupper($ecoId));
        if (!$u) $this->api->respond_error('No resident found for this eco-ID.', 404);
        if (!(int) ($u['is_active'] ?? 0)) $this->api->respond_error('This resident account is inactive. Transaction blocked.', 403);
        $u['balance'] = $this->Reward_model->balance_for((int) $u['id']);
        $this->api->respond(['resident' => $u]);
    }

    /**
     * Step 4 of the collection flow: check whether a transaction reference already exists
     * before the collector confirms. Route: GET /dropoffs/transactions/{ref}
     */
    public function transactions($txnRef)
    {
        $this->api->require_method('GET');
        eco_current_user(['collector', 'admin']);

        $txn = trim((string) $txnRef);
        if ($txn === '' || strlen($txn) > 64) {
            $this->api->respond_error('Invalid transaction ID.', 422);
        }

        $exists = (bool) $this->Dropoff_model->find_by_txn($txn);
        $this->api->respond(['exists' => $exists]);
    }

    /**
     * Logs a verified recyclable drop-off and credits points.
     * The transaction ID comes from the body (transaction_id), or from the Idempotency-Key header as a fallback.
     * A repeated transaction ID returns 409, so a retry or double-submit cannot add points twice.
     * The DB UNIQUE index on txn_ref is the final guard against race conditions.
     */
    public function store()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['collector', 'admin']);

        // Multipart requests (photo upload) put fields in $_POST; JSON clients send a body.
        $isMultipart = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
        $in = $isMultipart ? $_POST : (array) $this->api->body();
        $txn = trim($in['transaction_id'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($txn === '' || strlen($txn) > 64) {
            $this->api->respond_error('A transaction ID (max 64 characters) is required.', 422);
        }

        $material = $in['material'] ?? '';
        $materials = config_item('dropoff_materials');
        if (!isset($materials[$material])) $this->api->respond_error('Unknown material.', 422);

        $spec = $materials[$material];
        $unit = $spec['unit'];
        $quantity = (float) ($in['quantity'] ?? $in['weight_kg'] ?? 0);
        $max = $unit === 'pcs' ? 10000 : 500;
        if ($quantity <= 0 || $quantity > $max) {
            $this->api->respond_error(
                $unit === 'pcs' ? 'Count must be between 1 and 10,000 pieces.' : 'Weight must be between 0 and 500 kg.',
                422
            );
        }
        if ($unit === 'pcs' && floor($quantity) !== $quantity) {
            $this->api->respond_error('Count must be a whole number of pieces.', 422);
        }

        $resident = $this->User_lookup(strtoupper(trim((string) ($in['eco_id'] ?? ''))));
        if (!$resident) $this->api->respond_error('No resident found for this eco-ID.', 404);
        if (!(int) ($resident['is_active'] ?? 0)) $this->api->respond_error('This resident account is inactive. Transaction blocked.', 403);

        // Step 5 (fast path): reject a transaction id that already exists before doing any work.
        if ($this->Dropoff_model->find_by_txn($txn)) {
            $this->api->respond_error('Transaction already exists!', 409);
        }

        // Recyclables must be photographed before they are credited.
        if (empty($_FILES['photo']['tmp_name'])) {
            $this->api->respond_error('A photo of the recyclables is required.', 422);
        }
        // Upload outside the DB transaction: it is a network call, not a database write.
        $photoUrl = eco_store_photo($_FILES['photo']);

        $weight = $unit === 'kg' ? $quantity : 0;
        $points = round($quantity * $spec['points'], 2);

        $db = lava_instance()->db;
        $db->transaction();
        try {
            // Step 5 (inside the transaction): another request may have committed since the fast check.
            if ($this->Dropoff_model->find_by_txn($txn)) {
                throw new RuntimeException('duplicate_txn');
            }
            $id = $this->Dropoff_model->create([
                'txn_ref' => $txn,
                'resident_id' => (int) $resident['id'],
                'hub_id' => !empty($in['hub_id']) ? (int) $in['hub_id'] : null,
                'collector_id' => (int) $user['id'],
                'material' => $material,
                'unit' => $unit,
                'quantity' => $quantity,
                'weight_kg' => $weight,
                'points' => $points,
                'photo_url' => $photoUrl,
            ]);
            $unitLabel = $unit === 'pcs' ? 'pcs' : 'kg';
            eco_audit(
                (int) $user['id'],
                'dropoff_logged',
                "dropoff:$id",
                "resident:{$resident['id']} $material {$quantity}$unitLabel +$points pts ref:$txn"
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->roll_back();
            // Duplicate txn_ref — either detected here or rejected by the UNIQUE index on txn_ref.
            if ($e->getMessage() === 'duplicate_txn' || $this->is_duplicate_key($e)) {
                $this->api->respond_error('Transaction already exists!', 409);
            }
            error_log('dropoff store failed: ' . $e->getMessage());
            $this->api->respond_error('Unable to save this transaction. Please try again.', 500);
        }

        $this->api->respond([
            'id' => $id,
            'txn_ref' => $txn,
            'points' => $points,
            'resident' => $resident['name'],
            'unit' => $unit,
            'quantity' => $quantity,
            'photo_url' => $photoUrl,
            'balance' => $this->Reward_model->balance_for((int) $resident['id']),
        ], 201);
    }

    /** True when the database rejected the write because txn_ref already exists (unique constraint). */
    private function is_duplicate_key(\Throwable $e): bool
    {
        $code = (string) $e->getCode();
        $msg = $e->getMessage();
        return $code === '23000' || str_contains($msg, '1062') || stripos($msg, 'Duplicate entry') !== false;
    }

    private function User_lookup(string $ecoId): ?array
    {
        return eco_db_one('SELECT id, name, eco_id, barangay, is_active FROM users WHERE eco_id = ? AND role = ?', [$ecoId, 'resident']);
    }
}