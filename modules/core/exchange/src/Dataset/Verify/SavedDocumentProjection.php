<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Export\CanonicalParty;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Adaptér export → papírová projekce (tasks/testing-phase3.md, kontrola 1):
 * export dokladu (`DocumentExporter`) nenese to, co projekce čeká, protože
 * to v datech leží jinde — doplní se ze zdroje dat, nic se nehádá:
 *
 *   - `customer` — vlastní strana se neexportuje; doplní se vlastní firma
 *     (`OwnCompanyResolver` + `CanonicalParty::fromPerson`), jen když ji
 *     návrh nese (účtenka bez odběratele zůstává `null`);
 *   - `rows[].item.name` — export dává název účetní položky, text řádku
 *     z dokladu je v `rows[].description` (`CanonicalRowText::compose`);
 *   - `rows[].unit` — export dává `core_units.system_code`; když jednotka
 *     návrhu (`UnitResolver`) vede na tutéž jednotku jako uložený řádek,
 *     dosadí se text z návrhu, jinak zůstane kód (= rozdíl);
 *   - `vat.reverseCharge` — export ho nenese, odvodí se z párové
 *     rekapitulace (`vatRecap[].isReversePair`).
 */
final class SavedDocumentProjection
{
    private ?UnitResolver $units = null;
    private ?int $ownPersonId = null;
    private bool $ownResolved = false;

    public function __construct(private readonly Connection $db) {}

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
        foreach ((array) ($export['vatRecap'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['isReversePair'] ?? null) === true) {
                $reverse = true;
            }
        }
        $out['vat'] = is_array($out['vat'] ?? null) ? $out['vat'] : [];
        $out['vat']['reverseCharge'] = $reverse;

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

            $proposalUnit = $proposal['rows'][$i]['unit'] ?? null;
            $rowUnit = $unitIds[$i] ?? null;
            if (is_string($proposalUnit) && $rowUnit !== null) {
                $resolved = $this->units()->resolve($proposalUnit);
                if ($resolved->status === ResolveStatus::Matched && $resolved->matchedId === $rowUnit) {
                    $row['unit'] = $proposalUnit;
                }
            }
            $rows[] = $row;
        }
        $out['rows'] = $rows;
        return $out;
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
