<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Base\Persons\Send\SendPurposes;

/**
 * Agenda Odeslaná pošta (#90 D45) — co, komu a kdy odešlo, k jakému záznamu
 * a jak dopadl transport. Zprávy se nezakládají ručně (vznikají odesláním
 * záznamu), proto viewer nemá akci Přidat; formulář zprávy je jen pro
 * čtení a nese Odeslat znovu, Archivovat a Smazat.
 */
class SentMessagesViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.mail.docStatesSent';

    /** Styl štítku stavu transportu → třída spanu v řádku. */
    private const TRANSPORT_SPAN_CLASS = [
        'success' => 'success',
        'warning' => 'warning',
        'danger'  => 'danger',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        // Celý řádek zprávy: štítky stavu transportu a pojistky čtou sloupce,
        // které zdroj dat před `ds-upgrade` mít nemusí.
        $sql = 'SELECT m.*, p.`full_name` AS recipient_name'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = m.`recipient_person`';

        $conditions = [];
        $params     = [];

        $viewGroup = 'active';
        foreach ($filters as $filter) {
            if ($filter['id'] === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            }
        }
        if ($viewGroup !== 'all' && $this->config !== null) {
            $cfg    = DocStateConfig::fromCfgItem($this->config->cfgItem((string) $this->docStatesCfgItem));
            $states = $cfg->getViewGroupStates($viewGroup);
            if ($states !== []) {
                $conditions[] = 'm.`docState` IN (' . implode(', ', array_fill(0, count($states), '%i')) . ')';
                $params       = array_merge($params, $states);
            } elseif ($viewGroup !== 'active') {
                $conditions[] = '1=0';
            }
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['m.`subject`', 'm.`email_to`', 'm.`target_label`', 'p.`full_name`'],
                $search,
            );
            $conditions[] = $searchSql;
            $params       = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY m.`created` DESC, m.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $transport = $this->transportInfo()->state($rowData);
        $recipient = trim((string) ($rowData['recipient_name'] ?? ''));
        $to        = AddressList::format(AddressList::parse($rowData['email_to'] ?? null));

        $t3 = [];
        $label = trim((string) ($rowData['target_label'] ?? ''));
        if ($label !== '') {
            $t3[] = ['text' => $label];
        }
        // Adresy vedle osoby; bez osoby jsou adresy už v t2.
        if ($recipient !== '' && $to !== '') {
            $t3[] = ['text' => $to, 'class' => 'muted'];
        }

        return [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['subject'] ?? ''),
            'i1'         => SubtableCellFormatter::dateTime($rowData['created'] ?? null),
            // Bez osoby i adresy (importovaná zpráva bez příjemce, #104 D4) „—“.
            't2'         => $recipient !== '' ? $recipient : ($to !== '' ? $to : '—'),
            'i2'         => array_values(array_filter([
                [
                    'text'  => $transport['stateLabel'],
                    'class' => self::TRANSPORT_SPAN_CLASS[$transport['stateStyle']] ?? 'muted',
                ],
                $transport['safety'] !== null ? [
                    'text'  => $transport['safety']['label'],
                    'class' => self::TRANSPORT_SPAN_CLASS[$transport['safety']['style']] ?? 'muted',
                ] : null,
            ])),
            't3'         => $t3 !== [] ? $t3 : null,
            'stateStyle' => $this->stateStyle((int) ($rowData['docState'] ?? SentMessageStore::DOC_STATE_SENT)),
        ];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT m.*, p.`full_name` AS recipient_name,'
            . ' COALESCE(NULLIF(u.`full_name`, %s), u.`login`) AS author_name'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = m.`recipient_person`'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = m.`created_by`'
            . ' WHERE m.`id` = %i',
            '',
            $recordId,
        );
    }

    /** Předmět jako titulek, Komu · vytvořeno v podtitulku; štítek stavu (Odeslaná) přidává builder. */
    protected function detailHeader(array $record): DetailHeader
    {
        $cs = ($this->language ?? 'en') === 'cs';
        $to = AddressList::format(AddressList::parse($record['email_to'] ?? null));
        $subject = trim((string) ($record['subject'] ?? ''));

        $subtitle = array_filter([
            $to !== '' ? ($cs ? 'Komu: ' : 'To: ') . $to : null,
            SubtableCellFormatter::dateTime($record['created'] ?? null),
        ]);

        return new DetailHeader(
            $subject !== '' ? $subject : ($cs ? '(bez předmětu)' : '(no subject)'),
            $subtitle !== [] ? implode(' · ', $subtitle) : null,
        );
    }

    /** Stav doručení a stopa pojistky odchozí pošty (#95) za štítkem stavu zprávy. */
    protected function detailBadges(array $record): array
    {
        $transport = $this->transportInfo()->state($record);

        return array_values(array_filter([
            ['label' => $transport['stateLabel'], 'style' => $transport['stateStyle']],
            $transport['safety'] !== null
                ? ['label' => $transport['safety']['label'], 'style' => $transport['safety']['style']]
                : null,
        ]));
    }

    /**
     * Jediný tab Obsah: tělo zprávy, přílohy (vlastní blok —
     * `detailAttachments: "content"`) a vlastnosti Zpráva / Odeslání.
     */
    protected function detailTabs(array $record): array
    {
        $cs        = ($this->language ?? 'en') === 'cs';
        $transport = $this->transportInfo()->state($record);

        $blocks = [];
        $body   = (string) ($record['body_text'] ?? '');
        if ($body !== '') {
            $blocks[] = [
                'type' => 'html',
                'html' => '<pre style="white-space: pre-wrap; font-family: inherit;">'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</pre>',
            ];
        }

        $attachments = $this->attachments((int) $record['id']);
        if ($attachments !== []) {
            $blocks[] = [
                'type' => 'heading',
                'text' => $this->detailTabLabel('core.mail.viewerDetailLabels', 'attachments', 'Attachments'),
            ];
            $blocks[] = ['type' => 'attachment-grid', 'attachments' => $attachments];
        }

        $from = trim((string) ($record['email_from_name'] ?? '')) !== ''
            ? $record['email_from_name'] . ' <' . $record['email_from'] . '>'
            : (string) $record['email_from'];

        $message = [];
        $this->addItem($message, $cs ? 'Odesílatel' : 'From', $from);
        $this->addItem($message, $cs ? 'Kopie' : 'Cc', AddressList::format(AddressList::parse($record['email_cc'] ?? null)));
        $this->addItem($message, $cs ? 'Osoba' : 'Person', $record['recipient_name'] ?? null);
        $this->addItem($message, $cs ? 'Záznam' : 'Record', $record['target_label'] ?? null);
        if (!empty($record['purpose'])) {
            $this->addItem($message, $cs ? 'Účel' : 'Purpose', SendPurposes::label((string) $record['purpose'], $this->config));
        }
        $this->addItem($message, $cs ? 'Jazyk' : 'Language', $record['language'] ?? null);

        $sending = [];
        $this->addItem($sending, $cs ? 'Odesláno' : 'Sent at', SubtableCellFormatter::dateTime($record['sent_at'] ?? null));
        $this->addItem($sending, $cs ? 'Počet odeslání' : 'Send count', (string) (int) ($record['send_count'] ?? 0));
        $this->addItem($sending, $cs ? 'Poslední chyba' : 'Last error', $record['last_error'] ?? null);
        $this->addItem($sending, $cs ? 'Spuštěno' : 'Trigger', $this->triggerLabel((string) ($record['send_trigger'] ?? '')));
        $this->addItem($sending, $cs ? 'Odeslal' : 'Sent by', $record['author_name'] ?? null);

        $blocks[] = [
            'type'   => 'properties',
            'groups' => [
                ['title' => $cs ? 'Zpráva' : 'Message', 'items' => $message],
                ['title' => $cs ? 'Odeslání' : 'Sending', 'items' => $sending],
            ],
        ];

        return [[
            'id'      => 'content',
            'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'content', 'Content'),
            'content' => count($blocks) === 1 ? $blocks[0] : ['type' => 'composite', 'blocks' => $blocks],
        ]];
    }

    /** Odkaz na záznam, ke kterému zpráva patří. */
    protected function detailActions(array $record): array
    {
        if (empty($record['target_table_id']) || (int) ($record['target_row'] ?? 0) <= 0) {
            return [];
        }
        $cs = ($this->language ?? 'en') === 'cs';

        return [[
            'id'      => 'openSentMessageTarget',
            'label'   => $cs ? 'Otevřít záznam' : 'Open record',
            'kind'    => 'open_form',
            'variant' => 'secondary',
            'target'  => [
                'table' => (string) $record['target_table_id'],
                'mode'  => 'edit',
                'id'    => (int) $record['target_row'],
            ],
        ]];
    }

    /** Zprávy vznikají odesláním záznamu — bez akce Přidat. */
    public function getToolbarActions(?array $selectedRow): array
    {
        return array_values(array_filter(
            parent::getToolbarActions($selectedRow),
            static fn (array $action): bool => ($action['id'] ?? '') !== 'create',
        ));
    }

    /** @return list<array<string, mixed>> */
    private function attachments(int $messageId): array
    {
        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type` FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `id` ASC',
            SentMessageStore::TABLE_ID,
            $messageId,
        );

        return array_map(static fn (array $f): array => [
            'id'        => (int) $f['id'],
            'name'      => (string) ($f['name'] ?? $f['file_name']),
            'mime_type' => (string) ($f['mime_type'] ?? ''),
            'file_size' => (int) ($f['file_size'] ?? 0),
        ], $files);
    }

    private function transportInfo(): SentMessageTransportInfo
    {
        return new SentMessageTransportInfo($this->db, $this->config);
    }

    /** Popisek `send_trigger` z `core.mail.sendTriggers`; bez konfigurace kód. */
    private function triggerLabel(string $trigger): ?string
    {
        if ($trigger === '') {
            return null;
        }
        $triggers = $this->config?->cfgItem('core.mail.sendTriggers');
        $name     = is_array($triggers) ? ($triggers[$trigger]['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : $trigger;
    }

    private function stateStyle(int $docState): string
    {
        $state = $this->docStateData($docState);
        return (string) ($state['stateStyle'] ?? 'done');
    }

    /** @return array<string, mixed> */
    private function docStateData(int $docState): array
    {
        $states = $this->config?->cfgItem((string) $this->docStatesCfgItem);
        $state  = is_array($states) ? ($states[(string) $docState] ?? null) : null;
        return is_array($state) ? $state : [];
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value !== '') {
            $items[] = ['label' => $label, 'value' => $value];
        }
    }
}
