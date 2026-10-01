<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\SearchCondition;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\Economy\Assets\Depreciation\Plan;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessage;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Assets\Posting\AssetPostingBuilder;

/**
 * Viewer karet majetku (tasks/assets-phase1.md → UI, fáze 2b → plán
 * odpisů a akce detailu).
 *
 * Spodní taby (TableViewer::getBottomTabs): Vše / per druh z cfgItem
 * (id = klíč druhu, Přidat předvyplní druh) / Cizí (is_foreign). Filtry
 * pravého panelu: Typ, Účetní skupina. Fulltext: inventární číslo, název,
 * zkrácený název. ViewGroups ze stavů — V archívu = vyřazené.
 *
 * Detail dlouhodobého majetku: taby Daňové / Účetní odpisy (plán z enginu
 * přes AssetPlanService — souhrn, tabulka řádků, hlášení) a akce podle
 * stavu karty a událostí: Zařadit / Počáteční stav (bez zařazení), jinak
 * Odepsat (`depreciation_run`, vlastní obsluha ve Viewer.svelte), TZ,
 * Snížení, Přerušit (přerušitelná metoda), Vyřadit — vše `open_form`
 * s presetem karty, druhu a okruhu události. Toolbar nese „Odpisy za
 * období“ (`depreciation_run` bez karty).
 */
class AssetsViewer extends AssetsViewerBase
{
    public const TAB_ALL = 'all';
    public const TAB_FOREIGN = 'foreign';

