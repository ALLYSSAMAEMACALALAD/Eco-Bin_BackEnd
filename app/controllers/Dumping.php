<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Illegal dumping reports: residents file tickets with a photo, staff work the queue. */
class Dumping extends Controller
{
    const STATUSES = ['open', 'investigating', 'resolved', 'rejected'];
    const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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

        $photoUrl = !empty($_FILES['photo']['tmp_name']) ? $this->store_photo($_FILES['photo']) : null;

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
        $this->api->respond(['ok' => true]);
    }

    /** Uploads to Cloudinary when configured; otherwise saves into public/uploads. Returns the URL to store. */
    private function store_photo(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) $this->api->respond_error('Photo upload failed.', 422);
        if ($file['size'] > config_item('upload_max_bytes')) $this->api->respond_error('Photo must be 5 MB or smaller.', 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::IMAGE_TYPES[$mime])) $this->api->respond_error('Photo must be a JPEG, PNG or WebP image.', 422);

        $cloud  = config_item('cloudinary_cloud_name');
        $preset = config_item('cloudinary_upload_preset');
        if ($cloud && $preset) {
            $ch = curl_init("https://api.cloudinary.com/v1_1/$cloud/image/upload");
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($file['tmp_name'], $mime, 'report.' . self::IMAGE_TYPES[$mime]),
                    'upload_preset' => $preset,
                ],
            ]);
            $body = json_decode((string) curl_exec($ch), true);
            curl_close($ch);
            if (!empty($body['secure_url'])) return $body['secure_url'];
            error_log('Cloudinary upload failed: ' . json_encode($body));
            $this->api->respond_error('Photo upload to storage failed. Please try again.', 502);
        }

        $dir = ROOT_DIR . PUBLIC_DIR . '/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = bin2hex(random_bytes(12)) . '.' . self::IMAGE_TYPES[$mime];
        move_uploaded_file($file['tmp_name'], "$dir/$name");
        return "/uploads/$name";
    }
}
