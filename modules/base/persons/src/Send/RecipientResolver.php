<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons\Send;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\AddressList;

/**
 * Komu se odesílá (#90 D35) — vždy **živě** z osoby a jejích kontaktů, ne
 * ze snapshotu dokladu (D44): opravená adresa platí pro další odeslání.
 *
 * 1. Platné kontakty osoby s e-mailem a s daným účelem — všechny do „Komu“,
 *    v pořadí `order_pos`, `id`, stejná adresa jen jednou.
 * 2. Jinak e-mail osoby.
 * 3. Jinak nic a chyba `NO_RECIPIENT`.
 *
 * Kontakt bez účelu se nepoužije nikdy — ani jako záloha místo e-mailu
 * osoby. Syntakticky neplatná adresa se přeskočí s varováním.
 */
class RecipientResolver
{
    private const LABELS_ITEM = 'base.persons.formLabels';

    /** Stavy platného kontaktu (`core.system.docStatesArchive` bez archivu a koše). */
    private const ACTIVE_STATES = [10, 40, 80];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param ?ConfigRuntime $config Konfigurace v jazyce rozhraní — názvy
     *        účelů a texty důvodů.
     * @param ?\Closure(): \DateTimeImmutable $clock Dnešek (testy).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @param ?int $personId Osoba příjemce; null = záznam žádnou nemá. */
    public function resolve(?int $personId, string $purpose): RecipientResolution
    {
        if ($personId === null || $personId <= 0) {
            return $this->none('recipientNoPerson', 'No recipient: the record has no person to send to.');
        }

        $person = $this->db->fetchRow(
            'SELECT [id], [full_name], [email] FROM [base_persons_persons] WHERE [id] = %i',
            $personId,
        );
        if ($person === null) {
            return $this->none('recipientNoPerson', 'No recipient: the record has no person to send to.');
        }

        $messages   = [];
        $recipients = [];
        $seen       = [];
        $purposeLabel = SendPurposes::label($purpose, $this->config);

        foreach ($this->contactsWithPurpose($personId, $purpose) as $contact) {
            $email = trim((string) $contact['email']);
            $name  = trim((string) ($contact['name'] ?? ''));
            $label = $this->text('recipientContact', 'Contact {name} — {purpose}', [
                'name'    => $name,
                'purpose' => $purposeLabel,
            ]);
            if (!AddressList::isValid($email)) {
                $messages[] = $this->invalidAddress($email, $label);
                continue;
            }
            $key = mb_strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key]   = true;
            $recipients[] = new Recipient($email, $name, Recipient::SOURCE_CONTACT, $label, (int) $contact['id']);
        }

        if ($recipients === []) {
            $email = trim((string) ($person['email'] ?? ''));
            $label = $this->text('recipientPerson', "Person's e-mail");
            if ($email !== '' && AddressList::isValid($email)) {
                $recipients[] = new Recipient(
                    $email,
                    trim((string) ($person['full_name'] ?? '')),
                    Recipient::SOURCE_PERSON,
                    $label,
                );
            } elseif ($email !== '') {
                $messages[] = $this->invalidAddress($email, $label);
            }
        }

        if ($recipients === []) {
            $messages[] = $this->noRecipient(
                'recipientNone',
                'No recipient: the person has no contact with this purpose and no e-mail of its own.',
            );
        }

        return new RecipientResolution($recipients, $messages);
    }

    /**
     * Platné kontakty osoby s e-mailem a s účelem, v pořadí pro „Komu“.
     *
     * @return list<array<string, mixed>>
     */
    private function contactsWithPurpose(int $personId, string $purpose): array
    {
        $today = ($this->clock)()->format('Y-m-d');

        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [email], [send_purposes] FROM [base_persons_contacts]'
            . ' WHERE [person] = %i AND [docState] IN %in'
            . " AND [email] IS NOT NULL AND [email] <> ''"
            . ' AND [send_purposes] IS NOT NULL'
            . ' AND ([valid_from] IS NULL OR [valid_from] <= %s)'
            . ' AND ([valid_to] IS NULL OR [valid_to] >= %s)'
            . ' ORDER BY [order_pos], [id]',
            $personId,
            self::ACTIVE_STATES,
            $today,
            $today,
        );

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool
                => in_array($purpose, SendPurposes::decode($row['send_purposes']) ?? [], true),
        ));
    }

    private function none(string $labelKey, string $fallback): RecipientResolution
    {
        return new RecipientResolution([], [$this->noRecipient($labelKey, $fallback)]);
    }

    /** @return array{severity: string, code: string, text: string} */
    private function noRecipient(string $labelKey, string $fallback): array
    {
        return [
            'severity' => 'error',
            'code'     => RecipientResolution::NO_RECIPIENT,
            'text'     => $this->text($labelKey, $fallback),
        ];
    }

    /** @return array{severity: string, code: string, text: string} */
    private function invalidAddress(string $email, string $source): array
    {
        return [
            'severity' => 'warning',
            'code'     => RecipientResolution::INVALID_ADDRESS,
            'text'     => $this->text(
                'recipientInvalidAddress',
                "Address '{email}' ({source}) is not a valid e-mail address and was skipped.",
                ['email' => $email, 'source' => $source],
            ),
        ];
    }

    /**
     * Text z `base.persons.formLabels` s dosazenými `{parametry}`; bez
     * zkompilované konfigurace anglický fallback.
     *
     * @param array<string, string> $params
     */
    private function text(string $key, string $fallback, array $params = []): string
    {
        $labels = $this->config?->cfgItem(self::LABELS_ITEM);
        $text   = is_array($labels) ? ($labels[$key]['name'] ?? null) : null;
        $text   = is_string($text) && $text !== '' ? $text : $fallback;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        return $text;
    }
}
