<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Audit_model extends Model
{
    protected $table = 'audit_logs';

    public function latest(int $limit = 200): array
    {
        return eco_db_all(
            'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT ' . (int) $limit
        );
    }
}
