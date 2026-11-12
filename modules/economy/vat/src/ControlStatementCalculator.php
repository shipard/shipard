<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

/**
 * Rozpad dokladů do sekcí kontrolního hlášení DPHKH1 (tasks/taxes-phase01.md,
 * referenční logika old_shipard VatCSEngine):
 *
 * - config nese jen skupinu (`A1`/`A2`/`A4A5`/`B1`/`B2B3`); rozpad A4/A5
 *   a B2/B3 řeší tato třída per doklad — limit strict > 10 000,00 Kč na
 *   abs() celkové částky dokladu vč. daně v domácí měně; A4 navíc vyžaduje
 *   CZ DIČ odběratele, B2 DIČ netestuje (chybějící je jen měkká chyba),
 * - sazbová pásma (sloupce 1/2/3) z kategorie kódu: standard → 1,
 *   reduced + reduced1 → 2, reduced2 → 3; zero/exempt se neakumulují,
 * - ev. číslo: A1/A4 vlastní číslo dokladu, A2/B1/B2 číslo dodavatele
 *   (`partner_doc_number`), bez jakékoli normalizace,
 * - datum per sekce (#55 X12): A1 a B1 vykazují **DUZP**, ostatní sekce
 *   DPPD — tak to má formulář i XML (atributy `duzp` vs. `dppd`); druhé
 *   datum slouží jako fallback, když chybí,
 * - doklad se dvěma předměty plnění (např. 4 a 14) = dva řádky A1/B1,
 * - A5/B3 = jeden agregátní součtový řádek (pásma ve sloupcích),
 * - ruční zařazení `cs_mode` (#77, 1:1 se starým `vatCS`): 1 vynutí detail
 *   i pod limitem a i bez CZ DIČ (A4 pak dostane měkkou chybu
 *   `missingVatId`), 2 vynutí souhrn i nad limitem, 3 doklad z hlášení
 *   vyřadí úplně — včetně A1/A2/B1 (v přiznání zůstává).
 *
 * Čistá třída bez DB — vstup připravuje VatDocumentSelection, měkké chyby
 * vrací jako data (builder z nich dělá lokalizované ReportMessage).
 */
final class ControlStatementCalculator
{
    /** Limit rozpadu A4/A5 a B2/B3 — strict `>`, vč. daně, domácí měna. */
    public const LIMIT = 10000.0;

    /**
     * Ruční zařazení dokladu (`docs_core_heads.cs_mode`, cfgItem
     * `economy.vat.controlStatementModes`).
     */
    public const MODE_AUTO      = 0;
    public const MODE_DETAIL    = 1;
    public const MODE_AGGREGATE = 2;
    public const MODE_EXCLUDE   = 3;

    public const SECTIONS = ['A1', 'A2', 'A4', 'A5', 'B1', 'B2', 'B3'];

    /**
     * Sekce přijatých plnění — ev. číslo i DIČ patří dodavateli, ne
     * odběrateli. A2 je výjimka z písmenkové logiky: pořízení z EU je
     * přijaté plnění, ale vykazuje se v sekci A, protože z něj přiznáváme
     * daň. B3 do detailních řádků nikdy nedojde (je agregát), ale
     * konstanta je vystavená i pro snapshot podání (FilingComposer), který
     * DIČ protistrany určuje i u dokladů v agregátních sekcích.
     */
    public const RECEIVED_SECTIONS = ['A2', 'B1', 'B2', 'B3'];

    /** Sekce se součtovým řádkem místo detailů (limit 10 000 Kč). */
    public const AGGREGATE_SECTIONS = ['A5', 'B3'];

    /**
     * Sekce, které vykazují **DUZP** místo DPPD (#55 X12) — režim přenesení
     * daňové povinnosti u dodavatele (A1) i odběratele (B1). Zdrojem pravdy
     * jsou jména atributů v `vat-xml-cz.jsonc` (`duzp` vs. `dppd`); shodu
     * s nimi hlídá `VatXmlMappingCompletenessTest`.
     */
    public const DUZP_SECTIONS = ['A1', 'B1'];

