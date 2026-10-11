<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\HtmlContentService;
use App\Services\SettingsService;
use App\Services\UpdateService;
use App\Services\UploadService;
use RuntimeException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SettingsService $settings,
        private readonly UploadService $uploads,
        private readonly CsrfService $csrf,
        private readonly AuthService $auth,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen,
        private readonly UpdateService $updater,
        private readonly HtmlContentService $html
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        $tab = (string) ($request->getQueryParams()['tab'] ?? 'uebersicht');

        return $this->renderSettings($response, $flash, $error, null, $tab);
    }

    public function save(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $current = $this->settings->all();
        $section = (string) ($data['settings_section'] ?? 'all');
        $ajax = $this->isAjax($request);
        $tab = (string) ($data['active_tab'] ?? ($section !== 'all' ? $section : 'darstellung'));

        try {
            $updates = [];
            $reload = false;

            if (in_array($section, ['darstellung', 'all'], true)) {
                $part = $this->buildDarstellungUpdates($data, $files, $current);
                $updates = array_merge($updates, $part['updates']);
                $reload = $reload || $part['reload'];
            }
            if (in_array($section, ['zeitraum', 'all'], true)) {
                $updates = array_merge($updates, [
                    'registration_start' => trim((string) ($data['registration_start'] ?? '')),
                    'registration_end' => trim((string) ($data['registration_end'] ?? '')),
                ]);
            }
            if (in_array($section, ['email', 'all'], true)) {
                $updates = array_merge($updates, [
                    'mail_from' => trim((string) ($data['mail_from'] ?? '')),
                    'mail_from_name' => trim((string) ($data['mail_from_name'] ?? '')),
                    'mail_subject' => trim((string) ($data['mail_subject'] ?? '')),
                    'mail_body' => $this->html->sanitize((string) ($data['mail_body'] ?? '')),
                    'mail_cancel_subject' => trim((string) ($data['mail_cancel_subject'] ?? '')),
                    'mail_cancel_body' => $this->html->sanitize((string) ($data['mail_cancel_body'] ?? '')),
                ]);
            }
            if (in_array($section, ['rechtliches', 'all'], true)) {
                $updates = array_merge($updates, $this->buildRechtlichesUpdates($data));
            }

            if ($updates === []) {
                throw new RuntimeException('Keine Einstellungen zum Speichern.');
            }

            $this->settings->setMany($updates);
            $this->applySettingsGlobals($updates, $current);

            if ($ajax) {
                return $this->json($response, [
                    'ok' => true,
                    'reload' => $reload,
                    'message' => 'Gespeichert.',
                ]);
            }

            $_SESSION['flash'] = 'Einstellungen gespeichert.';
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=' . urlencode($tab))
                ->withStatus(302);
        } catch (\Throwable $e) {
            if ($ajax) {
                return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 400);
            }
            return $this->renderSettings(
                $response->withStatus(400),
                null,
                $e->getMessage(),
                array_merge($current, $data),
                $tab
            );
        }
    }

    public function createUser(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $role = (string) ($data['role'] ?? AuthService::ROLE_ADMIN);
            $fbRaw = $data['fachbereich_id'] ?? '';
            $this->auth->createUser([
                'username' => trim((string) ($data['username'] ?? '')),
                'password' => (string) ($data['password'] ?? ''),
                'role' => $role,
                'fachbereich_id' => $fbRaw !== '' && $fbRaw !== null ? (int) $fbRaw : null,
            ]);
            $_SESSION['flash'] = 'Benutzer angelegt.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=benutzer')
            ->withStatus(302);
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->auth->deleteUser((int) $args['id']);
            $_SESSION['flash'] = 'Benutzer gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=benutzer')
            ->withStatus(302);
    }

    public function wipeData(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $confirm = trim((string) ($data['confirm'] ?? ''));
        if ($confirm !== 'LÖSCHEN') {
            $_SESSION['flash_error'] = 'Bitte zur Bestätigung genau LÖSCHEN eingeben.';
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=daten')
                ->withStatus(302);
        }

        try {
            $count = $this->anmeldungen->deleteAll();
            $_SESSION['flash'] = $count === 1
                ? '1 Anmeldung wurde gelöscht.'
                : $count . ' Anmeldungen wurden gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=daten')
            ->withStatus(302);
    }

    public function saveIndexing(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $ajax = $this->isAjax($request);
        $enabled = !empty($data['google_indexing']);
        $metaDescription = trim((string) ($data['meta_description'] ?? ''));
        if (mb_strlen($metaDescription) > 300) {
            $error = 'Die Meta-Beschreibung darf höchstens 300 Zeichen lang sein.';
            if ($ajax) {
                return $this->json($response->withStatus(400), ['ok' => false, 'error' => $error]);
            }
            $_SESSION['flash_error'] = $error;
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=indexierung')
                ->withStatus(302);
        }

        $this->settings->setMany([
            'google_indexing' => $enabled ? '1' : '0',
            'meta_description' => $metaDescription,
        ]);
        $this->view->getEnvironment()->addGlobal('google_indexing', $enabled);
        $this->view->getEnvironment()->addGlobal('meta_description', $metaDescription);

        $message = $enabled
            ? 'Indexierung gespeichert. Suchmaschinen dürfen die öffentlichen Seiten erfassen.'
            : 'Indexierung gespeichert. Suchmaschinen werden angewiesen, die Seite nicht zu indexieren.';

        if ($ajax) {
            return $this->json($response, ['ok' => true, 'message' => $message]);
        }

        $_SESSION['flash'] = $message;

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=indexierung')
            ->withStatus(302);
    }

    public function saveBotProtection(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $ajax = $this->isAjax($request);
        try {
            $provider = $this->settings->normalizeBotProtectionProvider(
                (string) ($data['bot_protection_provider'] ?? SettingsService::BOT_OFF)
            );
            $siteKey = trim((string) ($data['bot_protection_site_key'] ?? ''));
            $secretKey = trim((string) ($data['bot_protection_secret_key'] ?? ''));

            if ($provider !== SettingsService::BOT_OFF) {
                if ($siteKey === '' || $secretKey === '') {
                    throw new RuntimeException(
                        'Bitte Site Key und Secret Key eintragen oder den Bot-Schutz auf „Aus“ stellen.'
                    );
                }
            }

            $this->settings->setMany([
                'bot_protection_provider' => $provider,
                'bot_protection_site_key' => $siteKey,
                'bot_protection_secret_key' => $secretKey,
            ]);

            $message = match ($provider) {
                SettingsService::BOT_RECAPTCHA => 'Bot-Schutz mit Google reCAPTCHA ist aktiv.',
                SettingsService::BOT_TURNSTILE => 'Bot-Schutz mit Cloudflare Turnstile ist aktiv.',
                default => 'Bot-Schutz ist deaktiviert.',
            };

            if ($ajax) {
                return $this->json($response, ['ok' => true, 'message' => $message]);
            }
            $_SESSION['flash'] = $message;
        } catch (\Throwable $e) {
            if ($ajax) {
                return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 400);
            }
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=bot-schutz')
            ->withStatus(302);
    }

    public function saveUpdateSource(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $ajax = $this->isAjax($request);
        try {
            $this->persistUpdateSource($data);
            $message = 'Update-Quelle gespeichert. Prüfung und Aktualisierung nutzen diese Quelle dauerhaft.';
            if ($ajax) {
                return $this->json($response, ['ok' => true, 'message' => $message]);
            }
            $_SESSION['flash'] = $message;
        } catch (\Throwable $e) {
            if ($ajax) {
                return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 400);
            }
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
            ->withStatus(302);
    }

    public function checkUpdate(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $this->persistUpdateSource($data, true);
            $result = $this->updater->checkForUpdate(true);
            if ($result === null) {
                throw new RuntimeException('Remote-Version konnte nicht gelesen werden. URL und Branch/Tag prüfen.');
            }
            if ($result['available']) {
                $_SESSION['flash'] = 'Update verfügbar: ' . $result['current'] . ' → ' . $result['remote'] . '.';
            } else {
                $_SESSION['flash'] = 'Aktuell: Version ' . $result['current']
                    . ' (Remote: ' . $result['remote'] . ').';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
            ->withStatus(302);
    }

    public function runUpdate(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($request, $response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $this->persistUpdateSource($data, true);
            @set_time_limit(300);
            $result = $this->updater->applyConfigured();
            $_SESSION['flash'] = $result['message'];
            unset($_SESSION['update_notice']);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
            ->withStatus(302);
    }

    /**
     * Speichert die Update-Quelle aus dem Formular dauerhaft in den Settings.
     * Wenn keine URL im Request steht und $allowSaved true ist, bleibt die gespeicherte Quelle.
     *
     * @param array<string, mixed> $data
     */
    private function persistUpdateSource(array $data, bool $allowSaved = false): void
    {
        $repo = trim((string) ($data['update_repo_url'] ?? ''));
        $ref = trim((string) ($data['update_ref'] ?? ''));

        if ($repo !== '') {
            $this->updater->savePreferences(
                $repo,
                $ref !== '' ? $ref : UpdateService::DEFAULT_REF
            );
            return;
        }

        if ($allowSaved && $this->updater->isConfigured()) {
            return;
        }

        throw new RuntimeException('Bitte eine GitHub-Repository-URL als Update-Quelle angeben.');
    }

    private function requireFullAdmin(Request $request, Response $response): ?Response
    {
        if ($this->auth->canManageSettings()) {
            return null;
        }
        if ($this->isAjax($request)) {
            return $this->json($response, ['ok' => false, 'error' => 'Keine Berechtigung für die Einstellungen.'], 403);
        }
        $_SESSION['flash'] = 'Keine Berechtigung für die Einstellungen.';
        return $response->withHeader('Location', '/administrator')->withStatus(302);
    }

    private function isAjax(Request $request): bool
    {
        return strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $files
     * @param array<string, mixed> $current
     * @return array{updates: array<string, string>, reload: bool}
     */
    private function buildDarstellungUpdates(array $data, array $files, array $current): array
    {
        $reload = false;
        $image = (string) ($current['welcome_image'] ?? '');
        if (isset($files['welcome_image']) && $files['welcome_image']->getError() !== UPLOAD_ERR_NO_FILE) {
            $file = $files['welcome_image'];
            $tmp = tempnam(sys_get_temp_dir(), 'upl');
            if ($tmp === false) {
                throw new RuntimeException('Temporäre Datei fehlgeschlagen.');
            }
            $file->moveTo($tmp);
            $new = $this->uploads->storeImage([
                'name' => $file->getClientFilename() ?? 'upload',
                'type' => $file->getClientMediaType() ?? '',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => $file->getSize() ?? 0,
            ], 'welcome');
            $this->uploads->delete($image);
            $image = $new;
            $reload = true;
        }
        if (!empty($data['remove_welcome_image'])) {
            $this->uploads->delete($image);
            $image = '';
            $reload = true;
        }

        $logo = (string) ($current['site_logo'] ?? '');
        if (isset($files['site_logo']) && $files['site_logo']->getError() !== UPLOAD_ERR_NO_FILE) {
            $file = $files['site_logo'];
            $tmp = tempnam(sys_get_temp_dir(), 'upl');
            if ($tmp === false) {
                throw new RuntimeException('Temporäre Datei fehlgeschlagen.');
            }
            $file->moveTo($tmp);
            $new = $this->uploads->storeImage([
                'name' => $file->getClientFilename() ?? 'upload',
                'type' => $file->getClientMediaType() ?? '',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => $file->getSize() ?? 0,
            ], 'logo');
            $this->uploads->delete($logo);
            $logo = $new;
            $reload = true;
        }
        if (!empty($data['remove_site_logo'])) {
            $this->uploads->delete($logo);
            $logo = '';
            $reload = true;
        }

        $siteTitle = trim((string) ($data['site_title'] ?? ''));
        if ($siteTitle === '') {
            throw new RuntimeException('Bitte einen Seitentitel angeben.');
        }
        if (mb_strlen($siteTitle) > 150) {
            throw new RuntimeException('Der Seitentitel darf höchstens 150 Zeichen lang sein.');
        }

        $siteSubtitle = trim((string) ($data['site_subtitle'] ?? ''));
        if (mb_strlen($siteSubtitle) > 150) {
            throw new RuntimeException('Der Untertitel darf höchstens 150 Zeichen lang sein.');
        }

        $homepageEnabled = !empty($data['homepage_button_enabled']);
        $homepageUrlRaw = trim((string) ($data['homepage_button_url'] ?? ''));
        $homepageUrl = $this->settings->normalizeLegalUrl($homepageUrlRaw);
        if ($homepageEnabled) {
            if ($homepageUrlRaw === '') {
                throw new RuntimeException('Bitte eine Homepage-URL angeben oder den Button deaktivieren.');
            }
            if ($homepageUrl === '') {
                throw new RuntimeException('Die Homepage-URL muss mit http:// oder https:// beginnen.');
            }
        } elseif ($homepageUrlRaw !== '' && $homepageUrl === '') {
            throw new RuntimeException('Die Homepage-URL muss mit http:// oder https:// beginnen.');
        }

        $homepageLabel = trim((string) ($data['homepage_button_label'] ?? ''));
        if ($homepageLabel === '') {
            $homepageLabel = 'Zur Homepage';
        }
        if (mb_strlen($homepageLabel) > 80) {
            throw new RuntimeException('Der Button-Text darf höchstens 80 Zeichen lang sein.');
        }

        $publicThemeToggle = !empty($data['public_theme_toggle']);

        return [
            'updates' => [
                'site_title' => $siteTitle,
                'site_subtitle' => $siteSubtitle,
                'welcome_text' => $this->html->sanitize((string) ($data['welcome_text'] ?? '')),
                'welcome_image' => $image,
                'site_logo' => $logo,
                'capacity_display' => $this->settings->normalizeCapacityDisplay(
                    (string) ($data['capacity_display'] ?? SettingsService::CAPACITY_FREE_NUMBERS)
                ),
                'full_item_behavior' => $this->settings->normalizeFullItemBehavior(
                    (string) ($data['full_item_behavior'] ?? SettingsService::FULL_OPEN)
                ),
                'homepage_button_enabled' => $homepageEnabled ? '1' : '0',
                'homepage_button_url' => $homepageUrl,
                'homepage_button_label' => $homepageLabel,
                'public_theme_toggle' => $publicThemeToggle ? '1' : '0',
            ],
            'reload' => $reload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function buildRechtlichesUpdates(array $data): array
    {
        $impressumUrl = trim((string) ($data['impressum_url'] ?? ''));
        $datenschutzUrl = trim((string) ($data['datenschutz_url'] ?? ''));
        if ($impressumUrl !== '' && $this->settings->normalizeLegalUrl($impressumUrl) === '') {
            throw new RuntimeException('Impressum-URL muss mit http:// oder https:// beginnen.');
        }
        if ($datenschutzUrl !== '' && $this->settings->normalizeLegalUrl($datenschutzUrl) === '') {
            throw new RuntimeException('Datenschutz-URL muss mit http:// oder https:// beginnen.');
        }

        $aboutLabel = trim((string) ($data['about_link_label'] ?? ''));
        if ($aboutLabel === '') {
            $aboutLabel = 'Woher kommt diese Seite';
        }
        if (mb_strlen($aboutLabel) > 80) {
            throw new RuntimeException('Der Linkname darf höchstens 80 Zeichen lang sein.');
        }

        return [
            'impressum_url' => $this->settings->normalizeLegalUrl($impressumUrl),
            'impressum_text' => $this->html->sanitize((string) ($data['impressum_text'] ?? '')),
            'datenschutz_url' => $this->settings->normalizeLegalUrl($datenschutzUrl),
            'datenschutz_text' => $this->html->sanitize((string) ($data['datenschutz_text'] ?? '')),
            'about_link_label' => $aboutLabel,
            'about_text' => $this->html->sanitize((string) ($data['about_text'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $updates
     * @param array<string, mixed> $current
     */
    private function applySettingsGlobals(array $updates, array $current): void
    {
        if (array_key_exists('site_title', $updates)) {
            $this->view->getEnvironment()->addGlobal('app_name', $updates['site_title']);
        }
        if (array_key_exists('site_subtitle', $updates)) {
            $this->view->getEnvironment()->addGlobal('site_subtitle', $updates['site_subtitle']);
        }
        if (array_key_exists('site_logo', $updates)) {
            $this->view->getEnvironment()->addGlobal('site_logo', $updates['site_logo']);
        }
        if (array_key_exists('homepage_button_enabled', $updates)
            || array_key_exists('homepage_button_url', $updates)
            || array_key_exists('homepage_button_label', $updates)
        ) {
            $enabled = ($updates['homepage_button_enabled'] ?? $current['homepage_button_enabled'] ?? '0') === '1';
            $url = (string) ($updates['homepage_button_url'] ?? $current['homepage_button_url'] ?? '');
            $label = (string) ($updates['homepage_button_label'] ?? $current['homepage_button_label'] ?? 'Zur Homepage');
            $this->view->getEnvironment()->addGlobal(
                'homepage_button_href',
                $enabled && $url !== '' ? $url : ''
            );
            $this->view->getEnvironment()->addGlobal('homepage_button_label', $label);
        }
        if (array_key_exists('public_theme_toggle', $updates)) {
            $this->view->getEnvironment()->addGlobal(
                'public_theme_toggle',
                $updates['public_theme_toggle'] === '1'
            );
        }
        if (array_key_exists('impressum_url', $updates)
            || array_key_exists('impressum_text', $updates)
            || array_key_exists('datenschutz_url', $updates)
            || array_key_exists('datenschutz_text', $updates)
            || array_key_exists('about_text', $updates)
            || array_key_exists('about_link_label', $updates)
        ) {
            $impressumHref = $this->settings->legalHref('impressum_url', 'impressum_text', '/impressum');
            $datenschutzHref = $this->settings->legalHref('datenschutz_url', 'datenschutz_text', '/datenschutz');
            $aboutHref = trim($this->settings->get('about_text')) !== '' ? '/woher' : '';
            $aboutLabel = trim($this->settings->get('about_link_label')) ?: 'Woher kommt diese Seite';
            $this->view->getEnvironment()->addGlobal('impressum_href', $impressumHref);
            $this->view->getEnvironment()->addGlobal('datenschutz_href', $datenschutzHref);
            $this->view->getEnvironment()->addGlobal('about_href', $aboutHref);
            $this->view->getEnvironment()->addGlobal('about_link_label', $aboutLabel);
            $this->view->getEnvironment()->addGlobal('impressum_external', $this->settings->isExternalHref($impressumHref));
            $this->view->getEnvironment()->addGlobal('datenschutz_external', $this->settings->isExternalHref($datenschutzHref));
        }
    }

    /**
     * @param array<string, mixed>|null $settingsOverride
     */
    private function renderSettings(
        Response $response,
        ?string $flash,
        ?string $error,
        ?array $settingsOverride = null,
        ?string $activeTab = null
    ): Response {
        $allowed = [
            'uebersicht',
            'darstellung',
            'zeitraum',
            'email',
            'rechtliches',
            'benutzer',
            'system',
            'indexierung',
            'bot-schutz',
            'daten',
            'update',
        ];
        $tab = $activeTab ?? 'uebersicht';
        if (!in_array($tab, $allowed, true)) {
            $tab = 'uebersicht';
        }

        $updateStatus = null;
        if ($tab === 'update') {
            try {
                $updateStatus = $this->updater->status();
            } catch (\Throwable $e) {
                $error = $error ?? $e->getMessage();
                $updateStatus = [
                    'repo_url' => $this->updater->configuredRepoUrl(),
                    'ref' => $this->updater->configuredRef(),
                    'current' => $this->updater->currentVersion(),
                    'remote' => null,
                    'up_to_date' => null,
                    'configured' => $this->updater->isConfigured(),
                    'download_url' => '',
                    'check_at' => '',
                ];
            }
        }

        $settings = $settingsOverride ?? $this->settings->all();
        if (trim((string) ($settings['mail_body'] ?? '')) === '') {
            $settings['mail_body'] = AnmeldungService::defaultMailBody();
        }
        if (trim((string) ($settings['mail_cancel_subject'] ?? '')) === '') {
            $settings['mail_cancel_subject'] = AnmeldungService::defaultCancelMailSubject();
        }
        if (trim((string) ($settings['mail_cancel_body'] ?? '')) === '') {
            $settings['mail_cancel_body'] = AnmeldungService::defaultCancelMailBody();
        }

        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'mail_body_default' => AnmeldungService::defaultMailBody(),
            'mail_cancel_body_default' => AnmeldungService::defaultCancelMailBody(),
            'users' => $this->auth->listUsers(),
            'fachbereiche' => $this->fachbereiche->all(),
            'anmeldungen_total' => $this->anmeldungen->countAll(),
            'flash' => $flash,
            'error' => $error,
            'active_tab' => $tab,
            'current_admin_id' => $this->auth->id(),
            'update_status' => $updateStatus,
        ]);
    }
}
