<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dumping_model extends Model
{
    protected $table = 'dumping_reports';
    const COLUMNS = 'd.*, u.name AS resident_name FROM dumping_reports d JOIN users u ON u.id = d.resident_id';

    public function for_resident(int $userId): array
    {
        return eco_db_all('SELECT * FROM dumping_reports WHERE resident_id = ? ORDER BY created_at DESC', [$userId]);
    }

    public function all(?string $status = null): array
    {
        if ($status) {
            return eco_db_all('SELECT ' . self::COLUMNS . ' WHERE d.status = ? ORDER BY d.created_at', [$status]);
        }
        return eco_db_all('SELECT ' . self::COLUMNS . ' ORDER BY d.created_at DESC');
    }

    public function find(int $id): ?array
    {
        return eco_db_one('SELECT ' . self::COLUMNS . ' WHERE d.id = ?', [$id]);
    }

    public function create(string $ticket, int $residentId, string $location, ?string $description, ?string $photoUrl): int
    {
        return eco_db_insert('INSERT INTO dumping_reports (ticket_no, resident_id, location, description, photo_url) VALUES (?, ?, ?, ?, ?)',
            [$ticket, $residentId, $location, $description, $photoUrl]);
    }

    public function set_status(int $id, string $status, int $by): void
    {
        eco_db_exec('UPDATE dumping_reports SET status = ?, handled_by = ? WHERE id = ?', [$status, $by, $id]);
    }
}
