<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Generates and lists report history for administrators. */
class Reports extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('Reports_model');
    }

    public function history()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'GET') {
            $user = eco_current_user(['admin']);
            $this->api->respond(['reports' => $this->Reports_model->history((int) $user['id'])]);
        }

        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $input = $this->api->body();
        $periods = ['daily', 'weekly', 'monthly', 'yearly'];
        $formats = ['pdf', 'excel'];

        if (
            !is_string($input['title'] ?? null) || trim($input['title']) === '' ||
            !in_array($input['period'] ?? null, $periods, true) ||
            !in_array($input['format'] ?? null, $formats, true) ||
            !$this->valid_date($input['from'] ?? null) ||
            !$this->valid_date($input['to'] ?? null) ||
            !is_array($input['categories'] ?? null) ||
            !is_array($input['filters'] ?? null) ||
            !is_array($input['tables'] ?? null)
        ) {
            $this->api->respond_error('Invalid report details.', 422);
        }

        if ($input['from'] > $input['to']) {
            $this->api->respond_error('Report start date must be on or before its end date.', 422);
        }

        $id = $this->Reports_model->create((int) $user['id'], [
            'title' => trim($input['title']),
            'period' => $input['period'],
            'format' => $input['format'],
            'categories' => $input['categories'],
            'from' => $input['from'],
            'to' => $input['to'],
            'filters' => $input['filters'],
            'tables' => $input['tables'],
        ]);
        $this->api->respond(['id' => $id], 201);
    }

    private function valid_date($value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value;
    }
}
