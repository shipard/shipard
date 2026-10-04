<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;

/**
 * Adresy, ze kterých smí zdroj dat odesílat (#90 D39): výchozí adresa
 * `mail.defaultFrom` a adresy aktivních odesílatelů z `core_mail_senders`.
 * Je to nabídka, ne volný text — z jiné adresy by pošta neprošla SPF / DKIM.
 */
class AllowedSenders
{
    public const DEFAULT_FROM_KEY = 'mail.defaultFrom';

    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_SENDER  = 'sender';

    /** @var list<array{email: string, source: string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly SettingsStore $settings,
    ) {}

    /** Výchozí adresa odesílatele zdroje dat; null = není nastavená. */
    public function defaultFrom(): ?string
    {
        $from = trim((string) ($this->settings->get(self::DEFAULT_FROM_KEY) ?? ''));
        return $from === '' ? null : $from;
    }

    /**
     * Povolené adresy — výchozí první, pak odesílatelé podle adresy; stejná
     * adresa (bez ohledu na velikost písmen) jen jednou.
     *
     * @return list<array{email: string, source: string}>
     */
    public function addresses(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out  = [];
        $seen = [];
        $add  = static function (string $email, string $source) use (&$out, &$seen): void {
            $email = trim($email);
            $key   = mb_strtolower($email);
            if ($email === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[]      = ['email' => $email, 'source' => $source];
        };

        $default = $this->defaultFrom();
        if ($default !== null) {
            $add($default, self::SOURCE_DEFAULT);
        }

        try {
            $senders = $this->db->fetchAll(
                'SELECT [email_from] FROM [core_mail_senders] WHERE [is_active] = 1 ORDER BY [email_from]',
            );
        } catch (\Dibi\Exception) {
            // Zdroj dat bez modulu pošty tabulku odesílatelů nemá.
            $senders = [];
        }
        foreach ($senders as $sender) {
            $add((string) $sender['email_from'], self::SOURCE_SENDER);
        }

        return $this->cache = $out;
    }

    /** @return list<string> */
    public function emails(): array
    {
        return array_column($this->addresses(), 'email');
    }

    public function isAllowed(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        foreach ($this->addresses() as $address) {
            if (mb_strtolower($address['email']) === $email) {
                return true;
            }
        }
        return false;
    }
}
