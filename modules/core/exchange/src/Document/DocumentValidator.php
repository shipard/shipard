<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Document\Interpret\PaperChecks;
use Shipard\Module\Core\Exchange\Values\CanonicalValueValidator;

/**
 * Semantic validator for a canonical document — runs **after** schema
 * validation and **before** resolve. Two kinds of checks:
 *
 *   - Required fields per `docType` (polymorphism the JSON schema
 *     intentionally does not enforce).
 *   - Totals coherence — declared vs. computed amounts; mismatch produces
 *     a warning, not an error, since the canonical's totals are
 *     informative and DocDocument::beforeSave recomputes them anyway.
 *   - Dimenze deníku (objekt `dimensions`, #110 T2): klíč musí být id
 *     deklarované dimenze, jejíž cílová tabulka má přirozený klíč
 *     (`exchangeKey` v definici tabulky, #166 D8), jinak `dimension_unknown`.
 *     Kontroluje se jen se znalostí sady dimenzí; bez ní (preflight sady
 *     bez DS) se neznámé id odhalí až při apply.
 *
 * Returns the same `{severity, path, code, message}` shape as
 * {@see \Shipard\Module\Core\Exchange\Schema\SchemaValidator}. Issues
 * with `severity = error` block /apply; warnings only surface in
 * `_resolve.issues` and never block.
 */
final class DocumentValidator
{
    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        aktivní tabulky DS — přirozený klíč cílových tabulek dimenzí
     */
    public function __construct(
        private readonly ?JournalDimensionSet $dimensions = null,
        private readonly array $tables = [],
    ) {}

