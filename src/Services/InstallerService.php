<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class InstallerService
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath()) && is_file($this->envPath());
    }

    public function lockPath(): string
    {
        return $this->rootPath . '/storage/install.lock';
    }

    public function envPath(): string
    {
        return $this->rootPath . '/.env';
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,pass:string} $db
     */
    public function testConnection(array $db): void
    {
        $pdo = $this->createPdo($db);
        $pdo->query('SELECT 1');
        $pdo = null;
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,pass:string} $db
     * @param array{from:string,from_name:string,subject:string} $mail
     * @param array{username:string,password:string} $admin
     * @param array{app_url:string,app_name:string} $app
     */
    public function install(array $db, array $mail, array $admin, array $app): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('Die Anwendung ist bereits installiert.');
        }

        $this->testConnection($db);
        $this->writeEnv($db, $mail, $app);

        $pdo = $this->createPdo($db);
        $schema = file_get_contents($this->rootPath . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('schema.sql konnte nicht gelesen werden.');
        }
        $pdo->exec($schema);

        $defaults = [
            'site_title' => $app['app_name'],
            'site_subtitle' => '',
            'welcome_text' => "Willkommen zur Anmeldung für den Grundschultag.\n\nBitte melden Sie sich über den Button unten an.",
            'welcome_image' => '',
            'site_logo' => '',
            'registration_start' => '',
            'registration_end' => '',
            'capacity_display' => SettingsService::CAPACITY_FREE_NUMBERS,
            'full_item_behavior' => SettingsService::FULL_OPEN,
            'mail_from' => $mail['from'],
            'mail_from_name' => $mail['from_name'],
            'mail_subject' => $mail['subject'],
            'mail_body' => AnmeldungService::defaultMailBody(),
            'mail_cancel_subject' => AnmeldungService::defaultCancelMailSubject(),
            'mail_cancel_body' => AnmeldungService::defaultCancelMailBody(),
            'impressum_url' => '',
            'impressum_text' => '',
            'datenschutz_url' => '',
            'datenschutz_text' => '',
            'about_link_label' => 'Woher kommt diese Seite',
            'about_text' => '',
            'homepage_button_enabled' => '0',
            'homepage_button_url' => '',
            'homepage_button_label' => 'Zur Homepage',
            'public_theme_toggle' => '0',
            'google_indexing' => '0',
            'meta_description' => '',
            'meta_keywords' => '',
            'indexing_summary' => '',
            'bot_protection_provider' => SettingsService::BOT_OFF,
            'bot_protection_site_key' => '',
            'bot_protection_secret_key' => '',
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($defaults as $key => $value) {
            $stmt->execute(['k' => $key, 'v' => $value]);
        }

        $hash = password_hash($admin['password'], PASSWORD_DEFAULT);
        $adminStmt = $pdo->prepare(
            'INSERT INTO admins (username, password_hash, role, fachbereich_id)
             VALUES (:u, :p, \'admin\', NULL)'
        );
        $adminStmt->execute(['u' => $admin['username'], 'p' => $hash]);

        $storage = $this->rootPath . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0755, true) && !is_dir($storage)) {
            throw new RuntimeException('storage/ konnte nicht erstellt werden.');
        }
        file_put_contents($this->lockPath(), date('c') . "\n");
        $pdo = null;
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,pass:string} $db
     */
    private function createPdo(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );
        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,pass:string} $db
     * @param array{from:string,from_name:string,subject:string} $mail
     * @param array{app_url:string,app_name:string} $app
     */
    private function writeEnv(array $db, array $mail, array $app): void
    {
        $lines = [
            'APP_URL=' . $this->escapeEnv($app['app_url']),
            'APP_NAME=' . $this->escapeEnv($app['app_name']),
            'APP_DEBUG=0',
            'BASE_PATH=',
            '',
            'DB_HOST=' . $this->escapeEnv($db['host']),
            'DB_PORT=' . $this->escapeEnv($db['port']),
            'DB_NAME=' . $this->escapeEnv($db['name']),
            'DB_USER=' . $this->escapeEnv($db['user']),
            'DB_PASS=' . $this->escapeEnv($db['pass']),
            '',
            'MAIL_FROM=' . $this->escapeEnv($mail['from']),
            'MAIL_FROM_NAME=' . $this->escapeEnv($mail['from_name']),
            'MAIL_SUBJECT=' . $this->escapeEnv($mail['subject']),
            '',
        ];
        if (file_put_contents($this->envPath(), implode("\n", $lines)) === false) {
            throw new RuntimeException('.env konnte nicht geschrieben werden. Prüfen Sie die Schreibrechte.');
        }
    }

    private function escapeEnv(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
