<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Viewer\TableViewer;

/**
 * Generic viewer for all documents in docs_core_heads (Phase 3).
 *
 * Phase 6 will add per-type viewers (issued / received invoices) with a
 * bottom tab bar of number series; this generic viewer remains as the
 * "all documents" cross-type entry point.
 */
class DocsHeadsViewer extends TableViewer
{
    protected ?string $docStatesCfgItem = 'docs.core.docStates';

    /** tableId tabulky docs_core_heads — pro vlastní přílohy dokladu. */
    private const TABLE_ID_HEADS = 401;

    /**
     * docStates cfgItem položek (economy_items) — musí ladit
     * s ItemsViewer::$docStatesCfgItem. Pro badge stavu položky u řádků
     * detailu dokladu (itemStateBadge).
     */
    private const ITEMS_DOC_STATES_CFG_ITEM = 'core.system.docStatesArchive';

    private ?PersonSnapshotBuilder $personSnapshotBuilder = null;
    private ?OwnCompanyResolver $ownCompanyResolver = null;

    private const STATE_SPAN_CLASS = [
        'concept'   => 'warning',
        'confirmed' => 'primary',
        'edit'      => 'warning',
        'done'      => 'success',
        'cancelled' => 'danger',
        'archive'   => 'muted',
        'trash'     => 'muted',
    ];

    /**
     * When set, this viewer is scoped to a single doc_type (e.g. 'invni' for
     * received invoices). Drives the implicit doc_type filter in selectRows(),
     * the bottom number-series tab list (getNumberSeries), and the default
     * doc_type for newly created records (getNewRecordDefaults).
     *
     * Generic viewers (cross-type "all documents") leave this null.
     */
    protected ?string $scopedDocType = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        $sql = 'SELECT h.`id`, h.`doc_type`, h.`doc_number`, h.`doc_text`,'
            . ' h.`docState`, h.`docStateMain`,'
            . ' h.`issue_date`, h.`due_date`,'
            . ' h.`total_amount`, h.`doc_currency`,'
            . ' h.`cash_dir`, h.`payment_method`,'
            . ' p.`full_name` AS partner_name'
            . ' FROM `' . $this->table . '` h'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = h.`partner`';

        $conditions = [];
        $params = [];

        $viewGroup = 'active';
        $docTypeFilter = $this->scopedDocType;
        $numberSeriesFilter = null;
        foreach ($filters as $filter) {
            $id = $filter['id'] ?? null;
            if ($id === 'viewGroup') {
                $viewGroup = (string) $filter['value'];
            } elseif ($id === '_doc_type') {
                // Explicit override (e.g. a cross-type viewer pinning a type manually).
                $docTypeFilter = (string) $filter['value'];
            } elseif ($id === 'number_series') {
                $numberSeriesFilter = (int) $filter['value'];
            }
        }

        if ($viewGroup !== 'all') {
            [$vgSql, $vgParams] = $this->buildViewGroupFilter($this->docStatesCfgItem ?? '', $viewGroup);
            if ($vgSql !== '') {
                $conditions[] = 'h.' . $vgSql;
                $params = array_merge($params, $vgParams);
            }
        }

        if ($docTypeFilter !== null && $docTypeFilter !== '') {
            $conditions[] = 'h.`doc_type` = %s';
            $params[] = $docTypeFilter;
        }

        if ($numberSeriesFilter !== null && $numberSeriesFilter > 0) {
            $conditions[] = 'h.`number_series` = %i';
            $params[] = $numberSeriesFilter;
        }

        if ($search !== null && $search !== '') {
            $term = '%' . $search . '%';
            $conditions[] = '(h.`doc_number` LIKE %s OR h.`doc_text` LIKE %s OR p.`full_name` LIKE %s)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY h.`docStateMain` ASC, h.`doc_number` DESC, h.`id` DESC';

        [$offset, $limit] = $this->buildPaginationLimit($pageNumber);
        $sql .= ' LIMIT ' . $offset . ', ' . $limit;

        return $this->db->fetchAll($sql, ...$params);
    }

    /**
     * Bottom-tab number series for this viewer.
     *
     * Returns only series in "V pořádku" state (docState = 40):
     *  - Koncept (10) — series not yet in use for filing documents.
     *  - Archivovaná (70) — past series, not shown (its documents are thus
     *    not visible in the default view — a deliberate choice).
     *  - Smazaná (90) — gone.
     *
     * Empty for cross-type viewers ($scopedDocType === null) — the generic
     * DocsHeadsViewer (and any subclass that doesn't pin a type) shows no tabs.
     *
     * Note: wider than DocsHeadsFormBase::resolveNumberSeriesOptions() (which
     * uses docState IN (10, 40, 80)) on purpose — that's "which series may a
     * new document be filed into", a different question than "which series are
     * worth showing as a tab".
     *
     * @return list<array{id: int, name: string}>
     */
    public function getNumberSeries(): array
    {
        if ($this->scopedDocType === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT `id`, `name` FROM `docs_core_number_series`'
            . ' WHERE `doc_type` = %s AND `docState` = 40'
            . ' ORDER BY `name` ASC',
            $this->scopedDocType,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }
        return $out;
    }

