<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Community environmental analytics for the admin dashboard. */
class Analytics extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('Analytics_model');
    }

    public function summary()
    {
        $this->api->require_method('GET');
        eco_current_user(['admin']);
        $this->api->respond($this->Analytics_model->summary());
    }
}
