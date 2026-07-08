<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

service('auth')->routes($routes);

// Admin (세션 인증) — 기본사항: 사업장·거래처
$routes->group('admin', ['filter' => 'session'], static function ($routes): void {
    $routes->get('', 'Admin\BusinessController::index');

    $routes->group('businesses', static function ($routes): void {
        $routes->get('', 'Admin\BusinessController::index');
        $routes->get('new', 'Admin\BusinessController::new');
        $routes->post('', 'Admin\BusinessController::create');
        $routes->get('(:num)/edit', 'Admin\BusinessController::edit/$1');
        $routes->post('(:num)', 'Admin\BusinessController::update/$1');
        $routes->post('(:num)/delete', 'Admin\BusinessController::delete/$1');

        // 거래처(사업장 스코프 중첩)
        $routes->get('(:num)/partners', 'Admin\PartnerController::index/$1');
        $routes->get('(:num)/partners/new', 'Admin\PartnerController::new/$1');
        $routes->post('(:num)/partners', 'Admin\PartnerController::create/$1');
        $routes->get('(:num)/partners/import', 'Admin\PartnerController::importForm/$1');
        $routes->post('(:num)/partners/import', 'Admin\PartnerController::import/$1');
        $routes->get('(:num)/partners/(:num)/edit', 'Admin\PartnerController::edit/$1/$2');
        $routes->post('(:num)/partners/(:num)', 'Admin\PartnerController::update/$1/$2');
        $routes->post('(:num)/partners/(:num)/delete', 'Admin\PartnerController::delete/$1/$2');
    });
});
