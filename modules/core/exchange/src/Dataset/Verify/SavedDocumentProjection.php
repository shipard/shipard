<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Adaptér export → papírová projekce (tasks/testing-phase3.md, kontrola 1):
 * export dokladu (`DocumentExporter`) nenese to, co projekce čeká, protože
 * to v datech leží jinde nebo v jiné podobě — doplní se ze zdroje dat,
 * nic se nehádá. Pravidla (schválená 2026-10-14):
 *
 *   - `customer` — vlastní strana se neexportuje; doplní se vlastní firma
 *     (`OwnCompanyResolver` + `CanonicalParty::fromPerson`), jen když ji
 *     návrh nese (účtenka bez odběratele zůstává `null`);
 *   - `rows[].item.name` — export dává název účetní položky, text řádku
 *     z dokladu je v `rows[].description` (`CanonicalRowText::compose`);
 *     když je text složený z názvu a popisu návrhu (pomlčka), dosadí se
 *     obojí z návrhu (#103 nález C, 2026-10-20);
 *   - `exchangeRate` — doklad v domácí měně má kurz 1 (#121 U4), papír
 *     kurz nenese: 1 u domácí měny ≡ null (nález A);
 *   - `rows[].vat.supplyKind` — řádek druh plnění neukládá, nese ho
 *     definice kódu DPH (cz-215 zboží, cz-217 služby; nález B);
 *   - `rows[].unit` — export dává `core_units.system_code`; když jednotka
 *     návrhu (`UnitResolver`) vede na tutéž jednotku jako uložený řádek,
 *     dosadí se text z návrhu; jednotka mimo číselník (`bal`, `krt`, `role`)
 *     se neukládá (řádek bez jednotky) — schválená výjimka: text z návrhu
 *     se dosadí, když řádek jednotku nemá a návrh ji neumí rozlišit;
 *   - samovyměření — na papíře 0 %, Shipard nese sazbu kódu (`cz-115` 21 %)
 *     a oddaňovací pár (`isReversePair`); řádky s kódem, který má
 *     `reverseVatCode`, dostanou papírovou podobu (`pct` 0), pár se vynechá
 *     a primární záznam rekapitulace má daň 0 a celkem = základ;
 *   - `vat.reverseCharge` — export ho nenese, odvodí se z kódů řádků: true
 *     u tuzemského přenesení (kód s `reverseChargeCode` / `reverseCharge`)
 *     a u dokladu jen se službami z EU (čl. 196, `intracom` + `services`);
 *     pořízení zboží z EU (čl. 138, i s dopravným jako službou) ani plnění
 *     ze třetí země papírové „přenesení“ nemají (nález E);
 *   - položky rekapitulace samovyměření se stejnou sazbou (zboží + služba
 *     z EU, Shipard je vede per kód) se v papírové podobě sloučí do jedné
 *     (nález G).
 */
final class SavedDocumentProjection
{
    private ?UnitResolver $units = null;
    private ?int $ownPersonId = null;
    private bool $ownResolved = false;
    /** @var array<string, array<string, mixed>> kód DPH → definice z číselníku ([] = neznámý) */
    private array $codeDefs = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ?VatRateResolver $vat = null,
        private readonly string $country = 'cz',
        private readonly ?string $homeCurrency = null,
    ) {}

    /**
     * @param array<string, mixed> $export   canonical z `DocumentExporter`
     * @param array<string, mixed> $proposal canonical použitého návrhu
     * @return array<string, mixed>
     */
    public function build(array $export, array $proposal, int $docId): array
    {
        $out = $export;

        if (($proposal['customer'] ?? null) !== null && ($export['customer'] ?? null) === null) {
            $ownId = $this->ownPersonId();
            $out['customer'] = $ownId !== null ? CanonicalParty::fromPerson($this->db, $ownId) : null;
        }

        // Kurz 1 u dokladu v domácí měně (#121 U4) ≡ papír bez kurzu (nález A).
        if (($proposal['exchangeRate'] ?? null) === null && $this->homeCurrency !== null
            && strtoupper(trim((string) ($export['currency'] ?? ''))) === strtoupper(trim($this->homeCurrency))
            && is_numeric($export['exchangeRate'] ?? null) && abs((float) $export['exchangeRate'] - 1.0) < 1e-9) {
            $out['exchangeRate'] = null;
        }

        $codes = [];   // kódy DPH řádků a rekapitulace → papírové přenesení (nález E)
        $unitIds = $this->rowUnitIds($docId);
        $rows = [];
        foreach ((array) ($export['rows'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                $rows[] = $row;
                continue;
            }
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $proposalRow = is_array($proposal['rows'][$i] ?? null) ? $proposal['rows'][$i] : null;
            $text = $row['description'] ?? null;
            $composed = $proposalRow !== null ? CanonicalRowText::compose($proposalRow) : null;
            if (is_string($text) && $composed !== null && $composed === $text) {
                // Text řádku = název a popis návrhu spojené pomlčkou (nález C).
                $item['name'] = $proposalRow['item']['name'] ?? null;
                $item['description'] = $proposalRow['item']['description'] ?? null;
            } else {
                $item['name'] = $text ?? ($item['name'] ?? null);
            }
            $row['item'] = $item;

            $row['unit'] = $this->paperUnit($row['unit'] ?? null, $proposalRow['unit'] ?? null, $unitIds[$i] ?? null);

            $code = $row['vat']['code'] ?? null;
            if (is_string($code)) {
                $def = $this->codeDef($code);
                if (is_array($row['vat'] ?? null) && ($row['vat']['supplyKind'] ?? null) === null && is_string($def['supplyKind'] ?? null)) {
                    $row['vat']['supplyKind'] = $def['supplyKind'];   // nález B
                }
                if ($this->isReverseCode($code)) {
                    $row['vat']['pct'] = 0;
                    $codes[$code] = true;
                }
            }
            $rows[] = $row;
        }
        $out['rows'] = $rows;

        $recap = [];
        $paperIndex = [];   // sazba → index položky papírové podoby samovyměření (nález G)
        foreach ((array) ($export['vatRecap'] ?? []) as $entry) {
            if (!is_array($entry)) {
                $recap[] = $entry;
                continue;
            }
            if (($entry['isReversePair'] ?? null) === true) {
                continue;
            }
            $code = $entry['vatCode'] ?? null;
            if (is_string($code) && $this->isReverseCode($code)) {
                $codes[$code] = true;
                $entry['vatPct'] = 0;
                $entry['tax'] = 0;
                $entry['total'] = $entry['base'] ?? null;
                $key = (string) $entry['vatPct'];
                if (isset($paperIndex[$key])) {
                    $target = $recap[$paperIndex[$key]];
                    $target['base'] = round((float) ($target['base'] ?? 0) + (float) ($entry['base'] ?? 0), 2);
                    $target['total'] = $target['base'];
                    $recap[$paperIndex[$key]] = $target;
                    continue;
                }
                $paperIndex[$key] = count($recap);
            }
            $recap[] = $entry;
        }
        $out['vatRecap'] = $recap;

        $out['vat'] = is_array($out['vat'] ?? null) ? $out['vat'] : [];
        $out['vat']['reverseCharge'] = $this->isPaperReverseCharge(array_keys($codes));

        if (($proposal['vat']['mode'] ?? null) === 'none') {
            $out = $this->withoutZeroVat($out);
        }
        return $out;
    }

    /**
     * Doklad bez DPH (`vat.mode` none — dodavatel neplátce): Shipard nese kód
     * bez daně (cz-112), nulovou rekapitulaci a základ = částka, papír DPH
     * vůbec nemá. Rovnocenné jen při nulové dani — jinak zůstává rozdíl.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private function withoutZeroVat(array $out): array
    {
        foreach ((array) ($out['vatRecap'] ?? []) as $entry) {
            if (is_array($entry) && abs((float) ($entry['tax'] ?? 0)) > 0.005) {
                return $out;
            }
        }
        if (abs((float) ($out['totals']['totalVat'] ?? 0)) > 0.005) {
            return $out;
        }
        foreach ((array) ($out['rows'] ?? []) as $i => $row) {
            if (is_array($row) && is_array($row['vat'] ?? null)) {
                $out['rows'][$i]['vat']['pct'] = null;
            }
        }
        $out['vatRecap'] = [];
        if (is_array($out['totals'] ?? null)) {
            $out['totals']['totalBase'] = null;
            $out['totals']['totalVat'] = null;
        }
        return $out;
    }

    /**
     * Jednotka v papírové podobě: text návrhu, když vede na tutéž jednotku
     * jako uložený řádek, nebo když řádek jednotku nemá a text z číselníku
     * rozlišit nejde (jednotka mimo číselník se neukládá — schválená výjimka).
     */
    private function paperUnit(mixed $exportUnit, mixed $proposalUnit, ?int $rowUnitId): mixed
    {
        if (!is_string($proposalUnit) || trim($proposalUnit) === '') {
            return $exportUnit;
        }
        $resolved = $this->units()->resolve($proposalUnit);
        $matched = $resolved->status === ResolveStatus::Matched;
        if ($rowUnitId !== null && $matched && $resolved->matchedId === $rowUnitId) {
            return $proposalUnit;
        }
        if ($rowUnitId === null && !$matched) {
            return $proposalUnit;
        }
        return $exportUnit;
    }

    /** Kód samovyměření (má oddaňovací pár) — na papíře 0 %. */
    private function isReverseCode(string $code): bool
    {
        return !empty($this->codeDef($code)['reverseVatCode']);
    }

    /**
     * Papírové „přenesení daňové povinnosti“ (nález E) z kódů samovyměření
     * dokladu: tuzemské PDP (kód s kódem předmětu plnění nebo příznakem),
     * nebo doklad jen se službami z EU (čl. 196). Pořízení zboží z EU
     * (čl. 138) — i s dopravným jako službou — a plnění ze třetí země ne.
     *
     * @param list<string> $codes
     */
    private function isPaperReverseCharge(array $codes): bool
    {
        $euServices = false;
        $euGoods = false;
        foreach ($codes as $code) {
            $def = $this->codeDef($code);
            if (!empty($def['reverseCharge']) || isset($def['reverseChargeCode'])) {
                return true;
            }
            if (($def['place'] ?? null) === 'intracom') {
                $euServices = $euServices || ($def['supplyKind'] ?? null) === 'services';
                $euGoods = $euGoods || ($def['supplyKind'] ?? null) === 'goods';
            }
        }
        return $euServices && !$euGoods;
    }

    /** @return array<string, mixed> definice kódu DPH země dokladu, [] bez resolveru nebo neznámý kód */
    private function codeDef(string $code): array
    {
        if ($this->vat === null) {
            return [];
        }
        if (!array_key_exists($code, $this->codeDefs)) {
            $def = $this->vat->getVatCode($this->country, $code);
            $this->codeDefs[$code] = is_array($def) ? $def : [];
        }
        return $this->codeDefs[$code];
    }

    /** @return list<int|null> id jednotky řádků v pořadí exportu (`order_pos`, `id`) */
    private function rowUnitIds(int $docId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [unit] FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos], [id]',
            $docId,
        ) as $row) {
            $out[] = $row['unit'] !== null ? (int) $row['unit'] : null;
        }
        return $out;
    }

    private function units(): UnitResolver
    {
        return $this->units ??= new UnitResolver($this->db);
    }

    private function ownPersonId(): ?int
    {
        if (!$this->ownResolved) {
            $this->ownPersonId = (new OwnCompanyResolver($this->db))->getOwnPersonId();
            $this->ownResolved = true;
        }
        return $this->ownPersonId;
    }
}
