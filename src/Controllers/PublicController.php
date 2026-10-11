<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\BotProtectionService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PublicController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SettingsService $settings,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen,
        private readonly CsrfService $csrf,
        private readonly AuthService $auth,
        private readonly BotProtectionService $botProtection
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $all = $this->settings->all();
        return $this->view->render($response, 'public/home.twig', [
            'welcome_text' => $all['welcome_text'] ?? '',
            'welcome_image' => $all['welcome_image'] ?? '',
            'open' => $this->settings->isRegistrationOpen(),
            'registration_start' => $all['registration_start'] ?? '',
            'registration_end' => $all['registration_end'] ?? '',
            'admin_logged_in' => $this->auth->check(),
        ]);
    }

    public function registerList(Request $request, Response $response): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->view->render($response, 'public/register_list.twig', [
            'fachbereiche' => $this->activeFachbereicheWithSchienen(),
            'admin_test_mode' => !$open && $adminTest,
            'flash' => $flash,
            'capacity_display' => $this->settings->capacityDisplay(),
            'full_item_behavior' => $this->settings->fullItemBehavior(),
        ]);
    }

    public function registerForm(Request $request, Response $response, array $args): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $fachbereich = $this->findActiveFachbereichWithSchienen((int) $args['id']);
        if ($fachbereich === null) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist nicht verfügbar.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        if ($this->isFachbereichBlockedWhenFull($fachbereich) && !$adminTest) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist ausgebucht.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        return $this->view->render($response, 'public/register.twig', [
            'fachbereich' => $fachbereich,
            'error' => null,
            'old' => [],
            'admin_test_mode' => !$open && $adminTest,
            'capacity_display' => $this->settings->capacityDisplay(),
            'bot_protection' => $this->botProtection->widgetConfig(),
        ]);
    }

    public function registerSubmit(Request $request, Response $response, array $args): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $fachbereichId = (int) $args['id'];
        $fachbereich = $this->findActiveFachbereichWithSchienen($fachbereichId);
        if ($fachbereich === null) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist nicht verfügbar.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $schieneId = (int) ($data['schiene_id'] ?? 0);

        if ($this->isFachbereichBlockedWhenFull($fachbereich) && !$adminTest) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist ausgebucht.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        try {
            $this->botProtection->assertValid($data, $this->clientIp($request));
            $this->assertSchieneBelongsToFachbereich($schieneId, $fachbereichId);
            $anmeldung = $this->anmeldungen->register([
                'name' => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'schiene_id' => $schieneId,
            ], !$open && $adminTest);
            return $response
                ->withHeader('Location', '/danke/' . $anmeldung['token'])
                ->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'public/register.twig', [
                'fachbereich' => $fachbereich,
                'error' => $e->getMessage(),
                'old' => $data,
                'admin_test_mode' => !$open && $adminTest,
                'capacity_display' => $this->settings->capacityDisplay(),
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        }
    }

    public function thanks(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/thanks.twig', [
            'anmeldung' => $anmeldung,
        ]);
    }

    public function cancelForm(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/cancel.twig', [
            'anmeldung' => $anmeldung,
            'bot_protection' => $this->botProtection->widgetConfig(),
        ]);
    }

    public function cancelSubmit(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        $data = (array) $request->getParsedBody();
        try {
            $this->botProtection->assertValid($data, $this->clientIp($request));
            $this->anmeldungen->cancel($token);
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response, 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'done' => true,
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        } catch (\Throwable $e) {
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response->withStatus(400), 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'error' => $e->getMessage(),
                'bot_protection' => $this->botProtection->widgetConfig(),
            ]);
        }
    }

    public function impressum(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Impressum',
            'public/impressum.twig',
            'impressum_url',
            'impressum_text'
        );
    }

    public function datenschutz(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Datenschutz',
            'public/datenschutz.twig',
            'datenschutz_url',
            'datenschutz_text'
        );
    }

    public function about(Request $request, Response $response): Response
    {
        $label = trim($this->settings->get('about_link_label'));
        if ($label === '') {
            $label = 'Woher kommt diese Seite';
        }
        $text = trim($this->settings->get('about_text'));

        if ($text !== '') {
            return $this->view->render($response, 'public/about.twig', [
                'page_title' => $label,
                'body' => $text,
                'missing' => false,
            ]);
        }

        return $this->view->render($response->withStatus(404), 'public/about.twig', [
            'page_title' => $label,
            'body' => '',
            'missing' => true,
        ]);
    }

    private function registrationGate(Response $response): ?Response
    {
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        if (!$open && !$adminTest) {
            return $this->view->render($response, 'public/closed.twig');
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function activeFachbereicheWithSchienen(): array
    {
        $list = $this->fachbereiche->all(true);
        $withSchienen = [];
        foreach ($list as $fb) {
            $withSchienen[] = $this->enrichFachbereichCapacity($fb, $this->fachbereiche->schienenFor((int) $fb['id']));
        }
        return $withSchienen;
    }

    /** @return array<string, mixed>|null */
    private function findActiveFachbereichWithSchienen(int $id): ?array
    {
        $fb = $this->fachbereiche->find($id);
        if ($fb === null || !(int) ($fb['aktiv'] ?? 0)) {
            return null;
        }
        return $this->enrichFachbereichCapacity($fb, $this->fachbereiche->schienenFor($id));
    }

    /**
     * @param array<string, mixed> $fb
     * @param list<array<string, mixed>> $schienen
     * @return array<string, mixed>
     */
    private function enrichFachbereichCapacity(array $fb, array $schienen): array
    {
        $kapTotal = 0;
        $freiTotal = 0;
        $belegtTotal = 0;
        $schienenFree = 0;
        foreach ($schienen as &$schiene) {
            $kap = (int) $schiene['kapazitaet'];
            $belegt = (int) ($schiene['belegt'] ?? 0);
            $frei = max(0, $kap - $belegt);
            $schiene['frei'] = $frei;
            $schiene['belegt'] = $belegt;
            $kapTotal += $kap;
            $freiTotal += $frei;
            $belegtTotal += $belegt;
            if ($frei > 0) {
                $schienenFree++;
            }
        }
        unset($schiene);

        $fb['schienen'] = $schienen;
        $fb['kap_total'] = $kapTotal;
        $fb['frei_total'] = $freiTotal;
        $fb['belegt_total'] = $belegtTotal;
        $fb['schienen_total'] = count($schienen);
        $fb['schienen_free'] = $schienenFree;
        return $fb;
    }

    /** @param array<string, mixed> $fachbereich */
    private function isFachbereichBlockedWhenFull(array $fachbereich): bool
    {
        return $this->settings->fullItemBehavior() === SettingsService::FULL_GRAY
            && (int) ($fachbereich['frei_total'] ?? 0) <= 0;
    }

    private function assertSchieneBelongsToFachbereich(int $schieneId, int $fachbereichId): void
    {
        $schiene = $this->fachbereiche->findSchiene($schieneId);
        if ($schiene === null || (int) $schiene['fachbereich_id'] !== $fachbereichId) {
            throw new \RuntimeException('Bitte eine gültige Schiene für diesen Fachbereich wählen.');
        }
    }

    public function robotsTxt(Request $request, Response $response): Response
    {
        if ($this->settings->googleIndexingEnabled()) {
            $body = "User-agent: *\nAllow: /\nDisallow: /administrator\nDisallow: /danke/\nDisallow: /stornieren/\nDisallow: /setup\n";
            $baseUrl = $this->publicBaseUrl();
            if ($baseUrl !== '') {
                $body .= 'Sitemap: ' . $baseUrl . "/sitemap.xml\n";
            }
        } else {
            $body = "User-agent: *\nDisallow: /\n";
        }

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function sitemapXml(Request $request, Response $response): Response
    {
        if (!$this->settings->googleIndexingEnabled()) {
            $response->getBody()->write('');
            return $response
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $baseUrl = $this->publicBaseUrl();
        if ($baseUrl === '') {
            $response->getBody()->write('APP_URL ist nicht konfiguriert.');
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $paths = ['/', '/anmelden'];
        if (trim($this->settings->get('impressum_text')) !== '') {
            $paths[] = '/impressum';
        }
        if (trim($this->settings->get('datenschutz_text')) !== '') {
            $paths[] = '/datenschutz';
        }
        if (trim($this->settings->get('about_text')) !== '') {
            $paths[] = '/woher';
        }

        foreach ($this->fachbereiche->all(true) as $fb) {
            $id = (int) ($fb['id'] ?? 0);
            if ($id > 0) {
                $paths[] = '/anmelden/' . $id;
            }
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($paths as $path) {
            $loc = htmlspecialchars($baseUrl . $path, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $priority = $path === '/' ? '1.0' : ($path === '/anmelden' ? '0.9' : '0.7');
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . $loc . '</loc>';
            $xml[] = '    <changefreq>weekly</changefreq>';
            $xml[] = '    <priority>' . $priority . '</priority>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';

        $response->getBody()->write(implode("\n", $xml) . "\n");
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function publicBaseUrl(): string
    {
        $configured = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return $host !== '' ? $scheme . '://' . $host : '';
    }

    private function clientIp(Request $request): ?string
    {
        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : null;
    }

    private function renderLegalPage(
        Response $response,
        string $title,
        string $template,
        string $urlKey,
        string $textKey
    ): Response {
        $text = trim($this->settings->get($textKey));
        $url = $this->settings->normalizeLegalUrl($this->settings->get($urlKey));

        if ($text !== '') {
            return $this->view->render($response, $template, [
                'title' => $title,
                'body' => $text,
                'missing' => false,
            ]);
        }

        if ($url !== '') {
            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        }

        return $this->view->render($response->withStatus(404), $template, [
            'title' => $title,
            'body' => '',
            'missing' => true,
        ]);
    }
}
