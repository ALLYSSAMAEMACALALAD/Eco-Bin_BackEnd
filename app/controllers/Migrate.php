<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Runs pending database migrations. CLI only:
 *   php public/index.php migrate
 */
class Migrate extends Controller
{
    public function run()
    {
        if (!defined('IS_CLI') || !IS_CLI) {
            http_response_code(403);
            exit('Migrations can only be run from the command line.');
        }
        $this->call->library('migration');
        $this->migration->migrate();

        $applied = array_map(
            'intval',
            array_column(eco_db_all('SELECT migration FROM migrations'), 'migration')
        );
        foreach (glob(APP_DIR . 'migrations/*.php') ?: [] as $file) {
            if (preg_match('/^(\d+)_.*\.php$/', basename($file), $matches)
                && !in_array((int) $matches[1], $applied, true)) {
                fwrite(STDERR, 'Migration was not applied: ' . basename($file) . PHP_EOL);
                exit(1);
            }
        }

        $this->call->dbforge();
        $requiredColumns = [
            'users' => ['id', 'username', 'name', 'email', 'password_hash', 'role', 'phone', 'barangay', 'eco_id', 'is_active'],
            'refresh_tokens' => ['user_id', 'token', 'expires_at', 'jti'],
            'audit_logs' => ['user_id', 'action', 'target', 'details', 'created_at'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            if (!$this->dbforge->table_exists($table)) {
                fwrite(STDERR, "Required table is missing: {$table}" . PHP_EOL);
                exit(1);
            }
            foreach ($columns as $column) {
                if (!$this->dbforge->column_exists($table, $column)) {
                    fwrite(STDERR, "Required column is missing: {$table}.{$column}" . PHP_EOL);
                    exit(1);
                }
            }
        }
    }
}
