<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Schedule_model extends Model
{
    protected $table = 'schedules';
    const DAY_ORDER = "FIELD(collection_day,'Mon','Tue','Wed','Thu','Fri','Sat','Sun')";

    public function all(): array
    {
        return eco_db_all('SELECT * FROM schedules ORDER BY zone, ' . self::DAY_ORDER);
    }

    public function for_zone(string $zone): array
    {
        return eco_db_all('SELECT * FROM schedules WHERE zone = ? ORDER BY ' . self::DAY_ORDER, [$zone]);
    }

    public function for_resident(string $zone): array
    {
        $schedules = $zone !== '' ? $this->for_zone($zone) : [];
        return $schedules ?: $this->all();
    }

    public function create(array $s, int $by): int
    {
        return eco_db_insert('INSERT INTO schedules (zone, collection_day, time_slot, waste_type, created_by) VALUES (?, ?, ?, ?, ?)',
            [$s['zone'], $s['collection_day'], $s['time_slot'], $s['waste_type'], $by]);
    }

    public function update(int $id, array $s): int
    {
        return eco_db_exec('UPDATE schedules SET zone = ?, collection_day = ?, time_slot = ?, waste_type = ? WHERE id = ?',
            [$s['zone'], $s['collection_day'], $s['time_slot'], $s['waste_type'], $id]);
    }

    public function delete(int $id): int
    {
        return eco_db_exec('DELETE FROM schedules WHERE id = ?', [$id]);
    }

    public function queue_notification(int $userId, string $channel, string $message): void
    {
        eco_db_insert('INSERT INTO notifications (user_id, channel, message) VALUES (?, ?, ?)', [$userId, $channel, $message]);
    }
}
