<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Counts consecutive failed password attempts per account email.
 * Used to trigger the automatic recovery-link email after 3 wrong passwords.
 * Cleared on successful login. Keyed by email so attempts are account-wide,
 * regardless of which IP or browser the attacker uses.
 */
class Create_login_attempts_table
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS login_attempts (
            email VARCHAR(191) PRIMARY KEY,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            first_failed_at DATETIME NOT NULL,
            last_failed_at DATETIME NOT NULL,
            INDEX idx_login_attempts_last (last_failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS login_attempts');
    }
}
