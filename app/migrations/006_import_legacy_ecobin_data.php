<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Imports records from the archived pre-LavaLust Eco-Bin schema.
 * The legacy_* tables remain untouched as a complete rollback/reference copy.
 */
class Import_legacy_ecobin_data
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $db = $this->_lava->db;
        $forge = $this->_lava->dbforge;

        if (!$forge->table_exists('legacy_users')) {
            return;
        }

        $imports = [
            [
                'legacy_collection_points',
                "INSERT INTO hubs (id, name, address)
                 SELECT point_id, name, address FROM legacy_collection_points
                 ON DUPLICATE KEY UPDATE name = VALUES(name), address = VALUES(address)",
            ],
            [
                'legacy_rewards',
                "INSERT INTO rewards (id, name, points_cost, is_active)
                 SELECT reward_id, name, points_cost, is_active FROM legacy_rewards
                 ON DUPLICATE KEY UPDATE name = VALUES(name), points_cost = VALUES(points_cost), is_active = VALUES(is_active)",
            ],
            [
                'legacy_routes',
                "INSERT IGNORE INTO routes (id, name, route_date, collector_id, status)
                 SELECT r.route_id, r.name, r.route_date,
                    CASE WHEN u.id IS NULL THEN NULL ELSE r.collector_id END,
                    CASE WHEN r.status = 'completed' THEN 'completed'
                         WHEN r.status IN ('in_progress', 'active') THEN 'in_progress'
                         ELSE 'pending' END
                 FROM legacy_routes r LEFT JOIN users u ON u.id = r.collector_id",
            ],
            [
                'legacy_schedules',
                "INSERT IGNORE INTO schedules (id, zone, collection_day, time_slot, waste_type, created_by)
                 SELECT s.schedule_id, COALESCE(NULLIF(p.name, ''), NULLIF(p.address, ''), 'Legacy zone'),
                    CASE WHEN s.collection_date IS NULL THEN 'Mon'
                         ELSE ELT(DAYOFWEEK(s.collection_date), 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat') END,
                    COALESCE(NULLIF(s.time_window, ''), 'Unspecified'),
                    CASE WHEN s.waste_type IN ('biodegradable', 'recyclable', 'residual', 'special') THEN s.waste_type
                         ELSE 'special' END,
                    CASE WHEN u.id IS NULL THEN NULL ELSE s.created_by END
                 FROM legacy_schedules s
                 LEFT JOIN legacy_collection_points p ON p.point_id = s.point_id
                 LEFT JOIN users u ON u.id = s.created_by",
            ],
            [
                'legacy_route_stops',
                "INSERT IGNORE INTO route_stops (id, route_id, sequence_no, address, resident_id, lat, lng, status)
                 SELECT s.stop_id, s.route_id, s.sequence_no,
                    COALESCE(NULLIF(p.address, ''), NULLIF(p.name, ''), CONCAT('Legacy stop ', s.stop_id)),
                    NULL, p.latitude, p.longitude,
                    CASE WHEN s.stop_status IN ('complete', 'completed', 'done') THEN 'completed' ELSE 'pending' END
                 FROM legacy_route_stops s
                 JOIN routes r ON r.id = s.route_id
                 LEFT JOIN legacy_collection_points p ON p.point_id = s.point_id",
            ],
            [
                'legacy_drop_off_logs',
                "INSERT IGNORE INTO dropoff_logs (txn_ref, resident_id, hub_id, collector_id, material, weight_kg, points, created_at)
                 SELECT CONCAT('legacy-', d.log_id), d.resident_id,
                    CASE WHEN h.id IS NULL THEN NULL ELSE d.point_id END, d.recorded_by,
                    CASE WHEN d.material_type IN ('plastic', 'paper', 'metal', 'glass') THEN d.material_type ELSE 'plastic' END,
                    d.weight_kg, d.points_awarded, d.logged_at
                 FROM legacy_drop_off_logs d
                 JOIN users resident ON resident.id = d.resident_id
                 JOIN users collector ON collector.id = d.recorded_by
                 LEFT JOIN hubs h ON h.id = d.point_id",
            ],
            [
                'legacy_redemptions',
                "INSERT IGNORE INTO redemptions (id, resident_id, reward_id, points_cost, status, certificate_code, decided_by, created_at)
                 SELECT r.redemption_id, r.resident_id, r.reward_id, r.points_used,
                    CASE WHEN r.status IN ('approved', 'rejected') THEN r.status ELSE 'pending' END,
                    CONCAT('LEGACY-', r.redemption_id),
                    CASE WHEN u.id IS NULL THEN NULL ELSE r.verified_by END, r.requested_at
                 FROM legacy_redemptions r
                 JOIN users resident ON resident.id = r.resident_id
                 JOIN rewards reward ON reward.id = r.reward_id
                 LEFT JOIN users u ON u.id = r.verified_by",
            ],
            [
                'legacy_dumping_reports',
                "INSERT IGNORE INTO dumping_reports (id, ticket_no, resident_id, location, description, photo_url, status, handled_by, created_at)
                 SELECT d.report_id, CONCAT('LEGACY-', d.report_id), d.resident_id,
                    COALESCE(NULLIF(d.location_text, ''), 'Unspecified location'), d.description, d.photo_url,
                    CASE WHEN d.status IN ('resolved', 'rejected') THEN d.status
                         WHEN d.status IN ('investigating', 'in_progress', 'under_review') THEN 'investigating'
                         ELSE 'open' END,
                    CASE WHEN u.id IS NULL THEN NULL ELSE d.assigned_to END, d.created_at
                 FROM legacy_dumping_reports d
                 JOIN users resident ON resident.id = d.resident_id
                 LEFT JOIN users u ON u.id = d.assigned_to",
            ],
            [
                'legacy_notifications',
                "INSERT IGNORE INTO notifications (id, user_id, channel, message, sent_at)
                 SELECT n.notif_id, n.user_id,
                    CASE WHEN n.channel IN ('sms', 'email') THEN n.channel ELSE 'email' END,
                    n.message, n.sent_at
                 FROM legacy_notifications n JOIN users u ON u.id = n.user_id",
            ],
            [
                'legacy_audit_logs',
                "INSERT IGNORE INTO audit_logs (id, user_id, action, target, details, created_at)
                 SELECT a.log_id, CASE WHEN u.id IS NULL THEN NULL ELSE a.user_id END, a.action,
                    CONCAT(COALESCE(a.table_name, 'legacy'), ':', COALESCE(a.record_id, '')),
                    a.table_name, a.created_at
                 FROM legacy_audit_logs a LEFT JOIN users u ON u.id = a.user_id",
            ],
        ];

        foreach ($imports as [$table, $sql]) {
            if ($forge->table_exists($table)) {
                $db->raw($sql);
            }
        }
    }

    public function down()
    {
        throw new RuntimeException('Imported legacy records are retained in both schemas. Restore the pre-migration backup to roll back.');
    }
}
