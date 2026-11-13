<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Extends drop-off logging so collectors can log plastic bottles by piece
 * (0.5 points each) and credit more recyclable materials than the original
 * four set. Points are stored as DECIMAL so half points survive rounding.
 * Existing kilogram entries are mirrored into the new quantity column.
 */
class Extend_dropoffs_for_pieces_and_materials
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

        // Half points (plastic bottles) need a decimal points column.
        $db->raw('ALTER TABLE dropoff_logs MODIFY points DECIMAL(10,2) NOT NULL');

        // Material keys now include plastic_bottle and the added recyclables.
        $db->raw("ALTER TABLE dropoff_logs MODIFY material ENUM('plastic','plastic_bottle','paper','cardboard','metal','aluminum','glass','ewaste','textile') NOT NULL");

        if (!$forge->column_exists('dropoff_logs', 'unit')) {
            $db->raw("ALTER TABLE dropoff_logs ADD COLUMN unit ENUM('kg','pcs') NOT NULL DEFAULT 'kg' AFTER material");
        }
        if (!$forge->column_exists('dropoff_logs', 'quantity')) {
            $db->raw('ALTER TABLE dropoff_logs ADD COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER unit');
        }

        // Backfill: every pre-existing row was logged in kilograms.
        $db->raw("UPDATE dropoff_logs SET unit = 'kg', quantity = weight_kg WHERE quantity = 0 AND weight_kg > 0");
    }

    public function down()
    {
        $db = $this->_lava->db;
        $forge = $this->_lava->dbforge;

        $db->raw("UPDATE dropoff_logs SET material = 'plastic' WHERE material NOT IN ('plastic','paper','metal','glass')");
        $db->raw("ALTER TABLE dropoff_logs MODIFY material ENUM('plastic','paper','metal','glass') NOT NULL");
        $db->raw('ALTER TABLE dropoff_logs MODIFY points INT NOT NULL');
        if ($forge->column_exists('dropoff_logs', 'quantity')) {
            $db->raw('ALTER TABLE dropoff_logs DROP COLUMN quantity');
        }
        if ($forge->column_exists('dropoff_logs', 'unit')) {
            $db->raw('ALTER TABLE dropoff_logs DROP COLUMN unit');
        }
    }
}
