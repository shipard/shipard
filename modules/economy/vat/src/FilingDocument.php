<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Podání DPH (`economy_vat_filings`, issue #55 D14–D18).
 *
 * Podání je kotvené na instanci tvrzení — typ, registrace i rozsah plynou
 * z ní (`report_type` je jen denormalizace pro viewer a indexy, Document ji
 * plní a hlídá shodu). Druhy podání povolené u typu výstupu, povinnost data
 * zjištění důvodů a režim dodatečného podání říká mapovací config země
 * (`VatOutputsMapping`) — legislativa nepatří do PHP.
 *
 * Pravidla pořadí (D18): řádné podání je za tvrzení jen jedno (dokud žádné
 * není podané), opravné / dodatečné / následné navazují na už podané
 * tvrzení a v instanci smí být nejvýš jeden živý koncept.
 *
 * Lifecycle: 10 sestaveno → 40 podáno | 90 zrušeno. Přechod do 40 doplní
 * datum podání a vyžaduje sestavený snapshot; **podané podání je
 * immutable** — smí se u něj změnit jen poznámka a nedá se smazat. Zrušené
 * podání je zmrazené stejně (stav je `readOnly`); zrušený koncept se
 * nevrací, sestaví se nový.
 *
 * Import ze starého systému (`origin = imported`, #55 D34–D37) zrcadlí
 * cizí podání: dodaný název se neskládá, účetní doklad smí dostat už
 * koncept, pořadí druhů se nevynucuje a přechod do Podáno XML nevaliduje
 * ani negeneruje — původní přílohy jsou pravda. Zakládá ho výhradně
 * `Import\FilingImportService`.
 *
 * DB dotazy jsou v protected metodách, aby šly v testech přepsat bez
 * mockování dibi.
 */
class FilingDocument extends Document
{
    public const DOC_STATE_COMPOSED  = 10;
    public const DOC_STATE_FILED     = 40;
    public const DOC_STATE_CANCELLED = 90;

    public const TABLE = 'economy_vat_filings';

    /**
     * Tabulky snapshotu — vlastníkem je podání, plní je výhradně
     * FilingComposer. Bez FK constraintů (Shipard je nemá), takže úklid
     * po smazání podání je na `beforeDelete`.
     */
    public const SNAPSHOT_TABLES = [
        'economy_vat_filing_items',
        'economy_vat_filing_return_rows',
        'economy_vat_filing_cs_rows',
        'economy_vat_filing_rs_rows',
    ];

    /** Kanonický pořadový klíč druhu — řádné je vždy první. */
    public const KIND_REGULAR = 'regular';

    /** Dodatečné přiznání: rozdíly proti předchozímu podání + ř. 66. */
    public const KIND_SUPPLEMENTARY = 'supplementary';

    /** Původ podání (`origin`, #55 D34): sestaveno v Shipardu. */
    public const ORIGIN_COMPOSED = 'composed';

    /** Původ podání: importováno ze starého systému — viz docblock třídy. */
    public const ORIGIN_IMPORTED = 'imported';

    /** Typ účetního dokladu přiznání (`acc_document`, #55 D28–D31). */
    public const ACC_DOCUMENT_TYPE = 'cmnbkp';

    /**
     * Stavy dokladu, ve kterých účetní doklad přiznání už „nežije" (Storno,
     * Smazáno) — jen tehdy se smí `acc_document` přepsat novým dokladem.
     */
    public const ACC_DOCUMENT_DEAD_STATES = [30, 90];

    /**
     * Sloupce, které po podání (a po zrušení) drží hodnotu z okamžiku
     * přechodu. Mimo ně zbývá jen `note` — jediné, co smí přibýt k už
     * podanému tvrzení.
     */
    private const FROZEN_COLUMNS = [
        'report_period', 'report_type', 'filing_kind', 'origin', 'sequence', 'name',
        'date_issue', 'date_filed', 'date_found', 'previous_filing',
        'header', 'result', 'messages',
    ];

    /**
     * Snapshot je potřeba (pře)sestavit — nastaví `beforeSave`, provede
     * `afterPersist` uvnitř save transakce.
     */
    private bool $composeNeeded = false;

    /**
     * Hlavička podání má jinou sadu polí pro přiznání, kontrolní a souhrnné
     * hlášení (#55 Fáze 3, X2). Zrcadlo je `FilingsForm::structuredSchemaFor()`
     * — obě delegují na `FilingHeaderSchema`, aby se formulář kreslil podle
     * téhož schématu, podle jakého se ukládá.
     */
    public function structuredSchemaFor(string $column, array $data): ?string
    {
        if ($column !== FilingHeaderSchema::COLUMN) {
            return null;
        }
        return FilingHeaderSchema::forReportType($this->resolveReportType($data));
    }

    /**
     * Typ tvrzení pro zapisovaná data. Hook běží **před** `beforeSave()`,
     * takže denormalizovaný `report_type` v payloadu ještě být nemusí —
     * dohledá se přes instanci, u částečné aktualizace přes uložený řádek.
     *
     * @param array<string, mixed> $data
     */
    protected function resolveReportType(array $data): ?string
    {
        $type = (string) ($data['report_type'] ?? '');
        if ($type !== '') {
            return $type;
        }

        $periodId = (int) ($data['report_period'] ?? 0);
        if ($periodId <= 0 && !empty($data['id'])) {
            $current = $this->loadCurrent((int) $data['id']);
            $type    = (string) ($current['report_type'] ?? '');
            if ($type !== '') {
                return $type;
            }
            $periodId = (int) ($current['report_period'] ?? 0);
        }
        if ($periodId <= 0) {
            return null;
        }

        $period = $this->loadReportPeriod($periodId);
        return $period !== null ? (string) ($period['report_type'] ?? '') : null;
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $isNew = empty($data['id']);
        // Uložení může být částečné (API pošle jen to, co mění) — hodnoty,
        // které v payloadu nejsou, drží uložený řádek. Bez tohoto by
        // částečná aktualizace podaného podání vypadala jako koncept
        // a přepsala mu název i základ pro rozdíly.
        $effective = array_merge($originalData ?? [], $data);

        $state    = (int) ($effective['docState'] ?? self::DOC_STATE_COMPOSED);
        $oldState = $originalData !== null ? (int) ($originalData['docState'] ?? 0) : 0;

        $periodId = (int) ($effective['report_period'] ?? 0);
        $period   = $periodId > 0 ? $this->loadReportPeriod($periodId) : null;
        if ($period !== null) {
            $data['report_type'] = (string) $period['report_type'];
        }

        if ($isNew) {
            if (empty($data['date_issue'])) {
                $data['date_issue'] = date('Y-m-d');
            }
            $data['sequence'] = $this->nextSequence($periodId);
        }

        $kind     = (string) ($effective['filing_kind'] ?? '');
        $sequence = (int) ($data['sequence'] ?? $effective['sequence'] ?? 1);

        // Základ pro rozdíly drží koncept aktuální; po podání se zmrazí.
        if ($state === self::DOC_STATE_COMPOSED) {
            $data['previous_filing'] = $kind === self::KIND_REGULAR
                ? null
                : $this->lastFiledFiling($periodId, $isNew ? null : (int) $data['id']);
        }

        // Datum podání: default dnes, editovatelné před přechodem.
        if ($state === self::DOC_STATE_FILED
            && $oldState !== self::DOC_STATE_FILED
            && empty($effective['date_filed'])
        ) {
            $data['date_filed'] = date('Y-m-d');
        }

        // Název se skládá jen u konceptu (nebo když ještě žádný není).
        // Podanému podání by ho jinak přepsal každý save a při jiném
        // jazyce požadavku i přeložil, ačkoli je to zmrazený údaj. Import
        // nese název ze starého systému (#55 D35) — ten se neskládá.
        $keepName = $this->isImported($effective) && !empty($effective['name']);
        if ($period !== null && !$keepName && ($state === self::DOC_STATE_COMPOSED || empty($effective['name']))) {
            $data['name'] = $this->composeName((string) ($period['name'] ?? ''), $kind, $sequence);
        }

        // Snapshot se sestavuje u nového konceptu a při každé změně, která
        // mění jeho obsah. Přechod do Podáno ani editace poznámky ho
        // nepřepočítávají — podává se to, co bylo sestavené.
        $this->composeNeeded = $state === self::DOC_STATE_COMPOSED && (
            $isNew
            || $originalData === null
            || ($originalData['result'] ?? null) === null
            || (int) ($originalData['report_period'] ?? 0) !== $periodId
            || (string) ($originalData['filing_kind'] ?? '') !== $kind
            || (int) ($originalData['previous_filing'] ?? 0) !== (int) ($data['previous_filing'] ?? 0)
        );

    }

    /**
     * Uvnitř save transakce po zápisu hlavičky: snapshot musí být atomický
     * s podáním, ke kterému patří (kód DPH bez mapování = výjimka
     * a rollback celého uložení).
     */
    public function afterPersist(array $data): void
    {
        if (!$this->composeNeeded || $this->db === null || empty($data['id'])) {
            return;
        }
        $this->compose((int) $data['id']);
    }

    protected function compose(int $filingId): void
    {
        if ($this->db === null) {
            return;
        }
        (new FilingComposer($this->db, $this->config))->compose($filingId);
    }

    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        $selfId       = !empty($data['id']) ? (int) $data['id'] : null;
        $current      = $selfId !== null ? $this->loadCurrent($selfId) : null;
        $currentState = $current !== null ? (int) ($current['docState'] ?? 0) : 0;
        // Efektivní hodnoty: co payload neposlal, drží uložený řádek
        // (explicitní null v payloadu je záměrné vymazání, proto merge).
        $effective = array_merge($current ?? [], $data);

        $periodId = (int) ($effective['report_period'] ?? 0);
        if ($periodId <= 0) {
            $result->addError('report_period', 'Daňové tvrzení je povinné', 'required');
        }
        $kind = (string) ($effective['filing_kind'] ?? '');
        if ($kind === '') {
            $result->addError('filing_kind', 'Druh podání je povinný', 'required');
        }
        if (!$result->isValid()) {
            return $result;
        }

        $period = $this->loadReportPeriod($periodId);
        if ($period === null) {
            $result->addError('report_period', 'Daňové tvrzení neexistuje', 'invalid_value');
            return $result;
        }
        if ((int) ($period['docState'] ?? 0) === self::DOC_STATE_CANCELLED) {
            $result->addError('report_period', 'Za zrušené daňové tvrzení nelze podat.', 'invalid_value');
            return $result;
        }

        $type         = (string) ($period['report_type'] ?? '');
        $incomingType = (string) ($data['report_type'] ?? '');
        if ($incomingType !== '' && $incomingType !== $type) {
            $result->addError(
                'report_type',
                "Typ podání ({$incomingType}) neodpovídá typu tvrzení ({$type}).",
                'type_mismatch',
            );
            return $result;
        }

        $state    = (int) ($effective['docState'] ?? self::DOC_STATE_COMPOSED);
        $imported = $this->isImported($effective);

        // Podané (i zrušené) podání je zmrazené — jediná povolená změna je
        // poznámka. Kontrola běží před vším ostatním: u zmrazeného záznamu
        // nemá smysl řešit pravidla pořadí.
        if (in_array($currentState, [self::DOC_STATE_FILED, self::DOC_STATE_CANCELLED], true)) {
            $message = $currentState === self::DOC_STATE_FILED
                ? 'Podané podání už nelze změnit — oprava se podává jako nové podání jiného druhu.'
                : 'Zrušené podání už nelze změnit — sestavte nové.';

            // Účetní doklad přiznání (#55 F4b) není zmrazený, ale má vlastní
            // pravidla: jen u podaného podání, jen z NULL nebo z mrtvého
            // dokladu, jen na živý cmnbkp. Zaúčtování zároveň zapisuje
            // záznam do zpráv podání — jediná povolená změna `messages`.
            $frozen = self::FROZEN_COLUMNS;
            if (array_key_exists('acc_document', $data)
                && $this->normalizeValue($data['acc_document']) !== $this->normalizeValue($current['acc_document'] ?? null)
            ) {
                $error = $currentState === self::DOC_STATE_CANCELLED
                    ? ['Zrušenému podání nelze přiřadit účetní doklad.', 'immutable']
                    : $this->accDocumentError($data['acc_document'], $current['acc_document'] ?? null);
                if ($error !== null) {
                    $result->addError('acc_document', $error[0], $error[1]);
                } else {
                    $frozen = array_values(array_diff($frozen, ['messages']));
                }
            }

            foreach ($this->changedFrozenColumns($data, $current ?? [], $frozen) as $column) {
                $result->addError($column, $message, 'immutable');
            }
            return $result;
        }

        // Koncept účetní doklad nemá — vzniká až akcí nad podaným podáním.
        // Výjimka je import (#55 D37): starý systém měl doklad k podání,
        // které se teprve zakládá, takže FK smí do konceptu — ale jen na
        // živý cmnbkp, stejně jako u podaného.
        if (!empty($data['acc_document'])) {
            $error = $imported
                ? $this->accDocumentError($data['acc_document'], $current['acc_document'] ?? null)
                : ['Účetní doklad lze přiřadit jen k podanému podání.', 'invalid_state'];
            if ($error !== null) {
                $result->addError('acc_document', $error[0], $error[1]);
                return $result;
            }
        }

        $this->validateFilingKind($result, $effective, $type, $kind);
        if (!$result->isValid()) {
            return $result;
        }

        if ($state !== self::DOC_STATE_CANCELLED) {
            $this->validateOrder($result, $periodId, $selfId, $state, $kind, $imported);
        }

        // Podat lze jen sestavené podání — snapshot je to, co se podává.
        if ($state === self::DOC_STATE_FILED && $currentState !== self::DOC_STATE_FILED) {
            $composed = $current !== null
                && ($current['result'] ?? null) !== null
                && $current['result'] !== '';
            if (!$composed) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    'Podání ještě nemá sestavený snapshot — nejdřív ho přepočítejte.',
                    'not_composed',
                );
            } elseif ($selfId !== null && !$imported) {
                // Soubor pro daňový portál vzniká při podání (#55 X6), takže
                // se nedovyplněná hlavička musí ukázat **teď** — po přechodu
                // je podání zmrazené a opravit by ho šlo jen novým podáním.
                // Import soubory negeneruje (původní XML je pravda), hlavičku
                // z roku 2016 by dnešní pravidla ani nemusela pustit.
                $this->validateXml($result, $selfId);
            }
        }

        return $result;
    }

    /**
     * Původ podání z efektivních hodnot (payload + uložený řádek) — u
     * částečné aktualizace ho nese jen DB.
     *
     * @param array<string, mixed> $effective
     */
    private function isImported(array $effective): bool
    {
        return (string) ($effective['origin'] ?? '') === self::ORIGIN_IMPORTED;
    }

    /**
     * Porušuje druh podání pořadí D18? Řádné je jen jedno (dokud není nic
     * podané), ostatní druhy navazují na podané. U sestaveného podání je to
     * chyba, u importu jen zpráva `imported_order_irregular` — proto je
     * pravidlo veřejné a sdílené s `Import\FilingImportService`.
     */
    public static function isOrderIrregular(string $kind, bool $hasFiled): bool
    {
        return $kind === self::KIND_REGULAR ? $hasFiled : !$hasFiled;
    }

    /**
     * Kontrola, že z podání půjde vyrobit soubor pro EPO. Chyby jsou
     * field-level na `header.*`, takže je formulář ukáže u příslušného
     * pole. Nedostupné mapování (nezkompilovaný config) přechod neblokuje —
     * degraduje se stejně jako živé reporty.
     */
    protected function validateXml(ValidationResult $result, int $filingId): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $input   = (new Xml\FilingXmlInputLoader($this->db))->load($filingId);
            $mapping = Xml\VatXmlMapping::forReportType($this->config, $input->reportType);
            if ($mapping === null) {
                return;
            }
            foreach ((new Xml\FilingXmlValidator($mapping))->validate($input) as $error) {
                $result->addError($error->column, $error->message, $error->code);
            }
        } catch (\DomainException $e) {
            $result->addError(ValidationError::FIELD_FORM, $e->getMessage(), 'xml_invalid');
        }
    }

    /**
     * Po podání se dogenerují soubory pro daňový portál, pokud chybí
     * (#55 X6). Běží **po commitu**: obsah podání je už zmrazený a případné
     * selhání infrastruktury nesmí vrátit přechod zpět — soubory jde
     * vyrobit znovu akcí „Vytvořit soubory".
     */
    public function afterSave(array $data): void
    {
        if ($this->db === null
            || (int) ($data['docState'] ?? 0) !== self::DOC_STATE_FILED
            || empty($data['id'])
        ) {
            return;
        }
        // Import soubory negeneruje — původní přílohy jsou pravda (#55 D34).
        // Payload přechodu může být částečný, původ pak nese uložený řádek.
        $origin = $data['origin'] ?? $this->loadCurrent((int) $data['id'])['origin'] ?? null;
        if ((string) $origin === self::ORIGIN_IMPORTED) {
            return;
        }
        $this->generateFiles((int) $data['id']);
    }

    /** Seam pro testy — bez DS adresáře se soubory neukládají. */
    protected function generateFiles(int $filingId): void
    {
        if ($this->db === null || $this->dsConfig === null) {
            return;
        }

        try {
            $service = Xml\FilingFilesFactory::create($this->db, $this->config, $this->dsConfig);
            if (!$service->hasXml($filingId)) {
                $service->generate($filingId);
            }
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, "Podání #{$filingId}: soubory pro EPO se nepodařilo vytvořit");
        }
    }

    /**
     * Tvrdé smazání (DELETE). Podané podání je trvalý záznam — smazat se
     * nedá. U konceptu a zrušeného podání se s hlavičkou uklidí i snapshot;
     * `previous_filing` na ně nikdy nemíří (ukazuje vždy na podané podání),
     * takže po smazání nezůstane visící odkaz.
     */
    public function beforeDelete(array $data): void
    {
        if ((int) ($data['docState'] ?? 0) === self::DOC_STATE_FILED) {
            throw new \DomainException(
                'Podané podání nelze smazat — je to trvalý záznam o tom, co bylo za období podáno.'
                . ' Opravu podejte jako nové podání (opravné, dodatečné nebo následné).',
            );
        }
        if (!empty($data['id'])) {
            $this->deleteSnapshot((int) $data['id']);
        }
    }

    /**
     * Druh podání a datum zjištění důvodů dle configu země. Bez
     * kompilovaného configu se druh nekontroluje (stejná degradace jako
     * u živých reportů — na takovém DS nejde ani otevřít formulář).
     *
     * @param array<string, mixed> $data
     */
    private function validateFilingKind(ValidationResult $result, array $data, string $type, string $kind): void
    {
        $mapping = VatOutputsMapping::fromConfig($this->config);
        $dateFoundRequired = false;

        if ($mapping !== null) {
            $allowed = $mapping->filingKinds($type);
            if ($allowed !== [] && !in_array($kind, $allowed, true)) {
                $result->addError(
                    'filing_kind',
                    'Tento druh podání u typu tvrzení neexistuje (povolené: ' . implode(', ', $allowed) . ').',
                    'invalid_value',
                );
                return;
            }
            $dateFoundRequired = in_array($kind, $mapping->dateFoundRequiredFor($type), true);
        }

        // Dodatečné přiznání se podává z důvodů zjištěných po lhůtě —
        // datum zjištění je povinné vždy (§ 141 odst. 1 DŘ).
        if ($kind === self::KIND_SUPPLEMENTARY) {
            $dateFoundRequired = true;
        }
        if ($dateFoundRequired && empty($data['date_found'])) {
            $result->addError(
                'date_found',
                'U tohoto druhu podání je povinné datum zjištění důvodů pro podání.',
                'required',
            );
        }
    }

    /**
     * Pravidla pořadí druhů a jediný živý koncept v instanci (D18).
     *
     * Import pořadí nevynucuje (#55 D35): starý systém mohl podat řádné až
     * po ručně podaném tvrzení; nesrovnalost zapíše importní služba do
     * zpráv. Jediný živý koncept platí i pro import — do instance
     * s rozdělaným podáním se neimportuje.
     */
    private function validateOrder(
        ValidationResult $result,
        int $periodId,
        ?int $selfId,
        int $state,
        string $kind,
        bool $imported,
    ): void {
        $hasFiled = $this->lastFiledFiling($periodId, $selfId) !== null;

        if (!$imported && self::isOrderIrregular($kind, $hasFiled)) {
            $result->addError(
                'filing_kind',
                $kind === self::KIND_REGULAR
                    ? 'Řádné podání je za tvrzení jen jedno — po podaném řádném následuje opravné,'
                        . ' dodatečné nebo následné.'
                    : 'Tento druh podání navazuje na už podané tvrzení — nejdřív podejte řádné podání.',
                'invalid_value',
            );
        }

        if ($state === self::DOC_STATE_COMPOSED) {
            $draft = $this->findOtherDraft($periodId, $selfId);
            if ($draft !== null) {
                $result->addError(
                    ValidationError::FIELD_FORM,
                    "Za toto tvrzení je už sestavené podání (#{$draft}) — podejte ho, nebo ho zrušte.",
                    'draft_exists',
                );
            }
        }
    }

    /**
     * Zmrazené sloupce, které se v ukládaných datech liší od DB. Sloupce
     * v payloadu chybějící se nekontrolují (částečná aktualizace je
     * legitimní — formulář posílá jen to, co edituje).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $current
     * @param list<string> $columns
     * @return list<string>
     */
    private function changedFrozenColumns(array $data, array $current, array $columns = self::FROZEN_COLUMNS): array
    {
        $changed = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            if ($this->normalizeValue($data[$column]) !== $this->normalizeValue($current[$column] ?? null)) {
                $changed[] = $column;
            }
        }
        return $changed;
    }

    /**
     * Srovnatelný tvar hodnoty — DB vrací datumy jako DateTime a čísla
     * jako int/float, formulář totéž jako řetězce. Bez normalizace by
     * guard hlásil změnu i u nedotčeného přechodu stavu.
     */
    private function normalizeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        $string = (string) $value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $string, $m) === 1) {
            return $m[1];
        }
        return $string;
    }

    /**
     * Důvod, proč `acc_document` nejde nastavit na `$new`, nebo null.
     * Živý současný doklad se nepřepisuje (nejdřív storno); nová hodnota
     * musí být existující cmnbkp v živém stavu; NULL smí odpojit jen mrtvý.
     *
     * @return ?array{0: string, 1: string} [zpráva, kód]
     */
    private function accDocumentError(mixed $new, mixed $currentValue): ?array
    {
        $currentId = (int) ($currentValue ?? 0);
        if ($currentId > 0) {
            $head = $this->loadHead($currentId);
            if ($head !== null && !in_array((int) ($head['docState'] ?? 0), self::ACC_DOCUMENT_DEAD_STATES, true)) {
                return ["Podání už má živý účetní doklad #{$currentId} — nejdřív ho stornujte.", 'acc_document_live'];
            }
        }
        if ($new === null || $new === '') {
            return null;
        }
        $newId = (int) $new;
        if ($newId <= 0) {
            return ['Neplatný odkaz na účetní doklad.', 'invalid_value'];
        }
        $head = $this->loadHead($newId);
        if ($head === null) {
            return ["Účetní doklad #{$newId} neexistuje.", 'invalid_value'];
        }
        if ((string) ($head['doc_type'] ?? '') !== self::ACC_DOCUMENT_TYPE) {
            return ['Účetní doklad přiznání musí být typu Účetní doklad (cmnbkp).', 'invalid_value'];
        }
        if (in_array((int) ($head['docState'] ?? 0), self::ACC_DOCUMENT_DEAD_STATES, true)) {
            return ['Nelze přiřadit stornovaný ani smazaný doklad.', 'invalid_value'];
        }
        return null;
    }

    /** „{název instance} — {druh} {pořadí}"; popisek druhu je z cfgItem. */
    private function composeName(string $periodName, string $kind, int $sequence): string
    {
        $parts = array_filter([$periodName, $this->kindLabel($kind) . ' ' . $sequence]);
        return mb_substr(implode(' — ', $parts), 0, 80);
    }

    /** Lokalizovaný popisek druhu (kompilovaný cfgItem je už lokalizovaný). */
    private function kindLabel(string $kind): string
    {
        $cfg   = $this->config?->cfgItem('economy.vat.filingKinds');
        $label = is_array($cfg) ? (string) ($cfg[$kind]['name'] ?? '') : '';
        return $label !== '' ? $label : $kind;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /** @return ?array<string, mixed> */
    protected function loadReportPeriod(int $periodId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [report_type], [name], [vat_registration], [date_begin], [date_end], [docState]'
            . ' FROM [economy_vat_report_periods] WHERE [id] = %i',
            $periodId,
        );
        return $row !== null ? $row->toArray() : null;
    }

    /** @return ?array<string, mixed> */
    protected function loadCurrent(int $id): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM %n WHERE [id] = %i', self::TABLE, $id);
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * Hlavička dokladu pro guard `acc_document`.
     *
     * @return ?array{id: int, doc_type: string, docState: int}
     */
    protected function loadHead(int $headId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [id], [doc_type], [docState] FROM [docs_core_heads] WHERE [id] = %i',
            $headId,
        );
        return $row !== null ? $row->toArray() : null;
    }

    /** Další pořadí v instanci — max + 1 přes všechna podání včetně zrušených. */
    protected function nextSequence(int $periodId): int
    {
        if ($this->db === null) {
            return 1;
        }
        return 1 + (int) $this->db->fetchSingle(
            'SELECT COALESCE(MAX([sequence]), 0) FROM %n WHERE [report_period] = %i',
            self::TABLE, $periodId,
        );
    }

    /** Id posledního podaného podání instance (kromě sebe sama), null když není. */
    protected function lastFiledFiling(int $periodId, ?int $selfId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM %n WHERE [report_period] = %i AND [docState] = %i AND [id] != %i'
            . ' ORDER BY [sequence] DESC, [id] DESC LIMIT 1',
            self::TABLE, $periodId, self::DOC_STATE_FILED, $selfId ?? 0,
        );
        return $id !== null && $id !== false ? (int) $id : null;
    }

    /** Id jiného živého konceptu téže instance, null když není. */
    protected function findOtherDraft(int $periodId, ?int $selfId): ?int
    {
        if ($this->db === null) {
            return null;
        }
        $id = $this->db->fetchSingle(
            'SELECT [id] FROM %n WHERE [report_period] = %i AND [docState] = %i AND [id] != %i'
            . ' ORDER BY [id] LIMIT 1',
            self::TABLE, $periodId, self::DOC_STATE_COMPOSED, $selfId ?? 0,
        );
        return $id !== null && $id !== false ? (int) $id : null;
    }

    protected function deleteSnapshot(int $filingId): void
    {
        if ($this->db === null) {
            return;
        }
        foreach (self::SNAPSHOT_TABLES as $table) {
            $this->db->query('DELETE FROM %n WHERE [filing] = %i', $table, $filingId);
        }
    }
}
