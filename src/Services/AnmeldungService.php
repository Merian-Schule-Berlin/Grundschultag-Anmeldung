<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AnmeldungService
{
    /**
     * @param array<string, mixed> $appSettings
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly HtmlContentService $html,
        private readonly array $appSettings
    ) {
    }

    /**
     * @param array{name:string,email:string,schiene_id:int} $data
     * @param bool $allowWhenClosed Admin-Testmodus darf trotz geschlossenem Zeitraum anmelden
     * @return array<string, mixed>
     */
    public function register(array $data, bool $allowWhenClosed = false): array
    {
        if (!$allowWhenClosed && !$this->settings->isRegistrationOpen()) {
            throw new RuntimeException('Die Anmeldung ist derzeit geschlossen.');
        }

        $name = trim($data['name']);
        $email = trim($data['email']);
        $schieneId = (int) $data['schiene_id'];

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Bitte Name und gültige E-Mail-Adresse angeben.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.*, f.name AS fachbereich_name, f.aktiv AS fachbereich_aktiv
                 FROM schienen s
                 INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
                 WHERE s.id = :id
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $schieneId]);
            $schiene = $stmt->fetch();
            if (!$schiene || !(int) $schiene['fachbereich_aktiv']) {
                throw new RuntimeException('Die gewählte Schiene ist nicht verfügbar.');
            }

            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM anmeldungen WHERE schiene_id = :id AND status = \'aktiv\''
            );
            $countStmt->execute(['id' => $schieneId]);
            $belegt = (int) $countStmt->fetchColumn();
            if ($belegt >= (int) $schiene['kapazitaet']) {
                throw new RuntimeException('Diese Schiene ist leider ausgebucht.');
            }

            $token = bin2hex(random_bytes(32));
            $insert = $this->pdo->prepare(
                'INSERT INTO anmeldungen (schiene_id, name, email, token, status)
                 VALUES (:sid, :name, :email, :token, \'aktiv\')'
            );
            $insert->execute([
                'sid' => $schieneId,
                'name' => $name,
                'email' => $email,
                'token' => $token,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung konnte nicht geladen werden.');
        }

        $this->sendConfirmationMail($anmeldung);
        return $anmeldung;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, s.name AS schiene_name, s.kapazitaet,
                    f.name AS fachbereich_name, f.id AS fachbereich_id
             FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
             WHERE a.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function cancel(string $token): void
    {
        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung nicht gefunden.');
        }
        if ($anmeldung['status'] === 'storniert') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE anmeldungen SET status = \'storniert\', cancelled_at = NOW() WHERE token = :token AND status = \'aktiv\''
        );
        $stmt->execute(['token' => $token]);
        if ($stmt->rowCount() > 0) {
            $this->sendCancelMail($anmeldung);
        }
    }

    public function countActive(?int $fachbereichId = null): int
    {
        if ($fachbereichId === null) {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM anmeldungen WHERE status = 'aktiv'"
            )->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             WHERE a.status = 'aktiv' AND s.fachbereich_id = :fb"
        );
        $stmt->execute(['fb' => $fachbereichId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Distinct active participant emails. Never expose this list in outbound mail headers.
     *
     * @return list<string>
     */
    public function activeEmails(?int $fachbereichId = null): array
    {
        $emails = [];
        foreach ($this->activeRecipients($fachbereichId) as $row) {
            $emails[] = (string) $row['email'];
        }
        return $emails;
    }

    /**
     * One row per distinct active email (first matching registration wins).
     *
     * @return list<array{
     *     email:string,
     *     name:string,
     *     fachbereich_name:string,
     *     schiene_name:string,
     *     token:string
     * }>
     */
    public function activeRecipients(?int $fachbereichId = null): array
    {
        if ($fachbereichId === null) {
            $rows = $this->pdo->query(
                "SELECT a.name, a.email, a.token, s.name AS schiene_name, f.name AS fachbereich_name
                 FROM anmeldungen a
                 INNER JOIN schienen s ON s.id = a.schiene_id
                 INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
                 WHERE a.status = 'aktiv' AND a.email <> ''
                 ORDER BY a.email ASC, a.id ASC"
            )->fetchAll();
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT a.name, a.email, a.token, s.name AS schiene_name, f.name AS fachbereich_name
                 FROM anmeldungen a
                 INNER JOIN schienen s ON s.id = a.schiene_id
                 INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
                 WHERE a.status = 'aktiv' AND a.email <> '' AND s.fachbereich_id = :fb
                 ORDER BY a.email ASC, a.id ASC"
            );
            $stmt->execute(['fb' => $fachbereichId]);
            $rows = $stmt->fetchAll();
        }

        $byEmail = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($byEmail[$email])) {
                continue;
            }
            $byEmail[$email] = [
                'email' => $email,
                'name' => (string) ($row['name'] ?? ''),
                'fachbereich_name' => (string) ($row['fachbereich_name'] ?? ''),
                'schiene_name' => (string) ($row['schiene_name'] ?? ''),
                'token' => (string) ($row['token'] ?? ''),
            ];
        }

        return array_values($byEmail);
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM anmeldungen')->fetchColumn();
    }

    public function deleteAll(): int
    {
        $count = $this->countAll();
        $this->pdo->exec('DELETE FROM anmeldungen');
        return $count;
    }

    /** @param array<string, mixed> $anmeldung */
    private function sendConfirmationMail(array $anmeldung): void
    {
        $this->sendTemplatedMail(
            $anmeldung,
            'mail_subject',
            (string) ($this->appSettings['mail']['subject'] ?? 'Ihre Anmeldung zum Grundschultag'),
            'mail_body',
            self::defaultMailBody()
        );
    }

    /** @param array<string, mixed> $anmeldung */
    private function sendCancelMail(array $anmeldung): void
    {
        $this->sendTemplatedMail(
            $anmeldung,
            'mail_cancel_subject',
            'Ihre Stornierung zum Grundschultag',
            'mail_cancel_body',
            self::defaultCancelMailBody()
        );
    }

    /**
     * @param array<string, mixed> $anmeldung
     */
    private function sendTemplatedMail(
        array $anmeldung,
        string $subjectKey,
        string $subjectFallback,
        string $bodyKey,
        string $bodyFallback
    ): void {
        $baseUrl = rtrim((string) ($this->appSettings['app_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->guessBaseUrl();
        }
        $cancelUrl = $baseUrl . '/stornieren/' . $anmeldung['token'];
        $thanksUrl = $baseUrl . '/danke/' . $anmeldung['token'];

        $from = $this->settings->get('mail_from', (string) ($this->appSettings['mail']['from'] ?? ''));
        $fromName = $this->settings->get(
            'mail_from_name',
            (string) ($this->appSettings['mail']['from_name'] ?? 'Grundschultag')
        );

        $rawPlaceholders = [
            '{name}' => (string) $anmeldung['name'],
            '{email}' => (string) $anmeldung['email'],
            '{fachbereich}' => (string) $anmeldung['fachbereich_name'],
            '{schiene}' => (string) $anmeldung['schiene_name'],
            '{cancel_url}' => $cancelUrl,
            '{thanks_url}' => $thanksUrl,
            '{from_name}' => $fromName,
            '{logo}' => '',
        ];
        $htmlPlaceholders = [];
        foreach ($rawPlaceholders as $key => $value) {
            $htmlPlaceholders[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        // Nach Sanitize als HTML ersetzen, damit <img> nicht herausgefiltert wird.
        $htmlPlaceholders['{logo}'] = $this->logoImgHtml($baseUrl, $fromName);

        $subjectTemplate = $this->settings->get($subjectKey, $subjectFallback);
        $bodyTemplate = trim($this->settings->get($bodyKey));
        if ($bodyTemplate === '') {
            $bodyTemplate = $bodyFallback;
        }

        $subject = strtr($subjectTemplate, $rawPlaceholders);
        $bodyHtml = strtr($this->html->toSafeHtml($bodyTemplate), $htmlPlaceholders);

        if ($from !== '') {
            $this->mail->send((string) $anmeldung['email'], $subject, $bodyHtml, $from, $fromName, true);
        }
    }

    public static function defaultMailBody(): string
    {
        return '<p>{logo}</p>'
            . '<p>Hallo {name},</p>'
            . '<p>vielen Dank für Ihre Anmeldung.</p>'
            . '<p><strong>Ihre Anmeldung:</strong><br>'
            . 'Fachbereich: {fachbereich}<br>'
            . 'Schiene: {schiene}<br>'
            . 'Name: {name}</p>'
            . '<p>Sollten Sie den Termin nicht wahrnehmen können, nutzen Sie bitte folgenden Link, '
            . 'um Ihre Anmeldung zu stornieren:<br>'
            . '<a href="{cancel_url}">{cancel_url}</a></p>'
            . '<p>Mit freundlichen Grüßen<br>Das Team der {from_name}</p>'
            . '<p>Webansicht Ihrer Anmeldung:<br>'
            . '<a href="{thanks_url}">{thanks_url}</a></p>';
    }

    public static function defaultCancelMailBody(): string
    {
        return '<p>{logo}</p>'
            . '<p>Hallo {name},</p>'
            . '<p>Ihre Anmeldung wurde storniert.</p>'
            . '<p><strong>Stornierte Anmeldung:</strong><br>'
            . 'Fachbereich: {fachbereich}<br>'
            . 'Schiene: {schiene}<br>'
            . 'Name: {name}</p>'
            . '<p>Mit freundlichen Grüßen<br>Das Team der {from_name}</p>';
    }

    public static function defaultCancelMailSubject(): string
    {
        return 'Ihre Stornierung zum Grundschultag';
    }

    private function logoImgHtml(string $baseUrl, string $alt): string
    {
        $logo = trim($this->settings->get('site_logo'));
        if ($logo === '' || $baseUrl === '') {
            return '';
        }

        $filename = basename(str_replace('\\', '/', $logo));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return '';
        }

        $maxWidth = 180;
        $maxHeight = 72;
        $displayWidth = $maxWidth;
        $displayHeight = 0;

        $path = dirname(__DIR__, 2) . '/public/uploads/' . $filename;
        if (is_file($path)) {
            $size = @getimagesize($path);
            if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                $scale = min($maxWidth / $size[0], $maxHeight / $size[1], 1.0);
                $displayWidth = (int) max(1, round($size[0] * $scale));
                $displayHeight = (int) max(1, round($size[1] * $scale));
            }
        }

        $src = $baseUrl . '/uploads/' . rawurlencode($filename);
        $attrs = ' src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' width="' . $displayWidth . '"';
        if ($displayHeight > 0) {
            $attrs .= ' height="' . $displayHeight . '"';
        }

        // Seitenverhältnis beibehalten: echte Maße setzen, height:auto als Fallback.
        return '<img' . $attrs
            . ' style="display:block;border:0;outline:none;width:' . $displayWidth . 'px;'
            . 'max-width:' . $maxWidth . 'px;height:auto;max-height:' . $maxHeight . 'px;" />';
    }

    private function guessBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
