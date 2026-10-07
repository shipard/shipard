<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;

/**
 * Formulář pro ruční pořízení / úpravu došlé zprávy.
 *
 * Layout odpovídá spec §6.1 — tři taby:
 *   1) „Zpráva" — všechny hlavičky + partner dokumentu + tělo, vpravo
 *      read-only náhledy příloh
 *   2) „Přílohy" — standardní attachments panel (tableId = 303)
 *   3) „Nastavení" — schránka + datum doručení
 *
 * `source_type` (zdroj) ani `message_id` (lidský kód) se v UI neupravují —
 * Form je nevystavuje, Document je nastaví v beforeSave.
 *
 * Partner (tasks/mail-message-title-partner.md D4/D8): `partner_person` je
 * editovatelný lookup na Osoby — ruční volba má přednost, analýza zapisuje
 * jen do NULL; `partner_name` je read-only snapshot jména z canonicalu.
 */
class IncomingMessagesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $mailboxOptions = $this->resolveMailboxOptions();
        $primaryTypeOptions = $this->resolvePrimaryTypeOptions();
        $tableId = $this->tableDef?->tableId ?? 0;

        $basic = $this->tab('basic', 'Zpráva')
            ->section()
                ->col()
                    ->select('primary_type',
                        options: $primaryTypeOptions,
                        required: true,
                    )
                    ->separator('Odesílatel')
                    ->input('sender_email', required: true, inputType: 'email')
                    ->input('sender_name')
                    ->separator('Partner')
                    ->lookup('partner_person',
                        table: 'base_persons_persons',
                        placeholder: 'Hledat partnera…',
                        hint: 'Protistrana dokumentu (dodavatel), ne odesílatel zprávy. Doplní se z analýzy, ruční volba má přednost.',
                    )
                    ->input('partner_name', readOnly: true)
                    ->separator('Obsah')
                    ->input('subject', required: true)
                    ->input('ai_title', readOnly: true,
                        hint: 'Titulek odvozený AI z obsahu — u skenů a nahraných souborů se zobrazuje místo předmětu. Přepíše ho každá analýza.',
                    );
        // Pozornost u zprávy bez dokladu (tasks/mail-other-attention.md D9):
        // jen read-only a jen když ji analýza určila — u dokladu, dokumentu
        // Spisovny a starší analýzy je NULL a pole se nekreslí (podmínka
        // v PHP nad $data, žádný nový mechanismus).
        if (!empty($data['attention'])) {
            $basic->select('attention', options: $this->resolveAttentionOptions(), readOnly: true,
                    hint: 'Určila AI z obsahu: k vyřízení (expirace, výzva k platbě, žádost), informativní, nebo obchodní sdělení. Vyřízení = archivace zprávy.',
                )
                ->input('action_note', readOnly: true)
                ->date('action_due', readOnly: true);
        }
        $basic = $basic
                    ->textarea('body_plain',
                        hint: 'Prostý text zprávy. HTML varianta se v Fázi 1 neupravuje ručně — vzniká jen přes import.',
                    )
                ->col()
                    ->component('attachmentsView', params: [
                        'table_id' => $tableId,
                        // Raw .eml se v panelu příloh neukazuje — konzistence
                        // s viewerem (fetchContentAttachments) a feedem.
                        'exclude_attachment_id' => isset($data['raw_source_attachment'])
                            ? (int) $data['raw_source_attachment']
                            : null,
                    ])
            ->build();

        $settings = $this->tab('settings', 'Nastavení')
            ->section()
                ->col()
                    ->select('mailbox',
                        options: $mailboxOptions,
                        required: true,
                        triggers: 'reload',
                    )
                    ->datetime('received_at', required: true)
            ->build();

        $tabs = [$basic, $this->attachmentsTab(), $settings];

        return new FormDefinition(
            table: $this->table,
            title: 'Došlá zpráva',
            titleNew: 'Nová došlá zpráva',
            tabs: $tabs,
        );
    }

    /**
     * Strukturovaná hlavička došlé zprávy.
     *
     *   ┌──┐ Faktura č. 2024-0001
     *   │✉ │ [Nový] Od Jan Novák · Doručeno 28.05.2024 14:30
     *   └──┘
     *
     *   - title  = lidský titulek zprávy (IncomingMessageTitle::display —
     *              předmět, u generického / prázdného předmětu a ručních zpráv
     *              ai_title). Fallback na formDef.title („Došlá zpráva")
     *              když není ani jedno.
     *   - info[] = odesílatel (sender_name preferovaně, e-mail fallback) +
     *              datum doručení (s časem — v rámci dne se pořadí hraje).
     *   - icon   = stejná jako u vieweru pošty (`mail`).
     *
     * Sender pattern (`name ?? email`) je shodný s `IncomingMessagesViewer::renderRow`
     * v poli `t2` — konzistentní identifikace odesílatele napříč UI.
     *
     * @param array<string, mixed> $data
     */
    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $subject = IncomingMessageTitle::display(
            (string) ($data['subject'] ?? ''),
            isset($data['ai_title']) ? (string) $data['ai_title'] : null,
            (int) ($data['source_type'] ?? 0),
            IncomingMessageTitle::patternsFrom($this->config),
        );
        if ($subject === '') {
            return null;
        }

        $info = [];

        $sender = $this->resolveSenderLabel($data);
        if ($sender !== '') {
            $info[] = ['label' => 'Od', 'value' => $sender];
        }

        $receivedAt = $this->formatHeaderDateTime($data['received_at'] ?? null);
        if ($receivedAt !== '') {
            $info[] = ['label' => 'Doručeno', 'value' => $receivedAt];
        }

        return new FormHeaderInfo(
            title: $subject,
            info: $info,
            icon: 'mail',
        );
    }

    /**
     * Sender label — `sender_name` preferovaně, `sender_email` fallback.
     * Stejný vzor jako `IncomingMessagesViewer::renderRow()` v `t2`.
     */
    protected function resolveSenderLabel(array $data): string
    {
        $name = trim((string) ($data['sender_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return trim((string) ($data['sender_email'] ?? ''));
    }

    /**
     * Bezpečně z DB DATETIME hodnoty (normálně 'Y-m-d\TH:i:s' string přes
     * DataSourceConnection) udělá formát vhodný pro hlavičku — „28.05.2024 14:30".
     * Dibi DateTime objekty jsou taky podporované (defenzivně).
     */
    protected function formatHeaderDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y H:i');
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format('d.m.Y H:i');
        } catch (\Exception) {
            return '';
        }
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        // Pokud se změnila schránka a uživatel nemá vyplněný primary_type,
        // dopočítáme default podle vybrané schránky.
        if ($changedColumn === 'mailbox' && empty($data['primary_type']) && isset($data['mailbox'])) {
            $mailboxId = (int) $data['mailbox'];
            $data['primary_type'] = $this->lookupMailboxDefaultType($mailboxId);
        }

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult(
            $this->buildFormDefinition($data, $isNew),
            $data,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Načte aktivní schránky (docState ∈ {10 Koncept, 40 V pořádku, 80 V opravě})
     * setříděné dle `name`. Archivní a smazané schránky v selectu nezobrazujeme.
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolveMailboxOptions(): array
    {
        if ($this->db === null) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, mailbox_id, name FROM core_mail_mailboxes'
            . ' WHERE docState IN (10, 40, 80)'
            . ' ORDER BY name ASC',
        );

        $options = [];
        foreach ($rows as $row) {
            $label = (string) ($row['name'] ?? $row['mailbox_id']);
            if (!empty($row['mailbox_id'])) {
                $label .= ' (' . $row['mailbox_id'] . ')';
            }
            $options[] = ['value' => (int) $row['id'], 'label' => $label];
        }
        return $options;
    }

    /**
     * Vrací pouze aktivní typy (enabled=true) seřazené dle `order`.
     *
     * @return list<array{value: string, label: string}>
     */
    private function resolvePrimaryTypeOptions(): array
    {
        if ($this->config === null) {
            return [
                ['value' => 'invoiceReceived', 'label' => 'Přijatá faktura'],
                ['value' => 'other', 'label' => 'Ostatní'],
            ];
        }

        $cfgData = $this->config->cfgItem('core.mail.primaryTypes');
        if (!is_array($cfgData)) {
            return [];
        }

        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            if (($entry['enabled'] ?? true) === false) {
                continue;
            }
            $entries[] = [
                'order' => (int) ($entry['order'] ?? 999),
                'value' => (string) $key,
                'label' => (string) $entry['name'],
            ];
        }

        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn(array $e): array => ['value' => $e['value'], 'label' => $e['label']], $entries);
    }

    /**
     * Možnosti pozornosti z cfgItem `core.mail.attentionKinds` (read-only
     * select — hodnotu určuje AI, uživatel ji needituje).
     *
     * @return list<array{value: string, label: string}>
     */
    private function resolveAttentionOptions(): array
    {
        $cfgData = $this->config?->cfgItem('core.mail.attentionKinds');
        if (!is_array($cfgData)) {
            return [
                ['value' => 'action', 'label' => 'K vyřízení'],
                ['value' => 'info', 'label' => 'Informativní'],
                ['value' => 'promo', 'label' => 'Obchodní sdělení'],
            ];
        }

        $entries = [];
        foreach ($cfgData as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            $entries[] = [
                'order' => (int) ($entry['order'] ?? 999),
                'value' => (string) $key,
                'label' => (string) $entry['name'],
            ];
        }
        usort($entries, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(static fn(array $e): array => ['value' => $e['value'], 'label' => $e['label']], $entries);
    }

    private function lookupMailboxDefaultType(int $mailboxId): string
    {
        if ($this->db === null || $mailboxId <= 0) {
            return 'other';
        }

        $row = $this->db->fetchRow(
            'SELECT default_primary_type FROM core_mail_mailboxes WHERE id = %i',
            $mailboxId,
        );

        $default = (string) ($row['default_primary_type'] ?? '');
        return $default !== '' ? $default : 'other';
    }
}
