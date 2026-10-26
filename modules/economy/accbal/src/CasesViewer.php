<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Viewer\Detail\DetailHeader;

/**
 * Viewer saldokonta **po případech** (#69 D1, T2) — výchozí vstup do
 * saldokonta: kdo kolik dluží, co je otevřené. Jeden řádek = případ, tj.
 * agregát klíče (saldokonto, období, partner, VS, SS, měna) z ledgeru
 * ({@see CaseQuery}); žádná entita, žádný sloupec — `GROUP BY` klíče nad
 * `economy_accbal_ledger`, id řádku = `row_id` (MIN(id) pohybů klíče), ze
 * kterého detail klíč odvodí.
 *
 * Filtry ve dvou úrovních ({@see CaseTotals::conditions}): klíčové
 * (saldokonto = chip, období, partner, VS, SS, měna) jdou do vnitřního
 * dotazu před GROUP BY; případové (otevřenost, typ, po splatnosti, splatné
 * do N dní) nad agregátem. Období je první filtr s výchozím
 * aktuálním fiskálním rokem (`default`, AccbalViewerBase::periodFilter) —
 * případ je na období vázaný (D11), bez filtru by tentýž klíč stál v deseti
 * letech pod sebou; uvolnění „— vše —" ukáže všechna období. „Jen
 * otevřené" je výchozí přes obrácený checkbox **Včetně uzavřených**
 * (z doby před `default`; zůstává, nové filtry ho nekopírují). Typ
 * otevřenosti (dluh / přeplatek / úhrada bez předpisu)
 * je samostatně filtrovatelný — na reimportovaném DS jsou tisíce úhrad bez
 * předpisu z pokladních a interních dokladů a nesmí se schovat mezi
 * přeplatky.
 *
 * Grid (docs/viewer-grid.md §5, D12): skupiny per partner, skupinový
 * řádek nese součet zůstatku partnera v domácí měně přes celý filtrovaný
 * set (okno `SUM() OVER (PARTITION BY partner)` — počítá se až po WHERE,
 * sedí i přes hranici stránek); footer Σ předpisy / úhrady / zůstatek
 * v domácí měně, s filtrem měny v této měně ({@see CaseTotals} — týž
 * výpočet jako Přehled firmy, #120). Zvýraznění typu doc-state konvencí (design-system.md §4,
 * žádná nová barva): dluh bez proužku, dluh po splatnosti `cancelled`
 * (červený „pozor"), přeplatek a úhrada bez předpisu `concept` (žlutý
 * „podívej se"), uzavřený `archive`.
 *
 * Akce detailu „Pohyby případu" otevře {@see LedgerViewer} s chipem
 * saldokonta a předvyplněnými filtry období případu / partner / VS / SS
 * (viditelné, uživatel je může uvolnit — pohyby klíče přes roky jsou
 * dostupné uvolněním období, výchozí je období případu, aby se z případu
 * 2024 neotevřely pohyby filtrované na aktuální rok).
 */