    /**
     * @param array<string, array<string, mixed>> $vatCodes Definice kódů
     *        z world.vat (klíč = kód; používá se pole `category`).
     */
    public function __construct(
        private readonly VatOutputsMapping $mapping,
        private readonly array $vatCodes,
    ) {}

    /**
     * @param list<array<string, mixed>> $docs Doklady z VatDocumentSelection:
     *        id, doc_number, partner_doc_number, total_amount_dom, vat_duzp,
     *        vat_dppd, customer_vat_id, supplier_vat_id, recap[] (vat_code,
     *        base_dom, tax_dom).
     * @return array{
     *     sections: array<string, list<array<string, mixed>>>,
     *     errors: list<array{code: string, docId: int, docNumber: string, section: string}>,
     *     excluded: list<array{docId: int, docNumber: string}>,
     * }
     *     `excluded` = doklady ručně vyřazené režimem 3 — report je vypíše,
     *     ať je vidět, co v hlášení chybí úmyslně.
     */
    public function calculate(array $docs): array
    {
        $sections   = array_fill_keys(self::SECTIONS, []);
        $aggregates = ['A5' => null, 'B3' => null];
        $errors     = [];
        $excluded   = [];

        foreach ($docs as $doc) {
            if (self::isExcluded($doc)) {
                $excluded[] = ['docId' => (int) $doc['id'], 'docNumber' => (string) ($doc['doc_number'] ?? '')];
                continue;
            }
            foreach ($this->groupRecap($doc) as $group) {
                $section = $this->resolveSection($group['group'], $doc);
                if (in_array($section, self::AGGREGATE_SECTIONS, true)) {
                    $aggregates[$section] = $this->addBands(
                        $aggregates[$section] ?? $this->emptyBands(),
                        $group['bands'],
                    );
                    continue;
                }
                $sections[$section][] = $this->detailRow($section, $group, $doc, $errors);
            }
        }

        foreach (self::AGGREGATE_SECTIONS as $aggregate) {
            if ($aggregates[$aggregate] !== null) {
                $sections[$aggregate][] = [
                    'docId'      => null,
                    'evidNumber' => null,
                    'vatId'      => null,
                    'kodPredPl'  => null,
                    'dppd'       => null,
                    'csMode'     => null,
                ] + $this->roundBands($aggregates[$aggregate]);
            }
        }

        foreach (['A1', 'A2', 'A4', 'B1', 'B2'] as $detail) {
            usort(
                $sections[$detail],
                static fn (array $a, array $b): int =>
                    strcmp((string) $a['evidNumber'], (string) $b['evidNumber'])
                        ?: (($a['docId'] ?? 0) <=> ($b['docId'] ?? 0)),
            );
        }

        return ['sections' => $sections, 'errors' => $errors, 'excluded' => $excluded];
    }

    /**
     * Sekce kontrolního hlášení, do které řádek dokladu s tímto kódem
     * spadne — tatáž pravidla jako `calculate()` (rozpad A4/A5 a B2/B3 dle
     * limitu a CZ DIČ). Null = kód do hlášení nespadá.
     *
     * Vystaveno pro snapshot podání: materializovaná sekce v
     * `economy_vat_filing_items` musí vzniknout tímto enginem, ne kopií
     * pravidel na druhém místě.
     *
     * @param array<string, mixed> $doc Doklad z VatDocumentSelection.
     */
    public function sectionForCode(array $doc, string $vatCode): ?string
    {
        if (self::isExcluded($doc)) {
            return null;
        }
        $kh = $this->mapping->kh($vatCode);
        return $kh === null ? null : $this->resolveSection((string) $kh['group'], $doc);
    }