    /**
     * Kontroly **pokynu**. Povinnost polí validátor nehlídá (#166 D45, U7):
     * pokyn vyžaduje jen obálku a `docType` (schéma) — co payload neuvádí,
     * má výchozí hodnotu jako ve formuláři, a co chybí i potom, ohlásí
     * `DocDocument::validate()` pro cílový stav, jeden nález na pole.
     * Čísla pokyn neposuzuje, počítá je `DocDocument`. Kontroly papírové
     * podoby (povinná pole předlohy, heuristiky nad čísly) drží interpretace
     * — `Interpret\PaperChecks::validate()` je prokládá kontrolami odsud.
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    public function validate(array $canonical, ApplyMode $mode = ApplyMode::Instruction): array
    {
        if ($mode->derives()) {
            return PaperChecks::validate($canonical, $this);
        }
        $issues = [];
        // Strana a položka jen referencí → objekt s `ref` (volající mimo
        // applier, např. kontrola sady před seedem, posílá tvar ze souboru).
        $canonical = CanonicalReferences::expand($canonical);

        $this->checkDocTypeFields($canonical, $issues);
        $this->checkHeadPartner($canonical, $issues);
        $this->checkPartnerDocNumber($canonical, $issues);
        $this->checkProposalSignals($canonical, $issues);
        $this->checkOptionsAndValues($canonical, $issues);

        return $issues;
    }

    /**
     * Volby `applyOptions`, klíč `source.ref`, dimenze a tvary hodnot stran
     * — platí pro pokyn i pro papírovou podobu.
     *
     * @param array<string, mixed> $canonical po {@see CanonicalReferences::expand()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function checkOptionsAndValues(array $canonical, array &$issues): void
    {
        $this->checkParkingStateRequiresImport($canonical, $issues);
        $this->checkReplaceConceptOptions($canonical, $issues);
        $this->checkSourceRef($canonical, $issues);
        $this->checkDimensions($canonical, $issues);
        // Tvar a platnost IČO, DIČ, PSČ, účtu, IBAN a BIC stran + opravy
        // z redundance (#118 D13, D14) — vše warning, apply neblokuje.
        foreach (CanonicalValueValidator::validate($canonical) as $issue) {
            $issues[] = $issue;
        }
    }

    /**
     * Pole `partner` na hlavičce (#166 D30) nesou jen účetní doklad a typy
     * vázané na pokladnu ({@see CanonicalReferences::HEAD_PARTNER_DOC_TYPES}).
     * U faktur je partnerem strana: pokyn pole odmítá chybou
     * `partner_not_supported` (návrh ho s varováním ignoruje —
     * `Interpret\PaperChecks::checkHeadPartner()`).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkHeadPartner(array $canonical, array &$issues): void
    {
        if (!CanonicalReferences::headPartnerMisplaced($canonical)) {
            return;
        }
        $issues[] = [
            'severity' => 'error',
            'path'     => CanonicalReferences::PARTNER,
            'code'     => 'partner_not_supported',
            'message'  => 'Pole partner platí jen u účetního a pokladního dokladu — u tohoto typu urči partnera stranou (supplier / customer).',
        ];
    }

    /**
     * Signály návrhu (#166 D39): `rows[].item.ourCode`, účet řádku holým
     * číslem a `vat.registrationCountry` nesou uložené návrhy z pošty a čte
     * je jen návrh. Pokyn je odmítá chybou `signal_not_allowed` s radou,
     * které pole použít — tiché ignorování by uložilo jiný doklad, než jaký
     * odesílatel zamýšlel. Dočasné: po převodu uložených návrhů pole zmizí.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkProposalSignals(array $canonical, array &$issues): void
    {
        $signal = static fn (string $path, string $message): array => [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'signal_not_allowed',
            'message'  => $message,
        ];
        $country = $canonical['vat']['registrationCountry'] ?? null;
        if (is_string($country) && trim($country) !== '') {
            $issues[] = $signal(
                'vat.registrationCountry',
                'Země registrace DPH je údaj návrhu — v pokynu urči registraci polem vat.registration (např. "@' . strtolower(trim($country)) . '").',
            );
        }
        foreach (is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [] as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $ourCode = is_array($row['item'] ?? null) ? ($row['item']['ourCode'] ?? null) : null;
            if (is_string($ourCode) && trim($ourCode) !== '') {
                $issues[] = $signal(
                    "rows.{$i}.item.ourCode",
                    'Kód položky je údaj návrhu — v pokynu urči položku referencí rows[].item.ref (např. "@' . trim($ourCode) . '").',
                );
            }
            $account = $row['account'] ?? null;
            if (CanonicalReferences::isAccountSignal($account)) {
                $issues[] = $signal(
                    "rows.{$i}.account",
                    'Účet holým číslem je údaj návrhu — v pokynu ho urči referencí (např. "@' . $account . '").',
                );
            }
        }
    }

    /**
     * `applyOptions.replaceConcept` (#110 D24, Q2) ukládá vždy Koncept —
     * `targetDocState` musí být 10 nebo chybět a import čísla (`importNumber`)
     * se s přegenerováním nekombinuje (koncept číslo nemá).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkReplaceConceptOptions(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? [];
        if (!is_array($options) || !isset($options['replaceConcept'])) {
            return;
        }
        $targetState = $options['targetDocState'] ?? null;
        if ($targetState !== null && (int) $targetState !== 10) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.targetDocState',
                'code'     => 'replace_concept_requires_draft',
                'message'  => 'Přegenerování (replaceConcept) ukládá vždy Koncept — targetDocState musí být 10 nebo chybět.',
            ];
        }
        if (is_array($options['importNumber'] ?? null)) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.importNumber',
                'code'     => 'replace_concept_with_import',
                'message'  => 'Přegenerování (replaceConcept) se nekombinuje s importNumber — koncept číslo nemá.',
            ];
        }
    }

    /**
     * `source.ref` (#166 D46) je klíč idempotence jen spolu se `source.kind`:
     * identifikátor bez druhu zdroje nejde dohledat a opakovaný pokyn by
     * doklad založil podruhé — chyba, ne tiché uložení bez pojistky.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkSourceRef(array $canonical, array &$issues): void
    {
        if (SourceRef::hasRefWithoutKind($canonical)) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'source.ref',
                'code'     => 'source_kind_missing',
                'message'  => 'Identifikátor ve zdroji (source.ref) vyžaduje druh zdroje (source.kind) — bez něj nejde opakovaný pokyn rozpoznat.',
            ];
        }
    }

    /**
     * Objekt `dimensions` na hlavičce a řádcích (#110 T2): klíč = id dimenze
     * deníku, jejíž cílová tabulka má `exchangeKey`. Neznámé id i dimenze bez klíče jsou chyba —
     * překlep v sadě nesmí hodnotu tiše zahodit. Hodnoty (existence záznamu)
     * řeší applier při resolve (`dimension_not_found`).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkDimensions(array $canonical, array &$issues): void
    {
        if ($this->dimensions === null) {
            return;
        }
        $this->checkDimensionKeys($canonical['dimensions'] ?? null, 'dimensions', $issues);
        foreach ((array) ($canonical['rows'] ?? []) as $i => $row) {
            if (is_array($row)) {
                $this->checkDimensionKeys($row['dimensions'] ?? null, "rows.{$i}.dimensions", $issues);
            }
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkDimensionKeys(mixed $dimensions, string $path, array &$issues): void
    {
        if (!is_array($dimensions)) {
            return;
        }
        foreach (array_keys($dimensions) as $id) {
            $dimension = $this->dimensions?->get((string) $id);
            if ($dimension === null) {
                $message = "Neznámá dimenze deníku „{$id}“.";
            } elseif (($this->tables[$dimension->table] ?? null)?->exchangeKey === null) {
                $message = "Dimenze „{$id}“ nemá klíč pro výměnný formát (exchangeKey) — formát ji nenese.";
            } else {
                continue;
            }
            $issues[] = [
                'severity' => 'error',
                'path'     => "{$path}.{$id}",
                'code'     => 'dimension_unknown',
                'message'  => $message,
            ];
        }
    }

    /**
     * Stav 80 (V opravě) je výhradně parkovací cíl migrace (nezaúčtovatelné
     * doklady s číslem) — bez applyOptions.importNumber přes exchange
     * dosažitelný není.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkParkingStateRequiresImport(array $canonical, array &$issues): void
    {
        $options = $canonical['applyOptions'] ?? [];
        $targetState = $options['targetDocState'] ?? null;
        if ($targetState === null || (int) $targetState !== 80) {
            return;
        }
        if (!is_array($options['importNumber'] ?? null)) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'applyOptions.targetDocState',
                'code'     => 'target_state_80_requires_import',
                'message'  => 'targetDocState 80 (V opravě) je povolen jen s applyOptions.importNumber (migrace).',
            ];
        }
    }

    /**
     * Co výchozí hodnotu mít nemůže a platí pro pokyn i papírovou podobu:
     * pokladna dokladu vázaného na pokladnu (#59 D12 — řada se nikdy
     * nevybírá potichu, bez ní doklad nemá kam vzniknout) a směr pokladního
     * dokladu (neplatnou hodnotu by doklad uložil jako „bez směru“). Strany
     * vyžaduje jen návrh (`Interpret\PaperChecks::checkParties()`) —
     * v pokynu je hlídá doklad pro cílový stav (U7).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function checkDocTypeFields(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        if (in_array($docType, ['cashDocument', 'cashRegisterDocument'], true)) {
            $this->checkCashDesk($canonical, $issues);
        }
        if ($docType === 'cashDocument') {
            $this->checkCashDirection($canonical, $issues);
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCashDirection(array $canonical, array &$issues): void
    {
        if (!in_array($canonical['cashDirection'] ?? null, [1, 2], true)) {
            $issues[] = [
                'severity' => 'error',
                'path'     => 'cashDirection',
                'code'     => 'invalid_value',
                'message'  => 'Směr pokladního dokladu musí být 1 (příjem) nebo 2 (výdej).',
            ];
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function checkCashDesk(array $canonical, array &$issues): void
    {
        if (!CanonicalReferences::isSet($canonical['cashDesk'] ?? null)) {
            $issues[] = self::required('cashDesk', 'Pokladna je povinná — řada dokladu je vázaná na pokladnu.');
        }
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    public function checkPartnerDocNumber(array $canonical, array &$issues): void
    {
        $docType = (string) ($canonical['docType'] ?? '');
        // Přijaté doklady: faktura i zálohová faktura (#106 D1) nesou číslo
        // dokladu dodavatele.
        if (!in_array($docType, ['invoiceReceived', 'proformaReceived'], true)) {
            return;
        }
        // Warning platí pro všechny cíle mimo Koncept (40, 30 i 80).
        $targetState = $canonical['applyOptions']['targetDocState'] ?? null;
        if ($targetState === null || (int) $targetState === 10) {
            return;
        }
        $partnerDocNumber = $canonical['docNumber'] ?? null;
        if ($partnerDocNumber === null || trim((string) $partnerDocNumber) === '') {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'docNumber',
                'code'     => 'partner_doc_number_missing',
                'message'  => 'Číslo dokladu od dodavatele není vyplněno.',
            ];
        }
    }

    /**
     * @return array{severity: string, path: string, code: string, message: string}
     */
    public static function required(string $path, string $message): array
    {
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'required',
            'message'  => $message,
        ];
    }
}
