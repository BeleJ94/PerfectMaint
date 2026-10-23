<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\EquipmentController;
use App\Controllers\FieldController;
use App\Controllers\ManagementController;
use App\Controllers\MeterController;
use App\Controllers\MeterEntryController;
use App\Controllers\NotificationController;
use App\Controllers\PartController;
use App\Controllers\PreventiveController;
use App\Controllers\ProcurementController;
use App\Controllers\SearchController;
use App\Controllers\SettingsController;
use App\Controllers\SiteController;
use App\Controllers\SiteOverviewController;
use App\Controllers\UserController;
use App\Controllers\WorkOrderController;
use App\Controllers\WorkforceController;
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
    $r->post('/context/site', [AuthController::class, 'switchSite'], ['permission' => 'dashboard.view']);

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
    $r->post('/equipment/{id}/readings', [EquipmentController::class, 'reading'], ['permission' => 'equipment.reading']);
    $r->post('/equipment/{id}/fluids', [EquipmentController::class, 'fluid'], ['permission' => 'equipment.reading']);
    $r->post('/equipment/{id}/documents', [EquipmentController::class, 'storeDocument'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/documents/{documentId}', [EquipmentController::class, 'updateDocument'], ['permission' => 'documents.manage']);
    $r->get('/equipment/{id}/documents/{documentId}/file', [EquipmentController::class, 'documentFile'], ['permission' => 'equipment.view']);
    $r->post('/equipment/{id}/documents/{documentId}/delete', [EquipmentController::class, 'deleteDocument'], ['permission' => 'documents.manage']);
    $r->post('/equipment/{id}/photos', [EquipmentController::class, 'uploadPhoto'], ['permission' => 'equipment.photo']);
    $r->get('/equipment/{id}/photos/{photoId}', [EquipmentController::class, 'photo'], ['permission' => 'equipment.view']);
    $r->post('/equipment/{id}/photos/{photoId}/primary', [EquipmentController::class, 'primaryPhoto'], ['permission' => 'equipment.photo']);
    $r->post('/equipment/{id}/photos/{photoId}/delete', [EquipmentController::class, 'deletePhoto'], ['permission' => 'equipment.manage']);

    // Documents de bord (assurance, contrôle technique, vignette, stationnement…)
    $r->get('/documents', [DocumentController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/documents/data', [DocumentController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/documents/create', [DocumentController::class, 'create'], ['permission' => 'documents.manage']);
    $r->post('/documents', [DocumentController::class, 'store'], ['permission' => 'documents.manage']);

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
    $r->post('/work-orders/{id}/technicians', [WorkOrderController::class, 'addTechnician'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/technicians/{technicianId}/delete', [WorkOrderController::class, 'removeTechnician'], ['permission' => 'workorder.execute']);
    $r->post('/work-orders/{id}/comments', [WorkOrderController::class, 'comment'], ['permission' => 'workorder.execute']);

    // Maintenance préventive
    $r->get('/preventive', [PreventiveController::class, 'index'], ['permission' => 'preventive.view']);
    $r->get('/preventive/data', [PreventiveController::class, 'data'], ['permission' => 'preventive.view']);
    $r->get('/preventive/create', [PreventiveController::class, 'create'], ['permission' => 'preventive.manage']);
    $r->post('/preventive', [PreventiveController::class, 'store'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/generate', [PreventiveController::class, 'generate'], ['permission' => 'preventive.manage']);
    $r->get('/preventive/{id}/edit', [PreventiveController::class, 'edit'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/{id}', [PreventiveController::class, 'update'], ['permission' => 'preventive.manage']);
    $r->post('/preventive/{id}/delete', [PreventiveController::class, 'delete'], ['permission' => 'preventive.delete']);

    // Supervision des index (compteurs) et échéances projetées
    $r->get('/meters', [MeterController::class, 'index'], ['permission' => 'equipment.view']);
    $r->get('/meters/screen', [MeterController::class, 'screen'], ['permission' => 'equipment.view']);
    $r->get('/meters/data', [MeterController::class, 'data'], ['permission' => 'equipment.view']);
    $r->get('/meters/{id}/series', [MeterController::class, 'series'], ['permission' => 'equipment.view']);

    // Saisie des index par les encodeurs (téléphone / tablette)
    $r->get('/saisie', [MeterEntryController::class, 'index'], ['permission' => 'meters.entry']);
    $r->get('/saisie/rapport', [MeterEntryController::class, 'report'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision', [MeterEntryController::class, 'supervision'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision/data', [MeterEntryController::class, 'supervisionData'], ['permission' => 'meters.entry']);
    $r->get('/saisie/supervision/{id}/series', [MeterEntryController::class, 'supervisionSeries'], ['permission' => 'meters.entry']);
    $r->post('/saisie/{id}', [MeterEntryController::class, 'save'], ['permission' => 'meters.entry']);

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

    // Pilotage : alertes, planning, rapports de direction
    $r->get('/alerts', [ManagementController::class, 'alerts'], ['permission' => 'dashboard.view']);
    $r->get('/notifications', [NotificationController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/search', [SearchController::class, 'index'], ['permission' => 'dashboard.view']);
    $r->get('/planning', [ManagementController::class, 'planning'], ['permission' => 'dashboard.view']);
    $r->get('/reports', [ManagementController::class, 'reports'], ['permission' => 'reports.view']);

    // Besoins achats & magasin
    $r->get('/procurement', [ProcurementController::class, 'index'], ['permission' => 'parts.view']);
    $r->get('/procurement/data', [ProcurementController::class, 'data'], ['permission' => 'parts.view']);

    // Écrans terrain (mobile)
    $r->get('/terrain', [FieldController::class, 'index'], ['permission' => 'field.use']);
    $r->get('/terrain/declarer', [FieldController::class, 'declare'], ['permission' => 'workorder.create']);
    $r->post('/terrain/declarer', [FieldController::class, 'storeDeclaration'], ['permission' => 'workorder.create']);
    $r->get('/terrain/ot/{id}', [FieldController::class, 'show'], ['permission' => 'field.use']);
    $r->get('/terrain/engins', [FieldController::class, 'equipment'], ['permission' => 'field.use']);
    $r->get('/terrain/engins/{id}', [FieldController::class, 'showEquipment'], ['permission' => 'field.use']);

    // Équipes par shift, personnel, affectations chauffeurs / opérateurs
    $r->get('/teams', [WorkforceController::class, 'teams'], ['permission' => 'personnel.view']);
    $r->get('/teams/create', [WorkforceController::class, 'createTeam'], ['permission' => 'personnel.manage']);
    $r->post('/teams', [WorkforceController::class, 'storeTeam'], ['permission' => 'personnel.manage']);
    $r->get('/teams/{id}/edit', [WorkforceController::class, 'editTeam'], ['permission' => 'personnel.manage']);
    $r->post('/teams/{id}', [WorkforceController::class, 'updateTeam'], ['permission' => 'personnel.manage']);
    $r->get('/personnel', [WorkforceController::class, 'personnel'], ['permission' => 'personnel.view']);
    $r->get('/personnel/data', [WorkforceController::class, 'personnelData'], ['permission' => 'personnel.view']);
    $r->get('/personnel/create', [WorkforceController::class, 'createPerson'], ['permission' => 'personnel.manage']);
    $r->post('/personnel', [WorkforceController::class, 'storePerson'], ['permission' => 'personnel.manage']);
    $r->get('/personnel/{id}/edit', [WorkforceController::class, 'editPerson'], ['permission' => 'personnel.manage']);
    $r->post('/personnel/{id}', [WorkforceController::class, 'updatePerson'], ['permission' => 'personnel.manage']);
    $r->post('/equipment/{id}/assignments', [WorkforceController::class, 'assign'], ['permission' => 'equipment.assign']);
    $r->post('/equipment/{id}/assignments/{assignmentId}/end', [WorkforceController::class, 'unassign'], ['permission' => 'equipment.assign']);

    // Fenêtre par site (tout utilisateur, dans son périmètre)
    $r->get('/sites/{id}', [SiteOverviewController::class, 'show'], ['permission' => 'dashboard.view']);

    // Référentiel marques / modèles
    $r->get('/catalog', [CatalogController::class, 'index'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/brands', [CatalogController::class, 'storeBrand'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/brands/{id}', [CatalogController::class, 'updateBrand'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models', [CatalogController::class, 'storeModel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models/{id}', [CatalogController::class, 'updateModel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/categories/{id}', [CatalogController::class, 'updateCategory'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/models/{id}/fuel', [CatalogController::class, 'modelFuel'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/gauges', [CatalogController::class, 'storeGauge'], ['permission' => 'equipment.manage']);
    $r->post('/catalog/gauges/{id}', [CatalogController::class, 'updateGauge'], ['permission' => 'equipment.manage']);

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

    // Paramètres : envoi d'e-mails et alarmes par e-mail
    $r->get('/settings/mail', [SettingsController::class, 'mail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail', [SettingsController::class, 'saveMail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail/test', [SettingsController::class, 'testMail'], ['permission' => 'settings.manage']);
    $r->post('/settings/mail/alerts', [SettingsController::class, 'sendAlerts'], ['permission' => 'settings.manage']);

    $r->get('/activity', [ActivityController::class, 'index'], ['permission' => 'activity.view']);
    $r->get('/activity/data', [ActivityController::class, 'data'], ['permission' => 'activity.view']);
    $r->get('/activity/summary', [ActivityController::class, 'summary'], ['permission' => 'activity.view']);
};
