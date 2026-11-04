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

    /** Queues an SMS and email reminder for every resident in the zone, and sends SMS when a provider is set. */
    public function remind()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $in = $this->api->body();
        $zone = trim($in['zone'] ?? '');
        $when = trim($in['when'] ?? 'tomorrow');
        if ($zone === '') $this->api->respond_error('Zone is required.', 422);

        $residents = $this->User_model->residents_in_zone($zone);
        $message = "Eco-Bin reminder: garbage collection in $zone is scheduled for $when. Please have your waste ready.";
        foreach ($residents as $r) {
            $this->Schedule_model->queue_notification((int) $r['id'], 'email', $message);
            if ($r['phone']) {
                $this->Schedule_model->queue_notification((int) $r['id'], 'sms', $message);
                eco_send_sms($r['phone'], $message);
            }
        }
        eco_audit((int) $user['id'], 'reminders_sent', "zone:$zone", count($residents) . ' residents');
        $this->api->respond(['queued' => count($residents)]);
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