    public function getNewRecordDefaults(): array
    {
        return $this->scopedDocType !== null
            ? ['doc_type' => $this->scopedDocType]
            : [];
    }

    public function renderRow(array $rowData): array
    {
        $docState = (int) ($rowData['docState'] ?? 10);
        $stateStyle = $this->resolveStateStyle($docState);

        $partnerName = trim((string) ($rowData['partner_name'] ?? ''));
        $docText = trim((string) ($rowData['doc_text'] ?? ''));

        $title = $partnerName !== '' ? $partnerName : ($docText !== '' ? $docText : '—');

        $row = [
            'id'         => (int) $rowData['id'],
            't1'         => $title,
            'i1'         => $rowData['doc_number'] !== '' ? $rowData['doc_number'] : null,
            'stateStyle' => $stateStyle,
        ];

        $t2 = [];
        $docTypeLabel = $this->resolveDocTypeLabel((string) ($rowData['doc_type'] ?? ''));
        if ($docTypeLabel !== '') {
            $t2[] = ['text' => $docTypeLabel, 'class' => 'muted'];
        }
        $issueDate = $this->formatDate($rowData['issue_date'] ?? null);
        if ($issueDate !== null) {
            $t2[] = ['text' => $issueDate];
        }
        $dueDate = $this->formatDate($rowData['due_date'] ?? null);
        if ($dueDate !== null) {
            $t2[] = ['text' => 'splat. ' . $dueDate, 'class' => 'muted'];
        }
        $stateTag = $this->stateTag($docState);
        if ($stateTag !== null) {
            $t2[] = $stateTag;
        }
        $row['t2'] = $t2 !== [] ? $t2 : null;

        $totalAmount = $rowData['total_amount'] ?? null;
        if ($totalAmount !== null && $totalAmount !== '' && (float) $totalAmount !== 0.0) {
            $currency = strtoupper((string) ($rowData['doc_currency'] ?? ''));
            $formatted = number_format((float) $totalAmount, 2, ',', ' ');
            if ($currency !== '') {
                $formatted .= ' ' . $currency;
            }
            $row['i2'] = ['text' => $formatted, 'class' => 'amount'];
        }

        if ($partnerName !== '' && $docText !== '') {
            $row['t3'] = $docText;
        }

        return $row;
    }

