<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController,DashboardController,ReferenceController,ContractController,ContractDocumentController,ProgressController,PaymentController,GuaranteeController,ReportController,AlertController,CompanyController,UserController,PhaseController,SettingsController,OrganizationController,StockController,FleetController,LogisticsController,DecompteController,ReportSnapshotController,AlertSettingsController,AuditController,NotificationController};

// ---- Public (le projet est transmis par l'en-tête X-Project)
// Routes publiques : limites de débit par adresse IP (AppServiceProvider::rateLimits).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:connexion');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:inscription');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:mot-de-passe-oublie');
Route::get('/auth/reset-check', [AuthController::class, 'resetCheck'])->middleware('throttle:lien-mot-de-passe');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:lien-mot-de-passe');
Route::get('/auth/registration-options', [OrganizationController::class, 'registrationOptions'])->middleware('throttle:30,1');

// ---- Authentifié : projet + phase active
Route::middleware(['auth:sanctum', 'project', 'throttle:api'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/reference-data', [ReferenceController::class, 'index']);

    // Contrats : consultation ouverte à tous les utilisateurs connectés (page en lecture seule), gestion soumise aux habilitations dans les contrôleurs
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/{id}', [ContractController::class, 'show'])->whereNumber('id');
    Route::post('/contracts', [ContractController::class, 'store']);
    Route::put('/contracts/{id}', [ContractController::class, 'update'])->whereNumber('id');
    Route::delete('/contracts/{id}', [ContractController::class, 'destroy'])->whereNumber('id');
    Route::post('/contracts/{id}/terminate', [ContractController::class, 'terminate'])->whereNumber('id');
    Route::post('/contracts/{id}/amendments', [ContractController::class, 'amendment'])->whereNumber('id');
    Route::post('/contracts/{id}/renew', [ContractController::class, 'renew'])->whereNumber('id');
    Route::post('/contracts/{id}/documents', [ContractDocumentController::class, 'store'])->whereNumber('id');
    Route::get('/documents/{id}/download', [ContractDocumentController::class, 'download'])->whereNumber('id');
    Route::delete('/documents/{id}', [ContractDocumentController::class, 'destroy'])->whereNumber('id');

    Route::get('/progress', [ProgressController::class, 'index']);
    Route::post('/progress', [ProgressController::class, 'store']);
    Route::put('/progress/{id}', [ProgressController::class, 'update'])->whereNumber('id');
    Route::delete('/progress/{id}', [ProgressController::class, 'destroy'])->whereNumber('id');

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::put('/payments/{id}', [PaymentController::class, 'update'])->whereNumber('id');
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy'])->whereNumber('id');

    // Décomptes : saisie (paiements), validation (comptable), consultation (situation)
    Route::middleware('permission:can_manage_payments|can_validate_decomptes|can_view_situation')->group(function () {
        Route::get('/decomptes', [DecompteController::class, 'index']);
        Route::post('/contracts/{id}/decomptes', [DecompteController::class, 'store'])->whereNumber('id');
        Route::post('/decomptes/{id}/decision', [DecompteController::class, 'decide'])->whereNumber('id');
        Route::post('/contracts/{id}/decomptes/release', [DecompteController::class, 'release'])->whereNumber('id');
        Route::post('/contracts/{id}/advance', [\App\Http\Controllers\ContractAdvanceController::class, 'store'])->whereNumber('id');
        Route::post('/advances/{id}/pay', [\App\Http\Controllers\ContractAdvanceController::class, 'pay'])->whereNumber('id');
        Route::post('/advances/{id}/cancel', [\App\Http\Controllers\ContractAdvanceController::class, 'cancel'])->whereNumber('id');
    });

    Route::middleware('permission:can_manage_guarantees')->group(function () {
        Route::get('/guarantees', [GuaranteeController::class, 'index']);
        Route::get('/guarantees/alerts', [GuaranteeController::class, 'alerts']);
        Route::put('/guarantees/alerts/settings', [GuaranteeController::class, 'updateAlertSettings']);
        Route::put('/guarantees/{id}/alerts', [GuaranteeController::class, 'updateAlerts'])->whereNumber('id');
        Route::put('/guarantees/{id}', [GuaranteeController::class, 'update'])->whereNumber('id');
        Route::post('/guarantees/{id}/extend', [GuaranteeController::class, 'extend'])->whereNumber('id');
        Route::post('/guarantees/{id}/renew', [GuaranteeController::class, 'renew'])->whereNumber('id');
    });

    Route::middleware('permission:can_view_reports|can_view_situation')->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/export', [ReportController::class, 'export']);
        Route::get('/reports/synthesis', [ReportController::class, 'synthesis']);
        // Historisation : aperçu d'une période, états figés, comparaisons, export avec choix des colonnes
        Route::get('/report-snapshots', [ReportSnapshotController::class, 'index']);
        Route::get('/report-snapshots/preview', [ReportSnapshotController::class, 'preview']);
        Route::get('/report-snapshots/compare', [ReportSnapshotController::class, 'compare']);
        Route::get('/report-snapshots/export', [ReportSnapshotController::class, 'export']);
        Route::get('/report-snapshots/{id}', [ReportSnapshotController::class, 'show'])->whereNumber('id');
        Route::post('/report-snapshots', [ReportSnapshotController::class, 'store']);
        Route::delete('/report-snapshots/{id}', [ReportSnapshotController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware('permission:can_view_alerts_contracts|can_view_alerts_guarantees|can_view_alerts_logistics')->group(function () {
        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alerts/send', [AlertController::class, 'send']);
        Route::get('/alerts/history', [AlertController::class, 'history']);
        Route::get('/alerts/templates', [AlertController::class, 'templates']);
        Route::put('/alerts/templates/{code}', [AlertController::class, 'updateTemplate'])->where('code', '[A-Z_]+');
        Route::delete('/alerts/templates/{code}', [AlertController::class, 'resetTemplate'])->where('code', '[A-Z_]+');
    });

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::middleware('permission:can_manage_companies')->group(function () {
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::put('/companies/{id}', [CompanyController::class, 'update'])->whereNumber('id');
        Route::delete('/companies/{id}', [CompanyController::class, 'destroy'])->whereNumber('id');
    });

    Route::middleware('permission:can_manage_users')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users/{id}/approve', [UserController::class, 'approve'])->whereNumber('id');
        Route::post('/users/{id}/reject', [UserController::class, 'reject'])->whereNumber('id');
        Route::post('/users/{id}/toggle', [UserController::class, 'toggle'])->whereNumber('id');
        Route::put('/users/{id}/permissions', [UserController::class, 'permissions'])->whereNumber('id');
        Route::post('/users/{id}/admin-reset', [UserController::class, 'adminReset'])->whereNumber('id');
        Route::put('/password-reset-requests/{id}/read', [UserController::class, 'markResetRead'])->whereNumber('id');
        Route::put('/users/{id}/assignment', [OrganizationController::class, 'assign'])->whereNumber('id');
    });

    // Socle organisationnel (TDR §5) : consultation pour l'affectation des comptes, administration réservée aux paramètres
    Route::middleware('permission:can_manage_settings|can_manage_users')->get('/organization', [OrganizationController::class, 'index']);
    Route::middleware('permission:can_manage_settings')->group(function () {
        Route::post('/organization/units', [OrganizationController::class, 'storeUnit']);
        Route::put('/organization/units/{id}', [OrganizationController::class, 'updateUnit'])->whereNumber('id');
        Route::put('/organization/units/{id}/toggle', [OrganizationController::class, 'toggleUnit'])->whereNumber('id');
        Route::delete('/organization/units/{id}', [OrganizationController::class, 'destroyUnit'])->whereNumber('id');
        Route::post('/organization/positions', [OrganizationController::class, 'storePosition']);
        Route::put('/organization/positions/{id}', [OrganizationController::class, 'updatePosition'])->whereNumber('id');
        Route::put('/organization/positions/{id}/toggle', [OrganizationController::class, 'togglePosition'])->whereNumber('id');
        Route::delete('/organization/positions/{id}', [OrganizationController::class, 'destroyPosition'])->whereNumber('id');
    });

    // Alertes par e-mail : destinataires et séquence d'envoi (super-administrateur, contrôlé dans le contrôleur)
    Route::prefix('alert-settings')->group(function () {
        Route::get('/', [AlertSettingsController::class, 'index']);
        Route::post('/recipients', [AlertSettingsController::class, 'storeRecipient']);
        Route::put('/recipients/{id}', [AlertSettingsController::class, 'updateRecipient'])->whereNumber('id');
        Route::put('/recipients/{id}/toggle', [AlertSettingsController::class, 'toggleRecipient'])->whereNumber('id');
        Route::delete('/recipients/{id}', [AlertSettingsController::class, 'destroyRecipient'])->whereNumber('id');
        Route::put('/rules', [AlertSettingsController::class, 'updateRules']);
        Route::post('/run', [AlertSettingsController::class, 'run'])->middleware('throttle:10,1');
    });

    // Logistique — tableau de bord : synthèse des modules autorisés (stock, charroi, groupes)
    Route::middleware('permission:can_view_stock|can_manage_stock|can_view_fleet|can_manage_fleet|can_authorize_fleet')
        ->get('/logistics/dashboard', [LogisticsController::class, 'dashboard']);

    // Logistique — stocks (TDR §6) : consultation par habilitation ; l'écriture est contrôlée dans le contrôleur (habilitation + unité)
    Route::middleware('permission:can_view_stock|can_manage_stock')->prefix('stock')->group(function () {
        Route::get('/', [StockController::class, 'overview']);
        Route::get('/articles/{id}/card', [StockController::class, 'card'])->whereNumber('id');
        Route::post('/movements', [StockController::class, 'storeMovement']);
        Route::post('/deliveries', [StockController::class, 'storeDelivery']);
        Route::post('/exits', [StockController::class, 'storeExits']);
        Route::post('/import', [StockController::class, 'import'])->middleware('throttle:20,1');
        Route::get('/import/template', [StockController::class, 'importTemplate']);
        Route::post('/movements/{id}/cancel', [StockController::class, 'cancelMovement'])->whereNumber('id');
        Route::post('/articles', [StockController::class, 'storeArticle']);
        Route::put('/articles/{id}', [StockController::class, 'updateArticle'])->whereNumber('id');
        Route::put('/articles/{id}/toggle', [StockController::class, 'toggleArticle'])->whereNumber('id');
        Route::delete('/articles/{id}', [StockController::class, 'destroyArticle'])->whereNumber('id');
    });

    // Charroi et groupes électrogènes (TDR §7, §8) : consultation, saisie logistique et visa comptable contrôlés dans le contrôleur
    Route::middleware('permission:can_view_fleet|can_manage_fleet|can_authorize_fleet')->prefix('fleet')->group(function () {
        Route::get('/', [FleetController::class, 'overview']);
        Route::post('/assets', [FleetController::class, 'storeAsset']);
        Route::put('/assets/{id}', [FleetController::class, 'updateAsset'])->whereNumber('id');
        Route::put('/assets/{id}/toggle', [FleetController::class, 'toggleAsset'])->whereNumber('id');
        Route::put('/assets/{id}/maintenance-plan', [FleetController::class, 'planMaintenance'])->whereNumber('id');
        Route::delete('/assets/{id}', [FleetController::class, 'destroyAsset'])->whereNumber('id');
        Route::get('/assets/{id}/logbook', [FleetController::class, 'logbook'])->whereNumber('id');
        Route::post('/logs', [FleetController::class, 'storeLog']);
        Route::post('/logs/{id}/cancel', [FleetController::class, 'cancelLog'])->whereNumber('id');
        Route::post('/logs/{id}/authorize', [FleetController::class, 'authorizeLog'])->whereNumber('id');
        Route::post('/logs/{id}/objection', [FleetController::class, 'objectLog'])->whereNumber('id');
        Route::put('/logs/{id}/assignee', [FleetController::class, 'reassignLog'])->whereNumber('id');
        Route::get('/authorizations', [FleetController::class, 'authorizations']);
        Route::get('/maintenances', [FleetController::class, 'maintenances']);
        Route::post('/maintenances', [FleetController::class, 'storeMaintenance']);
        Route::put('/maintenances/{id}', [FleetController::class, 'updateMaintenance'])->whereNumber('id');
        Route::delete('/maintenances/{id}', [FleetController::class, 'destroyMaintenance'])->whereNumber('id');
        Route::get('/insurances', [FleetController::class, 'insurances']);
        Route::post('/insurances', [FleetController::class, 'storeInsurance']);
        Route::put('/insurances/{id}', [FleetController::class, 'updateInsurance'])->whereNumber('id');
        Route::delete('/insurances/{id}', [FleetController::class, 'destroyInsurance'])->whereNumber('id');
        Route::get('/report', [FleetController::class, 'report']);
        Route::get('/report/export', [FleetController::class, 'exportReport']);
    });

    // Journal d'audit : filtres (personne, action, élément, période, texte), activité par compte, export
    Route::middleware('permission:can_manage_users|can_manage_settings')->group(function () {
        Route::get('/audit', [AuditController::class, 'index']);
        Route::get('/audit/users', [AuditController::class, 'users']);
        Route::get('/audit/export', [AuditController::class, 'export']);
    });

    Route::get('/archives', [PhaseController::class, 'archives'])->middleware('permission:can_view_archives');
    Route::middleware('permission:can_manage_settings')->group(function () {
        Route::get('/phases', [PhaseController::class, 'index']);
        Route::post('/phases', [PhaseController::class, 'store']);
        Route::put('/phases/{id}/current', [PhaseController::class, 'current'])->whereNumber('id');
        Route::put('/phases/{id}/archive', [PhaseController::class, 'archive'])->whereNumber('id');
        Route::put('/phases/{id}/restore', [PhaseController::class, 'restore'])->whereNumber('id');
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings/smtp', [SettingsController::class, 'saveSmtp']);
        Route::post('/settings/smtp/test', [SettingsController::class, 'testSmtp']);
        Route::post('/settings/recipients', [SettingsController::class, 'storeRecipient']);
        Route::put('/settings/recipients/{id}', [SettingsController::class, 'updateRecipient'])->whereNumber('id');
        Route::delete('/settings/recipients/{id}', [SettingsController::class, 'destroyRecipient'])->whereNumber('id');
        Route::post('/settings/backup', [SettingsController::class, 'backup']);
        Route::get('/settings/backups/{name}', [SettingsController::class, 'downloadBackup']);
    });
});
