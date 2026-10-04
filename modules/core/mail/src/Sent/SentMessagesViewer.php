<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Sent;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Form\SubtableCellFormatter;
use Shipard\Core\Mail\AddressList;
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
        $sql = 'SELECT m.`id`, m.`subject`, m.`email_to`, m.`target_label`, m.`transport_state`,'
            . ' m.`created`, m.`sent_at`, m.`docState`, m.`recipient_person`,'
            . ' p.`full_name` AS recipient_name'
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
            't2'         => $recipient !== '' ? $recipient : ($to !== '' ? $to : null),
            'i2'         => [[
                'text'  => $transport['stateLabel'],
                'class' => self::TRANSPORT_SPAN_CLASS[$transport['stateStyle']] ?? 'muted',
            ]],
            't3'         => $t3 !== [] ? $t3 : null,
            'stateStyle' => $this->stateStyle((int) ($rowData['docState'] ?? SentMessageStore::DOC_STATE_SENT)),
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT m.*, p.`full_name` AS recipient_name,'
            . ' COALESCE(NULLIF(u.`full_name`, %s), u.`login`) AS author_name'
            . ' FROM `' . $this->table . '` m'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = m.`recipient_person`'
            . ' LEFT JOIN `core_system_users` u ON u.`id` = m.`created_by`'
            . ' WHERE m.`id` = %i',
            '',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $cs        = ($this->language ?? 'en') === 'cs';
        $transport = $this->transportInfo()->state($record);
        $to        = AddressList::format(AddressList::parse($record['email_to'] ?? null));

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
        $this->addItem($message, $cs ? 'Komu' : 'To', $to);
        $this->addItem($message, $cs ? 'Kopie' : 'Cc', AddressList::format(AddressList::parse($record['email_cc'] ?? null)));
        $this->addItem($message, $cs ? 'Osoba' : 'Person', $record['recipient_name'] ?? null);
        $this->addItem($message, $cs ? 'Záznam' : 'Record', $record['target_label'] ?? null);
        if (!empty($record['purpose'])) {
            $this->addItem($message, $cs ? 'Účel' : 'Purpose', SendPurposes::label((string) $record['purpose'], $this->config));
        }
        $this->addItem($message, $cs ? 'Jazyk' : 'Language', $record['language'] ?? null);

        $sending = [];
        $this->addItem($sending, $cs ? 'Stav odeslání' : 'Transport state', $transport['stateLabel']);
        $this->addItem($sending, $cs ? 'Odesláno' : 'Sent at', SubtableCellFormatter::dateTime($record['sent_at'] ?? null));
        $this->addItem($sending, $cs ? 'Počet odeslání' : 'Send count', (string) (int) ($record['send_count'] ?? 0));
        $this->addItem($sending, $cs ? 'Poslední chyba' : 'Last error', $record['last_error'] ?? null);
        $this->addItem($sending, $cs ? 'Vytvořeno' : 'Created', SubtableCellFormatter::dateTime($record['created'] ?? null));
        $this->addItem($sending, $cs ? 'Odeslal' : 'Sent by', $record['author_name'] ?? null);

        $blocks[] = [
            'type'   => 'properties',
            'groups' => [
                ['title' => $cs ? 'Zpráva' : 'Message', 'items' => $message],
                ['title' => $cs ? 'Odeslání' : 'Sending', 'items' => $sending],
            ],
        ];

        $subtitle = array_filter([
            $to !== '' ? ($cs ? 'Komu: ' : 'To: ') . $to : null,
            SubtableCellFormatter::dateTime($record['created'] ?? null),
        ]);

        $detail = [
            'title'    => (string) ($record['subject'] ?? ''),
            'subtitle' => $subtitle !== [] ? implode(' · ', $subtitle) : null,
            'badges'   => array_values(array_filter([
                $this->stateBadge((int) ($record['docState'] ?? SentMessageStore::DOC_STATE_SENT)),
                ['label' => $transport['stateLabel'], 'style' => $transport['stateStyle']],
            ])),
            'icon'     => 'mail-out',
            'tabs'     => [[
                'id'      => 'content',
                'label'   => $this->detailTabLabel('core.mail.viewerDetailLabels', 'content', 'Content'),
                'content' => count($blocks) === 1 ? $blocks[0] : ['type' => 'composite', 'blocks' => $blocks],
            ]],
        ];

        // Odkaz na záznam, ke kterému zpráva patří.
        if (!empty($record['target_table_id']) && (int) ($record['target_row'] ?? 0) > 0) {
            $detail['actions'] = [[
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

        return $detail;
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

    private function stateStyle(int $docState): string
    {
        $state = $this->docStateData($docState);
        return (string) ($state['stateStyle'] ?? 'done');
    }

    /** @return array{label: string, style: string}|null */
    private function stateBadge(int $docState): ?array
    {
        $state = $this->docStateData($docState);
        if (!isset($state['stateName'])) {
            return null;
        }
        $style = (string) ($state['stateStyle'] ?? 'done');
        // Detail badge nemá variantu archivu ani koše — neutrální.
        return [
            'label' => (string) $state['stateName'],
            'style' => in_array($style, ['archive', 'trash'], true) ? 'neutral' : $style,
        ];
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
