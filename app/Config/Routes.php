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

        // 장부(사업장 스코프 중첩)
        $routes->get('(:num)/ledger', 'Admin\LedgerController::index/$1');
        $routes->get('(:num)/ledger/new', 'Admin\LedgerController::new/$1');
        $routes->post('(:num)/ledger', 'Admin\LedgerController::create/$1');
        $routes->get('(:num)/ledger/import', 'Admin\LedgerController::importForm/$1');
        $routes->post('(:num)/ledger/import', 'Admin\LedgerController::import/$1');
        $routes->get('(:num)/ledger/(:num)/edit', 'Admin\LedgerController::edit/$1/$2');
        $routes->post('(:num)/ledger/(:num)', 'Admin\LedgerController::update/$1/$2');
        $routes->post('(:num)/ledger/(:num)/delete', 'Admin\LedgerController::delete/$1/$2');
        $routes->post('(:num)/ledger/(:num)/copy', 'Admin\LedgerController::copy/$1/$2');

        // 리포트 — 영업현황표 · 신고서식
        $routes->get('(:num)/reports/business-status', 'Admin\ReportController::businessStatus/$1');
        $routes->get('(:num)/reports/tax-forms', 'Admin\ReportController::taxForms/$1');
        $routes->post('(:num)/reports/tax-forms/inventory', 'Admin\ReportController::saveInventory/$1');
        $routes->post('(:num)/reports/tax-forms/adjustments', 'Admin\ReportController::saveAdjustments/$1');
        $routes->get('(:num)/reports/tax-forms/print', 'Admin\ReportController::taxFormsPrint/$1');
        $routes->get('(:num)/reports/tax-forms/excel', 'Admin\ReportController::taxFormsExcel/$1');

        // 자산대장 · 감가상각
        $routes->get('(:num)/assets', 'Admin\AssetController::index/$1');
        $routes->get('(:num)/assets/new', 'Admin\AssetController::new/$1');
        $routes->post('(:num)/assets', 'Admin\AssetController::create/$1');
        $routes->get('(:num)/assets/(:num)/edit', 'Admin\AssetController::edit/$1/$2');
        $routes->post('(:num)/assets/(:num)', 'Admin\AssetController::update/$1/$2');
        $routes->post('(:num)/assets/(:num)/delete', 'Admin\AssetController::delete/$1/$2');
        $routes->get('(:num)/assets/(:num)/schedule', 'Admin\AssetController::schedule/$1/$2');
        $routes->post('(:num)/assets/(:num)/depreciation', 'Admin\AssetController::postDepreciation/$1/$2');
    });
});
