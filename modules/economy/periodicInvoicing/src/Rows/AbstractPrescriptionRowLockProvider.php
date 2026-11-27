<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Rows;

use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentLockReason;

/**
 * Společný základ zámku řádků předpisu podle stavu rodiče
 * (`documentLockProviders` nad tabulkou řádků, #174 D31): řádek je
 * zamčený, když rodič — uložený i nově zadaný ve sloupci rodiče — je ve
 * stavu s `readOnly` ve své sadě stavů. Potvrzený rodič je celý jen ke
 * čtení, řádky se mění přes V opravě; stavy se čtou z cfgItem, žádná čísla
 * natvrdo.
 *
 * Jádro provider vynucuje na všech zápisových cestách řádku: uložení
 * z dialogu (TableGateway → chyba `_form` / `locked`), mazání (gateway
 * i generické CRUD → `DOCUMENT_LOCKED`), CRUD update / patch. Přesun řádků
 * hlídá `/subtable/…/move` podle stavu rodiče (`DOCUMENT_READONLY`).
 * Import mód výjimku nemá. Bez zkompilovaného cfgItem (DS před ds-upgrade)
 * nezamyká nic.
 *
 * Potomek dodá tabulku a sloupec rodiče, cfgItem jeho stavů, předmět
 * důvodu a texty; DB přístup je v protected metodě (přepsatelné v testech).
 */
abstract class AbstractPrescriptionRowLockProvider extends AbstractDocumentLockProvider
{
    /** Tabulka rodiče. */
    abstract protected function parentTable(): string;

    /** Sloupec řádku s id rodiče. */
    abstract protected function parentColumn(): string;

    /** cfgItem sady stavů rodiče. */
    abstract protected function docStatesCfgItem(): string;

    /** Stabilní `tableId` tabulky rodiče — předmět důvodu zámku. */
    abstract protected function subjectTableId(): int;

    /** Strojové id důvodu (`DocumentLockReason::$source`). */
    abstract protected function source(): string;

    /** Titulek důvodu s popiskem rodiče. */
    abstract protected function lockTitle(string $label): string;

    /** Vysvětlení důvodu se jménem stavu rodiče. */
    abstract protected function lockMessage(string $stateName): string;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($this->db === null) {
            return [];
        }
        $cfgData = $this->config?->cfgItem($this->docStatesCfgItem());
        if (!is_array($cfgData) || $cfgData === []) {
            return [];
        }
        $states = DocStateConfig::fromCfgItem($cfgData);

        $column = $this->parentColumn();
        $ids = [];
        foreach ([$data[$column] ?? null, $original[$column] ?? null] as $value) {
            $id = (int) ($value ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $reasons = [];
        foreach ($this->loadParents(array_keys($ids)) as $id => $parent) {
            $docState = (int) ($parent['docState'] ?? 0);
            if (!$states->isReadOnly($docState)) {
                continue;
            }
            $label = $this->parentLabel($parent, (int) $id);
            $stateName = (string) ($states->getState($docState)['stateName'] ?? $docState);
            $reasons[] = new DocumentLockReason(
                source: $this->source(),
                title: $this->lockTitle($label),
                message: $this->lockMessage($stateName),
                subjectTableId: $this->subjectTableId(),
                subjectRowId: (int) $id,
                params: ['label' => $label, 'state' => $stateName, 'docState' => $docState],
            );
        }
        return $reasons;
    }

    /**
     * Popisek rodiče v titulku důvodu: číslo, bez něj (smazaný koncept
     * číslo nemá) název, jinak `#id`.
     *
     * @param array<string, mixed> $parent
     */
    protected function parentLabel(array $parent, int $id): string
    {
        $label = trim((string) ($parent['number'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($parent['title'] ?? '')) ?: "#{$id}";
        }
        return $label;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Rodiče podle id — řádky tabulky rodiče (aspoň `docState` a sloupce
     * popisku).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    protected function loadParents(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM [' . $this->parentTable() . '] WHERE [id] IN %in', $ids) as $row) {
            $out[(int) $row['id']] = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
        }
        return $out;
    }
}
