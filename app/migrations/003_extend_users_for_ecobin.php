<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Adds Eco-Bin profile fields to the framework's users table and replaces the default
 * roles (admin, moderator, user) with the three Eco-Bin roles.
 */
class Extend_users_for_ecobin
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

        if ($forge->column_exists('users', 'user_id') && !$forge->column_exists('users', 'id')) {
            $this->archive_legacy_schema($db, $forge);
            return;
        }

        if (!$forge->column_exists('users', 'id')) {
            throw new RuntimeException('Cannot migrate users: neither id nor user_id exists.');
        }

        if (!$forge->column_exists('users', 'name')) {
            $db->raw("ALTER TABLE users ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '' AFTER username");
            if ($forge->column_exists('users', 'full_name')) {
                $db->raw("UPDATE users SET name = full_name WHERE name = ''");
            }
        }
        if (!$forge->column_exists('users', 'phone')) {
            $db->raw("ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL");
        }
        if (!$forge->column_exists('users', 'barangay')) {
            $db->raw("ALTER TABLE users ADD COLUMN barangay VARCHAR(120) NULL");
        }
        if (!$forge->column_exists('users', 'eco_id')) {
            $db->raw("ALTER TABLE users ADD COLUMN eco_id VARCHAR(20) NULL UNIQUE");
        }
        if (!$forge->column_exists('users', 'password_hash')) {
            if (!$forge->column_exists('users', 'password')) {
                throw new RuntimeException('Cannot migrate users: neither password_hash nor password exists.');
            }
            $db->raw("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
            $db->raw("UPDATE users SET password_hash = password");
            $db->raw("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NOT NULL");
        }
        if ($forge->column_exists('users', 'password')) {
            $db->raw("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL DEFAULT NULL");
        }

        $db->raw("ALTER TABLE users MODIFY role ENUM('resident','collector','admin') NOT NULL DEFAULT 'resident'");
    }

    private function archive_legacy_schema($db, $forge): void
    {
        $legacyTables = [
            'audit_logs',
            'collection_points',
            'drop_off_logs',
            'dumping_reports',
            'notifications',
            'redemptions',
            'rewards',
            'route_stops',
            'routes',
            'schedules',
        ];

        foreach (array_merge(['users'], $legacyTables) as $table) {
            if (!$forge->table_exists($table)) {
                continue;
            }
            $legacyTable = 'legacy_' . $table;
            if ($forge->table_exists($legacyTable)) {
                throw new RuntimeException("Cannot migrate legacy schema: table {$legacyTable} already exists.");
            }
            $db->raw("RENAME TABLE `{$table}` TO `{$legacyTable}`");
        }

        $db->raw("CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            phone VARCHAR(30) NULL,
            barangay VARCHAR(120) NULL,
            eco_id VARCHAR(20) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('resident','collector','admin') NOT NULL DEFAULT 'resident',
            is_active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->raw("INSERT INTO users (id, username, name, email, phone, password_hash, role, is_active, created_at)
            SELECT user_id, COALESCE(NULLIF(username, ''), email), full_name, email, phone, password_hash, role,
                CASE WHEN status = 'active' THEN 1 ELSE 0 END, created_at
            FROM legacy_users");
    }

    public function down()
    {
        throw new RuntimeException('This schema migration is not automatically reversible. Restore the pre-migration backup instead.');
    }
}
