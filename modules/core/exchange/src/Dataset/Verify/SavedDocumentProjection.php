<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
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
 *   - `rows[].unit` — export dává `core_units.system_code`; když jednotka
 *     návrhu (`UnitResolver`) vede na tutéž jednotku jako uložený řádek,
 *     dosadí se text z návrhu; jednotka mimo číselník (`bal`, `krt`, `role`)
 *     se neukládá (řádek bez jednotky) — schválená výjimka: text z návrhu
 *     se dosadí, když řádek jednotku nemá a návrh ji neumí rozlišit;
 *   - samovyměření — na papíře 0 %, Shipard nese sazbu kódu (`cz-115` 21 %)
 *     a oddaňovací pár (`isReversePair`); řádky s kódem, který má
 *     `reverseVatCode`, dostanou papírovou podobu (`pct` 0), pár se vynechá
 *     a primární záznam rekapitulace má daň 0 a celkem = základ;
 *   - `vat.reverseCharge` — export ho nenese, odvodí se z kódů řádků / páru.
 */
final class SavedDocumentProjection
{
    private ?UnitResolver $units = null;
    private ?int $ownPersonId = null;
    private bool $ownResolved = false;
    /** @var array<string, bool> kód DPH → má reverseVatCode */
    private array $reverseCodes = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ?VatRateResolver $vat = null,
        private readonly string $country = 'cz',
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

        $reverse = false;
        $unitIds = $this->rowUnitIds($docId);
        $rows = [];
        foreach ((array) ($export['rows'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                $rows[] = $row;
                continue;
            }
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $item['name'] = $row['description'] ?? ($item['name'] ?? null);
            $row['item'] = $item;

            $row['unit'] = $this->paperUnit($row['unit'] ?? null, $proposal['rows'][$i]['unit'] ?? null, $unitIds[$i] ?? null);

            $code = $row['vat']['code'] ?? null;
            if (is_string($code) && $this->isReverseCode($code)) {
                $reverse = true;
                $row['vat']['pct'] = 0;
            }
            $rows[] = $row;
        }
        $out['rows'] = $rows;

        $recap = [];
        foreach ((array) ($export['vatRecap'] ?? []) as $entry) {
            if (!is_array($entry)) {
                $recap[] = $entry;
                continue;
            }
            if (($entry['isReversePair'] ?? null) === true) {
                $reverse = true;
                continue;
            }
            $code = $entry['vatCode'] ?? null;
            if (is_string($code) && $this->isReverseCode($code)) {
                $reverse = true;
                $entry['vatPct'] = 0;
                $entry['tax'] = 0;
                $entry['total'] = $entry['base'] ?? null;
            }
            $recap[] = $entry;
        }
        $out['vatRecap'] = $recap;

        $out['vat'] = is_array($out['vat'] ?? null) ? $out['vat'] : [];
        $out['vat']['reverseCharge'] = $reverse;

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

    private function isReverseCode(string $code): bool
    {
        if ($this->vat === null) {
            return false;
        }
        if (!array_key_exists($code, $this->reverseCodes)) {
            $def = $this->vat->getVatCode($this->country, $code);
            $this->reverseCodes[$code] = is_array($def) && !empty($def['reverseVatCode']);
        }
        return $this->reverseCodes[$code];
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
