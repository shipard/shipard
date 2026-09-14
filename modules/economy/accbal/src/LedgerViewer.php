<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Viewer saldo pohybů (economy_accbal_ledger).
 *
 * Read-only derivát deníku (jako JournalViewer): žádné new/edit/delete,
 * žádné docState taby. Pohyb sám „zbývá" nenese — v symbolovém modelu
 * (#69 D1) má zůstatek jen **případ** (klíč skupina / období / partner /
 * VS / SS / měna), agregovaný z ledgeru přes {@see CaseQuery}. Každý pohyb
 * nese zůstatek svého případu (sloupec Zůstatek případu, stejná hodnota na
 * všech pohybech klíče); filtr „Jen otevřené" = otevřenost případu.
 *
 * ViewGroups (chip lišta nahoře) = saldokonta z economy_accbal_balances,
 * identita přes `code`. Filtry: období (výchozí aktuální fiskální rok,
 * AccbalViewerBase::periodFilter), partner, VS, SS, jen otevřené — akce
 * „Pohyby případu" z vieweru případů je předvyplní obdobím případu.
 *
 * Grid layout (výchozí, docs/viewer-grid.md §7.4): skupinové řádky per
 * partner (D6/D12 — řazení primárně dle partnera, sdílené i listem), uvnitř
 * partnera po klíči případu, aby pohyby jednoho případu byly pohromadě;
 * footer se součty Předpisy/Úhrady/Zůstatek v domácí měně (D7). Datum
 * pohybu přes LEFT JOIN na deník (journal_row → accounting_date).
 */
