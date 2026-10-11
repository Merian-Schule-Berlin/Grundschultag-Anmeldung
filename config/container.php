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
use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\BotProtectionService;
use App\Services\CsrfService;
use App\Services\ExportService;
use App\Services\FachbereichService;
use App\Services\HtmlContentService;
use App\Services\InstallerService;
use App\Services\LoginThrottleService;
use App\Services\MailService;
use App\Services\SchemaMigrator;
use App\Services\SettingsService;
use App\Services\UpdateService;
use App\Services\UploadService;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Twig\TwigFilter;

$root = dirname(__DIR__);
$settings = require __DIR__ . '/settings.php';

return [
    'settings' => $settings,
    'root_path' => $root,

    PDO::class => static function (ContainerInterface $c): PDO {
        $db = $c->get('settings')['db'];
        if ($db['name'] === '' || $db['user'] === '') {
            throw new RuntimeException('Datenbank ist noch nicht konfiguriert.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        (new SchemaMigrator())->migrate($pdo);
        return $pdo;
    },

    Twig::class => static function (ContainerInterface $c): Twig {
        $debug = (bool) ($c->get('settings')['display_error_details'] ?? false);
        $twigCache = $c->get('root_path') . '/storage/twig_cache';
        $twig = Twig::create($c->get('root_path') . '/templates', [
            // Kompilierte Templates cachen (weniger CPU pro Request); bei APP_DEBUG=1 aus
            'cache' => $debug ? false : $twigCache,
            'debug' => $debug,
            'auto_reload' => true,
        ]);
        $appName = (string) ($c->get('settings')['app_name'] ?? 'Grundschultag Anmeldung');
        $twig->getEnvironment()->addGlobal('csrf', $c->get(CsrfService::class));
        $siteLogo = '';
        $siteSubtitle = '';
        $impressumHref = '';
        $datenschutzHref = '';
        $aboutHref = '';
        $aboutLinkLabel = 'Woher kommt diese Seite';
        $homepageButtonHref = '';
        $homepageButtonLabel = 'Zur Homepage';
        $publicThemeToggle = false;
        $googleIndexing = false;
        $metaDescription = '';
        $appUrl = rtrim((string) ($c->get('settings')['app_url'] ?? ''), '/');
        $requestPath = '/';
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parsedPath = parse_url($rawUri, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $requestPath = $parsedPath;
        }
        try {
            $db = $c->get('settings')['db'] ?? [];
            if (($db['name'] ?? '') !== '' && is_file($c->get('root_path') . '/.env')) {
                $settingsService = $c->get(SettingsService::class);
                $savedTitle = trim($settingsService->get('site_title'));
                if ($savedTitle !== '') {
                    $appName = $savedTitle;
                }
                $siteSubtitle = trim($settingsService->get('site_subtitle'));
                $siteLogo = $settingsService->get('site_logo');
                $impressumHref = $settingsService->legalHref('impressum_url', 'impressum_text', '/impressum');
                $datenschutzHref = $settingsService->legalHref('datenschutz_url', 'datenschutz_text', '/datenschutz');
                $savedAboutLabel = trim($settingsService->get('about_link_label'));
                if ($savedAboutLabel !== '') {
                    $aboutLinkLabel = $savedAboutLabel;
                }
                if (trim($settingsService->get('about_text')) !== '') {
                    $aboutHref = '/woher';
                }
                $homepageButtonLabel = $settingsService->homepageButtonLabel();
                if ($settingsService->homepageButtonEnabled()) {
                    $homepageButtonHref = $settingsService->homepageButtonUrl();
                }
                $publicThemeToggle = $settingsService->publicThemeToggleEnabled();
                $googleIndexing = $settingsService->googleIndexingEnabled();
                $metaDescription = $settingsService->metaDescription();
            }
        } catch (Throwable) {
            $siteLogo = '';
            $siteSubtitle = '';
            $impressumHref = '';
            $datenschutzHref = '';
            $aboutHref = '';
            $aboutLinkLabel = 'Woher kommt diese Seite';
            $homepageButtonHref = '';
            $homepageButtonLabel = 'Zur Homepage';
            $publicThemeToggle = false;
            $googleIndexing = false;
            $metaDescription = '';
        }
        $assetVersion = '1';
        $versionFile = $c->get('root_path') . '/VERSION';
        if (is_file($versionFile)) {
            $v = trim((string) file_get_contents($versionFile));
            if ($v !== '') {
                $assetVersion = $v;
            }
        }
        $twig->getEnvironment()->addGlobal('asset_version', $assetVersion);
        $twig->getEnvironment()->addGlobal('app_name', $appName);
        $twig->getEnvironment()->addGlobal('site_subtitle', $siteSubtitle);
        $twig->getEnvironment()->addGlobal('site_logo', $siteLogo);
        $twig->getEnvironment()->addGlobal('impressum_href', $impressumHref);
        $twig->getEnvironment()->addGlobal('datenschutz_href', $datenschutzHref);
        $twig->getEnvironment()->addGlobal('about_href', $aboutHref);
        $twig->getEnvironment()->addGlobal('about_link_label', $aboutLinkLabel);
        $twig->getEnvironment()->addGlobal('homepage_button_href', $homepageButtonHref);
        $twig->getEnvironment()->addGlobal('homepage_button_label', $homepageButtonLabel);
        $twig->getEnvironment()->addGlobal('public_theme_toggle', $publicThemeToggle);
        $twig->getEnvironment()->addGlobal('google_indexing', $googleIndexing);
        $twig->getEnvironment()->addGlobal('meta_description', $metaDescription);
        $twig->getEnvironment()->addGlobal('app_url', $appUrl);
        $twig->getEnvironment()->addGlobal('request_path', $requestPath);
        $twig->getEnvironment()->addGlobal(
            'impressum_external',
            $impressumHref !== '' && preg_match('#^https?://#i', $impressumHref) === 1
        );
        $twig->getEnvironment()->addGlobal(
            'datenschutz_external',
            $datenschutzHref !== '' && preg_match('#^https?://#i', $datenschutzHref) === 1
        );

        $htmlContent = $c->get(HtmlContentService::class);
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'rich',
            static fn (?string $value): string => $htmlContent->toSafeHtml((string) ($value ?? '')),
            ['is_safe' => ['html']]
        ));

        return $twig;
    },

    CsrfService::class => static fn () => new CsrfService(),
    HtmlContentService::class => static fn () => new HtmlContentService(),
    InstallerService::class => static fn (ContainerInterface $c) => new InstallerService($c->get('root_path')),
    MailService::class => static fn (ContainerInterface $c) => new MailService($c->get('settings')),
    SettingsService::class => static fn (ContainerInterface $c) => new SettingsService($c->get(PDO::class)),
    BotProtectionService::class => static fn (ContainerInterface $c) => new BotProtectionService(
        $c->get(SettingsService::class)
    ),
    UpdateService::class => static fn (ContainerInterface $c) => new UpdateService(
        $c->get('root_path'),
        $c->get(SettingsService::class)
    ),
    AuthService::class => static fn (ContainerInterface $c) => new AuthService($c->get(PDO::class)),
    FachbereichService::class => static fn (ContainerInterface $c) => new FachbereichService($c->get(PDO::class)),
    AnmeldungService::class => static function (ContainerInterface $c) {
        return new AnmeldungService(
            $c->get(PDO::class),
            $c->get(SettingsService::class),
            $c->get(MailService::class),
            $c->get(HtmlContentService::class),
            $c->get('settings')
        );
    },
    ExportService::class => static fn (ContainerInterface $c) => new ExportService(
        $c->get(PDO::class),
        $c->get('root_path') . '/public/uploads'
    ),
    UploadService::class => static fn (ContainerInterface $c) => new UploadService($c->get('root_path') . '/public/uploads'),

    SetupController::class => static function (ContainerInterface $c) {
        return new SetupController(
            $c->get(Twig::class),
            $c->get(InstallerService::class),
            $c->get(MailService::class),
            $c->get(CsrfService::class),
            $c->get('root_path')
        );
    },
    PublicController::class => static function (ContainerInterface $c) {
        return new PublicController(
            $c->get(Twig::class),
            $c->get(SettingsService::class),
            $c->get(FachbereichService::class),
            $c->get(AnmeldungService::class),
            $c->get(CsrfService::class),
            $c->get(AuthService::class),
            $c->get(BotProtectionService::class)
        );
    },
    LoginThrottleService::class => static function (ContainerInterface $c) {
        return new LoginThrottleService($c->get('root_path') . '/storage/login_throttle');
    },
    AuthController::class => static function (ContainerInterface $c) {
        return new AuthController(
            $c->get(Twig::class),
            $c->get(AuthService::class),
            $c->get(LoginThrottleService::class),
            $c->get(UpdateService::class)
        );
    },
    DashboardController::class => static function (ContainerInterface $c) {
        return new DashboardController(
            $c->get(Twig::class),
            $c->get(FachbereichService::class),
            $c->get(AnmeldungService::class),
            $c->get(AuthService::class),
            $c->get(UpdateService::class),
            $c->get(SettingsService::class)
        );
    },
    FachbereichController::class => static function (ContainerInterface $c) {
        return new FachbereichController(
            $c->get(Twig::class),
            $c->get(FachbereichService::class),
            $c->get(UploadService::class),
            $c->get(CsrfService::class),
            $c->get(AuthService::class),
            $c->get(HtmlContentService::class)
        );
    },
    SettingsController::class => static function (ContainerInterface $c) {
        return new SettingsController(
            $c->get(Twig::class),
            $c->get(SettingsService::class),
            $c->get(UploadService::class),
            $c->get(CsrfService::class),
            $c->get(AuthService::class),
            $c->get(FachbereichService::class),
            $c->get(AnmeldungService::class),
            $c->get(UpdateService::class),
            $c->get(HtmlContentService::class)
        );
    },
    ExportController::class => static function (ContainerInterface $c) {
        return new ExportController(
            $c->get(Twig::class),
            $c->get(FachbereichService::class),
            $c->get(ExportService::class),
            $c->get(SettingsService::class),
            $c->get(AuthService::class),
            $c->get(MailService::class)
        );
    },
    MailController::class => static function (ContainerInterface $c) {
        return new MailController(
            $c->get(Twig::class),
            $c->get(AuthService::class),
            $c->get(FachbereichService::class),
            $c->get(AnmeldungService::class),
            $c->get(MailService::class),
            $c->get(SettingsService::class),
            $c->get(HtmlContentService::class),
            $c->get('settings')
        );
    },
    AdminAuthMiddleware::class => static function (ContainerInterface $c) {
        return new AdminAuthMiddleware(
            $c->get(Twig::class),
            $c->get(AuthService::class),
            $c->get(FachbereichService::class)
        );
    },
];
