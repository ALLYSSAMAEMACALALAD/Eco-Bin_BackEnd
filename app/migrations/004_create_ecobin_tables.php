<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Eco-Bin domain tables: schedules, dispatch, drop-offs, rewards, dumping reports, notifications, audit log. */
class Create_ecobin_tables
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $db = $this->_lava->db;
        $tables = [
"CREATE TABLE IF NOT EXISTS schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone VARCHAR(80) NOT NULL,
  collection_day ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
  time_slot VARCHAR(40) NOT NULL,
  waste_type ENUM('biodegradable','recyclable','residual','special') NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_schedules_zone (zone),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS routes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  route_date DATE NOT NULL,
  collector_id INT UNSIGNED NULL,
  status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  truck_lat DECIMAL(10,7) NULL,
  truck_lng DECIMAL(10,7) NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (collector_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS route_stops (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id INT UNSIGNED NOT NULL,
  sequence_no INT NOT NULL,
  address VARCHAR(255) NOT NULL,
  resident_id INT UNSIGNED NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
  completed_at DATETIME NULL,
  FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
  FOREIGN KEY (resident_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS hubs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS dropoff_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  txn_ref VARCHAR(64) NOT NULL UNIQUE,
  resident_id INT UNSIGNED NOT NULL,
  hub_id INT UNSIGNED NULL,
  collector_id INT UNSIGNED NOT NULL,
  material ENUM('plastic','paper','metal','glass') NOT NULL,
  weight_kg DECIMAL(8,2) NOT NULL,
  points INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dropoff_resident (resident_id),
  FOREIGN KEY (resident_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (hub_id) REFERENCES hubs(id) ON DELETE SET NULL,
  FOREIGN KEY (collector_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS rewards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  points_cost INT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  resident_id INT UNSIGNED NOT NULL,
  reward_id INT UNSIGNED NOT NULL,
  points_cost INT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  certificate_code VARCHAR(32) NOT NULL UNIQUE,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_redemption_resident (resident_id),
  FOREIGN KEY (resident_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reward_id) REFERENCES rewards(id),
  FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS dumping_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(20) NOT NULL UNIQUE,
  resident_id INT UNSIGNED NOT NULL,
  location VARCHAR(255) NOT NULL,
  description TEXT NULL,
  photo_url VARCHAR(500) NULL,
  status ENUM('open','investigating','resolved','rejected') NOT NULL DEFAULT 'open',
  handled_by INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dumping_resident (resident_id),
  FOREIGN KEY (resident_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  channel ENUM('sms','email') NOT NULL,
  message TEXT NOT NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  target VARCHAR(120) NULL,
  details TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tables as $sql) {
            $db->raw($sql);
        }
        $db->raw("INSERT INTO rewards (name, points_cost) VALUES
            ('Reusable tote bag', 100), ('Plant seedling kit', 250), ('Utility bill discount (PHP 50)', 500)");
        $db->raw("INSERT INTO hubs (name, address) VALUES ('Barangay Drop-off Hub 1', 'Barangay Hall, Main Road')");
    }

    public function down()
    {
        $db = $this->_lava->db;
        foreach (['audit_logs', 'notifications', 'dumping_reports', 'redemptions', 'rewards',
                  'dropoff_logs', 'hubs', 'route_stops', 'routes', 'schedules'] as $t) {
            $db->raw("DROP TABLE IF EXISTS $t");
        }
    }
}