    /**
     * Ručně vyřazený doklad (`cs_mode` 3) — v hlášení není, ať má jakékoli
     * kódy; v přiznání zůstává (ř. DP3 řeší VatReturnCalculator).
     *
     * @param array<string, mixed> $doc
     */
    public static function isExcluded(array $doc): bool
    {
        return self::mode($doc) === self::MODE_EXCLUDE;
    }

    /** Ručně nastavený režim, jinak automatika. */
    public static function mode(array $doc): int
    {
        return (int) ($doc['cs_mode'] ?? self::MODE_AUTO);
    }

    /**
     * Recap řádky dokladu seskupené per KH cíl — skupina + předmět plnění
     * z řádku rekapitulace (#89 D13); doklad se dvěma předměty tak vyrobí
     * dva řádky.
     *
     * @param array<string, mixed> $doc
     * @return list<array{group: string, kodPredPl: ?string, bands: array<string, float>}>
     */
    private function groupRecap(array $doc): array
    {
        $groups = [];
        foreach ($doc['recap'] ?? [] as $row) {
            $code = (string) $row['vat_code'];
            $kh   = $this->mapping->kh($code);
            if ($kh === null) {
                continue;
            }
            $band = $this->rateBand($code);
            if ($band === null) {
                continue; // zero/exempt se do pásem neakumulují
            }
            // Kód předmětu plnění (A.1 / B.1) nese řádek rekapitulace
            // dokladu, ne mapování kódu DPH (#89 D13). Řádek bez předmětu
            // (starý nebo rozpracovaný doklad) zůstane s null — podání na
            // něm skončí chybou „chybí kód předmětu plnění“, žádná výchozí
            // hodnota.
            $kodPredPl = in_array($kh['group'], self::SUBJECT_GROUPS, true)
                ? self::subject($row['reverse_charge_code'] ?? null)
                : null;
            $key       = $kh['group'] . ($kodPredPl !== null ? ".{$kodPredPl}" : '');
            $groups[$key] ??= ['group' => $kh['group'], 'kodPredPl' => $kodPredPl, 'bands' => $this->emptyBands()];
            $groups[$key]['bands']["base{$band}"] += (float) $row['base_dom'];
            $groups[$key]['bands']["tax{$band}"]  += (float) $row['tax_dom'];
        }
        return array_values($groups);
    }

    /** Skupiny KH s kódem předmětu plnění (tuzemské přenesení daňové povinnosti). */
    private const SUBJECT_GROUPS = ['A1', 'B1'];