    /**
     * Span se jménem stavu pro řádek vieweru — jen mimo běžné stavy Koncept
     * (10) a V pořádku (40), které nese barva řádku. Sdílené per-typ
     * viewery, které si t2 skládají samy.
     *
     * @return array{text: string, class: string}|null
     */
    protected function stateTag(int $docState): ?array
    {
        if ($docState === 10 || $docState === 40) {
            return null;
        }
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem ?? ''));
        $stateName = (string) ($cfg->getState($docState)['stateName'] ?? '');
        if ($stateName === '') {
            return null;
        }
        return [
            'text'  => $stateName,
            'class' => self::STATE_SPAN_CLASS[$this->resolveStateStyle($docState)] ?? 'muted',
        ];
    }

    /**
     * Detail dokladu — hlavička nad taby (generický header ViewerDetail:
     * číslo, text, typ + stav, ikona) a tab `overview` s content typem
     * `document`: strany Dodavatel/Odběratel, meta
     * mřížka, řádky, DPH rekapitulace, součty a náhledy příloh (mailové
     * zdrojové skupiny + vlastní přílohy dokladu) na konci.
     *
     * Server formátuje hodnoty (datumy `j. n. Y`, částky `1 234,56`);
     * frontend (DocumentDetail.svelte) jen skládá layout.
     */
    public function renderDetail(int $recordId): array
    {
        $record = $this->db->fetchRow(
            'SELECT h.*, p.`full_name` AS partner_name, pb.`full_name` AS partner_balance_name,'
            . ' cd.`code` AS cash_desk_code, cd.`name` AS cash_desk_name'
            . ' FROM `' . $this->table . '` h'
            . ' LEFT JOIN `base_persons_persons` p ON p.`id` = h.`partner`'
            . ' LEFT JOIN `base_persons_persons` pb ON pb.`id` = h.`partner_balance`'
            . ' LEFT JOIN `economy_codebooks_cash_desks` cd ON cd.`id` = h.`cash_desk`'
            . ' WHERE h.`id` = %i',
            $recordId,
        );

        if ($record === null) {
            return ['tabs' => []];
        }

        [$supplier, $customer] = $this->buildDetailParties($record);

        $content = [
            'type'      => 'document',
            'supplier'  => $supplier,
            'customer'  => $customer,
            'meta'      => $this->buildDetailMeta($record),
            'rows'      => $this->buildDetailRows($recordId),
            'vat_recap' => $this->buildDetailVatRecap($recordId),
            'totals'    => $this->buildDetailTotals($record),
        ];

        $attachmentGroups = $this->detailAttachmentGroups($recordId);
        if ($attachmentGroups !== []) {
            $content['attachments'] = ['groups' => $attachmentGroups];
        }

        $tabs = [[
            'id'      => 'overview',
            'label'   => $this->defaultOverviewLabel(),
            'content' => $content,
        ]];

        $accountingTab = $this->buildAccountingTab($record);
        if ($accountingTab !== null) {
            $tabs[] = $accountingTab;
        }

        $header = $this->buildDetailHeader($record);

        $detail = [
            'title'    => $header['title'],
            'subtitle' => $header['subtitle'],
            'badges'   => $header['badges'],
            'icon'     => $header['icon'],
            'tabs'     => $tabs,
        ];

        $actions = $this->buildDetailActions($record);
        if ($actions !== []) {
            $detail['actions'] = $actions;
        }

        return $detail;
    }

    /**
     * Akce detailu dokladu. Přeúčtovat — jen pro doklad ve stavu 40
     * s nainstalovaným economy.accounting (guard přes extension sloupec,
     * stejně jako buildAccountingTab). Záměrně bez vazby na accounting_state:
     * přeúčtovat lze i bezchybně zaúčtovaný doklad (operace je idempotentní,
     * proto i bez confirm). Obsluha: Viewer.svelte volá
     * POST /_accounting/reaccount a refreshne detail.
     */
    private function buildDetailActions(array $record): array
    {
        if (!array_key_exists('accounting_state', $record)) {
            return [];
        }
        if ((int) ($record['docState'] ?? 0) !== 40) {
            return [];
        }

        return [[
            'id'      => 'reaccount',
            'label'   => $this->language === 'cs' ? 'Přeúčtovat' : 'Re-account',
            'kind'    => 'button',
            'variant' => 'secondary',
        ]];
    }

    // ── Tab Zaúčtování (economy.accounting) ────────────────────────────────

    /**
     * Tab Zaúčtování — stav účtování + řádky účetního deníku. Mezimodulová
     * vazba na economy.accounting řešená jako přílohy z core.attachments
     * (přímý dotaz); guardem je extension sloupec accounting_state, který je
     * v SELECT h.* jen s nainstalovaným modulem — bez něj se na tabulku
     * deníku nesahá. Obecný extension point pro cizí taby detailu zatím
     * neexistuje (dluh, viz tasks/accounting-phase3.md).
     *
     * Tab se zobrazí, když doklad má nenulový stav účtování nebo řádky
     * v deníku; koncept bez deníku tab nemá.
     */
    private function buildAccountingTab(array $record): ?array
    {
        if (!array_key_exists('accounting_state', $record)) {
            return null;
        }

        $state = (int) ($record['accounting_state'] ?? 0);
        $journalRows = $this->db->fetchAll(
            'SELECT `account_number`, `text`, `money_dr`, `money_cr`,'
            . ' `money_dr_cur`, `money_cr_cur`, `is_error`'
            . ' FROM `economy_accounting_journal`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `id` ASC',
            (int) $record['id'],
        );

        if ($state === 0 && $journalRows === []) {
            return null;
        }

        $blocks = [$this->accountingStatusBlock($record, $state)];
        if ($journalRows !== []) {
            $blocks[] = $this->accountingJournalTable($record, $journalRows);
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
     * Badge stavu účtování; při chybě (state 2) navíc banner s výpisem
     * accounting_messages. Scoped styly ViewerDetail.svelte se na {@html}
     * obsah nevztahují — vzhled jde přes globální CSS proměnné, které
     * fungují i v dark mode. Všechny hodnoty escapované (frontend vkládá
     * html bez sanitizace).
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

        $messages = $state === 2
            ? $this->decodeAccountingMessages($record['accounting_messages'] ?? null)
            : [];
        if ($messages !== []) {
            $rowWord = $this->language === 'cs' ? 'řádek' : 'row';
            $items = '';
            foreach ($messages as $msg) {
                $text = htmlspecialchars((string) ($msg['message'] ?? ''), ENT_QUOTES);
                $rowId = $msg['rowId'] ?? null;
                if ($rowId !== null) {
                    $text .= ' (' . $rowWord . ' ' . (int) $rowId . ')';
                }
                $items .= '<li>' . $text . '</li>';
            }
            $html .= '<ul role="alert" style="margin:var(--shpd-space-sm) 0 0;'
                . 'padding:var(--shpd-space-sm) var(--shpd-space-md) var(--shpd-space-sm) 28px;'
                . 'background:var(--shpd-color-state-error-bg);'
                . 'color:var(--shpd-color-state-error-text);'
                . 'border-radius:var(--shpd-radius-md);'
                . 'font-size:var(--shpd-font-size-sm)">' . $items . '</ul>';
        }

        return ['type' => 'html', 'html' => $html . '</div>'];
    }

    /**
     * Tabulka řádků deníku se součtovým Σ řádkem na konci. Nulová strana
     * zápisu se nechává prázdná (frontend vykreslí —). U cizoměnového
     * dokladu přibydou sloupce v měně dokladu s kódem měny v labelu.
     * Chybové řádky mají `_class: error`, Σ řádek `_class: total`.
     */
    private function accountingJournalTable(array $record, array $journalRows): array
    {
        $cs = $this->language === 'cs';
        $foreign = $this->isForeignCurrency($record);
        $curCode = strtoupper((string) ($record['doc_currency'] ?? ''));

        $mdLabel = $cs ? 'MD' : 'Debit';
        $dalLabel = $cs ? 'DAL' : 'Credit';
        $columns = [
            ['id' => 'account', 'label' => $cs ? 'Účet' : 'Account'],
            ['id' => 'text',    'label' => 'Text'],
            ['id' => 'md',      'label' => $mdLabel,  'align' => 'right'],
            ['id' => 'dal',     'label' => $dalLabel, 'align' => 'right'],
        ];
        if ($foreign) {
            $columns[] = ['id' => 'md_cur',  'label' => $mdLabel . ' ' . $curCode,  'align' => 'right'];
            $columns[] = ['id' => 'dal_cur', 'label' => $dalLabel . ' ' . $curCode, 'align' => 'right'];
        }

        $rows = [];
        $sum = ['md' => 0.0, 'dal' => 0.0, 'md_cur' => 0.0, 'dal_cur' => 0.0];
        foreach ($journalRows as $jr) {
            $row = [
                'account' => (string) ($jr['account_number'] ?? ''),
                'text'    => (string) ($jr['text'] ?? ''),
                'md'      => $this->formatNonZeroMoney($jr['money_dr'] ?? null),
                'dal'     => $this->formatNonZeroMoney($jr['money_cr'] ?? null),
            ];
            if ($foreign) {
                $row['md_cur']  = $this->formatNonZeroMoney($jr['money_dr_cur'] ?? null);
                $row['dal_cur'] = $this->formatNonZeroMoney($jr['money_cr_cur'] ?? null);
            }
            if ((int) ($jr['is_error'] ?? 0) === 1) {
                $row['_class'] = 'error';
            }
            $sum['md']      += (float) ($jr['money_dr'] ?? 0);
            $sum['dal']     += (float) ($jr['money_cr'] ?? 0);
            $sum['md_cur']  += (float) ($jr['money_dr_cur'] ?? 0);
            $sum['dal_cur'] += (float) ($jr['money_cr_cur'] ?? 0);
            $rows[] = $row;
        }

        $total = [
            'account' => 'Σ',
            'text'    => '',
            'md'      => $this->formatMoney($sum['md']),
            'dal'     => $this->formatMoney($sum['dal']),
            '_class'  => 'total',
        ];
        if ($foreign) {
            $total['md_cur']  = $this->formatMoney($sum['md_cur']);
            $total['dal_cur'] = $this->formatMoney($sum['dal_cur']);
        }
        $rows[] = $total;

        return ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
    }

    /** @return list<array<string, mixed>> dekódované accounting_messages (JSON) */
    private function decodeAccountingMessages(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** Částka pro stranu zápisu — nula se nechává prázdná. */
    private function formatNonZeroMoney(mixed $amount): ?string
    {
        if ($amount === null || $amount === '' || (float) $amount === 0.0) {
            return null;
        }
        return $this->formatMoney($amount);
    }

    /**
     * Hlavička detailu nad taby (generický header ViewerDetail, sjednocené
     * s Osobami, Položkami a Došlou poštou) — číslo dokladu jako title,
     * doc_text jako subtitle, badges s typem dokladu a stavem, ikona podle
     * doc_type. Nahrazuje dřívější hlavičku uvnitř content typu `document`;
     * DocumentDetail ji bez klíče `header` nevykresluje.
     *
     * @return array{title: string, subtitle: ?string, badges: array<int, array{label: string, style: string}>, icon: string}
     */
    private function buildDetailHeader(array $record): array
    {
        $cfg = DocStateConfig::fromCfgItem($this->config?->cfgItem($this->docStatesCfgItem ?? ''));
        $stateData = $cfg->getState((int) ($record['docState'] ?? 10));
        $docText = trim((string) ($record['doc_text'] ?? ''));
        $docNumber = trim((string) ($record['doc_number'] ?? ''));

        $badges = [];
        $typeLabel = $this->resolveDocTypeLabel((string) ($record['doc_type'] ?? ''));
        if ($typeLabel !== '') {
            $badges[] = ['label' => $typeLabel, 'style' => 'neutral'];
        }
        $stateName = (string) ($stateData['stateName'] ?? '');
        if ($stateName !== '') {
            $badges[] = [
                'label' => $stateName,
                'style' => (string) ($stateData['stateStyle'] ?? 'concept'),
            ];
        }

        return [
            'title'    => $docNumber !== '' ? $docNumber : '—',
            'subtitle' => $docText !== '' ? $docText : null,
            'badges'   => $badges,
            'icon'     => $this->detailIconForDocType((string) ($record['doc_type'] ?? '')),
        ];
    }

    /** Ikona detailu podle typu dokladu — stejné klíče jako viewers[].icon v module.jsonc. */
    private function detailIconForDocType(string $docType): string
    {
        return match ($docType) {
            'invni'   => 'invoice-in',
            'invno'   => 'invoice',
            'cash'    => 'wallet',
            'cashreg' => 'cash-register',
            default   => 'document',
        };
    }

    /**
     * Strany dokladu. Uložené snapshoty (zmrazené při Potvrzení) mají
     * přednost; u Konceptu se strany skládají živě přes PersonSnapshotBuilder
     * + OwnCompanyResolver. Chybějící vlastní firma → strana je null, detail
     * nesmí spadnout na nenakonfigurovaném DS.
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}  [supplier, customer]
     */
    private function buildDetailParties(array $record): array
    {
        $supplier = $this->decodeSnapshot($record['supplier_snapshot'] ?? null);
        $customer = $this->decodeSnapshot($record['customer_snapshot'] ?? null);
        if ($supplier !== null && $customer !== null) {
            return $this->attachPartnerPersonId($record, $supplier, $customer);
        }

        // Stejné mapování jako DocDocument::buildSnapshots(): 1 = my jsme
        // dodavatel, 2 = my odběratel, null = typ bez stran (cmnbkp) — živé
        // strany se nestaví, stejně jako se pro něj nestaví snapshoty.
        $tradeDir = DocDocument::resolveTradeDir($record, $this->config);
        if ($tradeDir === null) {
            return $this->attachPartnerPersonId($record, $supplier, $customer);
        }

        $partner = null;
        $partnerId = (int) ($record['partner'] ?? 0);
        if ($partnerId > 0) {
            $snap = $this->personSnapshotBuilder()->build(
                $partnerId,
                $record['partner_address'] ?? null,
                null,
                null,
            );
            $partner = $snap !== [] ? $snap : null;
        }

        $own = null;
        $ownPersonId = $this->ownCompanyResolver()->getOwnPersonId();
        if ($ownPersonId !== null) {
            $hqAddress = $this->ownCompanyResolver()->getOwnHeadquartersAddress();
            $snap = $this->personSnapshotBuilder()->build(
                $ownPersonId,
                $hqAddress !== null ? (int) $hqAddress['id'] : null,
                $record['bank_account'] ?? null,
                $record['vat_registration'] ?? null,
            );
            $own = $snap !== [] ? $snap : null;
        }

        $liveSupplier = $tradeDir === 1 ? $own : $partner;
        $liveCustomer = $tradeDir === 1 ? $partner : $own;

        return $this->attachPartnerPersonId(
            $record,
            $supplier ?? $liveSupplier,
            $customer ?? $liveCustomer,
        );
    }

    /**
     * Dekorace partnerské strany detailu odkazem na živý záznam osoby
     * (klíč person_id) — frontend z něj dělá klikatelné jméno, které otevře
     * formulář osoby. Jen partner; vlastní firma person_id nedostane,
     * a zůstává tak neklikatelná. Perzistovaný tvar snapshotu se nemění —
     * dekoruje se až výstup pro renderDetail. Odkaz vede na aktuální záznam
     * osoby, který se u potvrzených dokladů může od zmrazeného snapshotu
     * lišit (záměrné rozhodnutí).
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}  [supplier, customer]
     */
    private function attachPartnerPersonId(array $record, ?array $supplier, ?array $customer): array
    {
        $partnerId = (int) ($record['partner'] ?? 0);
        // partner_name z LEFT JOIN v renderDetail — null znamená visící FK,
        // mrtvý odkaz se neposílá.
        if ($partnerId <= 0 || ($record['partner_name'] ?? null) === null) {
            return [$supplier, $customer];
        }

        if (DocDocument::resolveTradeDir($record, $this->config) === 1) {
            if ($customer !== null) {
                $customer['person_id'] = $partnerId;
            }
        } elseif ($supplier !== null) {
            $supplier['person_id'] = $partnerId;
        }

        return [$supplier, $customer];
    }

    /** @return array<string, mixed>|null */
    private function decodeSnapshot(mixed $raw): ?array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /** @return array<string, ?string> */
    private function buildDetailMeta(array $record): array
    {
        $currency = strtoupper((string) ($record['doc_currency'] ?? ''));
        $rate = $record['exchange_rate'] ?? null;
        $hasRate = $this->isForeignCurrency($record) && $rate !== null && (float) $rate > 0;

        return [
            'issue_date'      => $this->formatDate($record['issue_date'] ?? null),
            'due_date'        => $this->formatDate($record['due_date'] ?? null),
            'accounting_date' => $this->formatDate($record['accounting_date'] ?? null),
            'vat_duzp'        => $this->formatDate($record['vat_duzp'] ?? null),
            'currency'        => $currency !== '' ? $currency : null,
            'exchange_rate'   => $hasRate ? number_format((float) $rate, 3, ',', ' ') : null,
            'payment_method'  => $this->resolvePaymentMethodLabel($record['payment_method'] ?? null),
            'cash_desk'       => $this->formatCashDesk($record),
            // Plátce (osoba pro saldokonto, #72) jen když se liší od partnera.
            'payer'           => $this->formatPayer($record),
            'payment_reference' => $this->nullableString($record['payment_reference'] ?? null),
            'specific_symbol' => $this->nullableString($record['specific_symbol'] ?? null),
            'constant_symbol' => $this->nullableString($record['constant_symbol'] ?? null),
        ];
    }

    /** Jméno plátce (LEFT JOIN v renderDetail), jen liší-li se od partnera hlavičky. */
    private function formatPayer(array $record): ?string
    {
        $payerId = (int) ($record['partner_balance'] ?? 0);
        if ($payerId <= 0 || $payerId === (int) ($record['partner'] ?? 0)) {
            return null;
        }
        $name = trim((string) ($record['partner_balance_name'] ?? ''));
        return $name !== '' ? $name : null;
    }

    /** „kód — název" pokladny dokladu (LEFT JOIN v renderDetail); null bez pokladny. */
    private function formatCashDesk(array $record): ?string
    {
        if (empty($record['cash_desk'])) {
            return null;
        }
        $code = trim((string) ($record['cash_desk_code'] ?? ''));
        $name = trim((string) ($record['cash_desk_name'] ?? ''));
        if ($code === '' && $name === '') {
            return null;
        }
        return $code !== '' && $name !== '' ? "{$code} — {$name}" : ($code !== '' ? $code : $name);
    }

    /** @return list<array<string, mixed>> */
    private function buildDetailRows(int $recordId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.`row_kind`, r.`order_pos`, r.`description`, r.`quantity`,'
            . ' r.`unit_price`, r.`total_price`, r.`vat_pct`,'
            . ' u.`shortcut` AS unit_shortcut,'
            . ' it.`id` AS item_id, it.`docState` AS item_doc_state'
            . ' FROM `docs_core_rows` r'
            . ' LEFT JOIN `core_units` u ON u.`id` = r.`unit`'
            . ' LEFT JOIN `economy_items` it ON it.`id` = r.`item`'
            . ' WHERE r.`doc_head` = %i'
            . ' ORDER BY r.`order_pos` ASC, r.`id` ASC',
            $recordId,
        );

        $out = [];
        foreach ($rows as $row) {
            $itemId = isset($row['item_id']) ? (int) $row['item_id'] : null;
            $entry = [
                'order_pos'   => (int) ($row['order_pos'] ?? 0),
                'kind'        => (int) ($row['row_kind'] ?? 1),
                'description' => (string) ($row['description'] ?? ''),
                'quantity'    => $this->formatTrimmedNumber($row['quantity'] ?? null, 4),
                'unit'        => $this->nullableString($row['unit_shortcut'] ?? null),
                'unit_price'  => $this->formatMoney($row['unit_price'] ?? null),
                'vat_pct'     => $this->formatTrimmedNumber($row['vat_pct'] ?? null, 2),
                'total_price' => $this->formatMoney($row['total_price'] ?? null),
            ];
            // Vazba na Položky přes FK docs_core_rows.item — řádek bez FK
            // (typicky import z pošty s pouhým popisem) odkaz nemá.
            if ($itemId !== null) {
                $entry['item_id'] = $itemId;
                $badge = $this->itemStateBadge((int) ($row['item_doc_state'] ?? 0));
                if ($badge !== null) {
                    $entry['item_state'] = $badge;
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** @return list<array<string, ?string>> */
    private function buildDetailVatRecap(int $recordId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT `vat_pct`, `base`, `tax`, `total`'
            . ' FROM `docs_core_vat_recap`'
            . ' WHERE `doc_head` = %i'
            . ' ORDER BY `order_pos` ASC, `id` ASC',
            $recordId,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'vat_pct' => $this->formatTrimmedNumber($row['vat_pct'] ?? null, 2),
                'base'    => $this->formatMoney($row['base'] ?? null),
                'tax'     => $this->formatMoney($row['tax'] ?? null),
                'total'   => $this->formatMoney($row['total'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function buildDetailTotals(array $record): array
    {
        $rounding = (float) ($record['total_rounding'] ?? 0);

        $totals = [
            'currency' => strtoupper((string) ($record['doc_currency'] ?? '')),
            'base'     => $this->formatMoney($record['total_base'] ?? 0),
            'vat'      => $this->formatMoney($record['total_vat'] ?? 0),
            'amount'   => $this->formatMoney($record['total_amount'] ?? 0),
            'rounding' => $rounding !== 0.0 ? $this->formatMoney($rounding) : null,
            'dom'      => null,
        ];

        if ($this->isForeignCurrency($record)) {
            $totals['dom'] = [
                'currency' => strtoupper((string) ($record['home_currency'] ?? '')),
                'base'     => $this->formatMoney($record['total_base_dom'] ?? 0),
                'vat'      => $this->formatMoney($record['total_vat_dom'] ?? 0),
                'amount'   => $this->formatMoney($record['total_amount_dom'] ?? 0),
            ];
        }

        return $totals;
    }

    /**
     * Skupiny příloh pro konec detailu: mailové zdrojové skupiny první
     * (kind 'mail'), pak vlastní přílohy dokladu (kind 'doc', vynechá se
     * když žádné nejsou).
     *
     * @return list<array<string, mixed>>
     */
    private function detailAttachmentGroups(int $recordId): array
    {
        $groups = $this->sourceAttachmentGroups($recordId);

        $files = $this->db->fetchAll(
            'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
            . ' FROM `core_attachments_files`'
            . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0'
            . ' ORDER BY `att_order` ASC, `name` ASC',
            self::TABLE_ID_HEADS,
            $recordId,
        );
        if ($files !== []) {
            $attachments = [];
            foreach ($files as $f) {
                $attachments[] = [
                    'id'        => (int) $f['id'],
                    'name'      => (string) ($f['name'] ?? $f['file_name']),
                    'mime_type' => (string) ($f['mime_type'] ?? ''),
                    'file_size' => (int) ($f['file_size'] ?? 0),
                ];
            }
            $groups[] = [
                'kind'        => 'doc',
                'attachments' => $attachments,
            ];
        }

        return $groups;
    }

    /**
     * Zprávy došlé pošty, ze kterých tento doklad vznikl. Primární cesta je
     * FK `heads.source_message` (AI apply flow, D6 z mail-message-centric);
     * doplněná o reverzní vazbu `message.target_table_id + target_row`
     * (importy old_shipard, které FK na heads nemají). Trash (90) vynechán.
     *
     * @return list<array{id:int, message_id:string, received_at:?string, raw_source_attachment:?int}>
     */
    private function sourceMessages(int $docId): array
    {
        $sourceMessage = (int) ($this->db->fetchSingle(
            'SELECT `source_message` FROM `docs_core_heads` WHERE `id` = %i',
            $docId,
        ) ?? 0);

        return $this->db->fetchAll(
            'SELECT `id`, `message_id`, `received_at`, `raw_source_attachment`'
            . ' FROM `core_mail_incoming_messages`'
            . ' WHERE ((`target_table_id` = %s AND `target_row` = %i) OR `id` = %i)'
            . ' AND `docState` != %i'
            . ' ORDER BY `received_at` ASC, `id` ASC',
            'docs_core_heads', $docId, $sourceMessage, 90,
        );
    }

    /**
     * Přílohy zdrojových zpráv seskupené per zpráva. Skupiny bez obsahových
     * příloh se vynechávají. Raw .eml originál (raw_source_attachment) je
     * vyloučen — patří zprávě zvlášť a není to obsahová příloha.
     *
     * Přílohy zprávy = core_attachments_files s table_id = 303 (tableId
     * core_mail_incoming_messages), record_id = message.id.
     *
     * @return list<array{kind:string, sourceViewerId:string, message_id:string, received_at:?string, message_ndx:int, attachments:list<array{id:int, name:string, mime_type:string, file_size:int}>}>
     */
    private function sourceAttachmentGroups(int $docId): array
    {
        $groups = [];
        foreach ($this->sourceMessages($docId) as $msg) {
            $rawId = $msg['raw_source_attachment'] !== null ? (int) $msg['raw_source_attachment'] : null;

            $sql = 'SELECT `id`, `name`, `file_name`, `file_size`, `mime_type`'
                . ' FROM `core_attachments_files`'
                . ' WHERE `table_id` = %i AND `record_id` = %i AND `is_deleted` = 0';
            $params = [303, (int) $msg['id']];
            if ($rawId !== null) {
                $sql .= ' AND `id` != %i';
                $params[] = $rawId;
            }
            $sql .= ' ORDER BY `att_order` ASC, `name` ASC';

            $files = $this->db->fetchAll($sql, ...$params);
            if ($files === []) {
                continue;
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

            $groups[] = [
                'kind'           => 'mail',
                'sourceViewerId' => 'core.mail.incoming',
                'message_id'     => (string) $msg['message_id'],
                'received_at'    => $this->formatDate($msg['received_at'] ?? null),
                'message_ndx'    => (int) $msg['id'],
                'attachments'    => $attachments,
            ];
        }

        return $groups;
    }

    private function resolveStateStyle(int $docState): string
    {
        if ($this->config === null || $this->docStatesCfgItem === null) {
            return 'concept';
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStatesCfgItem));
        return $cfg->getState($docState)['stateStyle'] ?? 'concept';
    }

    /**
     * Badge stavu položky pro řádek detailu — jen archiv a koš, aby šlo
     * hned vidět, že odkazovaná položka už není aktivní. Aktivní stavy
     * badge nedostávají (zbytečný šum). Label je stateName z cfgItem,
     * lokalizovaný přes compiled config daného jazyka.
     *
     * @return array{label: string, style: string}|null
     */
    private function itemStateBadge(int $docState): ?array
    {
        if ($this->config === null) {
            return null;
        }
        $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem(self::ITEMS_DOC_STATES_CFG_ITEM));
        $state = $cfg->getState($docState);
        $style = (string) ($state['stateStyle'] ?? '');
        if ($style !== 'archive' && $style !== 'trash') {
            return null;
        }
        $label = (string) ($state['stateName'] ?? '');
        if ($label === '') {
            return null;
        }
        return ['label' => $label, 'style' => $style];
    }

    private function resolveDocTypeLabel(string $key): string
    {
        if ($key === '' || $this->config === null) {
            return '';
        }
        $cfg = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($cfg) || !isset($cfg[$key]['name'])) {
            return $key;
        }
        return (string) $cfg[$key]['name'];
    }

    protected function resolvePaymentMethodLabel(mixed $value): ?string
    {
        if ($value === null || $value === '' || $this->config === null) {
            return null;
        }
        $cfg = $this->config->cfgItem('docs.core.paymentMethods');
        $key = (string) (int) $value;
        if (!is_array($cfg) || !isset($cfg[$key]['name'])) {
            return null;
        }
        return (string) $cfg[$key]['name'];
    }

    private function isForeignCurrency(array $record): bool
    {
        $doc = strtolower((string) ($record['doc_currency'] ?? ''));
        $home = strtolower((string) ($record['home_currency'] ?? ''));
        return $doc !== '' && $home !== '' && $doc !== $home;
    }

    private function formatMoney(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        return number_format((float) $amount, 2, ',', ' ');
    }

    /**
     * Číslo s ořezanými koncovými nulami v desetinné části
     * (množství "10,0000" → "10", sazba "21,00" → "21").
     */
    private function formatTrimmedNumber(mixed $value, int $decimals): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $formatted = number_format((float) $value, $decimals, ',', ' ');
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }
        return $formatted;
    }

    private function nullableString(mixed $value): ?string
    {
        $str = trim((string) ($value ?? ''));
        return $str !== '' ? $str : null;
    }

    // ── Lazy service factories (protected → overridable v testech) ─────────

    protected function personSnapshotBuilder(): PersonSnapshotBuilder
    {
        if ($this->personSnapshotBuilder === null) {
            $this->personSnapshotBuilder = new PersonSnapshotBuilder($this->db->getDibiConnection());
        }
        return $this->personSnapshotBuilder;
    }

    protected function ownCompanyResolver(): OwnCompanyResolver
    {
        if ($this->ownCompanyResolver === null) {
            $this->ownCompanyResolver = new OwnCompanyResolver($this->db->getDibiConnection());
        }
        return $this->ownCompanyResolver;
    }

    protected function formatDate(mixed $value): ?string
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
