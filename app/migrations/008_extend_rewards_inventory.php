<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Adds the inventory and display fields used by the admin reward manager. */
class Extend_rewards_inventory
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

        if (!$forge->column_exists('rewards', 'description')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN description TEXT NULL');
        }
        if (!$forge->column_exists('rewards', 'image_url')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN image_url VARCHAR(500) NULL');
        }
        if (!$forge->column_exists('rewards', 'category')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN category VARCHAR(80) NULL');
        }
        if (!$forge->column_exists('rewards', 'quantity_available')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN quantity_available INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (!$forge->column_exists('rewards', 'quantity_reserved')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN quantity_reserved INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (!$forge->column_exists('rewards', 'quantity_redeemed')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN quantity_redeemed INT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (!$forge->column_exists('rewards', 'min_stock')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN min_stock INT UNSIGNED NOT NULL DEFAULT 5');
        }
        if (!$forge->column_exists('rewards', 'created_at')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        if (!$forge->column_exists('rewards', 'updated_at')) {
            $db->raw('ALTER TABLE rewards ADD COLUMN updated_at DATETIME NULL');
        }
    }

    public function down()
    {
        $db = $this->_lava->db;
        foreach ([
            'updated_at',
            'created_at',
            'min_stock',
            'quantity_redeemed',
            'quantity_reserved',
            'quantity_available',
            'category',
            'image_url',
            'description',
        ] as $column) {
            $db->raw("ALTER TABLE rewards DROP COLUMN `$column`");
        }
    }
}
