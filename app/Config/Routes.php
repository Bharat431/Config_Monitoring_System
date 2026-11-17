<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Dashboard::index');
$routes->get('dashboard', 'Dashboard::index');

// ---------------------------------------------------------------------------
// Public endpoints (safe for load balancers / k8s probes)
// ---------------------------------------------------------------------------
$routes->get('health', 'Api\Health::index');
$routes->get('api/health', 'Api\Health::index');

// ---------------------------------------------------------------------------
// Management API (optional X-API-Key / Bearer auth via ApiKeyFilter)
// ---------------------------------------------------------------------------
$routes->group('api', ['filter' => 'apiKey'], static function (RouteCollection $routes): void {
    $routes->get('stats', 'Api\Stats::index');
    $routes->get('stats/series', 'Api\Stats::series');
    $routes->get('metrics', 'Api\Metrics::index');

    $routes->get('files', 'Api\Files::index');
    $routes->get('files/(:num)', 'Api\Files::show/$1');
    $routes->post('watch', 'Api\Files::store');
    $routes->delete('watch/(:any)', 'Api\Files::delete/$1');
    $routes->patch('files/(:num)', 'Api\Files::update/$1');
    $routes->post('scan', 'Api\Files::scan');

    $routes->get('events', 'Api\Events::index');
    $routes->get('events/(:segment)', 'Api\Events::show/$1');
    $routes->post('events/(:segment)/replay', 'Api\Events::replay/$1');

    $routes->get('deliveries', 'Api\Deliveries::index');
    $routes->get('dlq', 'Api\Deliveries::deadLetters');
    $routes->post('dlq/(:num)/retry', 'Api\Deliveries::retry/$1');

    $routes->get('targets', 'Api\Targets::index');
    $routes->post('targets', 'Api\Targets::store');
    $routes->patch('targets/(:num)', 'Api\Targets::update/$1');
    $routes->delete('targets/(:num)', 'Api\Targets::delete/$1');
    $routes->post('targets/(:num)/test', 'Api\Targets::test/$1');

    $routes->post('demo/seed', 'Api\Demo::seed');
    $routes->post('demo/change', 'Api\Demo::change');
});

// ---------------------------------------------------------------------------
// Spec-compatible aliases (also protected when an API key is configured)
// ---------------------------------------------------------------------------
$routes->get('files', 'Api\Files::index', ['filter' => 'apiKey']);
$routes->get('events', 'Api\Events::index', ['filter' => 'apiKey']);
$routes->get('events/(:segment)', 'Api\Events::show/$1', ['filter' => 'apiKey']);
$routes->post('watch', 'Api\Files::store', ['filter' => 'apiKey']);
$routes->delete('watch/(:any)', 'Api\Files::delete/$1', ['filter' => 'apiKey']);
$routes->get('metrics', 'Api\Metrics::index', ['filter' => 'apiKey']);
