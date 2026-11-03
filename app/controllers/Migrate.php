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
    }
}