class LedgerViewer extends AccbalViewerBase
{
    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT l.`id`, l.`balance`, l.`bal_side`, l.`source_kind`, l.`doc_head`,'
            . ' l.`bank_transaction`, l.`journal_row`, l.`account_number`, l.`partner`,'
            . ' l.`payment_reference`, l.`specific_symbol`, l.`due_date`, l.`currency`,'
            . ' l.`amount`, l.`amount_hc`, l.`text`,'
            . ' b.`name` AS balance_name, b.`short_name` AS balance_short_name,'
            . ' p.`full_name` AS partner_name, j.`accounting_date`,'
            . ' ' . CaseQuery::residualSubquerySql('l') . ' AS case_residual'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`'
            . ' LEFT JOIN `economy_accounting_journal` j ON j.`id` = l.`journal_row`';

        [$conditions, $params] = $this->buildConditions($filters);

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        // Primární řazení dle partnera je tvrdý kontrakt skupin gridu (D12):
        // nesouvislá skupina = duplicitní group.key = pád renderu. Pohyby bez
        // partnera na konec (ISNULL), l.partner jistí shodná jména. Platí
        // i pro list — selectRows je sdílené (D1), list tím získává totéž
        // seskupení. Uvnitř partnera klíč případu (období, skupina, měna,
        // VS, SS), pak role a datum pohybu.
        $sql .= ' ORDER BY ISNULL(p.`full_name`) ASC, p.`full_name` ASC, l.`partner` ASC,'
            . ' l.`fiscal_year` ASC, l.`balance` ASC, l.`currency` ASC,'
            . ' l.`payment_reference` ASC, l.`specific_symbol` ASC,'
            . ' l.`bal_side` ASC, j.`accounting_date` ASC, l.`id` ASC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Skladba WHERE podmínek seznamu — sdílená mezi selectRows()
     * a renderGridFooter(), aby součty vždy odpovídaly filtrovanému setu.
     * `only_open` = zůstatek případu řádku ≠ 0
     * ({@see CaseQuery::residualSubquerySql}).
     *
     * @return array{0: list<string>, 1: list<mixed>} [conditions, params]
     */
    private function buildConditions(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            $value = $filter['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($id === 'viewGroup') {
                [$cond, $param] = $this->viewGroupCondition((string) $value);
                if ($cond !== null) {
                    $conditions[] = $cond;
                    $params[] = $param;
                }
            } elseif ($id === 'fiscal_year') {
                $conditions[] = 'l.`fiscal_year` = %i';
                $params[] = (int) $value;
            } elseif ($id === 'partner') {
                $conditions[] = 'p.`full_name` LIKE %s';
                $params[] = '%' . (string) $value . '%';
            } elseif ($id === 'payment_reference') {
                $conditions[] = 'l.`payment_reference` LIKE %s';
                $params[] = (string) $value . '%';
            } elseif ($id === 'specific_symbol') {
                $conditions[] = 'l.`specific_symbol` LIKE %s';
                $params[] = (string) $value . '%';
            } elseif ($id === 'only_open' && (string) $value === '1') {
                $conditions[] = CaseQuery::residualSubquerySql('l') . ' <> 0';
            }
        }

        return [$conditions, $params];
    }

    public function renderRow(array $rowData): array
    {
        $balSide = (int) ($rowData['bal_side'] ?? 0);
        $account = (string) ($rowData['account_number'] ?? '');
        $balanceName = trim((string) ($rowData['balance_name'] ?? ''));

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $balanceName !== '' ? $balanceName : $account,
            'i1'         => $account,
            'stateStyle' => $balSide === 0 ? 'primary' : 'done',
        ];

        $t2 = [];
        $date = $this->formatDate($rowData['accounting_date'] ?? null);
        if ($date !== null) {
            $t2[] = ['text' => $date];
        }
        $t2[] = [
            'text'  => $balSide === 0 ? ($this->language === 'cs' ? 'Předpis' : 'Request')
                                      : ($this->language === 'cs' ? 'Úhrada' : 'Payment'),
            'class' => 'muted',
        ];
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        if ($partnerName !== '') {
            $t2[] = ['text' => $partnerName, 'class' => 'muted'];
        }
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
            $t2[] = ['text' => ($this->language === 'cs' ? 'splatnost ' : 'due ') . $due, 'class' => 'muted'];
        }
        $row['t2'] = $t2;

        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
        $i2 = [['text' => $this->formatMoney($rowData['amount'] ?? 0) . ' ' . $curCode, 'class' => 'amount']];
        $residual = (float) ($rowData['case_residual'] ?? 0);
        if (abs($residual) > 0.0001) {
            $i2[] = [
                'text'  => ($this->language === 'cs' ? 'případ ' : 'case ') . $this->formatMoney($residual) . ' ' . $curCode,
                'class' => 'muted',
            ];
        }
        $row['i2'] = $i2;

        return $row;
    }

    // ── Grid layout (docs/viewer-grid.md §7.4 — skupiny per partner) ────────

    /**
     * Bez `sortable` sloupců (záměr): skupiny per partner vyžadují primární
     * řazení dle partnera (D12) — sort klikem by clustering rozbil
     * (buildSortedOrderBy neumí prefixovat skupinový klíč). Partner není
     * sloupec — nese ho hlavička skupiny.
     */
    public function getGridColumns(): ?array
    {
        $cs = $this->language === 'cs';

        return [
            ['id' => 'accounting_date', 'label' => $cs ? 'Datum' : 'Date', 'width' => 96],
            ['id' => 'role', 'label' => 'Role', 'width' => 90],
            ['id' => 'payment_reference', 'label' => $cs ? 'VS' : 'Reference', 'width' => 110],
            ['id' => 'specific_symbol', 'label' => $cs ? 'SS' : 'Spec. symbol', 'width' => 90],
            ['id' => 'due_date', 'label' => $cs ? 'Splatnost' : 'Due date', 'width' => 96],
            ['id' => 'amount', 'label' => $cs ? 'Částka' : 'Amount', 'width' => 130, 'align' => 'right'],
            ['id' => 'case_residual', 'label' => $cs ? 'Zůstatek případu' : 'Case balance', 'width' => 130, 'align' => 'right'],
            ['id' => 'text', 'label' => 'Text', 'grow' => true],
            // Se zvoleným chipem redundantní, na „Vše" užitečné.
            ['id' => 'balance', 'label' => $cs ? 'Saldokonto' : 'Balance', 'width' => 140],
        ];
    }

    public function renderGridRow(array $rowData): array
    {
        $cs = $this->language === 'cs';
        $balSide = (int) ($rowData['bal_side'] ?? 0);
        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));

        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        $residual = (float) ($rowData['case_residual'] ?? 0);
        $balanceShort = trim((string) ($rowData['balance_short_name'] ?? ''));

        return [
            'id'         => (int) $rowData['id'],
            'stateStyle' => $balSide === 0 ? 'primary' : 'done',
            // Skupinová hlavička per partner — klíč z FK (stabilní i při
            // shodných jménech), pohyby bez partnera sdílí skupinu 'p0'.
            'group' => [
                'key'   => 'p' . (int) ($rowData['partner'] ?? 0),
                'label' => $partnerName !== '' ? $partnerName : ($cs ? '(Bez partnera)' : '(No partner)'),
            ],
            'cells' => [
                'accounting_date' => $this->formatDate($rowData['accounting_date'] ?? null),
                'role' => $balSide === 0
                    ? ['text' => $cs ? 'Předpis' : 'Request', 'badge' => 'primary']
                    : ['text' => $cs ? 'Úhrada' : 'Payment', 'badge' => 'success'],
                'payment_reference' => (string) ($rowData['payment_reference'] ?? ''),
                'specific_symbol'   => (string) ($rowData['specific_symbol'] ?? ''),
                'due_date' => $this->formatDate($rowData['due_date'] ?? null),
                'amount' => [
                    ['text' => $this->formatMoney($rowData['amount'] ?? 0), 'class' => 'amount'],
                    ['text' => $curCode, 'class' => 'muted'],
                ],
                // Zůstatek případu — stejná hodnota na všech pohybech klíče;
                // uzavřený případ (0) nechává buňku prázdnou.
                'case_residual' => abs($residual) > 0.0001
                    ? ['text' => $this->formatMoney($residual), 'class' => 'amount']
                    : null,
                'text'    => (string) ($rowData['text'] ?? ''),
                'balance' => $balanceShort !== '' ? $balanceShort : trim((string) ($rowData['balance_name'] ?? '')),
            ],
        ];
    }

    /**
     * Součty přes CELÝ filtrovaný set (D7) v domácí měně — vždy amount_hc,
     * zůstatek = Σ předpisů − Σ úhrad. WHERE skladba sdílená se selectRows()
     * (buildConditions) vč. filtru „Jen otevřené".
     */
    public function renderGridFooter(?string $search, array $filters): ?array
    {
        $cs = $this->language === 'cs';

        [$conditions, $params] = $this->buildConditions($filters);

        $sql = 'SELECT'
            . ' SUM(CASE WHEN l.`bal_side` = 0 THEN l.`amount_hc` ELSE 0 END) AS sum_requests,'
            . ' SUM(CASE WHEN l.`bal_side` = 1 THEN l.`amount_hc` ELSE 0 END) AS sum_payments,'
            . ' MAX(l.`home_currency`) AS home_currency'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $r = $this->db->fetchRow($sql, ...$params);

        $requests = (float) ($r['sum_requests'] ?? 0);
        $payments = (float) ($r['sum_payments'] ?? 0);
        // Domácí měna je přes filtrovaný set jednotná (měna DS) — MAX ji jen
        // vytáhne. Kód uvádíme, ať je zřejmé, že jde o HC (sloupec Částka je
        // v měně dokladu); prázdný set → bez kódu.
        $hc = strtoupper((string) ($r['home_currency'] ?? ''));
        $hc = $hc !== '' ? ' ' . $hc : '';

        return [
            'case_residual' => [
                ['text' => $cs ? 'Zůstatek' : 'Balance', 'class' => 'muted'],
                ['text' => $this->formatMoney($requests - $payments) . $hc, 'class' => 'amount'],
            ],
            'text' => [
                ['text' => $cs ? 'Předpisy' : 'Requests', 'class' => 'muted'],
                ['text' => $this->formatMoney($requests) . $hc],
                ['text' => $cs ? 'Úhrady' : 'Payments', 'class' => 'muted'],
                ['text' => $this->formatMoney($payments) . $hc],
            ],
        ];
    }

    public function renderDetail(int $recordId): array
    {
        $r = $this->db->fetchRow(
            'SELECT l.*, b.`name` AS balance_name, p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`'
            . ' WHERE l.`id` = %i',
            $recordId,
        );
        if ($r === null) {
            return ['tabs' => []];
        }
        // Případ pohybu (#69 D1): agregát klíče, do kterého pohyb patří.
        $case = (new CaseQuery($this->db->getDibiConnection()))->caseOf(CaseQuery::normalizeKey($r))
            ?? CaseQuery::decorate([]);

        $cs = $this->language === 'cs';

        $moveItems = [];
        $this->addItem($moveItems, $cs ? 'Saldokonto' : 'Balance', $r['balance_name'] ?? null);
        $this->addItem(
            $moveItems,
            $cs ? 'Role' : 'Role',
            (int) ($r['bal_side'] ?? 0) === 0 ? ($cs ? 'Předpis' : 'Request') : ($cs ? 'Úhrada' : 'Payment'),
        );
        $this->addItem($moveItems, $cs ? 'Účet' : 'Account', $r['account_number'] ?? null);
        $this->addItem($moveItems, 'Partner', $r['partner_name'] ?? null);
        $this->addItem($moveItems, 'Text', $r['text'] ?? null);

        $curCode = strtoupper((string) ($r['currency'] ?? ''));
        $hcCode = strtoupper((string) ($r['home_currency'] ?? ''));
        $amountItems = [];
        $this->addItem($amountItems, ($cs ? 'Částka ' : 'Amount ') . $curCode, $this->formatMoney($r['amount'] ?? 0));
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($amountItems, ($cs ? 'Částka ' : 'Amount ') . $hcCode, $this->formatMoney($r['amount_hc'] ?? 0));
        }

        $payItems = [];
        $this->addItem($payItems, $cs ? 'Variabilní symbol' : 'Payment reference', $r['payment_reference'] ?? null);
        $this->addItem($payItems, $cs ? 'Specifický symbol' : 'Specific symbol', $r['specific_symbol'] ?? null);
        $this->addItem($payItems, $cs ? 'Konstantní symbol' : 'Constant symbol', $r['constant_symbol'] ?? null);
        $this->addItem($payItems, $cs ? 'Splatnost' : 'Due date', $this->formatDate($r['due_date'] ?? null));

        $caseItems = [];
        $this->addItem($caseItems, $cs ? 'Předpisy' : 'Requests', $this->formatMoney($case['sum_requests']) . ' ' . $curCode);
        $this->addItem($caseItems, $cs ? 'Úhrady' : 'Payments', $this->formatMoney($case['sum_payments']) . ' ' . $curCode);
        $this->addItem($caseItems, $cs ? 'Zůstatek' : 'Balance', $this->formatMoney($case['residual']) . ' ' . $curCode);
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($caseItems, ($cs ? 'Zůstatek ' : 'Balance ') . $hcCode, $this->formatMoney($case['residual_hc']));
        }
        $this->addItem($caseItems, $cs ? 'Stav' : 'State', $this->kindLabel($case['kind']));
        $this->addItem($caseItems, $cs ? 'Pohybů' : 'Movements', $case['moves']);

        $groups = [
            ['title' => $cs ? 'Pohyb' : 'Movement', 'items' => $moveItems],
            ['title' => $cs ? 'Částky' : 'Amounts', 'items' => $amountItems],
        ];
        if ($payItems !== []) {
            $groups[] = ['title' => $cs ? 'Platba' : 'Payment', 'items' => $payItems];
        }
        $groups[] = ['title' => $cs ? 'Případ' : 'Case', 'items' => $caseItems];

        $detail = ['tabs' => [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => ['type' => 'properties', 'groups' => $groups],
        ]]];

        $actions = [];
        $docHead = (int) ($r['doc_head'] ?? 0);
        $bankTx = (int) ($r['bank_transaction'] ?? 0);
        if ($docHead > 0) {
            $actions[] = [
                'id' => 'open_doc', 'label' => $cs ? 'Otevřít doklad' : 'Open document',
                'kind' => 'open_viewer', 'viewerId' => 'docs.core.heads', 'recordId' => $docHead,
                'variant' => 'secondary',
            ];
        }
        if ($bankTx > 0) {
            $actions[] = [
                'id' => 'open_tx', 'label' => $cs ? 'Otevřít transakci' : 'Open transaction',
                'kind' => 'open_viewer', 'viewerId' => 'economy.bank.transactions', 'recordId' => $bankTx,
                'variant' => 'secondary',
            ];
        }
        $journalRow = (int) ($r['journal_row'] ?? 0);
        if ($journalRow > 0) {
            // Deník startuje s výchozím aktuálním rokem — období pohybu
            // posíláme, aby cílový řádek ze staršího roku nezmizel ze seznamu.
            $action = [
                'id' => 'open_journal', 'label' => $cs ? 'Otevřít řádek deníku' : 'Open journal row',
                'kind' => 'open_viewer', 'viewerId' => 'economy.accounting.journal', 'recordId' => $journalRow,
                'variant' => 'secondary',
            ];
            if (($r['fiscal_year'] ?? null) !== null) {
                $action['filters'] = ['fiscal_year' => (string) (int) $r['fiscal_year']];
            }
            $actions[] = $action;
        }
        if ($actions !== []) {
            $detail['actions'] = $actions;
        }

        return $detail;
    }

    public function getFilters(): array
    {
        $cs = $this->language === 'cs';

        return [
            $this->periodFilter(),
            ['id' => 'partner', 'label' => 'Partner', 'type' => 'text'],
            ['id' => 'payment_reference', 'label' => $cs ? 'Variabilní symbol' : 'Payment reference', 'type' => 'text'],
            ['id' => 'specific_symbol', 'label' => $cs ? 'Specifický symbol' : 'Specific symbol', 'type' => 'text'],
            ['id' => 'only_open', 'label' => $cs ? 'Jen otevřené případy' : 'Open cases only', 'type' => 'checkbox'],
        ];
    }
}
