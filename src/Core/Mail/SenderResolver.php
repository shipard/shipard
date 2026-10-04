<?php

declare(strict_types=1);

namespace Shipard\Core\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;

/**
 * Z jaké adresy se záznam odesílá (#90 D39):
 *
 * 1. adresa zvolená při odeslání (dialog, CLI) — musí být povolená;
 * 2. odesílatel podle záznamu (`RecordSenderProvider` tabulky; doklady
 *    podle číselné řady) — uložená adresa, která mezitím přestala být
 *    povolená, je chyba, ne tichý přechod na výchozí;
 * 3. výchozí adresa zdroje dat `mail.defaultFrom`;
 * 4. jinak `NO_SENDER`.
 *
 * Jméno odesílatele: podle záznamu, jinak název vlastní firmy, jinak žádné.
 */
class SenderResolver
{
    private const LABELS_ITEM = 'core.mail.sendLabels';

    /** @var \Closure(): ?string */
    private readonly \Closure $ownName;

    /**
     * @param array<string, class-string> $providers Tabulka → třída
     *        `RecordSenderProvider` (`RecordSenderProviderLoader`).
     * @param ?\Closure(): ?string $ownName Název vlastní firmy.
     * @param ?ConfigRuntime $config Konfigurace v jazyce rozhraní — texty hlášek.
     */
    public function __construct(
        private readonly AllowedSenders $allowed,
        private readonly DataSourceConnection $db,
        private readonly array $providers = [],
        ?\Closure $ownName = null,
        private readonly ?ConfigRuntime $config = null,
    ) {
        $this->ownName = $ownName ?? static fn (): ?string => null;
    }

    /**
     * Resolver nad zdrojem dat: povolené adresy z nastavení a odesílatelů,
     * jméno vlastní firmy z osoby s příznakem `is_own`.
     *
     * @param array<string, class-string> $providers `RecordSenderProviderLoader::load()`.
     */
    public static function forDataSource(
        DataSourceConnection $db,
        array $providers = [],
        ?ConfigRuntime $config = null,
    ): self {
        return new self(
            new AllowedSenders($db, new SettingsStore($db)),
            $db,
            $providers,
            static function () use ($db): ?string {
                try {
                    $name = $db->fetchSingle(
                        'SELECT [full_name] FROM [base_persons_persons]'
                        . ' WHERE [is_own] = 1 AND [docState] IN %in ORDER BY [id] LIMIT 1',
                        [10, 40, 80],
                    );
                } catch (\Throwable) {
                    // Zdroj dat bez osob (modul není aktivní) jméno nemá.
                    return null;
                }
                return is_string($name) ? $name : null;
            },
            $config,
        );
    }

    public function allowed(): AllowedSenders
    {
        return $this->allowed;
    }

    /**
     * @param string $table Tabulka záznamu.
     * @param array<string, mixed> $record
     * @param ?string $chosen Adresa zvolená při odeslání; null / '' = automaticky.
     */
    public function resolve(string $table, array $record, ?string $chosen = null): SenderResolution
    {
        $recordSender = $this->recordSender($table, $record);
        $name         = $this->name($recordSender);

        $chosen = trim((string) $chosen);
        if ($chosen !== '') {
            return $this->allowed->isAllowed($chosen)
                ? new SenderResolution($chosen, $name, SenderResolution::SOURCE_CHOSEN)
                : $this->notAllowed($chosen, $name, 'senderNotAllowed',
                    "Address '{email}' is not among the allowed sender addresses.");
        }

        $recordEmail = trim((string) ($recordSender?->email ?? ''));
        if ($recordEmail !== '') {
            return $this->allowed->isAllowed($recordEmail)
                ? new SenderResolution($recordEmail, $name, SenderResolution::SOURCE_RECORD)
                : $this->notAllowed($recordEmail, $name, 'recordSenderNotAllowed',
                    "Sender address '{email}' set for this record is no longer allowed"
                    . ' — the mail sender was deactivated or removed.');
        }

        $default = $this->allowed->defaultFrom();
        if ($default !== null) {
            return new SenderResolution($default, $name, SenderResolution::SOURCE_DEFAULT);
        }

        return new SenderResolution(
            null,
            $name,
            null,
            SenderResolution::NO_SENDER,
            $this->text('noSender', 'No sender address: set the default from address in the outbound mail settings.'),
        );
    }

    /** @param array<string, mixed> $record */
    private function recordSender(string $table, array $record): ?RecordSender
    {
        $class = $this->providers[$table] ?? null;
        if ($class === null) {
            return null;
        }
        if (!class_exists($class) || !is_subclass_of($class, RecordSenderProvider::class)) {
            throw new \RuntimeException(
                "Record sender provider '{$class}' for table '{$table}' does not implement RecordSenderProvider",
            );
        }
        return (new $class())->recordSender($record, $this->db);
    }

    private function name(?RecordSender $recordSender): ?string
    {
        $name = trim((string) ($recordSender?->name ?? ''));
        if ($name === '') {
            $name = trim((string) (($this->ownName)() ?? ''));
        }
        return $name === '' ? null : $name;
    }

    private function notAllowed(string $email, ?string $name, string $labelKey, string $fallback): SenderResolution
    {
        return new SenderResolution(
            null,
            $name,
            null,
            SenderResolution::SENDER_NOT_ALLOWED,
            str_replace('{email}', $email, $this->text($labelKey, $fallback)),
        );
    }

    /** Text z `core.mail.sendLabels`; bez zkompilované konfigurace anglický fallback. */
    private function text(string $key, string $fallback): string
    {
        $labels = $this->config?->cfgItem(self::LABELS_ITEM);
        $text   = is_array($labels) ? ($labels[$key]['name'] ?? null) : null;
        return is_string($text) && $text !== '' ? $text : $fallback;
    }
}
