<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Community environmental analytics for the admin dashboard. */
class Analytics_model extends Model
{
    protected $table = 'dropoff_logs';

    private function scalar(string $sql): float
    {
        return (float) eco_db_one($sql)['n'];
    }

    public function summary(): array
    {
        return [
            'totals' => [
                'residents'       => (int) $this->scalar("SELECT COUNT(*) AS n FROM users WHERE role = 'resident'"),
                'dropoffs'        => (int) $this->scalar('SELECT COUNT(*) AS n FROM dropoff_logs'),
                'kg_recycled'     => round($this->scalar('SELECT COALESCE(SUM(weight_kg),0) AS n FROM dropoff_logs'), 2),
                'points_issued'   => (int) $this->scalar('SELECT COALESCE(SUM(points),0) AS n FROM dropoff_logs'),
                'points_redeemed' => (int) $this->scalar("SELECT COALESCE(SUM(points_cost),0) AS n FROM redemptions WHERE status = 'approved'"),
            ],
            'recycling_by_material' => eco_db_all('SELECT material, ROUND(SUM(weight_kg),2) AS kg, SUM(points) AS points FROM dropoff_logs GROUP BY material'),
            'dropoffs_last_14_days' => eco_db_all('SELECT DATE(created_at) AS day, ROUND(SUM(weight_kg),2) AS kg FROM dropoff_logs
                WHERE created_at >= NOW() - INTERVAL 14 DAY GROUP BY DATE(created_at) ORDER BY day'),
            'dumping_by_status'     => eco_db_all('SELECT status, COUNT(*) AS n FROM dumping_reports GROUP BY status'),
            'redemptions_by_status' => eco_db_all('SELECT status, COUNT(*) AS n FROM redemptions GROUP BY status'),
            'collection' => [
                'routes_total'     => (int) $this->scalar('SELECT COUNT(*) AS n FROM routes'),
                'routes_completed' => (int) $this->scalar("SELECT COUNT(*) AS n FROM routes WHERE status = 'completed'"),
                'stops_total'      => (int) $this->scalar('SELECT COUNT(*) AS n FROM route_stops'),
                'stops_completed'  => (int) $this->scalar("SELECT COUNT(*) AS n FROM route_stops WHERE status = 'completed'"),
            ],
        ];
    }
}
