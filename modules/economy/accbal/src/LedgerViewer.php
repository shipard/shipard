<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\SearchCondition;
use Shipard\Core\Viewer\Detail\DetailHeader;

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
 * pohybu a operace přes LEFT JOIN na deník (journal_row → accounting_date,
 * operation). Druh pohybu: Předpis / Úhrada z `bal_side`; pohyb z bankovní
 * transakce jako platba podle směru (#69 D23, {@see roleOf}).
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
            . ' p.`full_name` AS partner_name, j.`accounting_date`, j.`operation`,'
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
                $conditions[] = SearchCondition::contains('p.`full_name`');
                $params[] = (string) $value;
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

    /**
     * Popis druhu pohybu (#69 D23). Pohyb z bankovní transakce je platba a
     * popisuje se směrem podle operace řádku deníku (Příjem / Výdaj), ne
     * předpisem/úhradou — bankovní záloha na 324 je datově předpis, vratka
     * přeplatku na 311 MD také, uživateli to ale říká jen „platba". Ostatní
     * zdroje Předpis / Úhrada z `bal_side`. Barva badge jde vždy z `bal_side`,
     * aby bylo vidět, do kterého součtu patky pohyb padá.
     *
     * @param array<string, mixed> $r řádek s bal_side, source_kind, operation
     * @return array{text: string, badge: string}
     */
    private function roleOf(array $r): array
    {
        $balSide = (int) ($r['bal_side'] ?? 0);
        if (($r['source_kind'] ?? null) === 'bankTransaction') {
            $text = match ((string) ($r['operation'] ?? '')) {
                'payment.in'  => $this->text('roleIncoming', 'Incoming payment'),
                'payment.out' => $this->text('roleOutgoing', 'Outgoing payment'),
                default       => $this->text('roleBankPayment', 'Bank payment'),
            };
        } else {
            // Předpis / Úhrada — hodnoty výčtu economy.accbal.balSides.
            $sides = $this->config?->cfgItem('economy.accbal.balSides');
            $text = $balSide === 0
                ? (string) ($sides['0']['name'] ?? 'Request')
                : (string) ($sides['1']['name'] ?? 'Payment');
        }
        return ['text' => $text, 'badge' => $balSide === 0 ? 'primary' : 'success'];
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
        $t2[] = ['text' => $this->roleOf($rowData)['text'], 'class' => 'muted'];
        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        if ($partnerName !== '') {
            $t2[] = ['text' => $partnerName, 'class' => 'muted'];
        }
        $vs = trim((string) ($rowData['payment_reference'] ?? ''));
        if ($vs !== '') {
            $t2[] = ['text' => $this->text('rowPaymentReference', 'Ref. {value}', ['value' => $vs]), 'class' => 'muted'];
        }
        $ss = trim((string) ($rowData['specific_symbol'] ?? ''));
        if ($ss !== '') {
            $t2[] = ['text' => $this->text('rowSpecificSymbol', 'Spec. symbol {value}', ['value' => $ss]), 'class' => 'muted'];
        }
        $due = $this->formatDate($rowData['due_date'] ?? null);
        if ($due !== null) {
            $t2[] = ['text' => $this->text('rowDue', 'due {date}', ['date' => $due]), 'class' => 'muted'];
        }
        $row['t2'] = $t2;

        $curCode = strtoupper((string) ($rowData['currency'] ?? ''));
        $i2 = [['text' => $this->formatMoney($rowData['amount'] ?? 0) . ' ' . $curCode, 'class' => 'amount']];
        $residual = (float) ($rowData['case_residual'] ?? 0);
        if (abs($residual) > 0.0001) {
            $i2[] = [
                'text'  => $this->text('rowCaseResidual', 'case {amount}', ['amount' => $this->formatMoney($residual) . ' ' . $curCode]),
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
        return [
            ['id' => 'accounting_date', 'label' => $this->sharedText('date', 'Date'), 'width' => 96],
            ['id' => 'role', 'label' => $this->text('role', 'Role'), 'width' => 90],
            ['id' => 'payment_reference', 'label' => $this->sharedText('paymentReferenceShort', 'Reference'), 'width' => 110],
            ['id' => 'specific_symbol', 'label' => $this->text('specificSymbolShort', 'Spec. symbol'), 'width' => 90],
            ['id' => 'due_date', 'label' => $this->text('dueDate', 'Due date'), 'width' => 96],
            ['id' => 'amount', 'label' => $this->text('amount', 'Amount'), 'width' => 130, 'align' => 'right'],
            ['id' => 'case_residual', 'label' => $this->text('caseBalance', 'Case balance'), 'width' => 130, 'align' => 'right'],
            ['id' => 'text', 'label' => $this->columnLabel('text', 'Text'), 'grow' => true],
            // Se zvoleným chipem redundantní, na „Vše" užitečné.
            ['id' => 'balance', 'label' => $this->columnLabel('balance', 'Balance'), 'width' => 140],
        ];
    }

    public function renderGridRow(array $rowData): array
    {
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
                'label' => $partnerName !== '' ? $partnerName : $this->text('noPartnerPlaceholder', '(No partner)'),
            ],
            'cells' => [
                'accounting_date' => $this->formatDate($rowData['accounting_date'] ?? null),
                'role' => $this->roleOf($rowData),
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
                ['text' => $this->text('residualBalance', 'Balance'), 'class' => 'muted'],
                ['text' => $this->formatMoney($requests - $payments) . $hc, 'class' => 'amount'],
            ],
            'text' => [
                ['text' => $this->text('requests', 'Requests'), 'class' => 'muted'],
                ['text' => $this->formatMoney($requests) . $hc],
                ['text' => $this->text('payments', 'Payments'), 'class' => 'muted'],
                ['text' => $this->formatMoney($payments) . $hc],
            ],
        ];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->db->fetchRow(
            'SELECT l.*, b.`name` AS balance_name, p.`full_name` AS partner_name, j.`operation`'
            . ' FROM `' . $this->table . '` l'
            . ' LEFT JOIN `economy_accbal_balances` b ON b.`id` = l.`balance`'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = l.`partner`'
            . ' LEFT JOIN `economy_accounting_journal` j ON j.`id` = l.`journal_row`'
            . ' WHERE l.`id` = %i',
            $recordId,
        );
    }

    /** Titulek partner, bez partnera text pohybu, pak účet; podtitul účet · částka s měnou (#136 §6). */
    protected function detailHeader(array $record): DetailHeader
    {
        $account = trim((string) ($record['account_number'] ?? ''));
        $amount = trim($this->formatMoney($record['amount'] ?? 0) . ' ' . strtoupper((string) ($record['currency'] ?? '')));
        $subtitle = implode(' · ', array_filter([$account, $amount], static fn (string $v): bool => $v !== ''));

        return new DetailHeader($this->titleOf($record)['value'], $subtitle !== '' ? $subtitle : null);
    }

    /**
     * Titulek pohybu: partner, bez něj text, bez obojího číslo účtu, jinak
     * `#id`. Tutéž volbu čte Přehled — údaj, který je titulkem, neopakuje
     * (#140 §2).
     *
     * @param array<string, mixed> $record
     * @return array{column: string, value: string}
     */
    private function titleOf(array $record): array
    {
        foreach (['partner_name', 'text', 'account_number'] as $column) {
            $value = trim((string) ($record[$column] ?? ''));
            if ($value !== '') {
                return ['column' => $column, 'value' => $value];
            }
        }
        return ['column' => 'id', 'value' => '#' . (int) ($record['id'] ?? 0)];
    }

    /** Přehled: pohyb, částky, platba a případ pohybu (#69 D1: agregát klíče, do kterého pohyb patří). */
    protected function detailTabs(array $record): array
    {
        $case = (new CaseQuery($this->db->getDibiConnection()))->caseOf(CaseQuery::normalizeKey($record))
            ?? CaseQuery::decorate([]);

        // Partner a účet nese hlavička; text jen když není titulkem (#140 D26, §2).
        $moveItems = [];
        $this->addItem($moveItems, $this->columnLabel('balance', 'Balance'), $record['balance_name'] ?? null);
        $this->addItem($moveItems, $this->text('role', 'Role'), $this->roleOf($record)['text']);
        if ($this->titleOf($record)['column'] !== 'text') {
            $this->addItem($moveItems, $this->columnLabel('text', 'Text'), $record['text'] ?? null);
        }

        // Částku v měně pohybu nese podtitul; zůstává jen přepočet v domácí měně.
        $curCode = strtoupper((string) ($record['currency'] ?? ''));
        $hcCode = strtoupper((string) ($record['home_currency'] ?? ''));
        $amountItems = [];
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($amountItems, $this->text('amountIn', 'Amount {currency}', ['currency' => $hcCode]), $this->formatMoney($record['amount_hc'] ?? 0));
        }

        $payItems = [];
        $this->addItem($payItems, $this->columnLabel('payment_reference', 'Payment reference'), $record['payment_reference'] ?? null);
        $this->addItem($payItems, $this->columnLabel('specific_symbol', 'Specific symbol'), $record['specific_symbol'] ?? null);
        $this->addItem($payItems, $this->columnLabel('constant_symbol', 'Constant symbol'), $record['constant_symbol'] ?? null);
        $this->addItem($payItems, $this->text('dueDate', 'Due date'), $this->formatDate($record['due_date'] ?? null));

        $caseItems = [];
        $this->addItem($caseItems, $this->text('requests', 'Requests'), $this->formatMoney($case['sum_requests']) . ' ' . $curCode);
        $this->addItem($caseItems, $this->text('payments', 'Payments'), $this->formatMoney($case['sum_payments']) . ' ' . $curCode);
        $this->addItem($caseItems, $this->text('residualBalance', 'Balance'), $this->formatMoney($case['residual']) . ' ' . $curCode);
        if ($hcCode !== '' && $hcCode !== $curCode) {
            $this->addItem($caseItems, $this->text('residualBalanceIn', 'Balance {currency}', ['currency' => $hcCode]), $this->formatMoney($case['residual_hc']));
        }
        $this->addItem($caseItems, $this->sharedText('state', 'State'), $this->kindLabel($case['kind']));
        $this->addItem($caseItems, $this->text('movementsCount', 'Movements'), $case['moves']);

        $groups = [
            ['title' => $this->text('movement', 'Movement'), 'items' => $moveItems],
            ['title' => $this->sharedText('groupAmounts', 'Amounts'), 'items' => $amountItems],
        ];
        if ($payItems !== []) {
            $groups[] = ['title' => $this->text('groupPayment', 'Payment'), 'items' => $payItems];
        }
        $groups[] = ['title' => $this->text('case', 'Case'), 'items' => $caseItems];

        return [$this->propertiesTab($groups)];
    }

    /** Odkazy na doklad, bankovní transakci a řádek deníku pohybu. */
    protected function detailActions(array $record): array
    {
        $actions = [];
        $docHead = (int) ($record['doc_head'] ?? 0);
        $bankTx = (int) ($record['bank_transaction'] ?? 0);
        if ($docHead > 0) {
            $actions[] = [
                'id' => 'open_doc', 'label' => $this->text('openDocument', 'Open document'),
                'kind' => 'open_viewer', 'viewerId' => 'docs.core.heads', 'recordId' => $docHead,
                'variant' => 'secondary',
            ];
        }
        if ($bankTx > 0) {
            $actions[] = [
                'id' => 'open_tx', 'label' => $this->text('openTransaction', 'Open transaction'),
                'kind' => 'open_viewer', 'viewerId' => 'economy.bank.transactions', 'recordId' => $bankTx,
                'variant' => 'secondary',
            ];
        }
        $journalRow = (int) ($record['journal_row'] ?? 0);
        if ($journalRow > 0) {
            // Deník startuje s výchozím aktuálním rokem — období pohybu
            // posíláme, aby cílový řádek ze staršího roku nezmizel ze seznamu.
            $action = [
                'id' => 'open_journal', 'label' => $this->text('openJournalRow', 'Open journal row'),
                'kind' => 'open_viewer', 'viewerId' => 'economy.accounting.journal', 'recordId' => $journalRow,
                'variant' => 'secondary',
            ];
            if (($record['fiscal_year'] ?? null) !== null) {
                $action['filters'] = ['fiscal_year' => (string) (int) $record['fiscal_year']];
            }
            $actions[] = $action;
        }
        return $actions;
    }

    public function getFilters(): array
    {
        return [
            $this->periodFilter(),
            ['id' => 'partner', 'label' => $this->columnLabel('partner', 'Partner'), 'type' => 'text'],
            ['id' => 'payment_reference', 'label' => $this->columnLabel('payment_reference', 'Payment reference'), 'type' => 'text'],
            ['id' => 'specific_symbol', 'label' => $this->columnLabel('specific_symbol', 'Specific symbol'), 'type' => 'text'],
            ['id' => 'only_open', 'label' => $this->text('openCasesOnly', 'Open cases only'), 'type' => 'checkbox'],
        ];
    }
}
