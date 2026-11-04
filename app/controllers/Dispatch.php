<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Route dispatch: admins create and assign routes; collectors track their truck and mark stops done. */
class Dispatch extends Controller
{
    public function before_action()
    {
        eco_boot();
        $this->call->model('Dispatch_model');
    }

    /** Admin: all routes. Collector: their assigned routes. Resident: routes that include one of their stops. */
    public function index()
    {
        $this->api->require_method('GET');
        $user = eco_current_user(['resident', 'collector', 'admin']);
        if ($user['role'] === 'admin') {
            $routes = $this->Dispatch_model->all();
        } elseif ($user['role'] === 'collector') {
            $routes = $this->Dispatch_model->for_collector((int) $user['id']);
        } else {
            $this->api->respond(['routes' => $this->Dispatch_model->pickups_for_resident((int) $user['id'])]);
        }
        foreach ($routes as &$r) {
            $stops = $this->Dispatch_model->stops((int) $r['id']);
            if ($user['role'] === 'resident') {
                $stops = array_values(array_filter($stops, fn($s) => (int) $s['resident_id'] === (int) $user['id']));
            }
            $r['stops'] = $stops;
        }
        unset($r);
        $this->api->respond(['routes' => $routes]);
    }

    /** Admin creates a route with its ordered stops. */
    public function store()
    {
        $this->api->require_method('POST');
        $user = eco_current_user(['admin']);
        $in = $this->api->body();
        $name  = trim($in['name'] ?? '');
        $date  = $in['route_date'] ?? '';
        $stops = $in['stops'] ?? [];
        if ($name === '' || !strtotime($date) || !is_array($stops) || !$stops) {
            $this->api->respond_error('Route name, date and at least one stop are required.', 422);
        }
        $collectorId = !empty($in['collector_id']) ? (int) $in['collector_id'] : null;
        if ($collectorId) $this->ensure_role($collectorId, 'collector');

        foreach ($stops as $s) {
            if (trim($s['address'] ?? '') === '') $this->api->respond_error('Every stop needs an address.', 422);
        }
        eco_db_exec('START TRANSACTION');
        try {
            $routeId = $this->Dispatch_model->create($name, $date, $collectorId);
            foreach ($stops as $i => $s) {
                $this->Dispatch_model->add_stop($routeId, $i + 1, trim($s['address']),
                    !empty($s['resident_id']) ? (int) $s['resident_id'] : null, $s['lat'] ?? null, $s['lng'] ?? null);
            }
            eco_db_exec('COMMIT');
        } catch (\Throwable $e) {
            eco_db_exec('ROLLBACK');
            throw $e;
        }
        eco_audit((int) $user['id'], 'route_created', "route:$routeId", count($stops) . ' stops');
        $this->api->respond(['id' => $routeId], 201);
    }

    public function assign($id)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['admin']);
        $collectorId = (int) ($this->api->body()['collector_id'] ?? 0);
        $this->ensure_role($collectorId, 'collector');
        $this->Dispatch_model->assign((int) $id, $collectorId);
        eco_audit((int) $user['id'], 'route_assigned', "route:$id", "collector:$collectorId");
        $this->api->respond(['ok' => true]);
    }

    /** Driver posts the truck's GPS position. The route moves to in_progress on the first update. */
    public function updateLocation($id)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['collector']);
        $route = $this->own_route((int) $id, $user);
        $in = $this->api->body();
        if (!isset($in['lat'], $in['lng']) || !is_numeric($in['lat']) || !is_numeric($in['lng'])) {
            $this->api->respond_error('Latitude and longitude are required.', 422);
        }
        $this->Dispatch_model->set_location((int) $route['id'], (float) $in['lat'], (float) $in['lng']);
        $this->api->respond(['ok' => true]);
    }

    /** Driver marks a stop as collected. When every stop is done, the route is completed. */
    public function completeStop($id, $stop)
    {
        $this->api->require_method('PATCH');
        $user = eco_current_user(['collector']);
        $route = $this->own_route((int) $id, $user);
        $row = $this->Dispatch_model->find_stop((int) $route['id'], (int) $stop);
        if (!$row) $this->api->respond_error('Stop not found on this route.', 404);

        $this->Dispatch_model->complete_stop((int) $row['id']);
        $done = $this->Dispatch_model->pending_stop_count((int) $route['id']) === 0;
        if ($done) $this->Dispatch_model->complete_route((int) $route['id']);
        eco_audit((int) $user['id'], 'stop_completed', "stop:{$row['id']}");
        $this->api->respond(['ok' => true, 'route_completed' => $done]);
    }

    private function own_route(int $routeId, array $user): array
    {
        $route = $this->Dispatch_model->find($routeId);
        if (!$route) $this->api->respond_error('Route not found.', 404);
        if ((int) $route['collector_id'] !== (int) $user['id']) $this->api->respond_error('This route is not assigned to you.', 403);
        return $route;
    }

    private function ensure_role(int $userId, string $role): void
    {
        $u = eco_db_one('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if (!$u || $u['role'] !== $role) $this->api->respond_error("User must be a $role.", 422);
    }
}
