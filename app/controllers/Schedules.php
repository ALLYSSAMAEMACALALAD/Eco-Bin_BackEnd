<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Schedules extends Controller
{
    const DAYS  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const TYPES = ['biodegradable', 'recyclable', 'residual', 'special'];

    public function before_action()
    {
        eco_boot();
        $this->call->model('Schedule_model');
        $this->call->model('User_model');
    }

    /** Residents see their barangay's schedule; staff see every zone. */
    public function index()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'collector', 'admin']);
        $rows = $user['role'] === 'resident'
            ? $this->Schedule_model->for_resident(trim($user['barangay'] ?? ''))
            : $this->Schedule_model->all();
        $this->api->respond(['schedules' => $rows]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $s = $this->validated($this->api->body());
        $id = $this->Schedule_model->create($s, (int) $user['id']);
        eco_audit((int) $user['id'], 'schedule_created', "schedule:$id");
        $this->api->respond(['id' => $id] + $s, 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');
        $user = eco_current_user(['admin']);
        $s = $this->validated($this->api->body());
        $this->Schedule_model->update((int) $id, $s);
        eco_audit((int) $user['id'], 'schedule_updated', "schedule:$id");
        $this->api->respond(['ok' => true]);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');
        $user = eco_current_user(['admin']);
        $this->Schedule_model->delete((int) $id);
        eco_audit((int) $user['id'], 'schedule_deleted', "schedule:$id");
        $this->api->respond(['ok' => true]);
    }

    /** Sends an email (and SMS when configured) reminder to every resident in the zone. */
    public function remind()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $in = $this->api->body();
        $zone = trim($in['zone'] ?? '');
        $timing = trim((string) ($in['timing'] ?? ''));
        $note = trim((string) ($in['note'] ?? ''));

        $timings = ['tomorrow' => 'tomorrow', 'in_2_days' => 'in 2 days'];
        $when = $timings[$timing] ?? trim((string) ($in['when'] ?? ''));
        if ($zone === '') $this->api->respond_error('Zone is required.', 422);
        if ($when === '') $when = 'tomorrow';
        if (strlen($note) > 1000) $this->api->respond_error('The additional note is too long.', 422);

        $residents = $this->User_model->residents_in_zone($zone);
        $message = "Eco-Bin reminder: garbage collection in $zone is scheduled for $when. Please have your waste ready.";
        if ($note !== '') $message .= "\n\nNote: $note";

        $queued = 0;
        $sent = 0;
        $failed = 0;

        foreach ($residents as $r) {
            $email = trim((string) ($r['email'] ?? ''));
            $name  = trim((string) ($r['name'] ?? '')) ?: $email;
            $phone = trim((string) ($r['phone'] ?? ''));

            // Skip invalid addresses and reserved test domains so they don't hard-bounce.
            $deliverable = filter_var($email, FILTER_VALIDATE_EMAIL)
                && !preg_match('/@(?:[a-z0-9-]+\.)*(test|invalid|example|localhost)$/i', $email);

            if ($deliverable) {
                $queued++;
                $this->Schedule_model->queue_notification((int) $r['id'], 'email', $message);

                $html = '<p>Hi ' . htmlspecialchars($name) . ',</p>'
                      . '<p>Ipinaaalala namin na ang garbage collection sa <strong>' . htmlspecialchars($zone)
                      . '</strong> ay naka-schedule <strong>' . htmlspecialchars($when) . '</strong>.</p>'
                      . '<p>Pakihanda ang inyong basura bago ang collection time.</p>'
                      . ($note !== '' ? '<p><strong>Note:</strong> ' . nl2br(htmlspecialchars($note)) . '</p>' : '')
                      . '<p>Salamat,<br>Eco-Bin</p>';

                if (eco_send_mail($email, $name, 'EcoBin: Garbage collection in ' . $zone . ' is ' . $when, $html)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            if ($phone !== '') {
                $this->Schedule_model->queue_notification((int) $r['id'], 'sms', $message);
                eco_send_sms($phone, $message);
            }
        }

        eco_audit((int) $user['id'], 'reminders_sent', "zone:$zone",
            "$sent sent, $failed failed, $queued recipients");

        $this->api->respond(['ok' => true, 'queued' => $queued, 'sent' => $sent, 'failed' => $failed]);
    }

    private function validated(array $in): array
    {
        $zone = trim($in['zone'] ?? '');
        $day  = $in['collection_day'] ?? '';
        $slot = trim($in['time_slot'] ?? '');
        $type = $in['waste_type'] ?? '';
        if ($zone === '' || !in_array($day, self::DAYS, true) || $slot === '' || !in_array($type, self::TYPES, true)) {
            $this->api->respond_error('Zone, a valid collection day, time slot and waste type are required.', 422);
        }
        return ['zone' => $zone, 'collection_day' => $day, 'time_slot' => $slot, 'waste_type' => $type];
    }
}
