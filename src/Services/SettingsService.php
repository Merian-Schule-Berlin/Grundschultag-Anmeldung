<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SettingsService
{
    public const CAPACITY_OFF = 'off';
    public const CAPACITY_FREE_NUMBERS = 'free_numbers';
    public const CAPACITY_FREE_SCHIENEN = 'free_schienen';

    public const FULL_OPEN = 'open';
    public const FULL_GRAY = 'gray';

    public const BOT_OFF = 'off';
    public const BOT_RECAPTCHA = 'recaptcha';
    public const BOT_TURNSTILE = 'turnstile';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $key => $value) {
            $stmt->execute(['k' => $key, 'v' => $value ?? '']);
        }
    }

    public function isRegistrationOpen(): bool
    {
        $start = $this->get('registration_start');
        $end = $this->get('registration_end');
        $now = time();

        if ($start !== '') {
            $startTs = strtotime($start);
            if ($startTs !== false && $now < $startTs) {
                return false;
            }
        }
        if ($end !== '') {
            $endTs = strtotime($end);
            if ($endTs !== false && $now > $endTs) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolved link for footer/banner: external URL wins, else internal path if text exists.
     */
    public function legalHref(string $urlKey, string $textKey, string $internalPath): string
    {
        $url = $this->normalizeLegalUrl($this->get($urlKey));
        if ($url !== '') {
            return $url;
        }
        if (trim($this->get($textKey)) !== '') {
            return $internalPath;
        }
        return '';
    }

    public function normalizeLegalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return $url;
    }

    public function isExternalHref(string $href): bool
    {
        return preg_match('#^https?://#i', $href) === 1;
    }

    /**
     * How capacity is shown to end users.
     * off | free_numbers | free_schienen | free_occupied
     */
    public function capacityDisplay(): string
    {
        $value = $this->get('capacity_display', self::CAPACITY_FREE_NUMBERS);
        // Legacy: free_occupied → Zahlen
        if ($value === 'free_occupied') {
            $value = self::CAPACITY_FREE_NUMBERS;
        }
        $allowed = [
            self::CAPACITY_OFF,
            self::CAPACITY_FREE_NUMBERS,
            self::CAPACITY_FREE_SCHIENEN,
        ];
        return in_array($value, $allowed, true) ? $value : self::CAPACITY_FREE_NUMBERS;
    }

    /**
     * When a Fachbereich is full: still open teaser page, or gray out (not clickable).
     * open | gray
     */
    public function fullItemBehavior(): string
    {
        $value = $this->get('full_item_behavior', self::FULL_OPEN);
        return in_array($value, [self::FULL_OPEN, self::FULL_GRAY], true)
            ? $value
            : self::FULL_OPEN;
    }

    public function normalizeCapacityDisplay(string $value): string
    {
        if ($value === 'free_occupied') {
            $value = self::CAPACITY_FREE_NUMBERS;
        }
        $allowed = [
            self::CAPACITY_OFF,
            self::CAPACITY_FREE_NUMBERS,
            self::CAPACITY_FREE_SCHIENEN,
        ];
        return in_array($value, $allowed, true) ? $value : self::CAPACITY_FREE_NUMBERS;
    }

    public function normalizeFullItemBehavior(string $value): string
    {
        return in_array($value, [self::FULL_OPEN, self::FULL_GRAY], true)
            ? $value
            : self::FULL_OPEN;
    }

    public function isTruthy(string $key): bool
    {
        return in_array($this->get($key, '0'), ['1', 'true', 'yes', 'on'], true);
    }

    public function homepageButtonEnabled(): bool
    {
        return $this->isTruthy('homepage_button_enabled');
    }

    public function homepageButtonUrl(): string
    {
        return $this->normalizeLegalUrl($this->get('homepage_button_url'));
    }

    public function homepageButtonLabel(): string
    {
        $label = trim($this->get('homepage_button_label', 'Zur Homepage'));
        return $label !== '' ? $label : 'Zur Homepage';
    }

    public function publicThemeToggleEnabled(): bool
    {
        return $this->isTruthy('public_theme_toggle');
    }

    public function googleIndexingEnabled(): bool
    {
        return $this->isTruthy('google_indexing');
    }

    public function metaDescription(): string
    {
        return trim($this->get('meta_description'));
    }

    public function metaKeywords(): string
    {
        return trim($this->get('meta_keywords'));
    }

    /**
     * Freitext mit korrekten Fakten zur Seite (Schule, Veranstaltung, Zweck)
     * für Suchmaschinen-Snippets und KI-Crawler (llms.txt / Structured Data).
     */
    public function indexingSummary(): string
    {
        return trim($this->get('indexing_summary'));
    }

    public function botProtectionProvider(): string
    {
        $value = trim($this->get('bot_protection_provider', self::BOT_OFF));
        return in_array($value, [self::BOT_OFF, self::BOT_RECAPTCHA, self::BOT_TURNSTILE], true)
            ? $value
            : self::BOT_OFF;
    }

    public function normalizeBotProtectionProvider(string $value): string
    {
        return in_array($value, [self::BOT_OFF, self::BOT_RECAPTCHA, self::BOT_TURNSTILE], true)
            ? $value
            : self::BOT_OFF;
    }
}
