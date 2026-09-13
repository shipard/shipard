<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\StructuredFields\StructuredFieldValidator;
use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Core\StructuredFields\StructuredSchema;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\FilingHeaderSchema;
use Shipard\Module\Economy\Vat\FilingRounding;
use Shipard\Module\Economy\Vat\FilingSnapshotLoader;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Shipard\Module\Economy\Vat\Xml\EpoXmlWriter;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Module\Economy\Vat\Xml\FilingXmlInputLoader;
use Shipard\Module\Economy\Vat\Xml\Kh1XmlWriter;
use Shipard\Module\Economy\Vat\Xml\ShvXmlWriter;
use Shipard\Module\Economy\Vat\Xml\VatXmlMapping;

/**
 * Import podaného podání ze starého systému (#55 D21, D32–D39).
 *
 * `import()` v jedné transakci: založí podání ve stavu Sestaveno s
 * `origin = imported` (Document sestaví snapshot composerem nad dnešními
 * doklady), u přiznání **přepíše podané hodnoty** řádků z původního XML
 * (rozdíl zaokrouhlené přesné vs. podané → `imported_row_mismatch`),
 * hlavičku předvyplněnou z profilu přepíše hodnotami věty D/P, u hlášení
 * jen porovná řádky writeru s XML (`imported_line_mismatch`) a všechno
 * zapíše do `messages`. Podání zůstane v 10, aby runner mohl nahrát
 * původní přílohy (`POST /_attachments/upload`, guard je po podání zamkne).
 *
 * `finish()` přechod do Podáno: přílohy bez druhu označí (`epo-xml` /
 * `epo-imported`), doplní `imported_files`; Document XML nevaliduje ani
 * negeneruje. Idempotentní — už podané podání je no-op.
 *
 * Co nejde předem: instance chybí, druh mimo config, chybí datum zjištění,
 * dodatečné bez podaného základu (diff by neměl proti čemu vzniknout
 * a další řetěz by ho bral jako plný stav), živý koncept v instanci.
 * Chybějící účetní doklad je jen varování (D37). Cokoli po založení =
 * rollback celé transakce.
 */
final class FilingImportService
{
    public const MSG_ROW_MISMATCH        = 'imported_row_mismatch';
    public const MSG_ROW_UNMAPPED        = 'imported_row_unmapped';
    public const MSG_LINE_MISMATCH       = 'imported_line_mismatch';
    public const MSG_LINE_COMPARE_FAILED = 'imported_line_compare_failed';
    public const MSG_WITHOUT_XML         = 'imported_without_xml';
    public const MSG_ORDER_IRREGULAR     = 'imported_order_irregular';
    public const MSG_KIND_MISMATCH       = 'imported_kind_mismatch';
    public const MSG_ACC_DOCUMENT_MISSING = 'imported_acc_document_missing';
    public const MSG_HEADER_INVALID      = 'imported_header_invalid';
    public const MSG_LEGACY              = 'imported_legacy';
    public const MSG_FILES               = 'imported_files';

    /** Slot mapování XML → klíč hodnoty `filedRows()` a sloupec snapshotu. */
    private const SLOTS = [
        'base'    => ['key' => 'base',       'column' => 'base_filed'],
        'full'    => ['key' => 'taxFull',    'column' => 'tax_full_filed'],
        'reduced' => ['key' => 'taxReduced', 'column' => 'tax_reduced_filed'],
    ];

    private const RETURN_RESULT_ROWS = [62, 63, 64, 65, 66];

