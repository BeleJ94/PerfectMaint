<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EquipmentController;
use App\Controllers\FieldController;
use App\Controllers\PartController;
use App\Controllers\PreventiveController;
use App\Controllers\SiteController;
use App\Controllers\UserController;
use App\Controllers\WorkOrderController;
use App\Core\Router;

/*
 * Toutes les routes exigent une session, sauf mention 'auth' => false.
 * Toute route POST est protégée par CSRF (App::dispatch).
 */
return static function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], ['auth' => false]);
    $r->post('/login', [AuthController::class, 'login'], ['auth' => false]);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/locale', [AuthController::class, 'switchLocale'], ['auth' => false]);

    $r->get('/', [DashboardController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/dashboard/data', [DashboardController::class, 'data'], ['permission' => 'dashboard.view']);

    // Parc d'engins
    $r->get('/equipment', [EquipmentController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/equipment/data', [EquipmentController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/equipment/search', [EquipmentController::class, 'search'], ['permission' => 'equipment.view']);
    $r->get('/equipment/create', [EquipmentController::class, 'create'], ['permission' => 'equipment.manage']);
    $r->post('/equipment', [EquipmentController::class, 'store'], ['permission' => 'equipment.manage']);
    $r->get('/equipment/{id}', [EquipmentController::class, 'show'], ['permission' => 'equipment.view']);
    $r->get('/equipment/{id}/edit', [EquipmentController::class, 'edit'], ['permission' => 'equipment.manage']);
    $r->post('/equipment/{id}', [EquipmentController::class, 'update'], ['permission' => 'equipment.manage']);
    $r->post('/equipment/{id}/status', [EquipmentController::class, 'status'], ['permission' => 'equipment.status']);
    $r->post('/equipment/{id}/archive', [EquipmentController::class, 'archive'], ['permission' => 'equipment.manage']);

    // Ordres de travail
    $r->get('/work-orders', [WorkOrderController::class, 'index'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/data', [WorkOrderController::class, 'data'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/assignees', [WorkOrderController::class, 'assignees'], ['permission' => 'workorder.manage']);
    $r->get('/work-orders/create', [WorkOrderController::class, 'create'], ['permission' => 'workorder.create']);
    $r->post('/work-orders', [WorkOrderController::class, 'store'], ['permission' => 'workorder.create']);
    $r->get('/work-orders/{id}', [WorkOrderController::class, 'show'], ['permission' => 'workorder.view']);
    $r->get('/work-orders/{id}/edit', [WorkOrderController::class, 'edit'], ['permission' => 'workorder.manage']);
    $r->post('/work-orders/{id}', [WorkOrderController::class, 'update'], ['permission' => 'workorder.manage']);
    $r->post('/work-orders/{id}/transition', [WorkOrderController::class, 'transition'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/parts', [WorkOrderController::class, 'addPart'], ['permission' => 'workorder.execute']);

    // Maintenance préventive
    $r->get('/preventive', [PreventiveController::class, 'index'], ['permission' => 'preventive.view']);
    $r->get('/preventive/data', [PreventiveController::class, 'data'], ['permission' => 'preventive.view']);
    $r->get('/preventive/create', [PreventiveController::class, 'create'], ['permission' => 'preventive.manage']);
    $r->post('/preventive', [PreventiveController::class, 'store'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/generate', [PreventiveController::class, 'generate'], ['permission' => 'preventive.manage']);
    $r->get('/preventive/{id}/edit', [PreventiveController::class, 'edit'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/{id}', [PreventiveController::class, 'update'], ['permission' => 'preventive.manage']);

    // Pièces détachées
    $r->get('/parts', [PartController::class, 'index'], ['permission' => 'parts.view']);
    $r->get('/parts/data', [PartController::class, 'data'], ['permission' => 'parts.view']);
    $r->get('/parts/create', [PartController::class, 'create'], ['permission' => 'parts.manage']);
    $r->post('/parts', [PartController::class, 'store'], ['permission' => 'parts.manage']);
    $r->get('/parts/{id}', [PartController::class, 'show'], ['permission' => 'parts.view']);
    $r->get('/parts/{id}/edit', [PartController::class, 'edit'], ['permission' => 'parts.manage']);
    $r->post('/parts/{id}', [PartController::class, 'update'], ['permission' => 'parts.manage']);
    $r->post('/parts/{id}/movements', [PartController::class, 'movement'], ['permission' => 'parts.manage']);
    $r->post('/parts/{id}/settings', [PartController::class, 'settings'], ['permission' => 'parts.manage']);

    // Écrans terrain (mobile)
    $r->get('/terrain', [FieldController::class, 'index'], ['permission' => 'field.use']);
    $r->get('/terrain/declarer', [FieldController::class, 'declare'], ['permission' => 'workorder.create']);
    $r->post('/terrain/declarer', [FieldController::class, 'storeDeclaration'], ['permission' => 'workorder.create']);
    $r->get('/terrain/ot/{id}', [FieldController::class, 'show'], ['permission' => 'field.use']);

    // Administration
    $r->get('/sites', [SiteController::class, 'index'], ['permission' => 'sites.manage']);
    $r->get('/sites/data', [SiteController::class, 'data'], ['permission' => 'sites.manage']);
    $r->get('/sites/create', [SiteController::class, 'create'], ['permission' => 'sites.manage']);
    $r->post('/sites', [SiteController::class, 'store'], ['permission' => 'sites.manage']);
    $r->get('/sites/{id}/edit', [SiteController::class, 'edit'], ['permission' => 'sites.manage']);
    $r->post('/sites/{id}', [SiteController::class, 'update'], ['permission' => 'sites.manage']);

    $r->get('/users', [UserController::class, 'index'], ['permission' => 'users.manage']);
    $r->get('/users/data', [UserController::class, 'data'], ['permission' => 'users.manage']);
    $r->get('/users/create', [UserController::class, 'create'], ['permission' => 'users.manage']);
    $r->post('/users', [UserController::class, 'store'], ['permission' => 'users.manage']);
    $r->get('/users/{id}/edit', [UserController::class, 'edit'], ['permission' => 'users.manage']);
    $r->post('/users/{id}', [UserController::class, 'update'], ['permission' => 'users.manage']);

    $r->get('/activity', [ActivityController::class, 'index'], ['permission' => 'activity.view']);
    $r->get('/activity/data', [ActivityController::class, 'data'], ['permission' => 'activity.view']);
};
