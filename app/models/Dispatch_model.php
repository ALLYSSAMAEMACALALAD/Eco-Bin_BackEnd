<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dispatch_model extends Model
{
    protected $table = 'routes';
    const ROUTE_COLUMNS = 'r.*, u.name AS collector_name FROM routes r LEFT JOIN users u ON u.id = r.collector_id';

    public function all(): array
    {
        return eco_db_all('SELECT ' . self::ROUTE_COLUMNS . ' ORDER BY r.route_date DESC, r.id DESC');
    }

    public function for_collector(int $userId): array
    {
        return eco_db_all('SELECT ' . self::ROUTE_COLUMNS . ' WHERE r.collector_id = ? ORDER BY r.route_date DESC', [$userId]);
    }

    /** Routes that include at least one stop for this resident. */
    public function for_resident(int $userId): array
    {
        return eco_db_all('SELECT DISTINCT ' . self::ROUTE_COLUMNS . ' JOIN route_stops s ON s.route_id = r.id WHERE s.resident_id = ? ORDER BY r.route_date DESC', [$userId]);
    }

    public function find(int $id): ?array
    {
        return eco_db_one('SELECT * FROM routes WHERE id = ?', [$id]);
    }

    public function stops(int $routeId): array
    {
        return eco_db_all('SELECT * FROM route_stops WHERE route_id = ? ORDER BY sequence_no', [$routeId]);
    }

    public function find_stop(int $routeId, int $stopId): ?array
    {
        return eco_db_one('SELECT * FROM route_stops WHERE id = ? AND route_id = ?', [$stopId, $routeId]);
    }

    public function create(string $name, string $date, ?int $collectorId): int
    {
        return eco_db_insert('INSERT INTO routes (name, route_date, collector_id) VALUES (?, ?, ?)', [$name, $date, $collectorId]);
    }

    public function add_stop(int $routeId, int $seq, string $address, ?int $residentId, $lat, $lng): void
    {
        eco_db_insert('INSERT INTO route_stops (route_id, sequence_no, address, resident_id, lat, lng) VALUES (?, ?, ?, ?, ?, ?)',
            [$routeId, $seq, $address, $residentId, $lat, $lng]);
    }

    public function assign(int $routeId, int $collectorId): void
    {
        eco_db_exec('UPDATE routes SET collector_id = ? WHERE id = ?', [$collectorId, $routeId]);
    }

    public function set_location(int $routeId, float $lat, float $lng): void
    {
        eco_db_exec("UPDATE routes SET truck_lat = ?, truck_lng = ?, status = IF(status = 'pending', 'in_progress', status) WHERE id = ?",
            [$lat, $lng, $routeId]);
    }

    public function complete_stop(int $stopId): void
    {
        eco_db_exec("UPDATE route_stops SET status = 'completed', completed_at = NOW() WHERE id = ?", [$stopId]);
    }

    public function pending_stop_count(int $routeId): int
    {
        return (int) eco_db_one("SELECT COUNT(*) AS n FROM route_stops WHERE route_id = ? AND status = 'pending'", [$routeId])['n'];
    }

    public function complete_route(int $routeId): void
    {
        eco_db_exec("UPDATE routes SET status = 'completed' WHERE id = ?", [$routeId]);
    }
}
