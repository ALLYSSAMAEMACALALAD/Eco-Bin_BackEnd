<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Create_report_history
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw("CREATE TABLE IF NOT EXISTS report_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            period ENUM('daily','weekly','monthly','yearly') NOT NULL,
            format ENUM('pdf','excel') NOT NULL,
            categories LONGTEXT NOT NULL,
            date_from DATE NOT NULL,
            date_to DATE NOT NULL,
            filters LONGTEXT NOT NULL,
            tables_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_history_user_created (user_id, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down()
    {
        $this->_lava->db->raw('DROP TABLE IF EXISTS report_history');
    }
}