    /** Předmět plnění jako řetězec ("4", "4a", "14"); prázdné = null. */
    private static function subject(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    /** Sazbové pásmo KH (sloupce 1/2/3) z kategorie kódu. */
    private function rateBand(string $code): ?int
    {
        return match ((string) ($this->vatCodes[$code]['category'] ?? '')) {
            'standard'            => 1,
            'reduced', 'reduced1' => 2,
            'reduced2'            => 3,
            default               => null,
        };
    }

    /**
     * Rozpad skupin A4A5 / B2B3: ruční režim má přednost před limitem
     * (a u A4 i před požadavkem na CZ DIČ — chybějící DIČ pak hlásí
     * `detailRow()`), pevné sekce A1/A2/B1 režim 1/2 nemění.
     *
     * @param array<string, mixed> $doc
     */
    private function resolveSection(string $group, array $doc): string
    {
        $mode = self::mode($doc);
        return match ($group) {
            'A4A5'  => match ($mode) {
                self::MODE_DETAIL    => 'A4',
                self::MODE_AGGREGATE => 'A5',
                default              => $this->overLimit($doc)
                    && $this->hasCzVatId((string) ($doc['customer_vat_id'] ?? '')) ? 'A4' : 'A5',
            },
            'B2B3'  => match ($mode) {
                self::MODE_DETAIL    => 'B2',
                self::MODE_AGGREGATE => 'B3',
                default              => $this->overLimit($doc) ? 'B2' : 'B3',
            },
            default => $group,
        };
    }

    /** @param array<string, mixed> $doc */
    private function overLimit(array $doc): bool
    {
        return abs((float) ($doc['total_amount_dom'] ?? 0.0)) > self::LIMIT;
    }

    /** CZ DIČ dle vzoru starého Shipardu: neprázdné s prefixem 'CZ'. */
    private function hasCzVatId(string $vatId): bool
    {
        return $vatId !== '' && str_starts_with($vatId, 'CZ');
    }

    /**
     * @param array{group: string, kodPredPl: ?string, bands: array<string, float>} $group
     * @param array<string, mixed> $doc
     * @param list<array{code: string, docId: int, docNumber: string, section: string}> $errors
     * @return array<string, mixed>
     */
    private function detailRow(string $section, array $group, array $doc, array &$errors): array
    {
        // A-sekce = naše prodeje (DIČ odběratele), A2/B-sekce = přijatá
        // plnění (DIČ dodavatele + jeho číslo dokladu).
        $received   = in_array($section, self::RECEIVED_SECTIONS, true);
        $vatId      = $received
            ? (string) ($doc['supplier_vat_id'] ?? '')
            : (string) ($doc['customer_vat_id'] ?? '');
        $evidNumber = $received
            ? (string) ($doc['partner_doc_number'] ?? '')
            : (string) ($doc['doc_number'] ?? '');

        // B2 DIČ netestuje (chybějící je měkká chyba); A4 ho automatika
        // vyžaduje, takže bez CZ DIČ tam doklad dojde jen ručně (režim 1)
        // — a `dic_odb` je v podání povinný, proto stejná měkká chyba.
        if (($section === 'B2' && $vatId === '') || ($section === 'A4' && !$this->hasCzVatId($vatId))) {
            $errors[] = [
                'code'      => 'missingVatId',
                'docId'     => (int) $doc['id'],
                'docNumber' => (string) $doc['doc_number'],
                'section'   => $section,
            ];
        }
        if (($section === 'B1' || $section === 'B2') && $evidNumber === '') {
            $errors[] = [
                'code'      => 'missingPartnerDocNumber',
                'docId'     => (int) $doc['id'],
                'docNumber' => (string) $doc['doc_number'],
                'section'   => $section,
            ];
        }

        return [
            'docId'      => (int) $doc['id'],
            'evidNumber' => $evidNumber,
            'vatId'      => $vatId,
            'kodPredPl'  => $group['kodPredPl'],
            'dppd'       => $this->reportedDate($section, $doc),
            // Ruční režim (1/2) — report ho u řádku ukáže, automatika ne.
            'csMode'     => self::mode($doc),
        ] + $this->roundBands($group['bands']);
    }

    /**
     * Datum, které sekce vykazuje: A1 a B1 DUZP, ostatní DPPD (#55 X12).
     * Druhé datum je fallback — doklad, kterému chybí to správné, je pořád
     * lepší vykázat s tím druhým než bez data.
     *
     * @param array<string, mixed> $doc
     */
    private function reportedDate(string $section, array $doc): ?string
    {
        $order = in_array($section, self::DUZP_SECTIONS, true)
            ? ['vat_duzp', 'vat_dppd']
            : ['vat_dppd', 'vat_duzp'];

        foreach ($order as $column) {
            $value = (string) ($doc[$column] ?? '');
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /** @return array<string, float> */
    private function emptyBands(): array
    {
        return ['base1' => 0.0, 'tax1' => 0.0, 'base2' => 0.0, 'tax2' => 0.0, 'base3' => 0.0, 'tax3' => 0.0];
    }

    /**
     * @param array<string, float> $target
     * @param array<string, float> $add
     * @return array<string, float>
     */
    private function addBands(array $target, array $add): array
    {
        foreach ($add as $key => $value) {
            $target[$key] += $value;
        }
        return $target;
    }

    /**
     * @param array<string, float> $bands
     * @return array<string, float>
     */
    private function roundBands(array $bands): array
    {
        return array_map(static fn (float $value): float => round($value, 2), $bands);
    }
}
