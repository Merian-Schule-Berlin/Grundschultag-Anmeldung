<?php

declare(strict_types=1);

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ExportController;
use App\Controllers\Admin\FachbereichController;
use App\Controllers\Admin\MailController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\PublicController;
use App\Controllers\SetupController;
use App\Middleware\AdminAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/setup', [SetupController::class, 'show']);
    $app->post('/setup/test-db', [SetupController::class, 'testDb']);
    $app->post('/setup/test-mail', [SetupController::class, 'testMail']);
    $app->post('/setup/install', [SetupController::class, 'install']);

    $app->get('/', [PublicController::class, 'home']);
    $app->get('/anmelden', [PublicController::class, 'registerList']);
    $app->get('/anmelden/{id}', [PublicController::class, 'registerForm']);
    $app->post('/anmelden/{id}', [PublicController::class, 'registerSubmit']);
    $app->get('/danke/{token}', [PublicController::class, 'thanks']);
    $app->get('/stornieren/{token}', [PublicController::class, 'cancelForm']);
    $app->post('/stornieren/{token}', [PublicController::class, 'cancelSubmit']);
    $app->get('/impressum', [PublicController::class, 'impressum']);
    $app->get('/datenschutz', [PublicController::class, 'datenschutz']);
    $app->get('/woher', [PublicController::class, 'about']);
    $app->get('/robots.txt', [PublicController::class, 'robotsTxt']);
    $app->get('/sitemap.xml', [PublicController::class, 'sitemapXml']);

    $app->group('/administrator', function (RouteCollectorProxy $group): void {
        $group->get('/login', [AuthController::class, 'loginForm']);
        $group->post('/login', [AuthController::class, 'loginSubmit']);
        $group->post('/logout', [AuthController::class, 'logout']);

        $group->group('', function (RouteCollectorProxy $admin): void {
            $admin->get('', [DashboardController::class, 'index']);
            $admin->get('/', [DashboardController::class, 'index']);

            $admin->get('/fachbereiche', [FachbereichController::class, 'index']);
            $admin->get('/fachbereiche/neu', [FachbereichController::class, 'createForm']);
            $admin->post('/fachbereiche', [FachbereichController::class, 'create']);
            $admin->post('/fachbereiche/reorder', [FachbereichController::class, 'reorder']);
            $admin->get('/fachbereiche/{id}', [FachbereichController::class, 'editForm']);
            $admin->post('/fachbereiche/{id}', [FachbereichController::class, 'update']);
            $admin->post('/fachbereiche/{id}/toggle', [FachbereichController::class, 'toggle']);
            $admin->post('/fachbereiche/{id}/delete', [FachbereichController::class, 'delete']);
            $admin->post('/fachbereiche/{id}/schienen', [FachbereichController::class, 'addSchiene']);
            $admin->post('/fachbereiche/{id}/schienen/reorder', [FachbereichController::class, 'reorderSchienen']);
            $admin->post('/fachbereiche/{id}/schienen/{schieneId}', [FachbereichController::class, 'updateSchiene']);
            $admin->post('/fachbereiche/{id}/schienen/{schieneId}/delete', [FachbereichController::class, 'deleteSchiene']);

            $admin->get('/listen', [ExportController::class, 'index']);
            $admin->get('/listen/alle.zip', [ExportController::class, 'downloadAll']);
            $admin->get('/listen/{id}/schiene/{schieneId}.pdf', [ExportController::class, 'download']);
            $admin->post('/listen/{id}/senden', [ExportController::class, 'sendToFachbereich']);

            $admin->get('/email', [MailController::class, 'form']);
            $admin->post('/email', [MailController::class, 'send']);

            $admin->get('/einstellungen', [SettingsController::class, 'form']);
            $admin->post('/einstellungen', [SettingsController::class, 'save']);
            $admin->post('/einstellungen/benutzer', [SettingsController::class, 'createUser']);
            $admin->post('/einstellungen/benutzer/{id}/delete', [SettingsController::class, 'deleteUser']);
            $admin->post('/einstellungen/daten-loeschen', [SettingsController::class, 'wipeData']);
            $admin->post('/einstellungen/indexierung', [SettingsController::class, 'saveIndexing']);
            $admin->post('/einstellungen/bot-schutz', [SettingsController::class, 'saveBotProtection']);
            $admin->post('/einstellungen/update-quelle', [SettingsController::class, 'saveUpdateSource']);
            $admin->post('/einstellungen/update-check', [SettingsController::class, 'checkUpdate']);
            $admin->post('/einstellungen/update', [SettingsController::class, 'runUpdate']);
        })->add(AdminAuthMiddleware::class);
    });
};