    public const ACTION_DEPRECIATION_RUN = 'depreciation_run';
    public const EVENTS_TABLE = 'economy_assets_events';
    /** Souhrnný viewer dokladů — cíl odkazů na doklad (`open_detail`). */
    public const DOCUMENTS_VIEWER = 'docs.core.heads';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT a.`id`, a.`asset_number`, a.`name`, a.`short_name`, a.`category`,'
            . ' a.`is_foreign`, a.`acquired_date`, a.`disposed_date`, a.`price`,'
            . ' a.`docState`, a.`docStateMain`,'
            . ' t.`name` AS `type_name`, g.`code` AS `group_code`, p.`full_name` AS `owner_name`,'
            . ' ' . $this->unpostedExistsSql() . ' AS `has_unposted`'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_assets_types` t ON t.`id` = a.`asset_type`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = a.`accounting_group`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = a.`owner`';

        [$conditions, $params] = $this->viewGroupCondition($filters, 'a');

        $bottomTab = self::TAB_ALL;
        foreach ($filters as $filter) {
            $id    = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($id === 'bottomTab') {
                $bottomTab = (string) $value;
            } elseif ($id === 'asset_type' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'a.`asset_type` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'accounting_group' && ctype_digit((string) $value) && (int) $value > 0) {
                $conditions[] = 'a.`accounting_group` = %i';
                $params[] = (int) $value;
            }
        }

        if ($bottomTab === self::TAB_FOREIGN) {
            $conditions[] = 'a.`is_foreign` = 1';
        } elseif ($bottomTab !== self::TAB_ALL && isset($this->categories()->all()[$bottomTab])) {
            $conditions[] = 'a.`category` = %s';
            $params[] = $bottomTab;
        }

        if ($search !== null && $search !== '') {
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['a.`asset_number`', 'a.`name`', 'a.`short_name`'],
                $search,
            );
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.`docStateMain` ASC, a.`asset_number` ASC, a.`name` ASC, a.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * @return list<array{id: string, label: string, newRecordDefaults?: array<string, mixed>}>
     */
    public function getBottomTabs(): array
    {
        $tabs = [[
            'id'    => self::TAB_ALL,
            'label' => $this->text('tab.all', 'All'),
        ]];

        foreach ($this->categories()->all() as $key => $entry) {
            $tabs[] = [
                'id'                => (string) $key,
                'label'             => (string) ($entry['name'] ?? $key),
                'newRecordDefaults' => ['category' => (string) $key],
            ];
        }

        $tabs[] = [
            'id'                => self::TAB_FOREIGN,
            'label'             => $this->text('tab.foreign', 'Foreign'),
            'newRecordDefaults' => ['is_foreign' => 1],
        ];

        return $tabs;
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        $actions = parent::getToolbarActions($selectedRow);
        $actions[] = [
            'id'      => self::ACTION_DEPRECIATION_RUN,
            'label'   => $this->text('action.depreciationRun', 'Depreciation for period'),
            'variant' => 'secondary',
        ];
        return $actions;
    }

    public function getFilters(): array
    {
        return [
            [
                'id'      => 'asset_type',
                'label'   => $this->text('label.type', 'Type'),
                'type'    => 'select',
                'options' => $this->codebookOptions('economy_assets_types', '`name`'),
            ],
            [
                'id'      => 'accounting_group',
                'label'   => $this->text('label.accountingGroup', 'Accounting group'),
                'type'    => 'select',
                'options' => $this->codebookOptions('economy_assets_accounting_groups', "CONCAT(`code`, ' — ', `name`)"),
            ],
        ];
    }

    public function renderRow(array $rowData): array
    {
        $categories = $this->categories();
        $category   = (string) ($rowData['category'] ?? '');
        $docState   = (int) ($rowData['docState'] ?? 10);

        $row = [
            'id' => (int) $rowData['id'],
            't1' => (string) ($rowData['name'] ?? ''),
            'i1' => !empty($rowData['asset_number']) ? (string) $rowData['asset_number'] : null,
        ];

        $t2 = [];
        if (!empty($rowData['type_name'])) {
            $t2[] = ['text' => (string) $rowData['type_name']];
        }
        if ($category !== '') {
            $t2[] = [
                'text'  => $categories->label($category),
                'class' => $categories->isLongTerm($category) ? 'primary' : 'muted',
            ];
        }
        if (!empty($rowData['is_foreign'])) {
            $owner = trim((string) ($rowData['owner_name'] ?? ''));
            $t2[] = [
                'text'  => ($this->text('tab.foreign', 'Foreign')) . ($owner !== '' ? ' · ' . $owner : ''),
                'class' => 'warning',
            ];
        }
        $badge = $this->stateBadge($docState);
        if ($badge !== null) {
            $t2[] = $badge;
        }
        // Potvrzené události účetního okruhu bez účetního dokladu (D52).
        if (!empty($rowData['has_unposted'])) {
            $t2[] = ['text' => $this->text('badge.unposted', 'Not posted'), 'class' => 'warning'];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $i2 = [];
        $acquired = $this->formatDate($rowData['acquired_date'] ?? null);
        if ($acquired !== null) {
            $i2[] = ['text' => $acquired, 'class' => 'muted'];
        }
        if ($category !== '' && !$categories->isLongTerm($category)) {
            $price = $this->formatAmount($rowData['price'] ?? null);
            if ($price !== null) {
                $i2[] = ['text' => $price, 'class' => 'amount'];
            }
        }
        $row['i2'] = $i2 !== [] ? $i2 : null;

        if (!empty($rowData['short_name'])) {
            $row['t3'] = (string) $rowData['short_name'];
        }

        $row['stateStyle'] = $this->stateStyleOf($docState);

        return $row;
    }

    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT a.*, t.`name` AS `type_name`, g.`code` AS `group_code`, g.`name` AS `group_name`,'
            . ' p.`full_name` AS `owner_name`'
            . ' FROM `' . $this->table . '` a'
            . ' LEFT JOIN `economy_assets_types` t ON t.`id` = a.`asset_type`'
            . ' LEFT JOIN `economy_assets_accounting_groups` g ON g.`id` = a.`accounting_group`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = a.`owner`'
            . ' WHERE a.`id` = %i',
            $recordId,
        );
        if ($record === null) {
            return ['tabs' => []];
        }

        $categories = $this->categories();
        $category   = (string) ($record['category'] ?? '');
        $longTerm   = $category !== '' && $categories->isLongTerm($category);
        $tracking   = $this->config?->cfgItem('economy.assets.trackingKinds') ?? [];

        $identity = [];
        $this->addItem($identity, $this->text('label.assetNumber', 'Inventory number'), $record['asset_number'] ?? null);
        $this->addItem($identity, $this->text('label.name', 'Name'), $record['name'] ?? null);
        $this->addItem($identity, $this->text('label.shortName', 'Short name'), $record['short_name'] ?? null);

        $classification = [];
        $this->addItem($classification, $this->text('label.type', 'Type'), $record['type_name'] ?? null);
        $this->addItem($classification, $this->text('label.category', 'Category'), $category !== '' ? $categories->label($category) : null);
        $trackingKey = (string) ($record['tracking'] ?? '');
        $this->addItem(
            $classification,
            $this->text('label.tracking', 'Tracking'),
            $trackingKey !== '' ? (string) ($tracking[$trackingKey]['name'] ?? $trackingKey) : null,
        );
        $group = trim(((string) ($record['group_code'] ?? '')) . ' — ' . ((string) ($record['group_name'] ?? '')), ' —');
        $this->addItem($classification, $this->text('label.accountingGroup', 'Accounting group'), $group);

        $ownership = [];
        $this->addItem($ownership, $this->text('label.foreignAsset', 'Foreign asset'), $this->yesNo($record['is_foreign'] ?? 0));
        if (!empty($record['is_foreign'])) {
            $this->addItem($ownership, $this->text('label.owner', 'Owner'), $record['owner_name'] ?? null);
        }

        $lifecycle = [];
        $this->addItem($lifecycle, $this->text('label.acquiredDate', 'Acquired'), $this->formatDate($record['acquired_date'] ?? null));
        $this->addItem($lifecycle, $this->text('label.disposedDate', 'Disposed'), $this->formatDate($record['disposed_date'] ?? null));
        if (!$longTerm) {
            $this->addItem($lifecycle, $this->text('label.price', 'Price'), $this->formatAmount($record['price'] ?? null));
        }

        $depreciable = $category !== '' && $categories->isDepreciable($category);
        $depreciation = [];
        if ($depreciable) {
            $rules = $this->planService()->rules();
            $taxMethod = (string) ($record['tax_method'] ?? '');
            $this->addItem($depreciation, $this->text('label.taxMethod', 'Tax method'), $taxMethod !== '' ? $rules->methodName($taxMethod) : null);
            $this->addItem($depreciation, $this->text('summary.rule', 'Group / rule'), $this->ruleName($taxMethod, (string) ($record['tax_rule'] ?? '')));
            $this->addItem($depreciation, $this->text('label.accMethod', 'Accounting method'), $this->accMethodLabel($record));
        }

        $note = [];
        $this->addItem($note, $this->text('label.note', 'Note'), $record['note'] ?? null);

        $detail = $this->overviewDetail([
            ['title' => $this->text('group.identity', 'Identity'), 'items' => $identity],
            ['title' => $this->text('group.classification', 'Classification'), 'items' => $classification],
            ['title' => $this->text('group.ownership', 'Ownership'), 'items' => $ownership],
            ['title' => $this->text('group.lifecycle', 'Acquisition and disposal'), 'items' => $lifecycle],
            ['title' => $this->text('group.depreciation', 'Depreciation'), 'items' => $depreciation],
            ['title' => $this->text('label.note', 'Note'), 'items' => $note],
        ]);

        // Pořízení z dokladů (D63) — i u drobného majetku pořízeného do nákladů.
        $acquisition = $this->acquisitionService()->acquisition($recordId);
        if ($acquisition['rows'] !== []) {
            $detail['tabs'][0]['content'] = [
                'type'   => 'composite',
                'blocks' => [
                    $detail['tabs'][0]['content'],
                    ['type' => 'heading', 'text' => $this->text('heading.acquisition', 'Acquisition')],
                    $this->acquisitionTable($acquisition),
                ],
            ];
        }

        if (!$longTerm) {
            return $detail;
        }

        $service = $this->planService();
        $card = AssetPlanService::plain($record);
        $events = $service->confirmedEvents($recordId);
        if ($depreciable) {
            $plans = $service->plan($card, $events);
            $detail['tabs'][] = [
                'id'      => 'taxPlan',
                'label'   => $this->text('tab.taxPlan', 'Tax depreciation'),
                'content' => $this->planContent($plans['tax'], $card),
            ];
            $detail['tabs'][] = [
                'id'      => 'accPlan',
                'label'   => $this->text('tab.accPlan', 'Accounting depreciation'),
                // Účetní okruh se účtuje — potvrzené řádky nesou stav zaúčtování.
                'content' => $this->planContent($plans['acc'], $card, $service->postingOf($recordId)),
            ];
        }
        $detail['actions'] = $this->detailActions($card, $events, $depreciable, $acquisition['activation']);

        return $detail;
    }

    // ── Pořízení v detailu ──────────────────────────────────────────────────

    /**
     * Sekce Pořízení v Přehledu (D63): řádky pořízení z potvrzených dokladů
     * s odkazem na doklad (`_action` řádku, sloupec `link`) a součtem.
     *
     * @param array{rows: list<array<string, mixed>>, total: float} $acquisition
     * @return array<string, mixed> content typu table
     */
    private function acquisitionTable(array $acquisition): array
    {
        $rows = [];
        foreach ($acquisition['rows'] as $row) {
            $rows[] = [
                'document' => $row['docNumber'] !== '' ? $row['docNumber'] : '#' . $row['docId'],
                'date'     => $this->formatDate($row['date']) ?? '',
                'text'     => $row['text'],
                'account'  => $row['accountNumber'],
                'amount'   => $this->formatAmount($row['amount']),
                '_action'  => [
                    'id'     => 'openDocument',
                    'kind'   => 'open_detail',
                    'target' => ['viewerId' => self::DOCUMENTS_VIEWER, 'recordId' => $row['docId']],
                ],
            ];
        }
        $rows[] = [
            'document' => $this->text('row.total', 'Total'),
            'amount'   => $this->formatAmount($acquisition['total']),
            '_class'   => 'total',
        ];

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'document', 'label' => $this->text('column.document', 'Document'), 'link' => true],
                ['id' => 'date', 'label' => $this->text('column.date', 'Date')],
                ['id' => 'text', 'label' => $this->text('column.text', 'Text')],
                ['id' => 'account', 'label' => $this->text('column.account', 'Account')],
                ['id' => 'amount', 'label' => $this->text('column.amount', 'Amount'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    protected function acquisitionService(): AssetAcquisitionService
    {
        return new AssetAcquisitionService($this->db->getDibiConnection());
    }

    // ── Plán odpisů v detailu ──────────────────────────────────────────────

    /**
     * Tab okruhu: souhrn (metoda, cena, oprávky, zůstatek, letošní odpis),
     * tabulka řádků plánu (plánované tlumeně, chybové červeně) a hlášení.
     *
     * @param array<string, mixed> $card
     * @param array<int, array{docId: int, docNumber: string}>|null $posting
     *        zaúčtování událostí (jen účetní okruh), null = okruh se neúčtuje
     * @return array<string, mixed> content typu composite
     */
    private function planContent(Plan $plan, array $card, ?array $posting = null): array
    {
        $summary = [];
        if ($plan->circuit === AssetEvent::SCOPE_TAX) {
            $taxMethod = (string) ($card['tax_method'] ?? '');
            $this->addItem($summary, $this->text('summary.method', 'Method'), $taxMethod !== '' ? $this->planService()->rules()->methodName($taxMethod) : null);
            $this->addItem($summary, $this->text('summary.rule', 'Group / rule'), $this->ruleName($taxMethod, (string) ($card['tax_rule'] ?? '')));
        } else {
            $this->addItem($summary, $this->text('summary.method', 'Method'), $this->accMethodLabel($card));
            if ((string) ($card['acc_method'] ?? '') === DepreciationSettings::ACC_TIME && !empty($card['acc_months'])) {
                $this->addItem(
                    $summary,
                    $this->text('summary.accMonths', 'Depreciation period'),
                    $this->text('summary.months', '{months} months', ['months' => (int) $card['acc_months']]),
                );
            }
        }
        $this->addItem($summary, $this->text('summary.entryPrice', 'Entry price'), $this->formatAmount($plan->entryPrice));
        $this->addItem($summary, $this->text('summary.accumulated', 'Accumulated depreciation'), $this->formatAmount($plan->accumulated));
        $this->addItem($summary, $this->text('summary.residual', 'Residual value'), $this->formatAmount($plan->residual));
        $this->addItem($summary, $this->text('summary.currentYear', "This year's depreciation"), $this->formatAmount($plan->currentYearAmount));

        $blocks = [['type' => 'properties', 'groups' => [['title' => '', 'items' => $summary]]]];

        if ($plan->rows === []) {
            $blocks[] = ['type' => 'heading', 'text' => $this->text('text.notActivated', 'The asset is not activated.')];
        } else {
            $blocks[] = $this->planTable($plan, $posting);
        }

        $messages = $this->messageRows($plan);
        if ($messages !== []) {
            $blocks[] = ['type' => 'heading', 'text' => $this->text('heading.messages', 'Messages')];
            $blocks[] = [
                'type'    => 'table',
                'columns' => [
                    ['id' => 'severity', 'label' => $this->text('column.severity', 'Severity')],
                    ['id' => 'message', 'label' => $this->text('column.message', 'Message')],
                ],
                'rows' => $messages,
            ];
        }

        return ['type' => 'composite', 'blocks' => $blocks];
    }

    /**
     * @param array<int, array{docId: int, docNumber: string}>|null $posting
     * @return array<string, mixed> content typu table
     */
    private function planTable(Plan $plan, ?array $posting = null): array
    {
        $kinds = $this->config?->cfgItem('economy.assets.eventKinds') ?? [];
        $rows = [];
        foreach ($plan->rows as $row) {
            $kind = (string) ($kinds[$row->kind]['name'] ?? $row->kind);
            if ($row->halfYear) {
                $kind .= ' (' . $this->text('halfYear', 'half-year') . ')';
            }
            $entry = [
                'period'      => $row->period !== null
                    ? $this->formatDate($row->period->begin) . ' – ' . $this->formatDate($row->period->end)
                    : $this->formatDate($row->date),
                'kind'        => $kind,
                'status'      => $this->planRowStatus($row, $posting),
                'formula'     => $row->formula ?? '',
                'amount'      => $this->formatAmount($row->amount),
                'accumulated' => $this->formatAmount($row->accumulated),
                'residual'    => $this->formatAmount($row->residual),
            ];
            $hasError = false;
            foreach ($row->messages as $message) {
                $hasError = $hasError || $message->isError();
            }
            if ($hasError) {
                $entry['_class'] = 'error';
            } elseif ($row->isPlanned()) {
                $entry['_class'] = 'muted';
            }
            $rows[] = $entry;
        }

        return [
            'type'    => 'table',
            'columns' => [
                ['id' => 'period', 'label' => $this->text('column.period', 'Period')],
                ['id' => 'kind', 'label' => $this->text('column.kind', 'Event')],
                ['id' => 'status', 'label' => $this->text('column.status', 'Status')],
                ['id' => 'formula', 'label' => $this->text('column.formula', 'Calculation')],
                ['id' => 'amount', 'label' => $this->text('column.amount', 'Amount'), 'align' => 'right'],
                ['id' => 'accumulated', 'label' => $this->text('column.accumulated', 'Accumulated'), 'align' => 'right'],
                ['id' => 'residual', 'label' => $this->text('column.residual', 'Residual'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Stav řádku plánu. V účetním okruhu (`$posting` není null) potvrzená
     * účtovaná událost ukazuje doklad, nebo že na zaúčtování čeká (D52);
     * počáteční stav a přerušení se neúčtují.
     *
     * @param array<int, array{docId: int, docNumber: string}>|null $posting
     */
    private function planRowStatus(PlanRow $row, ?array $posting): string
    {
        if ($row->isPlanned()) {
            return $this->text('status.planned', 'Planned');
        }
        if ($posting === null || $row->eventId === null
            || !AssetPostingBuilder::isPostable($row->kind, AssetEvent::SCOPE_ACC)
        ) {
            return $this->text('status.confirmed', 'Confirmed');
        }
        $document = $posting[$row->eventId] ?? null;

        return $document !== null
            ? $this->text('status.posted', 'Posted — document {number}', ['number' => $document['docNumber']])
            : $this->text('status.unposted', 'Waiting for posting');
    }

    /**
     * Podmínka seznamu: karta má potvrzenou událost účetního okruhu bez
     * živého účetního dokladu (badge „Nezaúčtováno“).
     */
    private function unpostedExistsSql(): string
    {
        $kinds = [];
        foreach ([
            AssetEvent::KIND_ACTIVATION, AssetEvent::KIND_IMPROVEMENT, AssetEvent::KIND_REDUCTION,
            AssetEvent::KIND_DEPRECIATION, AssetEvent::KIND_DISPOSAL,
        ] as $kind) {
            $kinds[] = "'" . $kind . "'";
        }
        return 'EXISTS (SELECT 1 FROM `' . self::EVENTS_TABLE . '` ue'
            . ' LEFT JOIN `docs_core_heads` uh ON uh.`id` = ue.`doc_head`'
            . ' WHERE ue.`asset` = a.`id` AND ue.`docState` = ' . AssetEventDocument::STATE_CONFIRMED
            . " AND ue.`scope` <> '" . AssetEvent::SCOPE_TAX . "'"
            . ' AND ue.`event_kind` IN (' . implode(', ', $kinds) . ')'
            . ' AND (ue.`doc_head` IS NULL OR uh.`id` IS NULL OR uh.`docState` IN ('
            . implode(', ', AssetEventDocument::DEAD_DOC_STATES) . ')))';
    }

    /**
     * Hlášení okruhu i řádků, chyby s třídou `error`.
     *
     * @return list<array<string, mixed>>
     */
    private function messageRows(Plan $plan): array
    {
        $texts = new PlanMessageTexts($this->config);
        $rows = [];
        $add = function (PlanMessage $message, ?PlanRow $row) use (&$rows, $texts): void {
            $text = $texts->text($message);
            if ($row !== null) {
                $text = $this->formatDate($row->period?->end ?? $row->date) . ': ' . $text;
            }
            $entry = [
                'severity' => $message->isError()
                    ? $this->text('severity.error', 'Error')
                    : $this->text('severity.warning', 'Warning'),
                'message' => $text,
            ];
            if ($message->isError()) {
                $entry['_class'] = 'error';
            }
            $rows[] = $entry;
        };
        foreach ($plan->messages as $message) {
            $add($message, null);
        }
        foreach ($plan->rows as $row) {
            foreach ($row->messages as $message) {
                $add($message, $row);
            }
        }
        return $rows;
    }

    /**
     * Akce detailu podle stavu karty a potvrzených událostí; jen karta
     * ve stavu V pořádku bez vyřazení něco nabízí.
     *
     * Zařadit u karty s pořízením na 04x předvyplní součet základů řádků
     * a datum posledního dokladu (D63) — uživatel je může upravit
     * (neodpočitatelná DPH, pozdější uvedení do užívání).
     *
     * @param array<string, mixed> $card
     * @param list<array<string, mixed>> $events
     * @param array{amount: float, date: ?string} $acquired pořízení k zařazení
     * @return list<array<string, mixed>>
     */
    private function detailActions(array $card, array $events, bool $depreciable, array $acquired): array
    {
        if ((int) ($card['docState'] ?? 0) !== AssetDocument::STATE_CONFIRMED) {
            return [];
        }
        $activation = null;
        $openings = [];
        foreach ($events as $event) {
            switch ($event['event_kind']) {
                case AssetEvent::KIND_DISPOSAL:
                    return [];
                case AssetEvent::KIND_ACTIVATION:
                    $activation = $event;
                    break;
                case AssetEvent::KIND_OPENING:
                    $openings[(string) $event['scope']] = $event;
                    break;
            }
        }
        $assetId = (int) $card['id'];
        $open = fn(string $id, string $labelKey, string $fallback, string $kind, string $scope, string $variant = 'secondary'): array => [
            'id'      => $id,
            'label'   => $this->text($labelKey, $fallback),
            'kind'    => 'open_form',
            'variant' => $variant,
            'target'  => [
                'table'  => self::EVENTS_TABLE,
                'preset' => ['asset' => $assetId, 'event_kind' => $kind, 'scope' => $scope],
            ],
        ];

        $started = $activation !== null
            || (isset($openings[AssetEvent::SCOPE_TAX]) && isset($openings[AssetEvent::SCOPE_ACC]));
        if (!$started) {
            $actions = [];
            if ($openings === []) {
                $activate = $open('activate', 'action.activate', 'Activate', AssetEvent::KIND_ACTIVATION, AssetEvent::SCOPE_BOTH, 'primary');
                if ($acquired['amount'] > 0) {
                    $activate['target']['preset']['amount'] = $acquired['amount'];
                    if ($acquired['date'] !== null) {
                        $activate['target']['preset']['event_date'] = $acquired['date'];
                    }
                }
                $actions[] = $activate;
            }
            if ($depreciable) {
                foreach ([AssetEvent::SCOPE_TAX => 'Tax', AssetEvent::SCOPE_ACC => 'Acc'] as $scope => $suffix) {
                    if (!isset($openings[$scope])) {
                        $actions[] = $open('opening' . $suffix, 'action.opening' . $suffix, "Opening balance — {$scope}", AssetEvent::KIND_OPENING, $scope);
                    }
                }
            }
            return $actions;
        }

        $actions = [];
        if ($depreciable) {
            $actions[] = [
                'id'      => self::ACTION_DEPRECIATION_RUN,
                'label'   => $this->text('action.depreciate', 'Depreciate'),
                'variant' => 'primary',
                'target'  => ['assetId' => $assetId],
            ];
        }
        $actions[] = $open('improvement', 'action.improvement', 'Improvement', AssetEvent::KIND_IMPROVEMENT, AssetEvent::SCOPE_BOTH);
        $actions[] = $open('reduction', 'action.reduction', 'Reduce value', AssetEvent::KIND_REDUCTION, AssetEvent::SCOPE_BOTH);
        $taxMethod = (string) ($card['tax_method'] ?? '');
        if ($depreciable && $taxMethod !== '' && $this->planService()->rules()->isInterruptible($taxMethod)) {
            $actions[] = $open('interruption', 'action.interruption', 'Interrupt depreciation', AssetEvent::KIND_INTERRUPTION, AssetEvent::SCOPE_TAX);
        }
        $actions[] = $open('disposal', 'action.disposal', 'Dispose', AssetEvent::KIND_DISPOSAL, AssetEvent::SCOPE_BOTH);

        return $actions;
    }

    /** Název pravidla (skupiny) daňové metody, null bez pravidla. */
    private function ruleName(string $taxMethod, string $taxRule): ?string
    {
        if ($taxMethod === '' || $taxRule === '') {
            return null;
        }
        foreach ($this->planService()->rules()->rules($taxMethod, null) as $rule) {
            if ($rule['code'] === $taxRule) {
                return $rule['name'];
            }
        }
        return $taxRule;
    }

    /** @param array<string, mixed> $card */
    private function accMethodLabel(array $card): ?string
    {
        $accMethod = (string) ($card['acc_method'] ?? '');
        if ($accMethod === '') {
            return null;
        }
        $cfg = $this->config?->cfgItem('economy.assets.accMethods') ?? [];
        return (string) ($cfg[$accMethod]['name'] ?? $accMethod);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    protected function categories(): AssetCategories
    {
        return new AssetCategories($this->config);
    }

    /**
     * Options filtru z živých záznamů číselníku (10/40/80).
     *
     * @return list<array{value: int, label: string}>
     */
    private function codebookOptions(string $table, string $labelExpr): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `id`, ' . $labelExpr . ' AS `label` FROM `' . $table . '`'
            . ' WHERE `docState` IN (10, 40, 80) ORDER BY `sort_order` ASC, `label` ASC',
        );
        $options = [];
        foreach ($rows as $row) {
            $options[] = ['value' => (int) $row['id'], 'label' => (string) $row['label']];
        }
        return $options;
    }
}