    private const XML_MIME_TYPES = ['application/xml', 'text/xml'];

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        private readonly \Dibi\Connection $db,
        private readonly ?ConfigRuntime $config,
        private readonly ?DataSourceConfig $dsConfig,
        private readonly DocumentRegistry $documents,
        private readonly array $tables,
        private readonly ?DocumentEventDispatcher $dispatcher = null,
    ) {}

    /**
     * Založí importované podání (stav 10). `$dryRun` = totéž v transakci
     * s rollbackem: composer i porovnání proběhnou, nic nezůstane.
     *
     * @throws FilingImportException
     */
    public function import(FilingImportRequest $request, bool $dryRun = false): FilingImportResult
    {
        $definition = $this->tables[FilingDocument::TABLE] ?? null;
        if ($this->config === null || $definition === null) {
            throw new FilingImportException(
                'CONFIG_MISSING',
                'Chybí kompilovaná konfigurace nebo definice tabulky podání — spusťte ds-upgrade.',
                500,
            );
        }
        $type    = $request->reportType;
        $kind    = $request->filingKind;
        $mapping = VatXmlMapping::forReportType($this->config, $type);
        $outputs = VatOutputsMapping::fromConfig($this->config);
        if ($mapping === null || $outputs === null) {
            throw new FilingImportException(
                'CONFIG_MISSING',
                'Chybí kompilované mapování DPH (economy.vat.reports.cz / economy.vat.xml.cz) — spusťte ds-upgrade.',
                500,
            );
        }

        // Původní XML se čte první: typ písemnosti musí sedět a datum
        // zjištění z věty D je potřeba už pro validaci Documentu.
        $xml = null;
        $dp3 = null;
        if ($request->xml !== null) {
            try {
                $xml = EpoXmlDocument::fromString($request->xml);
                if ($xml->type !== $mapping->element()) {
                    throw EpoXmlReadException::typeMismatch($mapping->element(), $xml->type);
                }
                if ($type === 'return') {
                    $dp3 = (new Dp3XmlReader($mapping))->read($xml);
                }
            } catch (EpoXmlReadException $e) {
                throw new FilingImportException($e->reason, $e->getMessage(), 422);
            }
        }
        $dateFound = $request->dateFound ?? $xml?->dateFound((string) ($mapping->header()['vetaD'] ?? 'VetaD'));

        $period = $this->loadPeriod($request->reportPeriodId);
        if ($period === null) {
            throw new FilingImportException('PERIOD_NOT_FOUND', "Instance tvrzení #{$request->reportPeriodId} neexistuje.", 404);
        }
        if ((string) $period['report_type'] !== $type) {
            throw new FilingImportException(
                'PERIOD_NOT_FOUND',
                "Instance tvrzení #{$request->reportPeriodId} je typu {$period['report_type']}, import čeká {$type}.",
                404,
            );
        }

        $allowed = $outputs->filingKinds($type);
        if ($allowed !== [] && !in_array($kind, $allowed, true)) {
            throw new FilingImportException(
                'INVALID_KIND',
                "Druh podání '{$kind}' u typu tvrzení {$type} neexistuje (povolené: " . implode(', ', $allowed) . ').',
            );
        }
        $dateFoundRequired = $kind === FilingDocument::KIND_SUPPLEMENTARY
            || in_array($kind, $outputs->dateFoundRequiredFor($type), true);
        if ($dateFoundRequired && $dateFound === null) {
            throw new FilingImportException(
                'DATE_FOUND_REQUIRED',
                "Druh podání '{$kind}' vyžaduje datum zjištění důvodů — v požadavku ani ve větě D (d_zjist) není.",
                422,
                ['kind' => $kind],
            );
        }

        $periodId = (int) $period['id'];
        $hasFiled = $this->lastFiledFiling($periodId) !== null;
        if ($type === 'return'
            && $kind === FilingDocument::KIND_SUPPLEMENTARY
            && !$hasFiled
            && $outputs->supplementaryMode('return') === 'diff'
        ) {
            throw new FilingImportException(
                'PREVIOUS_FILING_MISSING',
                'Dodatečné přiznání nemá v instanci podaný základ — nejdřív importujte řádné (nebo opravné) podání.',
            );
        }
        $draft = $this->findDraft($periodId);
        if ($draft !== null) {
            throw new FilingImportException(
                'DRAFT_EXISTS',
                "Za instanci #{$periodId} je rozdělané podání #{$draft['filingId']} — dokončete ho (finish), nebo zrušte.",
                422,
                $draft,
            );
        }

        $warnings    = [];
        $preMessages = [];
        $accDocument = $this->resolveAccDocument($request->accDocumentId, $warnings, $preMessages);

        $data = [
            'report_period' => $periodId,
            'filing_kind'   => $kind,
            'origin'        => FilingDocument::ORIGIN_IMPORTED,
            'date_issue'    => $request->dateIssue ?? $request->dateFiled ?? date('Y-m-d'),
            'date_filed'    => $request->dateFiled,
            'date_found'    => $dateFound,
            'acc_document'  => $accDocument,
            'docState'      => FilingDocument::DOC_STATE_COMPOSED,
            'docStateMain'  => 1,
        ];
        if ($request->name !== null) {
            $data['name'] = mb_substr($request->name, 0, 80);
        }

        $this->db->begin();
        try {
            // afterPersist Documentu sestaví snapshot composerem — uvnitř
            // naší transakce (gateway vlastní neotvírá).
            $save = $this->gateway($definition)->saveDocument($data);
            if (!$save->isSuccess()) {
                $this->db->rollback();
                throw $this->saveFailure('Importované podání se nepodařilo založit.', $save);
            }
            $filingId = (int) ($save->getData()['id'] ?? 0);
            $filing   = $this->loadFiling($filingId);

            $imported = $preMessages;
            if ($request->legacy !== []) {
                $imported[] = ['code' => self::MSG_LEGACY] + $request->legacy;
            }
            if (FilingDocument::isOrderIrregular($kind, $hasFiled)) {
                $imported[] = ['code' => self::MSG_ORDER_IRREGULAR, 'kind' => $kind, 'hasFiled' => $hasFiled];
            }

            $mismatchRows   = 0;
            $lineMismatches = 0;
            if ($xml === null) {
                $imported[] = ['code' => self::MSG_WITHOUT_XML];
            } else {
                if ($dp3 !== null) {
                    $mismatchRows = $this->overrideReturnRows($filingId, $dp3, $imported);
                }
                $this->applyHeader($filingId, $filing, $xml, $mapping, $type, $imported);
                $this->checkForma($filing, $xml, $mapping, $kind, $imported);
                if ($type !== 'return') {
                    $lineMismatches = $this->compareLines($filingId, $xml, $mapping, $type, $imported);
                }
            }

            $this->db->update(FilingDocument::TABLE, [
                'messages' => self::encodeJson(array_merge(self::decodeList($filing['messages'] ?? null), $imported)),
            ])->where('id = %i', $filingId)->execute();

            if ($dryRun) {
                $this->db->rollback();
            } else {
                $this->db->commit();
            }
        } catch (FilingImportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return new FilingImportResult(
            filingId: $filingId,
            sequence: (int) ($filing['sequence'] ?? 0),
            mismatchRows: $mismatchRows,
            lineMismatches: $lineMismatches,
            flags: self::flags($imported),
            warnings: $warnings,
            messages: $imported,
            dryRun: $dryRun,
        );
    }

    /**
     * Přechod importovaného podání do Podáno. Přílohy nahrané mezi
     * `import` a `finish` dostanou druh (`epo-xml` u XML, jinak
     * `epo-imported`), aby je po podání chránil `FilingAttachmentGuard`.
     *
     * @return array{filingId: int, docState: int, files: int}
     * @throws FilingImportException
     */
    public function finish(int $filingId): array
    {
        $definition = $this->tables[FilingDocument::TABLE] ?? null;
        if ($this->config === null || $definition === null) {
            throw new FilingImportException('CONFIG_MISSING', 'Chybí konfigurace nebo definice tabulky podání.', 500);
        }
        $filing = $this->loadFiling($filingId);
        if ($filing === null) {
            throw new FilingImportException('NOT_FOUND', "Podání #{$filingId} neexistuje.", 404);
        }
        if ((string) $filing['origin'] !== FilingDocument::ORIGIN_IMPORTED) {
            throw new FilingImportException('NOT_IMPORTED', "Podání #{$filingId} není importované — podává se z formuláře.");
        }
        $state = (int) $filing['docState'];
        $files = $this->attachments($filingId);
        if ($state === FilingDocument::DOC_STATE_FILED) {
            return ['filingId' => $filingId, 'docState' => $state, 'files' => count($files)];
        }
        if ($state !== FilingDocument::DOC_STATE_COMPOSED) {
            throw new FilingImportException('INVALID_DOC_STATE', "Podání #{$filingId} je ve stavu {$state} — dokončit lze jen koncept.");
        }

        $this->db->begin();
        try {
            foreach ($files as $file) {
                $this->tagAttachment($file);
            }
            $messages   = self::decodeList($filing['messages'] ?? null);
            $messages[] = ['code' => self::MSG_FILES, 'count' => count($files)];

            // Částečný payload: hlavička z roku podání by při plném uložení
            // procházela validací proti dnešnímu schématu.
            $save = $this->gateway($definition)->saveDocument([
                'id'       => $filingId,
                'docState' => FilingDocument::DOC_STATE_FILED,
                'origin'   => FilingDocument::ORIGIN_IMPORTED,
                'messages' => self::encodeJson($messages),
            ]);
            if (!$save->isSuccess()) {
                $this->db->rollback();
                throw $this->saveFailure('Importované podání se nepodařilo podat.', $save);
            }
            $this->db->commit();
        } catch (FilingImportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return ['filingId' => $filingId, 'docState' => FilingDocument::DOC_STATE_FILED, 'files' => count($files)];
    }

    // ── Kroky importu ───────────────────────────────────────────────────────

    /**
     * Podané hodnoty přiznání z XML místo zaokrouhlených přesných (D33).
     * Porovnává se jen ve slotech, které formulář na řádku má; řádek, který
     * composer nevyrobil, se založí s nulovými přesnými hodnotami.
     *
     * @param list<array<string, mixed>> $imported
     * @return int počet řádků s rozdílem
     */
    private function overrideReturnRows(int $filingId, Dp3XmlData $dp3, array &$imported): int
    {
        $composed   = (new FilingSnapshotLoader($this->db))->filedRows($filingId);
        $mismatches = 0;
        $empty      = ['base' => 0.0, 'taxFull' => 0.0, 'taxReduced' => 0.0];

        foreach ($dp3->rows as $row => $filed) {
            $slots   = $dp3->slots[$row] ?? [];
            $current = $composed[$row] ?? $empty;
            $update  = [];
            $differs = false;
            foreach ($slots as $slot) {
                $key = self::SLOTS[$slot]['key'];
                $update[self::SLOTS[$slot]['column']] = $filed[$key];
                if (abs($filed[$key] - $current[$key]) >= 0.005) {
                    $differs = true;
                }
            }
            if ($differs) {
                $mismatches++;
                $imported[] = [
                    'code'     => self::MSG_ROW_MISMATCH,
                    'row'      => $row,
                    'composed' => self::slotValues($current, $slots),
                    'filed'    => self::slotValues($filed, $slots),
                ];
            }
            if ($update === []) {
                continue;
            }
            if (isset($composed[$row])) {
                $this->db->update('economy_vat_filing_return_rows', $update)
                    ->where('filing = %i AND [row] = %i', $filingId, $row)->execute();
            } elseif ($differs) {
                $this->db->insert('economy_vat_filing_return_rows', $update + [
                    'filing'      => $filingId,
                    'row'         => $row,
                    'is_computed' => in_array($row, FilingRounding::COMPUTED_ROWS, true) ? 1 : 0,
                    'base'        => 0.0,
                    'tax_full'    => 0.0,
                    'tax_reduced' => 0.0,
                ])->execute();
            }
        }

        foreach ($dp3->unmapped as $attribute) {
            $imported[] = ['code' => self::MSG_ROW_UNMAPPED] + $attribute;
        }

        $this->refreshReturnResult($filingId);
        return $mismatches;
    }

    /** `result.return.row62–66` čte composer z podaných hodnot — po přepisu znovu. */
    private function refreshReturnResult(int $filingId): void
    {
        $filing = $this->loadFiling($filingId);
        $result = self::decodeMap($filing['result'] ?? null);
        $filed  = (new FilingSnapshotLoader($this->db))->filedRows($filingId);
        foreach (self::RETURN_RESULT_ROWS as $row) {
            $result['return']['row' . $row] = $filed[$row]['taxFull'] ?? 0.0;
        }
        $this->db->update(FilingDocument::TABLE, ['result' => self::encodeJson($result)])
            ->where('id = %i', $filingId)->execute();
    }

    /**
     * Hlavička: profil podatele (composer) přepsaný tím, co bylo doopravdy
     * podáno (věty D a P), v mezích dnešního schématu. Pole, které schéma
     * dnes nezná nebo nepustí (zaniklý úřad, cizí enum), zůstane z profilu
     * a zapíše se `imported_header_invalid` — jinak by formulář podání
     * odmítl i editaci poznámky.
     *
     * @param array<string, mixed> $filing
     * @param list<array<string, mixed>> $imported
     */
    private function applyHeader(
        int $filingId,
        array $filing,
        EpoXmlDocument $xml,
        VatXmlMapping $mapping,
        string $type,
        array &$imported,
    ): void {
        $cfgItem = FilingHeaderSchema::forReportType($type);
        $schema  = $cfgItem !== null ? StructuredSchema::fromCfgItem($this->config, $cfgItem) : null;
        if ($schema === null) {
            return;
        }
        $header    = $mapping->header();
        $vetaD     = $xml->sentence((string) ($header['vetaD'] ?? 'VetaD')) ?? [];
        $vetaP     = $xml->sentence((string) ($header['vetaP'] ?? 'VetaP')) ?? [];
        $yesNo     = $header['yesNoFields'] ?? [];
        $countries = $mapping->countryNameFields();

        $base    = StructuredFieldValues::decode($filing['header'] ?? null) ?? [];
        $patched = $base;
        foreach ([$vetaD, $vetaP] as $attributes) {
            foreach ($attributes as $name => $value) {
                $field = $schema->field((string) $name);
                $value = trim((string) $value);
                if ($field === null || $value === '') {
                    continue;
                }
                if (in_array($name, $countries, true)) {
                    $code = $mapping->countryCode($value);
                    if ($code !== null) {
                        $patched[$name] = $code;
                    }
                    continue;
                }
                if ($field->type === 'boolean' || in_array($name, $yesNo, true)) {
                    $patched[$name] = strtoupper($value) === 'A';
                    continue;
                }
                if ($field->type === 'date') {
                    $iso = EpoXmlDocument::isoDate($value);
                    if ($iso !== null) {
                        $patched[$name] = $iso;
                    }
                    continue;
                }
                $patched[$name] = $value;
            }
        }
        // `trans` composer odvodil z ř. 62 před přepisem; když ho XML nenese, dopočítat znovu.
        if ($type === 'return' && !isset($vetaD['trans']) && $schema->field('trans') !== null) {
            $row62 = (new FilingSnapshotLoader($this->db))->filedRows($filingId)[62]['taxFull'] ?? 0.0;
            $patched['trans'] = abs($row62) >= 0.005;
        }

        $validation = StructuredFieldValidator::validate(FilingHeaderSchema::COLUMN, $patched, $schema, $this->config);
        foreach ($validation['errors'] as $error) {
            $fieldId = $this->fieldIdOf($error->column, $schema);
            if ($fieldId === null) {
                continue;
            }
            $imported[] = [
                'code'    => self::MSG_HEADER_INVALID,
                'field'   => $fieldId,
                'value'   => $patched[$fieldId] ?? null,
                'message' => $error->message,
            ];
            if (array_key_exists($fieldId, $base)) {
                $patched[$fieldId] = $base[$fieldId];
            } else {
                unset($patched[$fieldId]);
            }
        }
        if ($validation['errors'] !== []) {
            $validation = StructuredFieldValidator::validate(FilingHeaderSchema::COLUMN, $patched, $schema, $this->config);
        }
        $values = $validation['errors'] === [] ? $validation['value'] : $patched;

        $this->db->update(FilingDocument::TABLE, ['header' => StructuredFieldValues::encode($values, $schema)])
            ->where('id = %i', $filingId)->execute();
    }

    /** Id pole schématu z `column` chyby validátoru (`header.c_ufo` → `c_ufo`). */
    private function fieldIdOf(string $column, StructuredSchema $schema): ?string
    {
        foreach (array_keys($schema->fields) as $fieldId) {
            if (StructuredSchema::virtualColumn(FilingHeaderSchema::COLUMN, (string) $fieldId) === $column) {
                return (string) $fieldId;
            }
        }
        return null;
    }

    /**
     * Forma podání v XML vs. druh, který určil runner (D35) — nesoulad se
     * zapíše, druh se nemění (XML není zdroj druhu).
     *
     * @param array<string, mixed> $filing
     * @param list<array<string, mixed>> $imported
     */
    private function checkForma(array $filing, EpoXmlDocument $xml, VatXmlMapping $mapping, string $kind, array &$imported): void
    {
        $filed = $xml->forma($mapping->formaAttribute(), (string) ($mapping->header()['vetaD'] ?? 'VetaD'));
        if ($filed === null) {
            return;
        }
        $previousId   = (int) ($filing['previous_filing'] ?? 0);
        $previousKind = $previousId > 0
            ? (string) $this->db->fetchSingle('SELECT [filing_kind] FROM %n WHERE [id] = %i', FilingDocument::TABLE, $previousId)
            : null;
        try {
            $expected = $mapping->forma($kind, $previousKind !== '' ? $previousKind : null);
        } catch (\DomainException) {
            return;
        }
        if ($filed !== $expected) {
            $imported[] = ['code' => self::MSG_KIND_MISMATCH, 'kind' => $kind, 'expected' => $expected, 'filed' => $filed];
        }
    }

    /**
     * Hlášení: řádky composeru (writer nad čerstvým snapshotem, bez
     * validace a XSD) proti původnímu XML — jen záznam, řádky se nepřepisují.
     *
     * @param list<array<string, mixed>> $imported
     * @return int počet rozdílů
     */
    private function compareLines(int $filingId, EpoXmlDocument $xml, VatXmlMapping $mapping, string $type, array &$imported): int
    {
        try {
            $input    = (new FilingXmlInputLoader($this->db))->load($filingId);
            $writer   = $this->writer($mapping, $type);
            $composed = EpoXmlDocument::fromString($writer->write($input));
            $differences = (new EpoXmlLineComparer($mapping))->compare($xml, $composed);
        } catch (\DomainException | \RuntimeException $e) {
            $imported[] = ['code' => self::MSG_LINE_COMPARE_FAILED, 'reason' => $e->getMessage()];
            return 0;
        }
        foreach ($differences as $difference) {
            $imported[] = ['code' => self::MSG_LINE_MISMATCH] + $difference;
        }
        return count($differences);
    }

    private function writer(VatXmlMapping $mapping, string $type): EpoXmlWriter
    {
        return match ($type) {
            'cs'    => new Kh1XmlWriter($mapping),
            'rs'    => new ShvXmlWriter($mapping),
            default => throw new \DomainException("Pro typ tvrzení '{$type}' není porovnání řádků"),
        };
    }

    /**
     * Účetní doklad přiznání ze starého systému (D37): jen živý `cmnbkp`;
     * jinak varování a FK zůstane prázdné (doplní se akcí Zaúčtovat).
     *
     * @param list<string> $warnings
     * @param list<array<string, mixed>> $messages
     */
    private function resolveAccDocument(?int $docId, array &$warnings, array &$messages): ?int
    {
        if ($docId === null) {
            return null;
        }
        $head = $this->db->fetch(
            'SELECT [id], [doc_type], [docState] FROM [docs_core_heads] WHERE [id] = %i',
            $docId,
        );
        $reason = match (true) {
            $head === null => 'neexistuje',
            (string) $head['doc_type'] !== FilingDocument::ACC_DOCUMENT_TYPE => "je typu {$head['doc_type']}, ne cmnbkp",
            in_array((int) $head['docState'], FilingDocument::ACC_DOCUMENT_DEAD_STATES, true) => "je ve stavu {$head['docState']}",
            default => null,
        };
        if ($reason === null) {
            return $docId;
        }
        $warnings[] = "Účetní doklad #{$docId} {$reason} — podání zůstane bez zaúčtování.";
        $messages[] = ['code' => self::MSG_ACC_DOCUMENT_MISSING, 'docId' => $docId, 'reason' => $reason];
        return null;
    }

    // ── Přílohy ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> živé přílohy podání */
    private function attachments(int $filingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [mime_type], [metadata] FROM [core_attachments_files]'
            . ' WHERE [table_id] = %i AND [record_id] = %i AND [is_deleted] = 0 ORDER BY [id]',
            FilingFilesService::TABLE_ID, $filingId,
        );
        return array_map(static fn ($row): array => $row->toArray(), $rows);
    }

    /** @param array<string, mixed> $file */
    private function tagAttachment(array $file): void
    {
        if (FilingFilesService::kindOf($file) !== null) {
            return;
        }
        $isXml = in_array(strtolower((string) ($file['mime_type'] ?? '')), self::XML_MIME_TYPES, true)
            || str_ends_with(strtolower((string) ($file['name'] ?? '')), '.xml');
        $metadata = self::decodeMap($file['metadata'] ?? null);
        $metadata['kind'] = $isXml ? FilingFilesService::KIND_XML : FilingFilesService::KIND_IMPORTED;
        $this->db->update('core_attachments_files', [
            'metadata' => self::encodeJson($metadata),
            'modified' => date('Y-m-d H:i:s'),
        ])->where('id = %i', (int) $file['id'])->execute();
    }

    // ── DB ──────────────────────────────────────────────────────────────────

    /** @return ?array<string, mixed> */
    private function loadPeriod(int $periodId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [report_type], [docState] FROM [economy_vat_report_periods] WHERE [id] = %i',
            $periodId,
        );
        return $row !== null ? $row->toArray() : null;
    }

    /** @return ?array<string, mixed> */
    private function loadFiling(int $filingId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        return $row !== null ? $row->toArray() : null;
    }

    private function lastFiledFiling(int $periodId): ?int
    {
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM %n WHERE [report_period] = %i AND [docState] = %i ORDER BY [sequence] DESC, [id] DESC LIMIT 1',
            FilingDocument::TABLE, $periodId, FilingDocument::DOC_STATE_FILED,
        );
        return $id !== null && $id !== false ? (int) $id : null;
    }

    /** @return ?array{filingId: int, origin: string, legacy: array<string, mixed>} */
    private function findDraft(int $periodId): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id], [origin], [messages] FROM %n WHERE [report_period] = %i AND [docState] = %i ORDER BY [id] LIMIT 1',
            FilingDocument::TABLE, $periodId, FilingDocument::DOC_STATE_COMPOSED,
        );
        if ($row === null) {
            return null;
        }
        $legacy = [];
        foreach (self::decodeList($row['messages']) as $message) {
            if (($message['code'] ?? null) === self::MSG_LEGACY) {
                unset($message['code']);
                $legacy = $message;
            }
        }
        return ['filingId' => (int) $row['id'], 'origin' => (string) $row['origin'], 'legacy' => $legacy];
    }

    private function gateway(TableDefinition $definition): TableGateway
    {
        return new TransactionlessTableGateway(
            FilingDocument::TABLE,
            $this->db,
            $this->documents,
            $definition->childTables,
            $this->config,
            $this->dsConfig,
            $this->dispatcher,
            $definition->docStates,
            $definition,
        );
    }

    private function saveFailure(string $message, DocumentResult $result): FilingImportException
    {
        $validation = $result->getValidation();
        if ($validation !== null) {
            $details = [];
            foreach ($validation->getErrors() as $e) {
                $details[] = ['field' => (string) $e->column, 'code' => (string) ($e->code ?: 'INVALID'), 'message' => $e->message];
            }
            return new FilingImportException('VALIDATION_ERROR', $message, 422, $details);
        }
        return new FilingImportException(
            $result->isDomainError() ? ($result->getDomainErrorCode() ?: 'FILING_IMPORT_FAILED') : 'FILING_IMPORT_FAILED',
            $message . ' ' . (string) $result->getErrorMessage(),
            $result->isDomainError() ? 422 : 500,
        );
    }

    // ── Pomocné ─────────────────────────────────────────────────────────────

    /**
     * @param array{base: float, taxFull: float, taxReduced: float} $values
     * @param list<string> $slots
     * @return array<string, float>
     */
    private static function slotValues(array $values, array $slots): array
    {
        $out = [];
        foreach ($slots as $slot) {
            $out[$slot] = round($values[self::SLOTS[$slot]['key']], 2);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<string> kódy zpráv bez řádkových detailů, každý jednou
     */
    private static function flags(array $messages): array
    {
        $flags = [];
        foreach ($messages as $message) {
            $code = (string) ($message['code'] ?? '');
            if ($code === '' || in_array($code, [self::MSG_ROW_MISMATCH, self::MSG_LINE_MISMATCH], true)) {
                continue;
            }
            $flags[$code] = true;
        }
        return array_keys($flags);
    }

    /** @return list<array<string, mixed>> */
    private static function decodeList(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array<string, mixed> */
    private static function decodeMap(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
