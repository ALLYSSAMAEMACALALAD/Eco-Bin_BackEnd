<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/** @var Router $router */
/*
 * Eco-Bin API routes. Every endpoint is JSON under /api.
 * Role checks happen inside each controller via eco_current_user([...roles]).
 */

$router->get('/', 'Welcome::index');

// CLI only (refuses web requests): php public/index.php migrate
$router->get('/migrate', 'Migrate::run');

// Auth
$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/login',    'Auth::login');
$router->post('/api/auth/refresh',  'Auth::refresh');
$router->post('/api/auth/logout',   'Auth::logout');
$router->get('/api/auth/me',        'Auth::me');

// Collection schedules
$router->get('/api/schedules',          'Schedules::index');
$router->post('/api/schedules',         'Schedules::store');
$router->post('/api/schedules/remind',  'Schedules::remind');
$router->put('/api/schedules/{id}',     'Schedules::update');
$router->delete('/api/schedules/{id}',  'Schedules::destroy');

// Route dispatch (collectors / drivers)
$router->get('/api/routes',                                  'Dispatch::index');
$router->post('/api/routes',                                 'Dispatch::store');
$router->patch('/api/routes/{id}/assign',                    'Dispatch::assign');
$router->patch('/api/routes/{id}/location',                  'Dispatch::updateLocation');
$router->patch('/api/routes/{id}/stops/{stop}/complete',     'Dispatch::completeStop');

// Recyclable drop-offs and QR eco-ID
$router->get('/api/hubs',                          'Dropoffs::hubs');
$router->get('/api/dropoffs',                      'Dropoffs::index');
$router->post('/api/dropoffs',                     'Dropoffs::store');
$router->get('/api/dropoffs/lookup/{ecoId}',       'Dropoffs::lookup');

// Rewards and redemptions
$router->get('/api/rewards',                              'Rewards::rewards');
$router->get('/api/balance',                              'Rewards::balance');
$router->get('/api/redemptions',                          'Rewards::index');
$router->post('/api/redemptions',                         'Rewards::redeem');
$router->patch('/api/redemptions/{id}/decision',          'Rewards::decide');
$router->get('/api/redemptions/{id}/certificate',         'Rewards::certificate');

// Illegal dumping reports
$router->get('/api/dumping-reports',                  'Dumping::index');
$router->post('/api/dumping-reports',                 'Dumping::store');
$router->get('/api/dumping-reports/{id}',             'Dumping::show');
$router->patch('/api/dumping-reports/{id}/status',    'Dumping::updateStatus');

// Analytics and administration
$router->get('/api/analytics',              'Analytics::summary');
$router->get('/api/admin/users',            'Admin::users');
$router->post('/api/admin/users',           'Admin::createStaff');
$router->get('/api/admin/audit-logs',       'Admin::auditLogs');
