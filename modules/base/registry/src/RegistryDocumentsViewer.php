<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Module\Core\Mail\IncomingMessageTitle;

/**
 * Viewer dokumentů Spisovny (base_registry_documents).
 *
 * Layout řádku (design §6.2):
 *   t1 — title (bold)
 *   i1 — valid_to relativně, warning/danger badge při blízké expiraci
 *        (čistě prezentace — deterministické alerty jsou fáze 4)
 *   t2 — partner (fallback ref_number)
 *   i2 — badge druhu (label z docKinds, barva dle mapy)
 *   t3 — [šanon] + první řádek ai_summary
 *
 * Spodní taby = šanony (TableViewer::getBottomTabs): `all` = Vše (bez
 *   filtru), číselné id = konkrétní šanon, `unfiled` = Nezařazené (binder
 *   IS NULL). Frontend posílá id jen zpět do filter[bottomTab], význam zná
 *   výhradně tento viewer. „Přidat“ ze záložky šanonu předvyplní šanon
 *   (newRecordDefaults).
 *
 * Detail: taby Obsah (vlastnosti + metadata dle druhu) / Přílohy / Původ.
 */
class RegistryDocumentsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'core.system.docStatesArchive';

    private const LABELS_CFG_ITEM = 'base.registry.viewerDetailLabels';

    /** Id spodních záložek mimo konkrétní šanon (šanon = jeho číselné id). */
    private const TAB_ALL = 'all';
    private const TAB_UNFILED = 'unfiled';

    /** Barevné hinty badge druhu — klíč = docKinds key. */
    private const KIND_SPAN_CLASS = [
        'contract'    => 'primary',
        'insurance'   => 'success',
        'quotation'   => 'warning',
        'certificate' => 'primary',
        'official'    => 'danger',
        'other'       => 'muted',
    ];

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT d.`id`, d.`title`, d.`doc_kind`, d.`binder`, d.`ref_number`,'
            . ' d.`valid_to`, d.`ai_summary`, d.`docState`, d.`docStateMain`,'
            . ' p.`full_name` AS `partner_name`, b.`name` AS `binder_name`'
            . ' FROM `' . $this->table . '` d'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = d.`partner`'
            . ' LEFT JOIN `base_registry_binders` b ON b.`id` = d.`binder`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        $binderTab = self::TAB_ALL;
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            if ($id === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            } elseif ($id === 'bottomTab') {
                $binderTab = (string) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = 'd.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($binderTab === self::TAB_UNFILED) {
            $conditions[] = 'd.`binder` IS NULL';
        } elseif (ctype_digit($binderTab) && (int) $binderTab > 0) {
            $conditions[] = 'd.`binder` = %i';
            $params[] = (int) $binderTab;
        }

        if ($search !== null && $search !== '') {
            // Hlavička bez diakritiky (helper), obsah příloh přes FULLTEXT —
            // tokeny MATCH se řídí collation sloupce, COLLATE na ně nejde.
            $conditions[] = '(' . SearchCondition::contains('d.`title`')
                . ' OR ' . SearchCondition::contains('d.`ref_number`')
                . ' OR ' . SearchCondition::contains('d.`ai_summary`')
                . ' OR MATCH (d.`extracted_text`) AGAINST (%s))';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY d.`docStateMain` ASC, d.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Spodní taby: Vše / per živý šanon / Nezařazené. Živý šanon =
     * docState IN (10, 40, 80) — archivované (70) se jako tab nezobrazují
     * (jejich dokumenty zůstávají dostupné přes Vše), smazané (90) nikdy.
     * Záložka šanonu nese šanon jako výchozí hodnotu nového dokumentu.
     *
     * @return list<array{id: string|int, label: string, newRecordDefaults?: array{binder: int}}>
     */
    public function getBottomTabs(): array
    {
        $tabs = [[
            'id'    => self::TAB_ALL,
            'label' => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'allDocuments', 'All'),
        ]];

        $binders = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `base_registry_binders`'
            . ' WHERE `docState` IN (10, 40, 80)'
            . ' ORDER BY `order_pos` ASC, `name` ASC',
        );
        foreach ($binders as $b) {
            $id = (int) $b['id'];
            $tabs[] = [
                'id'                => $id,
                'label'             => (string) $b['name'],
                'newRecordDefaults' => ['binder' => $id],
            ];
        }

        $tabs[] = [
            'id'    => self::TAB_UNFILED,
            'label' => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'unfiled', 'Unfiled'),
        ];

        return $tabs;
    }

    public function renderRow(array $rowData): array
    {
        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => (string) ($rowData['title'] ?? ''),
            'stateStyle' => $this->resolveStateStyle((int) ($rowData['docState'] ?? 10)),
        ];

        $row['i1'] = $this->buildValidToBadge(
            $rowData['valid_to'] ?? null,
            (string) ($rowData['doc_kind'] ?? ''),
        );

        $partner = trim((string) ($rowData['partner_name'] ?? ''));
        $refNumber = trim((string) ($rowData['ref_number'] ?? ''));
        $row['t2'] = $partner !== '' ? $partner : ($refNumber !== '' ? $refNumber : null);

        $kind = (string) ($rowData['doc_kind'] ?? '');
        $row['i2'] = $kind !== ''
            ? [[
                'text'  => $this->resolveKindLabel($kind),
                'class' => self::KIND_SPAN_CLASS[$kind] ?? 'muted',
            ]]
            : null;

        $t3 = [];
        $binderName = trim((string) ($rowData['binder_name'] ?? ''));
        if ($binderName !== '') {
            $t3[] = ['text' => '[' . $binderName . ']', 'class' => 'muted'];
        }
        $summary = $this->firstLine($rowData['ai_summary'] ?? null, 100);
        if ($summary !== '') {
            $t3[] = ['text' => $summary];
        }
        $row['t3'] = $t3 !== [] ? $t3 : null;

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT d.*, p.`full_name` AS `partner_name`, b.`name` AS `binder_name`'
            . ' FROM `' . $this->table . '` d'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = d.`partner`'
            . ' LEFT JOIN `base_registry_binders` b ON b.`id` = d.`binder`'
            . ' WHERE d.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        $record = (array) $record;
        $header = $this->buildDetailHeader($record);

        $tabs = [
            [
                'id'      => 'content',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'content', 'Content'),
                'content' => $this->buildContentTab($record),
            ],
            [
                'id'      => 'attachments',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'attachments', 'Attachments'),
                'content' => $this->buildAttachmentsTab((int) $record['id']),
            ],
            [
                'id'      => 'origin',
                'label'   => $this->detailTabLabel(self::LABELS_CFG_ITEM, 'origin', 'Origin'),
                'content' => $this->buildOriginTab($record),
            ],
        ];

        return [
            'title'    => $header['title'],
            'subtitle' => $header['subtitle'],
            'badges'   => $header['badges'],
            'icon'     => 'folder',
            'tabs'     => $tabs,
        ];
    }

    // -------------------------------------------------------------------------
    // Private — detail
    // -------------------------------------------------------------------------

    /**
     * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>}
     */
    private function buildDetailHeader(array $record): array
    {
        $subtitleParts = [];
        $partner = trim((string) ($record['partner_name'] ?? ''));
        if ($partner !== '') {
            $subtitleParts[] = $partner;
        }
        $binderName = trim((string) ($record['binder_name'] ?? ''));
        if ($binderName !== '') {
            $subtitleParts[] = $binderName;
        }
        $validTo = $this->formatDate($record['valid_to'] ?? null);
        if ($validTo !== null) {
            $subtitleParts[] = 'do ' . $validTo;
        }

        $badges = [];
        $stateBadge = $this->buildStateBadge((int) ($record['docState'] ?? 10));
        if ($stateBadge !== null) {
            $badges[] = $stateBadge;
        }
        $kind = (string) ($record['doc_kind'] ?? '');
        if ($kind !== '') {
            $style = self::KIND_SPAN_CLASS[$kind] ?? 'muted';
            $badges[] = [
                'label' => $this->resolveKindLabel($kind),
                'style' => $style === 'muted' ? 'neutral' : $style,
            ];
        }

        return [
            'title'    => (string) ($record['title'] ?? ''),
            'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
            'badges'   => $badges,
        ];
    }

    private function buildContentTab(array $record): array
    {
        $blocks = [];

        $summary = trim((string) ($record['ai_summary'] ?? ''));
        if ($summary !== '') {
            $blocks[] = [
                'type' => 'html',
                'html' => '<p>' . nl2br(htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>',
            ];
        }

        $identity = [];
        $this->addItem($identity, 'Druh', $this->resolveKindLabel((string) ($record['doc_kind'] ?? '')));
        $this->addItem($identity, 'Šanon', $record['binder_name'] ?? null);
        $this->addItem($identity, 'Číslo / značka', $record['ref_number'] ?? null);
        $this->addItem($identity, 'Partner', $record['partner_name'] ?? null);

        $validity = [];
        $this->addItem($validity, 'Platnost od', $this->formatDate($record['valid_from'] ?? null));
        $this->addItem($validity, 'Platnost do / lhůta', $this->formatDate($record['valid_to'] ?? null));

        $groups = [];
        if ($identity !== []) {
            $groups[] = ['title' => 'Identifikace', 'items' => $identity];
        }
        if ($validity !== []) {
            $groups[] = ['title' => 'Platnost', 'items' => $validity];
        }

        $metadataItems = $this->buildMetadataItems($record);
        if ($metadataItems !== []) {
            $groups[] = ['title' => 'Metadata', 'items' => $metadataItems];
        }

        $notice = trim((string) ($record['notice'] ?? ''));
        if ($notice !== '') {
            $groups[] = ['title' => 'Poznámka', 'items' => [['label' => '', 'value' => $notice]]];
        }

        if ($groups !== []) {
            $blocks[] = ['type' => 'properties', 'groups' => $groups];
        }

        if ($blocks === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Dokument nemá žádný obsah.</p>'];
        }
        if (count($blocks) === 1) {
            return $blocks[0];
        }
        return ['type' => 'composite', 'blocks' => $blocks];
    }

    /**
     * Read-only výpis metadata dle druhu: nejdřív pole z docKinds[kind].fields
     * (v deklarovaném pořadí), pak zbývající klíče (např. legacyKind z importu).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function buildMetadataItems(array $record): array
    {
        $raw = $record['metadata'] ?? null;
        $metadata = is_string($raw) && trim($raw) !== ''
            ? (json_decode($raw, true) ?: [])
            : (is_array($raw) ? $raw : []);
        if ($metadata === []) {
            return [];
        }

        $kindFields = [];
        if ($this->config !== null) {
            $kinds = $this->config->cfgItem('base.registry.docKinds');
            $kind = (string) ($record['doc_kind'] ?? '');
            $kindFields = is_array($kinds) ? ($kinds[$kind]['fields'] ?? []) : [];
        }

        $items = [];
        foreach ($kindFields as $field) {
            if (isset($metadata[$field]) && is_scalar($metadata[$field])) {
                $items[] = ['label' => (string) $field, 'value' => (string) $metadata[$field]];
                unset($metadata[$field]);
            }
        }
        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $items[] = ['label' => (string) $key, 'value' => (string) $value];
            }
        }
        return $items;
    }

    private function buildAttachmentsTab(int $recordId): array
    {
        $tableId = 428;
        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            $tableId,
            $recordId,
        );

        if ($files === []) {
            return ['type' => 'html', 'html' => '<p class="muted">Dokument nemá žádné přílohy.</p>'];
        }

        $attachments = [];
        foreach ($files as $f) {
            $attachments[] = [
                'id'        => (int) $f['id'],
                'name'      => (string) ($f['name'] ?? $f['file_name']),
                'mime_type' => (string) ($f['mime_type'] ?? ''),
                'file_size' => (int) ($f['file_size'] ?? 0),
            ];
        }

        return ['type' => 'attachment-grid', 'attachments' => $attachments];
    }

    private function buildOriginTab(array $record): array
    {
        $items = [];

        $sourceKind = (string) ($record['source_kind'] ?? '');
        $this->addItem($items, 'Zdroj', $this->resolveSourceKindLabel($sourceKind));

        if (!empty($record['source_message'])) {
            $message = $this->db->fetchRow(
                'SELECT `subject`, `ai_title`, `source_type`, `sender_email`, `sender_name`, `received_at`'
                . ' FROM `core_mail_incoming_messages` WHERE `id` = %i',
                (int) $record['source_message'],
            );
            if ($message !== null) {
                $sender = trim((string) ($message['sender_name'] ?? ''));
                if ($sender === '') {
                    $sender = trim((string) ($message['sender_email'] ?? ''));
                }
                // Lidský název zprávy (titulek z AI u skenů, jinak předmět — D3).
                $this->addItem($items, 'Zdrojová zpráva', IncomingMessageTitle::display(
                    (string) ($message['subject'] ?? ''),
                    isset($message['ai_title']) ? (string) $message['ai_title'] : null,
                    (int) ($message['source_type'] ?? 0),
                    IncomingMessageTitle::patternsFrom($this->config),
                ));
                $this->addItem($items, 'Odesílatel', $sender);
                $this->addItem($items, 'Doručeno', $this->formatDateTime($message['received_at'] ?? null));
            }
        }

        $this->addItem($items, 'Vytvořeno', $this->formatDateTime($record['created'] ?? null));
        $this->addItem($items, 'Změněno', $this->formatDateTime($record['modified'] ?? null));

        return [
            'type'   => 'properties',
            'groups' => [['title' => 'Původ', 'items' => $items]],
        ];
    }

    // -------------------------------------------------------------------------
    // Private — formátovací helpery
    // -------------------------------------------------------------------------

    /**
     * i1 badge: valid_to relativně, class dle blízkosti expirace.
     * Po termínu → danger, uvnitř warn okna druhu (max warnDaysBefore,
     * default 30 dní) → warning, jinak muted.
     *
     * @return array<int, array{text: string, class: string}>|null
     */
    private function buildValidToBadge(mixed $validTo, string $kind): ?array
    {
        $ts = $this->parseDate($validTo);
        if ($ts === null) {
            return null;
        }

        $today = strtotime(date('Y-m-d'));
        $days = (int) floor(($ts - $today) / 86400);

        $text = match (true) {
            $days < -1  => 'před ' . abs($days) . ' d',
            $days === -1 => 'včera',
            $days === 0 => 'dnes',
            $days === 1 => 'zítra',
            $days <= 60 => 'za ' . $days . ' d',
            default     => date('j. n. Y', $ts),
        };

        $class = match (true) {
            $days < 0                        => 'danger',
            $days <= $this->warnDays($kind)  => 'warning',
            default                          => 'muted',
        };

        return [['text' => $text, 'class' => $class]];
    }

    /** Max z docKinds[kind].expiration.warnDaysBefore, default 30. */
    private function warnDays(string $kind): int
    {
        if ($kind === '' || $this->config === null) {
            return 30;
        }
        $kinds = $this->config->cfgItem('base.registry.docKinds');
        $warn = is_array($kinds) ? ($kinds[$kind]['expiration']['warnDaysBefore'] ?? null) : null;
        if (!is_array($warn) || $warn === []) {
            return 30;
        }
        return (int) max($warn);
    }

    private function resolveKindLabel(string $kind): string
    {
        if ($kind === '') {
            return '';
        }
        if ($this->config !== null) {
            $kinds = $this->config->cfgItem('base.registry.docKinds');
            if (is_array($kinds) && isset($kinds[$kind]['name'])) {
                return (string) $kinds[$kind]['name'];
            }
        }
        return $kind;
    }

    private function resolveSourceKindLabel(string $key): string
    {
        if ($key === '') {
            return '';
        }
        if ($this->config !== null) {
            $cfg = $this->config->cfgItem('base.registry.sourceKinds');
            if (is_array($cfg) && isset($cfg[$key]['name'])) {
                return (string) $cfg[$key]['name'];
            }
        }
        return $key;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    private function buildStateBadge(int $docState): ?array
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return null;
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        $label = (string) ($stateData['stateName'] ?? '');
        if ($label === '') {
            return null;
        }
        return [
            'label' => $label,
            'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        $ts = $this->parseDate($value);
        return $ts !== null ? date('j. n. Y', $ts) : null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        $ts = $this->parseDate($value);
        return $ts !== null ? date('j. n. Y H:i', $ts) : null;
    }

    private function parseDate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    private function firstLine(mixed $text, int $maxLen): string
    {
        if (!is_string($text) || $text === '') {
            return '';
        }
        $lines = preg_split('/\R/', trim($text), 2);
        $first = is_array($lines) && $lines !== [] ? trim((string) $lines[0]) : '';
        if (mb_strlen($first) > $maxLen) {
            $first = mb_substr($first, 0, $maxLen - 1) . '…';
        }
        return $first;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }
}
