<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Accounting;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Economy\Accounting\AccountingRules;
use Shipard\Module\Economy\Accounting\AccountMaskResolver;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\FilingSnapshotLoader;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Zaúčtování podaného přiznání DPH (#55 D28–D31): načte snapshot podání,
 * předchozí podaný stav, registraci a účty, nechá `VatReturnAccountingBuilder`
 * sestavit řádky a založí účetní doklad `cmnbkp` ve stavu Koncept — uživatel
 * ho zkontroluje a uzavře sám (D31, žádné automatické účtování).
 *
 * Vše v jedné transakci: doklad s řádky přes `TableGateway` (Document
 * lifecycle, zámky, event handlery) a hned poté `economy_vat_filings
 * .acc_document` + záznam do `messages` podání (guard ve FilingDocument).
 * Chyba kdekoli = rollback.
 *
 * Idempotence: živý `acc_document` (mimo Storno/Smazáno) → `ALREADY_ACCOUNTED`;
 * po stornu dokladu vznikne nový a FK se přepíše.
 *
 * Řada dokladu: parametr vrstvy C `economy.vat.filingAccountingSeries`
 * (id řady cmnbkp); bez něj jen tehdy, když je aktivní řada typu právě
 * jedna — při více řadách žádný tichý výběr první (stejná zásada jako
 * u pokladen). Doklad nemá registraci k DPH ani rekapitulaci, takže ho
 * zámek instance nechytá; zámek fiskálního měsíce (`accounting_date` =
 * konec období) ano — gateway vrátí chybu `locked`.
 */
final class VatReturnAccountingService
{
    public const SETTING_SERIES = 'economy.vat.filingAccountingSeries';
    public const HEADS_TABLE    = 'docs_core_heads';
    public const MSG_ACCOUNTED  = 'vatReturn.accounted';

    private const ACTIVE_SERIES_STATES = [10, 40, 80];

    private ?FilingSnapshotLoader $snapshots = null;

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?DataSourceConfig $dsConfig,
        private readonly DocumentRegistry $documents,
        private readonly array $tables,
        private readonly ?DocumentEventDispatcher $dispatcher = null,
        private ?SettingsStore $settings = null,
    ) {}

    /**
     * Plán bez zápisu (CLI `--dry-run`). `$allowDraft` pustí i koncept
     * podání (stav 10) — nástroj zlatého testu, zápis to nikdy nedovolí.
     */
    public function plan(int $filingId, bool $allowDraft = false): VatReturnAccountingResult
    {
        $prepared = $this->prepare($filingId, $allowDraft);
        if ($prepared instanceof VatReturnAccountingResult) {
            return $prepared;
        }
        return VatReturnAccountingResult::planned($prepared['plan'], $prepared['context']);
    }

    /** Založí účetní doklad a naváže ho na podání. */
    public function account(int $filingId): VatReturnAccountingResult
    {
        $prepared = $this->prepare($filingId, false);
        if ($prepared instanceof VatReturnAccountingResult) {
            return $prepared;
        }
        /** @var VatReturnAccountingPlan $plan */
        $plan    = $prepared['plan'];
        $context = $prepared['context'];

        if (!$plan->isOk()) {
            return VatReturnAccountingResult::failed(
                'PLAN_FAILED',
                'Účetní doklad nejde sestavit — viz chyby plánu.',
                $context,
                $plan,
            );
        }
        if ($plan->rows === []) {
            return VatReturnAccountingResult::failed(
                'NOTHING_TO_ACCOUNT',
                'Podání nemění žádnou analytiku DPH ani daňovou povinnost — účetní doklad se nezakládá.',
                $context,
                $plan,
            );
        }

        $headsDef   = $this->tables[self::HEADS_TABLE] ?? null;
        $filingsDef = $this->tables[FilingDocument::TABLE] ?? null;
        if ($headsDef === null || $filingsDef === null) {
            return VatReturnAccountingResult::failed(
                'CONFIG_MISSING',
                'Chybí definice tabulky dokladů nebo podání — spusťte ds-upgrade.',
                $context,
                $plan,
            );
        }

        try {
            $series = $this->resolveSeries();
        } catch (\DomainException $e) {
            return VatReturnAccountingResult::failed('SERIES_MISSING', $e->getMessage(), $context, $plan);
        }
        $context['series'] = $series;

        $this->db->begin();
        try {
            $head = $this->gateway(self::HEADS_TABLE, $headsDef)
                ->saveDocument($this->headData($prepared, (int) $series['id'], $plan));
            if (!$head->isSuccess()) {
                $this->db->rollback();
                return $this->saveFailure('Účetní doklad se nepodařilo uložit.', $head, $context, $plan);
            }
            $docId = (int) ($head->getData()['id'] ?? 0);

            $filingSave = $this->gateway(FilingDocument::TABLE, $filingsDef)->saveDocument([
                'id'           => $filingId,
                'acc_document' => $docId,
                'messages'     => $this->messagesWith($prepared['filing'], $docId, $plan),
            ]);
            if (!$filingSave->isSuccess()) {
                $this->db->rollback();
                return $this->saveFailure('Podání se nepodařilo navázat na účetní doklad.', $filingSave, $context, $plan);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return VatReturnAccountingResult::accounted($docId, $plan, $context);
    }

    // ── příprava ────────────────────────────────────────────────────────────

    /**
     * @return VatReturnAccountingResult|array{filing: array<string, mixed>, period: array<string, mixed>,
     *         registration: array<string, mixed>, plan: VatReturnAccountingPlan, context: array<string, mixed>}
     */
    private function prepare(int $filingId, bool $allowDraft): VatReturnAccountingResult|array
    {
        $loader = $this->snapshots();
        $filing = $loader->loadFiling($filingId);
        if ($filing === null) {
            return VatReturnAccountingResult::failed('NOT_FOUND', "Podání #{$filingId} nenalezeno.");
        }
        $context = [
            'filingId'   => $filingId,
            'filingName' => (string) ($filing['name'] ?? ''),
            'filingKind' => (string) ($filing['filing_kind'] ?? ''),
            'sequence'   => (int) ($filing['sequence'] ?? 0),
            'docState'   => (int) ($filing['docState'] ?? 0),
        ];

        if ((string) $filing['report_type'] !== 'return') {
            return VatReturnAccountingResult::failed(
                'INVALID_REPORT_TYPE',
                'Účtuje se jen přiznání k DPH — kontrolní a souhrnné hlášení účetní doklad nemají.',
                $context,
            );
        }
        $state = (int) $filing['docState'];
        if ($state !== FilingDocument::DOC_STATE_FILED && !($allowDraft && $state === FilingDocument::DOC_STATE_COMPOSED)) {
            return VatReturnAccountingResult::failed(
                'INVALID_DOC_STATE',
                'Zaúčtovat lze jen podané podání (stav Podáno).',
                $context,
            );
        }
        $context['draft'] = $state === FilingDocument::DOC_STATE_COMPOSED;

        $existingId = (int) ($filing['acc_document'] ?? 0);
        if ($existingId > 0) {
            $head = $this->loadHead($existingId);
            if ($head !== null && !in_array((int) $head['docState'], FilingDocument::ACC_DOCUMENT_DEAD_STATES, true)) {
                $context['existingDocId'] = $existingId;
                return VatReturnAccountingResult::failed(
                    'ALREADY_ACCOUNTED',
                    "Podání už má účetní doklad #{$existingId}" . ($head['doc_number'] !== '' ? " ({$head['doc_number']})" : '')
                    . ' — nejdřív ho stornujte.',
                    $context,
                );
            }
        }

        $period = $this->loadPeriod((int) $filing['report_period']);
        if ($period === null) {
            return VatReturnAccountingResult::failed('NOT_FOUND', 'Instance tvrzení podání neexistuje.', $context);
        }
        $context['periodName'] = (string) $period['name'];
        $context['dateEnd']    = (string) $period['date_end'];
        $registration = $this->loadRegistration((int) $period['vat_registration']);

        $mapping    = VatOutputsMapping::fromConfig($this->config);
        $accounting = $mapping?->accounting('return');
        if ($mapping === null || $accounting === null || $this->config === null) {
            return VatReturnAccountingResult::failed(
                'CONFIG_MISSING',
                'Chybí kompilovaná konfigurace zaúčtování přiznání (economy.vat.reports.cz → reportTypes.return.accounting)'
                . ' — spusťte ds-upgrade.',
                $context,
            );
        }

        $country  = strtolower((string) ($registration['country'] ?? '')) ?: 'cz';
        $vatCodes = (new VatRateResolver($this->config))->getVatCodes($country, null, null, true);
        // Předpis = účetní jurisdikce vlastní firmy a varianta její osnovy
        // (#122 D3), ne země registrace — ta řídí jen kódy, výkaz a správce
        // daně; efektivní předpis (přepisy NNO) dává AccountingRules.
        $rules        = AccountingRules::resolve($this->config, $this->db);
        $accountRules = is_array($rules) && is_array($rules['accounts'] ?? null) ? $rules['accounts'] : [];
        $resolver     = new AccountMaskResolver($this->db);
        $dateEnd      = (string) $period['date_end'];

        $previousId = (int) ($filing['previous_filing'] ?? 0);
        $context['previousFilingId'] = $previousId > 0 ? $previousId : null;

        $input = new VatReturnAccountingInput(
            kind: (string) $filing['filing_kind'],
            periodName: (string) $period['name'],
            dateEnd: $dateEnd,
            taxByCode: $loader->taxByCode($filingId),
            previousTaxByCode: $previousId > 0 ? $loader->taxByCode($previousId) : [],
            exactRows: $loader->exactRows($filingId),
            previousExactRows: $previousId > 0 ? $loader->exactRows($previousId) : [],
            filedRows: $loader->filedRows($filingId),
            previousCumulativeFiledRows: $previousId > 0 ? $loader->cumulativeFiledRows($previousId) : [],
            vatCodes: $vatCodes,
            analyticAccount: fn (string $code): ?array => $this->resolveMasks(
                $resolver,
                $this->analyticMasks($accountRules, $code),
                $dateEnd,
            ),
            categoryAccounts: [
                'payable'         => $this->resolveMasks($resolver, $this->categoryMasks($accountRules, 'vat.payable'), $dateEnd),
                'receivable'      => $this->resolveMasks($resolver, $this->categoryMasks($accountRules, 'vat.receivable'), $dateEnd),
                'nondeductible'   => $this->resolveMasks($resolver, $this->categoryMasks($accountRules, 'vat.nondeductible'), $dateEnd),
                'roundingCost'    => $this->resolveMasks($resolver, $this->categoryMasks($accountRules, 'rounding.cost'), $dateEnd),
                'roundingRevenue' => $this->resolveMasks($resolver, $this->categoryMasks($accountRules, 'rounding.revenue'), $dateEnd),
            ],
            accounting: $accounting,
            vatId: (string) ($registration['vat_id'] ?? ''),
            taxOfficePerson: !empty($registration['tax_office_person']) ? (int) $registration['tax_office_person'] : null,
            categoryMasks: [
                'nondeductible'   => $this->categoryMasks($accountRules, 'vat.nondeductible'),
                'roundingCost'    => $this->categoryMasks($accountRules, 'rounding.cost'),
                'roundingRevenue' => $this->categoryMasks($accountRules, 'rounding.revenue'),
            ],
        );

        return [
            'filing'       => $filing,
            'period'       => $period,
            'registration' => $registration,
            'plan'         => (new VatReturnAccountingBuilder())->build($input),
            'context'      => $context,
        ];
    }

    // ── účty z předpisu ─────────────────────────────────────────────────────

    /**
     * Masky kategorie bez query (`vat.payable` → 343801) — první, která se
     * v rozvrhu dohledá, vyhrává (řetěz masek jako v AccountingEngine).
     *
     * @param list<array<string, mixed>> $accountRules
     * @return list<string>
     */
    private function categoryMasks(array $accountRules, string $category): array
    {
        $masks = [];
        foreach ($accountRules as $rule) {
            if (($rule['cat'] ?? null) !== $category || !empty($rule['query'])) {
                continue;
            }
            foreach ((array) ($rule['accountMask'] ?? []) as $mask) {
                $masks[] = (string) $mask;
            }
        }
        return $masks;
    }

    /**
     * Analytika 343 pro kód DPH: maska z předpisu (`cat: vat`, `query.vat_code`),
     * jinak konvence `343{NNN}` / `343{CC}{NNN}` z docs/accounting.md §5.
     *
     * @param list<array<string, mixed>> $accountRules
     * @return list<string>
     */
    private function analyticMasks(array $accountRules, string $code): array
    {
        $masks = [];
        foreach ($accountRules as $rule) {
            if (($rule['cat'] ?? null) !== 'vat' || (($rule['query']['vat_code'] ?? null) !== $code)) {
                continue;
            }
            foreach ((array) ($rule['accountMask'] ?? []) as $mask) {
                $masks[] = (string) $mask;
            }
        }
        if ($masks !== []) {
            return $masks;
        }
        $dash    = strpos($code, '-');
        $country = $dash !== false ? substr($code, 0, $dash) : 'cz';
        $number  = $dash !== false ? substr($code, $dash + 1) : $code;
        return [$country === 'cz' ? '343' . $number : '343' . strtoupper($country) . $number];
    }

    /**
     * @param list<string> $masks
     * @return ?array{id: int, number: string}
     */
    private function resolveMasks(AccountMaskResolver $resolver, array $masks, string $date): ?array
    {
        foreach ($masks as $mask) {
            $account = $resolver->resolve($mask, $date);
            if ($account !== null) {
                return $account;
            }
        }
        return null;
    }

    // ── řada a data dokladu ─────────────────────────────────────────────────

    /**
     * @return array{id: int, name: string}
     * @throws \DomainException řadu nejde určit
     */
    private function resolveSeries(): array
    {
        $configured = $this->settings()->get(self::SETTING_SERIES);
        if ($configured !== null && $configured !== '') {
            $row = $this->db->fetch(
                'SELECT [id], [name] FROM [docs_core_number_series]'
                . ' WHERE [id] = %i AND [doc_type] = %s AND [docState] IN %in',
                (int) $configured, FilingDocument::ACC_DOCUMENT_TYPE, self::ACTIVE_SERIES_STATES,
            );
            if ($row === null) {
                throw new \DomainException(
                    'Parametr ' . self::SETTING_SERIES . " = {$configured} nemíří na aktivní řadu účetních dokladů"
                    . ' (cmnbkp) — opravte ho: shpd-ds ds-setting set ' . self::SETTING_SERIES . ' <id řady>.',
                );
            }
            return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM [docs_core_number_series] WHERE [doc_type] = %s AND [docState] IN %in ORDER BY [id]',
            FilingDocument::ACC_DOCUMENT_TYPE, self::ACTIVE_SERIES_STATES,
        );
        if (count($rows) === 1) {
            return ['id' => (int) $rows[0]['id'], 'name' => (string) $rows[0]['name']];
        }
        if ($rows === []) {
            throw new \DomainException('Zdroj dat nemá aktivní řadu účetních dokladů (cmnbkp).');
        }
        $names = implode(', ', array_map(static fn ($r) => "{$r['id']} = {$r['name']}", $rows));
        throw new \DomainException(
            'Zdroj dat má více řad účetních dokladů — vyberte řadu pro přiznání DPH:'
            . ' shpd-ds ds-setting set ' . self::SETTING_SERIES . " <id> ({$names}).",
        );
    }

    /**
     * @param array{filing: array<string, mixed>, period: array<string, mixed>, registration: array<string, mixed>} $prepared
     * @return array<string, mixed>
     */
    private function headData(array $prepared, int $seriesId, VatReturnAccountingPlan $plan): array
    {
        $filing = $prepared['filing'];
        $period = $prepared['period'];
        $kind   = (string) $filing['filing_kind'];
        $title  = 'Přiznání DPH ' . (string) $period['name'];
        $suffix = match ($kind) {
            FilingDocument::KIND_SUPPLEMENTARY => ' — dodatečné',
            'corrective'                       => ' — opravné',
            default                            => '',
        };
        if ($suffix !== '') {
            $title .= $suffix . ' č. ' . (int) ($filing['sequence'] ?? 0);
        }

        $rows = [];
        foreach ($plan->rows as $i => $row) {
            $data = [
                'row_kind'        => 1,
                'operation'       => 'acc.record',
                'order_pos'       => $i + 1,
                'account'         => $row['account'],
                'acc_side'        => $row['acc_side'],
                'total_price'     => $row['amount'],
                'price_calc_mode' => 1,
                'description'     => mb_substr((string) $row['description'], 0, 500),
            ];
            foreach (['partner', 'payment_reference', 'specific_symbol', 'constant_symbol', 'due_date'] as $key) {
                if (isset($row[$key]) && $row[$key] !== '') {
                    $data[$key] = $row[$key];
                }
            }
            $rows[] = $data;
        }

        $head = [
            'doc_type'        => FilingDocument::ACC_DOCUMENT_TYPE,
            'number_series'   => $seriesId,
            'issue_date'      => date('Y-m-d'),
            'accounting_date' => (string) $period['date_end'],
            'doc_text'        => mb_substr($title, 0, 200),
            'vat_mode'        => 0,
            'notice'          => sprintf(
                'Zaúčtování podání DPH #%d (%s). Řádky sestavil Shipard ze snapshotu podání — doklad zkontrolujte a uzavřete.',
                (int) $filing['id'],
                (string) ($filing['name'] ?? ''),
            ),
            'docState'        => 10,
            'docStateMain'    => 1,
            'rows'            => $rows,
        ];
        if (!empty($prepared['registration']['tax_office_person'])) {
            $head['partner'] = (int) $prepared['registration']['tax_office_person'];
        }
        return $head;
    }

    /**
     * Zprávy podání + záznam o zaúčtování a varování builderu (JSON string —
     * dibi PHP pole pro `json` sloupec nepřevádí).
     *
     * @param array<string, mixed> $filing
     */
    private function messagesWith(array $filing, int $docId, VatReturnAccountingPlan $plan): string
    {
        $messages = [];
        $raw = $filing['messages'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $messages = array_values($decoded);
            }
        } elseif (is_array($raw)) {
            $messages = array_values($raw);
        }
        $messages[] = ['code' => self::MSG_ACCOUNTED, 'docId' => $docId, 'date' => date('Y-m-d')];
        foreach ($plan->warnings() as $warning) {
            $messages[] = ['code' => $warning['code'], 'docId' => $docId, 'message' => $warning['message']];
        }
        return (string) json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $context */
    private function saveFailure(
        string $message,
        DocumentResult $result,
        array $context,
        VatReturnAccountingPlan $plan,
    ): VatReturnAccountingResult {
        $errors = [];
        $validation = $result->getValidation();
        if ($validation !== null) {
            foreach ($validation->getErrors() as $e) {
                $errors[] = ['field' => (string) $e->column, 'code' => (string) ($e->code ?: 'INVALID'), 'message' => $e->message];
            }
        } elseif ($result->getErrorMessage() !== null) {
            $errors[] = ['field' => '', 'code' => $result->getDomainErrorCode() ?: 'ERROR', 'message' => $result->getErrorMessage()];
        }
        return VatReturnAccountingResult::failed('SAVE_FAILED', $message, $context, $plan, $errors);
    }

    // ── infrastruktura ──────────────────────────────────────────────────────

    private function gateway(string $table, TableDefinition $def): TableGateway
    {
        return new TransactionlessTableGateway(
            $table,
            $this->db,
            $this->documents,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->dispatcher,
            $def->docStates,
            $def,
        );
    }

    private function snapshots(): FilingSnapshotLoader
    {
        return $this->snapshots ??= new FilingSnapshotLoader($this->db);
    }

    private function settings(): SettingsStore
    {
        return $this->settings ??= new SettingsStore(new DataSourceConnection($this->db));
    }

    /** @return ?array{id: int, doc_type: string, doc_number: string, docState: int} */
    private function loadHead(int $headId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [doc_type], [doc_number], [docState] FROM [docs_core_heads] WHERE [id] = %i',
            $headId,
        );
        return $row !== null ? [
            'id'         => (int) $row['id'],
            'doc_type'   => (string) $row['doc_type'],
            'doc_number' => (string) $row['doc_number'],
            'docState'   => (int) $row['docState'],
        ] : null;
    }

    /** @return ?array{id: int, name: string, vat_registration: int, date_begin: string, date_end: string} */
    private function loadPeriod(int $periodId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [name], [vat_registration], [date_begin], [date_end]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            $periodId,
        );
        if ($row === null) {
            return null;
        }
        return [
            'id'               => (int) $row['id'],
            'name'             => (string) $row['name'],
            'vat_registration' => (int) $row['vat_registration'],
            'date_begin'       => self::isoDate($row['date_begin']),
            'date_end'         => self::isoDate($row['date_end']),
        ];
    }

    /** @return array<string, mixed> prázdné pole, když registrace chybí */
    private function loadRegistration(int $registrationId): array
    {
        if ($registrationId <= 0) {
            return [];
        }
        $row = $this->db->fetch(
            'SELECT [id], [vat_id], [country], [tax_office_person] FROM [economy_codebooks_vat_registrations]'
            . ' WHERE [id] = %i',
            $registrationId,
        );
        return $row !== null ? $row->toArray() : [];
    }

    private static function isoDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }
}
