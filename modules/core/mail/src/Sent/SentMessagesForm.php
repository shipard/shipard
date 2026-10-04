<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Mail\AddressList;

/**
 * Formulář odeslané zprávy (#90 D41, D45). Obsah je pevný — všechna pole
 * jsou jen pro čtení, uživatel mění jen stav (Archivovat / Smazat /
 * Obnovit ve stavové liště). Vpravo stav transportu s historií pokusů
 * a akcí Odeslat znovu (komponenta `sentMessageTransport`) a náhledy příloh.
 */
class SentMessagesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $tableId = $this->tableDef?->tableId ?? SentMessageStore::TABLE_ID;

        $transport = $this->db !== null && !$isNew && !empty($data['id'])
            ? (new SentMessageTransportInfo($this->db, $this->config))->describe($data)
            : null;

        $basic = $this->tab('basic', 'Zpráva')
            ->section()
                ->col()
                    ->input('subject', readOnly: true)
                    ->textarea('body_text', readOnly: true)
                    ->separator('Odesílatel a příjemci')
                    ->input('email_from', readOnly: true)
                    ->input('email_from_name', readOnly: true)
                    ->input('email_to', readOnly: true)
                    ->input('email_cc', readOnly: true, hidden: empty($data['email_cc']))
                    ->lookup('recipient_person', table: 'base_persons_persons', readOnly: true)
                    ->separator('Záznam')
                    ->input('target_label', readOnly: true)
                ->col()
                    ->component('sentMessageTransport', params: ['transport' => $transport])
                    ->component('attachmentsView', params: ['table_id' => $tableId])
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Odeslaná zpráva',
            titleNew: 'Odeslaná zpráva',
            tabs: [$basic],
        );
    }

    /**
     * Hlavička: předmět, komu a kdy zpráva vznikla.
     *
     * @param array<string, mixed> $data
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            return null;
        }

        $info = [];
        $to   = AddressList::parse($data['email_to'] ?? null);
        if ($to !== []) {
            $info[] = ['label' => 'Komu', 'value' => AddressList::format($to)];
        }
        $created = SubtableCellFormatter::dateTime($data['created'] ?? null);
        if ($created !== null) {
            $info[] = ['label' => 'Vytvořeno', 'value' => $created];
        }
        $author = $this->authorName($data['created_by'] ?? null);
        if ($author !== null) {
            $info[] = ['label' => 'Odeslal', 'value' => $author];
        }

        return new FormHeaderInfo(title: $subject, info: $info, icon: 'mail-out');
    }

    private function authorName(mixed $userId): ?string
    {
        if ($this->db === null || (int) $userId <= 0) {
            return null;
        }
        $name = $this->db->fetchSingle(
            'SELECT COALESCE(NULLIF([full_name], %s), [login]) FROM [core_system_users] WHERE [id] = %i',
            '',
            (int) $userId,
        );
        return is_string($name) && $name !== '' ? $name : null;
    }
}
