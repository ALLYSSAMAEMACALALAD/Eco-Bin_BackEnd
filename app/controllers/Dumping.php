<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Illegal dumping reports: residents file tickets with a photo, staff work the queue. */
class Dumping extends Controller
{
    const STATUSES = ['open', 'investigating', 'resolved', 'rejected'];

    public function before_action()
    {
        eco_boot();
        $this->call->model('Dumping_model');
    }

    /** Residents see their own tickets; staff see all, optionally filtered with ?status=. */
    public function index()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'collector', 'admin']);
        if ($user['role'] === 'resident') {
            $rows = $this->Dumping_model->for_resident((int) $user['id']);
        } else {
            $status = $_GET['status'] ?? null;
            if ($status !== null && !in_array($status, self::STATUSES, true)) {
                $this->api->respond_error('Invalid status filter.', 422);
            }
            $rows = $this->Dumping_model->all($status);
        }
        $this->api->respond(['reports' => $rows]);
    }

    /** Resident files a report with an optional photo (multipart/form-data). New tickets start as "open". */
    public function store()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['resident']);

        $location    = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($location === '') $this->api->respond_error('Location is required.', 422);

        $photoUrl = !empty($_FILES['photo']['tmp_name']) ? eco_store_photo($_FILES['photo']) : null;

        $ticket = 'DMP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $id = $this->Dumping_model->create($ticket, (int) $user['id'], $location, $description ?: null, $photoUrl);
        eco_audit((int) $user['id'], 'dumping_reported', "report:$id", $ticket);
        $this->api->respond(['id' => $id, 'ticket_no' => $ticket, 'status' => 'open', 'photo_url' => $photoUrl], 201);
    }

    public function show($id)
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'collector', 'admin']);
        $r = $this->Dumping_model->find((int) $id);
        if (!$r) $this->api->respond_error('Report not found.', 404);
        if ($user['role'] === 'resident' && (int) $r['resident_id'] !== (int) $user['id']) {
            $this->api->respond_error('Not your report.', 403);
        }
        $this->api->respond(['report' => $r]);
    }

    /** Staff move a ticket through open, investigating, resolved or rejected. */
    public function updateStatus($id)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['collector', 'admin']);
        $status = $this->api->body()['status'] ?? '';
        if (!in_array($status, self::STATUSES, true)) $this->api->respond_error('Invalid status.', 422);
        $r = $this->Dumping_model->find((int) $id);
        if (!$r) $this->api->respond_error('Report not found.', 404);

        $this->Dumping_model->set_status((int) $r['id'], $status, (int) $user['id']);
        eco_audit((int) $user['id'], 'dumping_status_changed', "report:{$r['id']}", $status);

        // Notify the resident who reported it. Best-effort: a mail failure must not undo the status change.
        try {
            $entry = eco_db_one(
                'SELECT u.name, u.email, d.ticket_no, d.location
                 FROM dumping_reports d JOIN users u ON u.id = d.resident_id WHERE d.id = ?',
                [(int) $r['id']]
            );
            if ($entry) {
                $labels = [
                    'open' => 'Open — awaiting action',
                    'investigating' => 'Investigating — field work started',
                    'resolved' => 'Resolved — completed',
                    'rejected' => 'Rejected — not processed',
                ];
                $statusLabel = $labels[$status] ?? $status;
                $ticket = htmlspecialchars($entry['ticket_no']);
                $local = htmlspecialchars($entry['location']);
                $html = '<p>Hi ' . htmlspecialchars($entry['name']) . ',</p>'
                      . '<p>There is an update on your illegal dumping report:</p>'
                      . '<ul>'
                      . '<li><strong>Ticket:</strong> ' . $ticket . '</li>'
                      . '<li><strong>Location:</strong> ' . $local . '</li>'
                      . '<li><strong>Status:</strong> ' . htmlspecialchars($statusLabel) . '</li>'
                      . '</ul>';
                if ($status === 'resolved') {
                    $html .= '<p>Thank you for being a responsible citizen! 🌱</p>';
                } elseif ($status === 'rejected') {
                    $html .= '<p>If you have questions, please contact the barangay office.</p>';
                } else {
                    $html .= '<p>We will keep following up on your report. Thank you for reporting it.</p>';
                }
                eco_send_mail($entry['email'], $entry['name'], 'EcoBin: Update on your report — ' . $ticket, $html);
            }
        } catch (\Throwable $e) {
            error_log('Dumping report email failed: ' . $e->getMessage());
        }

        $this->api->respond(['ok' => true]);
    }
}
