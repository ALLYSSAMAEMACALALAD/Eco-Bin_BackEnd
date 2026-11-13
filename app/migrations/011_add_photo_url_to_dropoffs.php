<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Collectors photograph the recyclables before crediting points.
 * Stores the Cloudinary (or local uploads) URL of that photo.
 */
class Add_photo_url_to_dropoffs
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

        if (!$forge->column_exists('dropoff_logs', 'photo_url')) {
            $db->raw('ALTER TABLE dropoff_logs ADD COLUMN photo_url VARCHAR(500) NULL AFTER points');
        }
    }

    public function down()
    {
        $forge = $this->_lava->dbforge;
        if ($forge->column_exists('dropoff_logs', 'photo_url')) {
            $this->_lava->db->raw('ALTER TABLE dropoff_logs DROP COLUMN photo_url');
        }
    }
}
