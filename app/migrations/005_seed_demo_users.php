<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * DEVELOPMENT ONLY: creates one admin, one collector and one resident so every dashboard can be tested.
 * Skipped unless APP_ENV=development. Change or delete these accounts before deploying.
 *
 *   admin@ecobin.test      / Admin@12345
 *   collector@ecobin.test  / Collector@123
 *   resident@ecobin.test   / Resident@123
 */
class Seed_demo_users
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        if (getenv('APP_ENV') !== 'development') {
            return;
        }
        $db = $this->_lava->db;
        $db->raw(<<<'SQL'
            INSERT IGNORE INTO users (username, name, email, password_hash, role, phone, barangay, eco_id) VALUES
            ('admin@ecobin.test', 'Demo Admin', 'admin@ecobin.test', '$2y$10$0kqzty0Tgee1B93jknlqEuSbMJKWGWuvTrfm4r0hJ3jjmoCf3niGy', 'admin', NULL, 'Barangay Hall', NULL),
            ('collector@ecobin.test', 'Demo Collector', 'collector@ecobin.test', '$2y$10$OQbBY5iJwZ6BV.jQHaL30uY5q3WaipL16vk7e0UH0AnGl3omtLRQS', 'collector', NULL, 'Barangay Hall', NULL),
            ('resident@ecobin.test', 'Demo Resident', 'resident@ecobin.test', '$2y$10$6Y62EJL/yevrvW0fXz0wgero/p/iUacLVTe3tI9wzuyUdor3VPNT.', 'resident', '09171234567', 'Barangay 1', 'ECO-DEMO0001')
            SQL);
    }

    public function down()
    {
        $db = $this->_lava->db;
        $db->raw("DELETE FROM users WHERE email IN ('admin@ecobin.test', 'collector@ecobin.test', 'resident@ecobin.test')");
    }
}
