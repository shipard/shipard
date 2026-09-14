<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Viewer saldokonta **po případech** (#69 D1, T2) — výchozí vstup do
 * saldokonta: kdo kolik dluží, co je otevřené. Jeden řádek = případ, tj.
 * agregát klíče (saldokonto, období, partner, VS, SS, měna) z ledgeru
 * ({@see CaseQuery}); žádná entita, žádný sloupec — `GROUP BY` klíče nad
 * `economy_accbal_ledger`, id řádku = `row_id` (MIN(id) pohybů klíče), ze
 * kterého detail klíč odvodí.
 *
 * Filtry ve dvou úrovních: klíčové (saldokonto = chip, partner, VS, SS)
 * jdou do vnitřního dotazu před GROUP BY; případové (otevřenost, typ,
 * po splatnosti) nad agregátem. „Jen otevřené" je výchozí — frontend nemá
 * výchozí hodnoty filtrů, proto je checkbox obrácený: **Včetně
 * uzavřených**. Typ otevřenosti (dluh / přeplatek / úhrada bez předpisu)
 * je samostatně filtrovatelný — na reimportovaném DS jsou tisíce úhrad bez
 * předpisu z pokladních a interních dokladů a nesmí se schovat mezi
 * přeplatky.
 *
 * Grid (docs/viewer-grid.md §5, D12): skupiny per partner, skupinový
 * řádek nese součet zůstatku partnera v domácí měně přes celý filtrovaný
 * set (okno `SUM() OVER (PARTITION BY partner)` — počítá se až po WHERE,
 * sedí i přes hranici stránek); footer Σ předpisy / úhrady / zůstatek
 * v domácí měně. Zvýraznění typu doc-state konvencí (design-system.md §4,
 * žádná nová barva): dluh bez proužku, dluh po splatnosti `cancelled`
 * (červený „pozor"), přeplatek a úhrada bez předpisu `concept` (žlutý
 * „podívej se"), uzavřený `archive`.
 *
 * Akce detailu „Pohyby případu" otevře {@see LedgerViewer} s chipem
 * saldokonta a předvyplněnými filtry partner / VS / SS (viditelné,
 * uživatel je může uvolnit).
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

    /**
     * Agregát případů: GROUP BY klíče nad pohyby, které prošly filtry
     * klíčové úrovně (saldokonto, partner, VS, SS) — sdílený seznamem
     * i footerem.
     *
     * @param list<string> $rowConds
     */
    private function casesSql(array $rowConds): string
    {
        $sql = 'SELECT ' . CaseQuery::keyColumnsSql('l') . ', ' . CaseQuery::aggregateColumnsSql('l')
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`';
        if ($rowConds !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $rowConds);
        }
        return $sql . ' GROUP BY ' . CaseQuery::keyColumnsSql('l');
    }

    /**
     * Filtry ve dvou úrovních: klíčové → vnitřní dotaz (před GROUP BY),
     * případové → nad agregátem (alias `c`). Bez „Včetně uzavřených" se
     * přidá otevřenost případu; typ Uzavřeno ji implicitně vypne.
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<string>, 3: list<mixed>}
     *         [rowConds, rowParams, caseConds, caseParams]
     */
    private function buildConditions(array $filters): array
    {
        $rowConds = [];
        $rowParams = [];
        $caseConds = [];
        $caseParams = [];
        $includeClosed = false;

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $value = (string) $value;
            if ($id === 'viewGroup') {
                [$cond, $param] = $this->viewGroupCondition($value);
                if ($cond !== null) {
                    $rowConds[] = $cond;
                    $rowParams[] = $param;
                }
            } elseif ($id === 'partner') {
                $rowConds[] = 'p.`full_name` LIKE %s';
                $rowParams[] = '%' . $value . '%';
            } elseif ($id === 'payment_reference') {
                $rowConds[] = 'l.`payment_reference` LIKE %s';
                $rowParams[] = $value . '%';
            } elseif ($id === 'specific_symbol') {
                $rowConds[] = 'l.`specific_symbol` LIKE %s';
                $rowParams[] = $value . '%';
            } elseif ($id === 'include_closed' && $value === '1') {
                $includeClosed = true;
            } elseif ($id === 'kind' && in_array($value, CaseQuery::KINDS, true)) {
                $caseConds[] = CaseQuery::kindConditionSql($value, 'c');
                if ($value === CaseQuery::KIND_CLOSED) {
                    $includeClosed = true;
                }
            } elseif ($id === 'overdue' && $value === '1') {
                // Po splatnosti = dluh s nejstarším předpisem před dneškem.
                $caseConds[] = 'c.[residual] > 0 AND c.[due_date] < %s';
                $caseParams[] = $this->today()->format('Y-m-d');
            }
        }

        if (!$includeClosed) {
            array_unshift($caseConds, CaseQuery::openConditionSql('c'));
        }

        return [$rowConds, $rowParams, $caseConds, $caseParams];
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
     * Součty přes CELÝ filtrovaný set v domácí měně — stejné podmínky obou
     * úrovní jako selectRows() (řádky přes všechny stránky).
     */
    public function renderGridFooter(?string $search, array $filters): ?array
    {
        $cs = $this->language === 'cs';
        [$rowConds, $rowParams, $caseConds, $caseParams] = $this->buildConditions($filters);

        $sql = 'SELECT SUM(c.`sum_requests_hc`) AS sum_requests, SUM(c.`sum_payments_hc`) AS sum_payments,'
            . ' SUM(c.`residual_hc`) AS residual, MAX(c.`home_currency`) AS home_currency'
            . ' FROM (' . $this->casesSql($rowConds) . ') c';
        if ($caseConds !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $caseConds);
        }

        $r = $this->db->fetchRow($sql, ...$rowParams, ...$caseParams);

        $hc = strtoupper((string) ($r['home_currency'] ?? ''));
        $hc = $hc !== '' ? ' ' . $hc : '';

        return [
            'sum_requests' => [
                ['text' => $cs ? 'Předpisy' : 'Requests', 'class' => 'muted'],
                ['text' => $this->formatMoney($r['sum_requests'] ?? 0) . $hc],
            ],
            'sum_payments' => [
                ['text' => $cs ? 'Úhrady' : 'Payments', 'class' => 'muted'],
                ['text' => $this->formatMoney($r['sum_payments'] ?? 0) . $hc],
            ],
            'residual_hc' => [
                ['text' => $cs ? 'Zůstatek' : 'Residual', 'class' => 'muted'],
                ['text' => $this->formatMoney($r['residual'] ?? 0) . $hc, 'class' => 'amount'],
            ],
        ];
    }

    /**
     * Detail případu: id řádku je pohyb klíče (row_id) → klíč → agregát.
     * Akce „Pohyby případu" otevře viewer pohybů s chipem saldokonta a
     * viditelnými filtry partner / VS / SS.
     */
    public function renderDetail(int $recordId): array
    {
        $query = new CaseQuery($this->db->getDibiConnection());
        $key = $query->keyOfRow($recordId);
        $case = $key !== null ? $query->caseOf($key) : null;
        if ($key === null || $case === null) {
            return ['tabs' => []];
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

        $cs = $this->language === 'cs';
        $curCode = strtoupper((string) ($key['currency'] ?? ''));
        $hcCode = strtoupper((string) ($case['home_currency'] ?? ''));
        $partnerName = trim((string) ($names['partner_name'] ?? ''));

        $keyItems = [];
        $this->addItem($keyItems, $cs ? 'Saldokonto' : 'Balance', $names['balance_name'] ?? null);
        $this->addItem($keyItems, $cs ? 'Období' : 'Period', $names['fiscal_year_name'] ?? null);
        $this->addItem($keyItems, 'Partner', $partnerName !== '' ? $partnerName : ($cs ? '(Bez partnera)' : '(No partner)'));
        $this->addItem($keyItems, $cs ? 'Variabilní symbol' : 'Payment reference', $key['payment_reference'] ?? null);
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

        $filters = [];
        if ($partnerName !== '') {
            $filters['partner'] = $partnerName;
        }
        if (($key['payment_reference'] ?? null) !== null) {
            $filters['payment_reference'] = (string) $key['payment_reference'];
        }
        if (($key['specific_symbol'] ?? null) !== null) {
            $filters['specific_symbol'] = (string) $key['specific_symbol'];
        }

        return [
            'tabs' => [[
                'id'      => 'overview',
                'label'   => $this->defaultOverviewLabel(),
                'content' => ['type' => 'properties', 'groups' => [
                    ['title' => $cs ? 'Případ' : 'Case', 'items' => $keyItems],
                    ['title' => $cs ? 'Částky' : 'Amounts', 'items' => $amountItems],
                ]],
            ]],
            'actions' => [[
                'id'        => 'open_ledger',
                'label'     => $cs ? 'Pohyby případu' : 'Case movements',
                'kind'      => 'open_viewer',
                'viewerId'  => 'economy.accbal.ledger',
                'viewGroup' => (string) ($names['balance_code'] ?? 'all'),
                'filters'   => $filters,
                'variant'   => 'primary',
            ]],
        ];
    }

    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        return [
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
            ['id' => 'overdue', 'label' => $cs ? 'Po splatnosti' : 'Overdue', 'type' => 'checkbox'],
            ['id' => 'include_closed', 'label' => $cs ? 'Včetně uzavřených' : 'Including closed', 'type' => 'checkbox'],
        ];
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

    /** Dnešek pro dny po splatnosti — nic se neukládá, počítá se při renderu. */
    protected function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }
}