class CasesViewer extends AccbalViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        [$rowConds, $rowParams, $caseConds, $caseParams] = $this->buildConditions($filters);

        $sql = 'SELECT c.*, b.`name` AS balance_name, b.`short_name` AS balance_short_name,'
            . ' b.`code` AS balance_code, p.`full_name` AS partner_name, fy.`name` AS fiscal_year_name,'
            // Součet zůstatku partnera (HC) pro skupinový řádek — okno se
            // vyhodnocuje po WHERE, tedy přes filtrovaný set, a před LIMIT.
            . ' SUM(c.`residual_hc`) OVER (PARTITION BY c.`partner`) AS partner_residual_hc'
            . ' FROM (' . $this->casesSql($rowConds) . ') c'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = c.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = c.`partner`'
            . ' LEFT JOIN `economy_codebooks_fiscal_years` fy ON fy.`id` = c.`fiscal_year`';
        if ($caseConds !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $caseConds);
        }

        // Primárně partner (kontrakt skupin gridu, D12; bez partnera na
        // konec), uvnitř partnera klíč případu.
        $sql .= ' ORDER BY ISNULL(p.`full_name`) ASC, p.`full_name` ASC, c.`partner` ASC,'
            . ' c.`fiscal_year` ASC, c.`balance` ASC, c.`currency` ASC,'
            . ' c.`payment_reference` ASC, c.`specific_symbol` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$rowParams, ...$caseParams);
    }

    /** Agregát případů — vnitřní dotaz sdílený seznamem, footerem i Přehledem firmy ({@see CaseTotals::casesSql}). */
    private function casesSql(array $rowConds): string
    {
        return CaseTotals::casesSql($this->table, $rowConds);
    }

    /**
     * Filtry ve dvou úrovních ({@see CaseTotals::conditions}): klíčové →
     * vnitřní dotaz (před GROUP BY), případové → nad agregátem (alias `c`).
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<string>, 3: list<mixed>}
     *         [rowConds, rowParams, caseConds, caseParams]
     */
    private function buildConditions(array $filters): array
    {
        return CaseTotals::conditions($filters, $this->today());
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public function renderRow(array $rowData): array
    {
        $cs = $this->language === 'cs';
        $case = CaseQuery::decorate($rowData, $this->today());
        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));

        $t2 = [];
        $balanceShort = trim((string) ($rowData['balance_short_name'] ?? ''));
        $t2[] = ['text' => $balanceShort !== '' ? $balanceShort : trim((string) ($rowData['balance_name'] ?? '')), 'class' => 'muted'];
        $vs = trim((string) ($rowData['payment_reference'] ?? ''));
        if ($vs !== '') {
            $t2[] = ['text' => 'VS ' . $vs, 'class' => 'muted'];
        }
        $ss = trim((string) ($rowData['specific_symbol'] ?? ''));
        if ($ss !== '') {
            $t2[] = ['text' => 'SS ' . $ss, 'class' => 'muted'];
        }
        $due = $this->formatDate($rowData['due_date'] ?? null);
        if ($due !== null) {
            $t2[] = ['text' => ($cs ? 'splatnost ' : 'due ') . $due, 'class' => 'muted'];
        }
        $t2[] = ['text' => $this->kindLabel($case['kind']), 'class' => 'muted'];

        $i2 = [
            ['text' => ($cs ? 'předpisy ' : 'requests ') . $this->formatMoney($case['sum_requests']), 'class' => 'muted'],
            ['text' => ($cs ? 'úhrady ' : 'payments ') . $this->formatMoney($case['sum_payments']), 'class' => 'muted'],
        ];

        return [
            'id'         => (int) ($rowData['row_id'] ?? 0),
            't1'         => $partnerName !== '' ? $partnerName : ($cs ? '(Bez partnera)' : '(No partner)'),
            'i1'         => $this->formatMoney($case['residual']) . ' ' . $curCode,
            'stateStyle' => $this->stateStyleOf($case),
            't2'         => $t2,
            'i2'         => $i2,
        ];
    }

    /**
     * Bez `sortable` sloupců (záměr, D12): skupiny per partner vyžadují
     * primární řazení dle partnera; partner není sloupec — nese ho
     * hlavička skupiny i se součtem zůstatku.
     */
    public function getGridColumns(): ?array
    {
        $cs = $this->language === 'cs';

        return [
            ['id' => 'fiscal_year', 'label' => $cs ? 'Období' : 'Period', 'width' => 80],
            ['id' => 'payment_reference', 'label' => $cs ? 'VS' : 'Reference', 'width' => 110],
            ['id' => 'specific_symbol', 'label' => $cs ? 'SS' : 'Spec. symbol', 'width' => 90],
            ['id' => 'currency', 'label' => $cs ? 'Měna' : 'Currency', 'width' => 60],
            ['id' => 'sum_requests', 'label' => $cs ? 'Předpisy' : 'Requests', 'width' => 120, 'align' => 'right'],
            ['id' => 'sum_payments', 'label' => $cs ? 'Úhrady' : 'Payments', 'width' => 120, 'align' => 'right'],
            ['id' => 'residual', 'label' => $cs ? 'Zůstatek' : 'Residual', 'width' => 120, 'align' => 'right'],
            ['id' => 'residual_hc', 'label' => $cs ? 'Zůstatek (dom.)' : 'Residual (home)', 'width' => 120, 'align' => 'right'],
            ['id' => 'due_date', 'label' => $cs ? 'Splatnost' : 'Due date', 'width' => 96],
            ['id' => 'overdue', 'label' => $cs ? 'Po splatnosti' : 'Overdue', 'width' => 90, 'align' => 'right'],
            ['id' => 'moves', 'label' => $cs ? 'Pohybů' : 'Moves', 'width' => 70, 'align' => 'right'],
            // Se zvoleným chipem redundantní, na „Vše" užitečné.
            ['id' => 'balance', 'label' => $cs ? 'Saldokonto' : 'Balance', 'grow' => true],
        ];
    }

    public function renderGridRow(array $rowData): array
    {
        $cs = $this->language === 'cs';
        $case = CaseQuery::decorate($rowData, $this->today());
        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
        $hcCode = strtoupper((string) ($rowData['home_currency'] ?? ''));
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        $balanceShort = trim((string) ($rowData['balance_short_name'] ?? ''));

        return [
            'id'         => (int) ($rowData['row_id'] ?? 0),
            'stateStyle' => $this->stateStyleOf($case),
            // Skupinová hlavička per partner (klíč z FK, případy bez
            // partnera sdílí 'p0') se součtem zůstatku partnera v HC.
            'group' => [
                'key'   => 'p' . (int) ($rowData['partner'] ?? 0),
                'label' => ($partnerName !== '' ? $partnerName : ($cs ? '(Bez partnera)' : '(No partner)'))
                    . ' · ' . ($cs ? 'zůstatek ' : 'balance ')
                    . $this->formatMoney($rowData['partner_residual_hc'] ?? 0)
                    . ($hcCode !== '' ? ' ' . $hcCode : ''),
            ],
            'cells' => [
                'fiscal_year'       => (string) ($rowData['fiscal_year_name'] ?? ''),
                'payment_reference' => (string) ($rowData['payment_reference'] ?? ''),
                'specific_symbol'   => (string) ($rowData['specific_symbol'] ?? ''),
                'currency'          => $curCode,
                'sum_requests'      => ['text' => $this->formatMoney($case['sum_requests']), 'class' => 'amount'],
                'sum_payments'      => ['text' => $this->formatMoney($case['sum_payments']), 'class' => 'amount'],
                'residual'          => ['text' => $this->formatMoney($case['residual']), 'class' => 'amount'],
                'residual_hc'       => ['text' => $this->formatMoney($case['residual_hc']), 'class' => 'amount'],
                'due_date'          => $this->formatDate($rowData['due_date'] ?? null),
                'overdue'           => $case['days_overdue'] > 0
                    ? ['text' => (string) $case['days_overdue'], 'badge' => 'danger']
                    : null,
                'moves'             => (string) $case['moves'],
                'balance'           => $balanceShort !== '' ? $balanceShort : trim((string) ($rowData['balance_name'] ?? '')),
            ],
        ];
    }

    /**
     * Součty přes CELÝ filtrovaný set — stejné podmínky obou úrovní jako
     * selectRows() (řádky přes všechny stránky), výpočet {@see CaseTotals}
     * sdílený s Přehledem firmy. S filtrem měny jsou součty v té měně
     * (`residual`, obchodní zůstatek), bez něj v domácí (`residual_hc`) —
     * součet obchodních zůstatků přes měny nedává smysl.
     */
    public function renderGridFooter(?string $search, array $filters): ?array
    {
        $cs   = $this->language === 'cs';
        $rows = (new CaseTotals($this->db, $this->table))->totals($filters, $this->today());

        $currency = null;
        foreach ($filters as $filter) {
            if (($filter['id'] ?? null) === CaseTotals::FILTER_CURRENCY) {
                $currency = CaseQuery::normalizeCurrency($filter['value'] ?? null);
            }
        }

        $hc = '';
        $residualHc = 0.0;
        foreach ($rows as $row) {
            $residualHc += $row['residual_hc'];
            if ($hc === '' && $row['home_currency'] !== '') {
                $hc = ' ' . strtoupper($row['home_currency']);
            }
        }

        if ($currency !== null) {
            $row  = $rows[0] ?? ['sum_requests' => 0.0, 'sum_payments' => 0.0, 'residual' => 0.0];
            $code = ' ' . strtoupper($currency);
            return [
                'sum_requests' => [
                    ['text' => $cs ? 'Předpisy' : 'Requests', 'class' => 'muted'],
                    ['text' => $this->formatMoney($row['sum_requests']) . $code],
                ],
                'sum_payments' => [
                    ['text' => $cs ? 'Úhrady' : 'Payments', 'class' => 'muted'],
                    ['text' => $this->formatMoney($row['sum_payments']) . $code],
                ],
                'residual' => [
                    ['text' => $cs ? 'Zůstatek' : 'Residual', 'class' => 'muted'],
                    ['text' => $this->formatMoney($row['residual']) . $code, 'class' => 'amount'],
                ],
                'residual_hc' => [
                    ['text' => $cs ? 'Zůstatek (dom.)' : 'Residual (home)', 'class' => 'muted'],
                    ['text' => $this->formatMoney($residualHc) . $hc, 'class' => 'amount'],
                ],
            ];
        }

        $sumRequests = 0.0;
        $sumPayments = 0.0;
        foreach ($rows as $row) {
            $sumRequests += $row['sum_requests_hc'];
            $sumPayments += $row['sum_payments_hc'];
        }

        return [
            'sum_requests' => [
                ['text' => $cs ? 'Předpisy' : 'Requests', 'class' => 'muted'],
                ['text' => $this->formatMoney($sumRequests) . $hc],
            ],
            'sum_payments' => [
                ['text' => $cs ? 'Úhrady' : 'Payments', 'class' => 'muted'],
                ['text' => $this->formatMoney($sumPayments) . $hc],
            ],
            'residual_hc' => [
                ['text' => $cs ? 'Zůstatek' : 'Residual', 'class' => 'muted'],
                ['text' => $this->formatMoney($residualHc) . $hc, 'class' => 'amount'],
            ],
        ];
    }

    /**
     * Záznam detailu = případ odvozený z řádku ledgeru (`row_id`): klíč,
     * agregát a jména (saldokonto, partner, období) v jednom poli, aby je
     * hlavička, Přehled i akce četly jednou. Řádek bez klíče nebo bez
     * případu = detail bez tabů.
     */
    protected function loadDetailRecord(int $recordId): ?array
    {
        $query = new CaseQuery($this->db->getDibiConnection());
        $key = $query->keyOfRow($recordId);
        $case = $key !== null ? $query->caseOf($key) : null;
        if ($key === null || $case === null) {
            return null;
        }
        $names = $this->db->fetchRow(
            'SELECT b.`name` AS balance_name, b.`code` AS balance_code, p.`full_name` AS partner_name,'
            . ' fy.`name` AS fiscal_year_name'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`'
            . ' LEFT JOIN `economy_codebooks_fiscal_years` fy ON fy.`id` = l.`fiscal_year`'
            . ' WHERE l.`id` = %i',
            $recordId,
        ) ?? [];

        return ['id' => $recordId, 'key' => $key, 'case' => $case] + (array) $names;
    }

    /** Titulek partner (bez partnera „Bez partnera"), podtitul saldokonto · VS · období (#136 §6). */
    protected function detailHeader(array $record): DetailHeader
    {
        $cs = $this->language === 'cs';
        $partnerName = trim((string) ($record['partner_name'] ?? ''));
        $subtitle = implode(' · ', array_filter([
            (string) ($record['balance_name'] ?? ''),
            (string) ($record['key']['payment_reference'] ?? ''),
            (string) ($record['fiscal_year_name'] ?? ''),
        ], static fn (string $v): bool => $v !== ''));

        return new DetailHeader(
            $partnerName !== '' ? $partnerName : ($cs ? 'Bez partnera' : 'No partner'),
            $subtitle !== '' ? $subtitle : null,
        );
    }

    /** Přehled: klíč případu a částky (předpisy, úhrady, zůstatek, stav, splatnost). */
    protected function detailTabs(array $record): array
    {
        $key = $record['key'];
        $case = $record['case'];
        $cs = $this->language === 'cs';
        $curCode = strtoupper((string) ($key['currency'] ?? ''));
        $hcCode = strtoupper((string) ($case['home_currency'] ?? ''));

        // Partner je titulkem; saldokonto, variabilní symbol a období podtitulkem (#140 D26).
        $keyItems = [];
        $this->addItem($keyItems, $cs ? 'Specifický symbol' : 'Specific symbol', $key['specific_symbol'] ?? null);
        $this->addItem($keyItems, $cs ? 'Měna' : 'Currency', $curCode);

        $amountItems = [];
        $this->addItem($amountItems, $cs ? 'Předpisy' : 'Requests', $this->formatMoney($case['sum_requests']) . ' ' . $curCode);
        $this->addItem($amountItems, $cs ? 'Úhrady' : 'Payments', $this->formatMoney($case['sum_payments']) . ' ' . $curCode);
        $this->addItem($amountItems, $cs ? 'Zůstatek' : 'Residual', $this->formatMoney($case['residual']) . ' ' . $curCode);
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($amountItems, ($cs ? 'Zůstatek ' : 'Residual ') . $hcCode, $this->formatMoney($case['residual_hc']));
        }
        $this->addItem($amountItems, $cs ? 'Stav' : 'State', $this->kindLabel($case['kind']));
        $this->addItem($amountItems, $cs ? 'Splatnost' : 'Due date', $this->formatDate($case['due_date'] ?? null));
        if ($case['days_overdue'] > 0) {
            $this->addItem($amountItems, $cs ? 'Dní po splatnosti' : 'Days overdue', $case['days_overdue']);
        }
        $this->addItem($amountItems, $cs ? 'Pohybů' : 'Movements', $case['moves']);

        return [$this->propertiesTab([
            ['title' => $cs ? 'Případ' : 'Case', 'items' => $keyItems],
            ['title' => $cs ? 'Částky' : 'Amounts', 'items' => $amountItems],
        ])];
    }

    /**
     * Akce „Pohyby případu" otevře viewer pohybů s chipem saldokonta
     * a viditelnými filtry období případu / partner / VS / SS (období
     * přebíjí výchozí aktuální rok cílového vieweru).
     */
    protected function detailActions(array $record): array
    {
        $key = $record['key'];
        $cs = $this->language === 'cs';
        $partnerName = trim((string) ($record['partner_name'] ?? ''));

        $filters = [];
        if (($key['fiscal_year'] ?? null) !== null) {
            $filters['fiscal_year'] = (string) $key['fiscal_year'];
        }
        if ($partnerName !== '') {
            $filters['partner'] = $partnerName;
        }
        if (($key['payment_reference'] ?? null) !== null) {
            $filters['payment_reference'] = (string) $key['payment_reference'];
        }
        if (($key['specific_symbol'] ?? null) !== null) {
            $filters['specific_symbol'] = (string) $key['specific_symbol'];
        }

        return [[
            'id'        => 'open_ledger',
            'label'     => $cs ? 'Pohyby případu' : 'Case movements',
            'kind'      => 'open_viewer',
            'viewerId'  => 'economy.accbal.ledger',
            'viewGroup' => (string) ($record['balance_code'] ?? 'all'),
            'filters'   => $filters,
            'variant'   => 'primary',
        ]];
    }

    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        return [
            $this->periodFilter(),
            ['id' => 'partner', 'label' => 'Partner', 'type' => 'text'],
            ['id' => 'payment_reference', 'label' => $cs ? 'Variabilní symbol' : 'Payment reference', 'type' => 'text'],
            [
                'id'      => 'kind',
                'label'   => $cs ? 'Typ' : 'Type',
                'type'    => 'select',
                'options' => [
                    ['value' => CaseQuery::KIND_DEBT, 'label' => $this->kindLabel(CaseQuery::KIND_DEBT)],
                    ['value' => CaseQuery::KIND_OVERPAYMENT, 'label' => $this->kindLabel(CaseQuery::KIND_OVERPAYMENT)],
                    ['value' => CaseQuery::KIND_UNREQUESTED, 'label' => $this->kindLabel(CaseQuery::KIND_UNREQUESTED)],
                    ['value' => CaseQuery::KIND_CLOSED, 'label' => $this->kindLabel(CaseQuery::KIND_CLOSED)],
                ],
            ],
            ['id' => CaseTotals::FILTER_CURRENCY, 'label' => $cs ? 'Měna' : 'Currency', 'type' => 'select', 'options' => $this->currencyOptions()],
            ['id' => 'overdue', 'label' => $cs ? 'Po splatnosti' : 'Overdue', 'type' => 'checkbox'],
            ['id' => CaseTotals::FILTER_DUE_WITHIN, 'label' => $cs ? 'Splatné do (dní)' : 'Due within (days)', 'type' => 'text'],
            ['id' => 'include_closed', 'label' => $cs ? 'Včetně uzavřených' : 'Including closed', 'type' => 'checkbox'],
        ];
    }

    /**
     * Měny, které v ledgeru jsou (filtr `currency`, #120): poslední sloupec
     * `idx_case`, DISTINCT jde po indexu.
     *
     * @return list<array{value: string, label: string}>
     */
    private function currencyOptions(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT `currency` FROM `' . $this->table . '` WHERE `currency` IS NOT NULL ORDER BY `currency` LIMIT 50',
        );
        $options = [];
        foreach ($rows as $row) {
            $code = CaseQuery::normalizeCurrency($row['currency'] ?? null);
            if ($code !== null) {
                $options[] = ['value' => $code, 'label' => strtoupper($code)];
            }
        }
        return $options;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Doc-state konvence (design-system.md §4): dluh bez proužku, dluh po
     * splatnosti `cancelled` (červený „pozor"), přeplatek / úhrada bez
     * předpisu `concept` (žlutý „podívej se"), uzavřený `archive`.
     *
     * @param array<string, mixed> $case dekorovaný agregát ({@see CaseQuery::decorate})
     */
    private function stateStyleOf(array $case): ?string
    {
        return match ($case['kind']) {
            CaseQuery::KIND_DEBT        => $case['days_overdue'] > 0 ? 'cancelled' : null,
            CaseQuery::KIND_OVERPAYMENT,
            CaseQuery::KIND_UNREQUESTED => 'concept',
            default                     => 'archive',
        };
    }
}
