<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\Detail\DetailHeader;
use Shipard\Core\Viewer\TableViewer;

/**
 * Viewer bankovních transakcí (economy_bank_transactions).
 *
 * Stavové taby přes $docStatesCfgItem = economy.bank.txStates. Bez akce
 * „nový" — transakce vzniká importem/migrací; editace (operation / partner /
 * message a stavové přechody) přes Open na vybraném řádku.
 *
 * selectRows joinuje base_persons_persons (partner_name pro render
 * i hledání v partnerově full_name); docState po JOINu qualifikujeme
 * aliasem t. Ostatní JOINy jen v loadDetailRecord() nad jedním řádkem.
 *
 * Grid layout (F2, docs/viewer-grid.md §7.3, D11): default grid, bez
 * footeru (SUM přes mix měn nedává smysl); stav = proužek (docStateMain
 * systém) + badge sloupec Zaúčtování. Sortable Datum a Částka — sort dle
 * `amount` řadí absolutní hodnotu sloupce (bez direction znaménka),
 * záměrně: velikost transakce.
 */
class BankTransactionsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'economy.bank.txStates';

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT t.`id`, t.`date_transaction`, t.`direction`, t.`amount`, t.`currency`,'
            . ' t.`counterparty_name`, t.`payment_reference`, t.`operation`, t.`accounting_state`,'
            . ' t.`docState`, t.`docStateMain`, p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        $onlyErrors = false;
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            } elseif (($filter['id'] ?? null) === 'only_errors' && (string) ($filter['value'] ?? '') === '1') {
                $onlyErrors = true;
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem, $viewGroup);
            if ($vgSql !== '') {
                // buildViewGroupFilter vrací nekvalifikovaný `docState`; po JOINu
                // je `docState` v base_persons_persons i zde, qualifikujeme aliasem t.
                $conditions[] = str_replace('`docState`', 't.`docState`', $vgSql);
                $params = array_merge($params, $vgParams);
            }
        }

        if ($onlyErrors) {
            $conditions[] = 't.`accounting_state` = 2';
        }

        if ($search !== null && $search !== '') {
            // Hledá i v partnerově full_name (joinovaná base_persons_persons).
            // buildSearchCondition nekvalifikuje sloupce, proto helper přímo s aliasy.
            [$searchSql, $searchParams] = SearchCondition::anyContains(
                ['t.`counterparty_name`', 't.`counterparty_account`', 't.`payment_reference`', 't.`message`', 'p.`full_name`'],
                $search,
            );
            $conditions[] = $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ' . $this->buildSortedOrderBy(
            ['date_transaction' => 't.`date_transaction`', 'amount' => 't.`amount`'],
            'ORDER BY t.`docStateMain` ASC, t.`date_transaction` DESC, t.`id` DESC',
            't.`id`',
        );

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    public function renderRow(array $rowData): array
    {
        $partner = trim((string) ($rowData['partner_name'] ?? ''));
        $row = [
            'id' => (int) $rowData['id'],
            't1' => $partner !== '' ? $partner : '—',
        ];

        $sign = (int) ($rowData['direction'] ?? 0) === 2 ? '−' : '+';
        $row['i1'] = [
            ['text' => $sign . $this->formatMoney($rowData['amount'] ?? 0), 'class' => 'amount'],
            ['text' => strtoupper((string) ($rowData['currency'] ?? '')), 'class' => 'muted'],
        ];

        $t2 = [];
        $date = $this->formatDate($rowData['date_transaction'] ?? null);
        if ($date !== null) {
            $t2[] = ['text' => $date];
        }
        $vs = trim((string) ($rowData['payment_reference'] ?? ''));
        if ($vs !== '') {
            $t2[] = ['text' => 'VS ' . $vs, 'class' => 'muted'];
        }
        $operation = $this->enumLabel('economy.bank.txOperations', $rowData['operation'] ?? null, 'string');
        if ($operation !== null) {
            $t2[] = ['text' => $operation, 'class' => 'muted'];
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $accState = (int) ($rowData['accounting_state'] ?? 0);
        if ($accState === 2) {
            $row['i2'] = [['text' => '⚠ ' . ($this->language === 'en' ? 'posting error' : 'chyba účtování'), 'class' => 'danger']];
        } elseif ($accState === 1) {
            $row['i2'] = [['text' => $this->language === 'en' ? 'posted' : 'zaúčtováno', 'class' => 'muted']];
        }

        $row['stateStyle'] = $this->stateStyleFor($rowData);

        return $row;
    }

    // ── Grid layout (F2, docs/viewer-grid.md §7.3) ──────────────────────────

    /** Transakce se na desktopu otevírají jako tabulka; list zůstává mobilním formátem. */
    public function getDefaultLayout(): string
    {
        return 'grid';
    }

    public function getGridColumns(): ?array
    {
        $cs = $this->language !== 'en';

        return [
            ['id' => 'date_transaction', 'label' => $cs ? 'Datum' : 'Date', 'width' => 96, 'sortable' => true],
            ['id' => 'amount', 'label' => $cs ? 'Částka' : 'Amount', 'width' => 130, 'align' => 'right', 'sortable' => true],
            ['id' => 'counterparty_name', 'label' => $cs ? 'Protistrana' : 'Counterparty', 'grow' => true],
            ['id' => 'partner_name', 'label' => 'Partner'],
            ['id' => 'payment_reference', 'label' => $cs ? 'VS' : 'Reference', 'width' => 120],
            ['id' => 'operation', 'label' => $cs ? 'Operace' : 'Operation', 'width' => 110],
            ['id' => 'accounting', 'label' => $cs ? 'Zaúčtování' : 'Accounting', 'width' => 120],
        ];
    }

    public function renderGridRow(array $rowData): array
    {
        $cs = $this->language !== 'en';

        // Chybu účtování nese badge — řádek se nečervená (rowClass), chybových
        // může být hodně (D11). Proužek patří stavu transakce (docState).
        $accState = (int) ($rowData['accounting_state'] ?? 0);
        $accounting = match ($accState) {
            1       => ['text' => $cs ? 'zaúčtováno' : 'posted', 'badge' => 'success'],
            2       => ['text' => $cs ? 'chyba účtování' : 'posting error', 'badge' => 'danger'],
            default => null,
        };

        $sign = (int) ($rowData['direction'] ?? 0) === 2 ? '−' : '+';

        return [
            'id'         => (int) $rowData['id'],
            'stateStyle' => $this->stateStyleFor($rowData),
            'cells' => [
                'date_transaction'  => $this->formatDate($rowData['date_transaction'] ?? null),
                'amount' => [
                    ['text' => $sign . $this->formatMoney($rowData['amount'] ?? 0), 'class' => 'amount'],
                    ['text' => strtoupper((string) ($rowData['currency'] ?? '')), 'class' => 'muted'],
                ],
                'counterparty_name' => (string) ($rowData['counterparty_name'] ?? ''),
                'partner_name'      => (string) ($rowData['partner_name'] ?? ''),
                'payment_reference' => (string) ($rowData['payment_reference'] ?? ''),
                'operation'         => $this->enumLabel('economy.bank.txOperations', $rowData['operation'] ?? null, 'string'),
                'accounting'        => $accounting,
            ],
        ];
    }

    /** stateStyle řádku z docState přes txStates config — sdílené list/grid. */
    private function stateStyleFor(array $rowData): string
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem));
        $stateData = $cfg->getState($docState);
        return $stateData['stateStyle'] ?? 'concept';
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT t.*, ba.`code` AS account_code, ba.`name` AS account_name,'
            . ' p.`full_name` AS partner_name,'
            . ' s.`period_start` AS st_start, s.`period_end` AS st_end'
            . ' FROM `' . $this->table . '` t'
            . ' LEFT JOIN `economy_codebooks_bank_accounts` ba ON ba.`id` = t.`bank_account`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = t.`partner`'
            . ' LEFT JOIN `economy_bank_statements` s ON s.`id` = t.`statement`'
            . ' WHERE t.`id` = %i',
            $recordId,
        );
    }

    /** Přehled (transakce, částky, protistrana, účet a výpis) a tab Zaúčtování, když má co ukázat. */
    protected function detailTabs(array $record): array
    {
        $cs = $this->language !== 'en';

        // Datum transakce nese podtitul, stav štítek (#140 D26).
        $txItems = [];
        $this->addItem($txItems, $cs ? 'Datum valuty' : 'Value date', $this->formatDate($record['date_value'] ?? null));
        $this->addItem($txItems, $cs ? 'Směr' : 'Direction', $this->enumLabel('economy.bank.txDirections', $record['direction'] ?? null, 'int'));
        $this->addItem($txItems, $cs ? 'Pohyb' : 'Operation', $this->enumLabel('economy.bank.txOperations', $record['operation'] ?? null, 'string'));

        // Částku se znaménkem a měnou nese titulek; přepočet a kurz jen u cizí měny.
        $amountItems = [];
        $rate = (float) ($record['exchange_rate'] ?? 1);
        if (abs($rate - 1.0) > 0.0000001) {
            $this->addItem($amountItems, $cs ? 'V domácí měně' : 'In home currency', $this->formatMoney($record['amount_dom'] ?? 0));
            $this->addItem($amountItems, $cs ? 'Kurz' : 'Exchange rate', number_format($rate, 6, ',', ' '));
        }

        // Partner je v podtitulku; bez něj je tam název protistrany (#140 §2).
        $cpItems = [];
        $this->addItem($cpItems, $cs ? 'Protiúčet' : 'Counterparty account', $record['counterparty_account'] ?? null);
        if ($this->partyOf($record)['column'] === 'partner_name') {
            $this->addItem($cpItems, $cs ? 'Název protistrany' : 'Counterparty name', $record['counterparty_name'] ?? null);
        }
        $this->addItem($cpItems, $cs ? 'Variabilní symbol' : 'Variable symbol', $record['payment_reference'] ?? null);
        $this->addItem($cpItems, $cs ? 'Specifický symbol' : 'Specific symbol', $record['specific_symbol'] ?? null);
        $this->addItem($cpItems, $cs ? 'Konstantní symbol' : 'Constant symbol', $record['constant_symbol'] ?? null);
        $this->addItem($cpItems, $cs ? 'Zpráva' : 'Message', $record['message'] ?? null);

        $accountLabel = trim(
            (string) ($record['account_code'] ?? '')
            . (($record['account_name'] ?? null) !== null ? ' — ' . $record['account_name'] : ''),
        );
        $ctxItems = [];
        $this->addItem($ctxItems, $cs ? 'Bankovní účet' : 'Bank account', $accountLabel !== '' ? $accountLabel : null);
        if (($record['st_start'] ?? null) !== null && ($record['st_end'] ?? null) !== null) {
            $this->addItem(
                $ctxItems,
                $cs ? 'Výpis' : 'Statement',
                $this->formatDate($record['st_start']) . ' – ' . $this->formatDate($record['st_end']),
            );
        }

        $groups = [];
        $this->addGroup($groups, $cs ? 'Transakce' : 'Transaction', $txItems);
        $this->addGroup($groups, $cs ? 'Částky' : 'Amounts', $amountItems);
        $this->addGroup($groups, $cs ? 'Protistrana' : 'Counterparty', $cpItems);
        $this->addGroup($groups, $cs ? 'Účet a výpis' : 'Account & statement', $ctxItems);

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]];

        $accountingTab = $this->buildAccountingTab($record);
        if ($accountingTab !== null) {
            $tabs[] = $accountingTab;
        }

        return $tabs;
    }

    /**
     * Hlavička detailu: částka se znaménkem a měnou jako titulek, „partner ·
     * datum" v podtitulku (bez jména jen datum, a naopak; bez partnera
     * protistrana z výpisu). Ikona z deklarace vieweru; štítek stavu
     * (vč. Zaúčtováno — nese účetní stav, #136 D25) přidává builder.
     */
    protected function detailHeader(array $record): DetailHeader
    {
        $sign = (int) ($record['direction'] ?? 0) === 2 ? '−' : '+';
        $curCode = strtoupper((string) ($record['currency'] ?? ''));
        $title = $sign . $this->formatMoney($record['amount'] ?? 0);
        if ($curCode !== '') {
            $title .= ' ' . $curCode;
        }

        $date = $this->formatDate($record['date_transaction'] ?? null);
        $subtitleParts = array_filter([$this->partyOf($record)['value'], $date], static fn ($v) => $v !== null && $v !== '');

        return new DetailHeader($title, implode(' · ', $subtitleParts));
    }

    /**
     * Kdo je v podtitulku: partner, bez něj název protistrany z výpisu.
     * Tutéž volbu čte Přehled — protistranu ukáže jen vedle partnera (#140 §2).
     *
     * @param array<string, mixed> $record
     * @return array{column: string, value: string}
     */
    private function partyOf(array $record): array
    {
        foreach (['partner_name', 'counterparty_name'] as $column) {
            $value = trim((string) ($record[$column] ?? ''));
            if ($value !== '') {
                return ['column' => $column, 'value' => $value];
            }
        }
        return ['column' => '', 'value' => ''];
    }

    /**
     * Stav účtování — štítek jen při chybě (stav 2). Zaúčtováno už
     * signalizuje stav transakce (docState 40), duplicitní štítek by byl šum.
     */
    protected function detailBadges(array $record): array
    {
        if ((int) ($record['accounting_state'] ?? 0) !== 2) {
            return [];
        }
        return [[
            'label' => $this->language === 'en' ? 'Posting error' : 'Chyba účtování',
            'style' => 'error',
        ]];
    }

    /**
     * Akce detailu. Přeúčtovat — jen pro transakci ve stavu 40 (idempotentní,
     * jde i u bezchybně zaúčtované po opravě rozvrhu). Viewer.svelte volá
     * POST /_bank/reaccount a refreshne detail.
     */
    protected function detailActions(array $record): array
    {
        if ((int) ($record['docState'] ?? 0) !== 40) {
            return [];
        }
        return [[
            'id'      => 'reaccountTransaction',
            'label'   => $this->language === 'en' ? 'Re-account' : 'Přeúčtovat',
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
    }

    /**
     * Tab Zaúčtování — stav účtování + řádky účetního deníku transakce.
     * Tab se zobrazí, když má transakce nenulový stav účtování nebo řádky
     * v deníku; Nová transakce bez deníku tab nemá.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function buildAccountingTab(array $record): ?array
    {
        $state = (int) ($record['accounting_state'] ?? 0);
        $journalRows = $this->db->fetchAll(
            'SELECT `account_number`, `text`, `money_dr`, `money_cr`, `is_error`'
            . ' FROM `economy_accounting_journal`'
            . ' WHERE `bank_transaction` = %i'
            . ' ORDER BY `id` ASC',
            (int) $record['id'],
        );

        if ($state === 0 && $journalRows === []) {
            return null;
        }

        $blocks = [$this->accountingStatusBlock($record, $state)];
        if ($journalRows !== []) {
            $blocks[] = $this->accountingJournalTable($journalRows);
        }

        return [
            'id'      => 'accounting',
            'label'   => $this->detailTabLabel(
                'economy.accounting.viewerDetailLabels',
                'accounting',
                'Accounting',
            ),
            'content' => ['type' => 'composite', 'blocks' => $blocks],
        ];
    }

    /**
     * Badge stavu účtování; při chybě (stav 2) banner s hlášeními. Vzor
     * DocsHeadsViewer::accountingStatusBlock — escapované hodnoty, vzhled
     * přes globální CSS proměnné (funguje i v dark mode).
     *
     * @param array<string, mixed> $record
     */
    private function accountingStatusBlock(array $record, int $state): array
    {
        $states = $this->config?->cfgItem('economy.accounting.accountingStates');
        $stateName = is_array($states) ? (string) ($states[(string) $state]['name'] ?? '') : '';
        if ($stateName === '') {
            $stateName = ['Not accounted', 'Accounted', 'Accounting error'][$state] ?? (string) $state;
        }
        $tone = match ($state) {
            1       => 'done',
            2       => 'error',
            default => 'concept',
        };

        $html = '<div style="margin-bottom:var(--shpd-space-md)">'
            . '<span style="display:inline-block;padding:2px 8px;border-radius:999px;'
            . 'font-size:0.75rem;font-weight:500;'
            . 'background:var(--shpd-color-state-' . $tone . '-bg);'
            . 'color:var(--shpd-color-state-' . $tone . '-text)">'
            . htmlspecialchars($stateName, ENT_QUOTES) . '</span>';

        // Stav OK může nést varování (zprávy úrovně `warning`, selhání
        // contributoru deníku #79 D3b) — vypíší se v tónu „k pozornosti“.
        $messages = $state >= 1 ? $this->decodeAccountingMessages($record['accounting_messages'] ?? null) : [];
        if ($messages !== []) {
            $listTone = $state === 2 ? 'error' : 'edit';
            $items = '';
            foreach ($messages as $msg) {
                $items .= '<li>' . htmlspecialchars((string) ($msg['message'] ?? ''), ENT_QUOTES) . '</li>';
            }
            $html .= '<ul role="' . ($state === 2 ? 'alert' : 'status') . '" style="margin:var(--shpd-space-sm) 0 0;'
                . 'padding:var(--shpd-space-sm) var(--shpd-space-md) var(--shpd-space-sm) 28px;'
                . 'background:var(--shpd-color-state-' . $listTone . '-bg);'
                . 'color:var(--shpd-color-state-' . $listTone . '-text);'
                . 'border-radius:var(--shpd-radius-md);'
                . 'font-size:var(--shpd-font-size-sm)">' . $items . '</ul>';
        }

        return ['type' => 'html', 'html' => $html . '</div>'];
    }

    /**
     * Tabulka řádků deníku se součtovým Σ řádkem. Nulová strana se nechává
     * prázdná, chybové řádky `_class: error`, Σ řádek `_class: total`.
     *
     * @param list<array<string, mixed>> $journalRows
     */
    private function accountingJournalTable(array $journalRows): array
    {
        $cs = $this->language !== 'en';
        $columns = [
            ['id' => 'account', 'label' => $cs ? 'Účet' : 'Account'],
            ['id' => 'text',    'label' => 'Text'],
            ['id' => 'md',      'label' => $cs ? 'MD' : 'Debit',  'align' => 'right'],
            ['id' => 'dal',     'label' => $cs ? 'DAL' : 'Credit', 'align' => 'right'],
        ];

        $rows = [];
        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($journalRows as $jr) {
            $row = [
                'account' => (string) ($jr['account_number'] ?? ''),
                'text'    => (string) ($jr['text'] ?? ''),
                'md'      => $this->formatNonZeroMoney($jr['money_dr'] ?? null),
                'dal'     => $this->formatNonZeroMoney($jr['money_cr'] ?? null),
            ];
            if ((int) ($jr['is_error'] ?? 0) === 1) {
                $row['_class'] = 'error';
            }
            $sumDr += (float) ($jr['money_dr'] ?? 0);
            $sumCr += (float) ($jr['money_cr'] ?? 0);
            $rows[] = $row;
        }
        $rows[] = [
            'account' => 'Σ',
            'text'    => '',
            'md'      => $this->formatMoney($sumDr),
            'dal'     => $this->formatMoney($sumCr),
            '_class'  => 'total',
        ];

        return ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
    }

    /** Částka pro stranu zápisu — nula se nechává prázdná. */
    private function formatNonZeroMoney(mixed $amount): ?string
    {
        if ($amount === null || $amount === '' || (float) $amount === 0.0) {
            return null;
        }
        return $this->formatMoney($amount);
    }

    /** @return list<array<string, mixed>> dekódované accounting_messages (JSON sloupec) */
    private function decodeAccountingMessages(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function getToolbarActions(?array $selectedRow): array
    {
        // Bez „nový" — transakce vzniká importem/migrací. Edit (operation /
        // partner / message a stavové přechody) přes Open na vybraném řádku.
        if ($selectedRow === null) {
            return [];
        }
        $defs = ($this->config?->cfgItem('core.system.viewerDefaults') ?? [])['toolbarActions'] ?? [];
        $editDef = $defs['edit'] ?? ['name' => 'Open', 'variant' => 'secondary'];
        return [[
            'id'      => 'edit',
            'label'   => $editDef['name'] ?? 'Open',
            'variant' => $editDef['variant'] ?? 'secondary',
        ]];
    }

    public function getFilters(): array
    {
        $cs = $this->language !== 'en';
        return [[
            'id'    => 'only_errors',
            'label' => $cs ? 'Jen chyby účtování' : 'Posting errors only',
            'type'  => 'checkbox',
        ]];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function enumLabel(string $cfgItemId, mixed $value, string $keyType = 'int', string $field = 'name'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $items = $this->config?->cfgItem($cfgItemId);
        if (!is_array($items)) {
            return null;
        }
        $key = $keyType === 'int' ? (string) (int) $value : (string) $value;
        $entry = $items[$key] ?? null;
        return is_array($entry) ? ($entry[$field] ?? null) : null;
    }

    /** @param array<int, array{label: string, value: string}> $items */
    private function addItem(array &$items, string $label, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $items[] = ['label' => $label, 'value' => (string) $value];
        }
    }

    private function addGroup(array &$groups, string $title, array $items): void
    {
        if ($items !== []) {
            $groups[] = ['title' => $title, 'items' => $items];
        }
    }

    private function formatMoney(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, ',', ' ');
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('j. n. Y');
        }
        $ts = is_string($value) ? strtotime($value) : false;
        return $ts !== false ? date('j. n. Y', $ts) : null;
    }
}
