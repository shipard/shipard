<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed řad vázaných na entitu (docTypes[].series_binding).
 *
 * Pro každý typ dokladu se `series_binding` a každou entitu ve stavu
 * V pořádku nebo V archívu (pokladna / sklad, 40 / 70) zajistí existenci
 * řady (doc_type, FK) mimo stav Smazáno. Nová řada: název „{typ} — {kód
 * entity}", kód řady (%C) = kód entity, vzorec z `doc_number_pattern_default`,
 * restart per fiskální rok, stav dle entity (40/3, archivovaná pokladna
 * 70/4 — jen pro import historických dokladů). Přejmenování entity se do
 * `doc_number_code` NEpropaguje (záměr, #59 D3) — čísla už vydaných
 * dokladů se nesmí měnit. Stejně tak do kódu řady (`code`, přirozený klíč
 * `<typ>-<kód entity>`, #166 D17), který řadě po vložení doplní
 * NumberSeriesCodeBackfill.
 *
 * Běží z `ds-upgrade` i pod skipProvisioning (řady jsou infrastruktura,
 * kterou import dokladů potřebuje — D12) a z CashDeskSeriesEventHandler
 * po uložení pokladny do stavu 40. Vazby jsou obecné přes
 * NumberSeriesDocument::BINDINGS — sklad nevyžaduje žádný další kód.
 */
final class BoundNumberSeriesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * Stavy entity, pro které řada vzniká, → stav založené řady
     * (docState => docStateMain dle core.system.docStatesArchive: 40 V pořádku
     * = mainState 3, 70 V archívu = mainState 4). Archivovaná pokladna (#59
     * Task E, E2) dostane řady v archivu: import historických dokladů ji
     * přijme (DocumentApplier v import módu), UI ji nenabízí (viewer i form
     * berou jen aktivní řady).
     */
    public const ENTITY_STATE_TO_SERIES_MAIN_STATE = [40 => 3, 70 => 4];

    /**
     * Všechny vázané typy × všechny entity ve stavu V pořádku / V archívu.
     *
     * @return array{created: int, existing: int}
     */
    public function provision(): array
    {
        $created = 0;
        $existing = 0;

        foreach ($this->boundDocTypes() as $docTypeKey => [$docType, $binding]) {
            $entities = $this->db->fetchAll(
                'SELECT id, code, docState FROM ' . NumberSeriesDocument::BINDINGS[$binding]['table']
                . ' WHERE docState IN %in ORDER BY id',
                array_keys(self::ENTITY_STATE_TO_SERIES_MAIN_STATE),
            );
            foreach ($entities as $entity) {
                $this->ensureSeries($docTypeKey, $docType, $binding, (int) $entity['id'], (string) $entity['code'], (int) $entity['docState'])
                    ? $created++
                    : $existing++;
            }
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * Jedna entita (po jejím uložení): řady všech typů s daným bindingem.
     * Entita mimo stav 40/70 nebo neexistující → no-op.
     *
     * @return array{created: int, existing: int}
     */
    public function provisionForEntity(string $binding, int $entityId): array
    {
        if (!isset(NumberSeriesDocument::BINDINGS[$binding])) {
            throw new \InvalidArgumentException("Unknown series binding '{$binding}'");
        }

        $entity = $this->db->fetchRow(
            'SELECT id, code, docState FROM ' . NumberSeriesDocument::BINDINGS[$binding]['table']
            . ' WHERE id = %i',
            $entityId,
        );
        if ($entity === null || !isset(self::ENTITY_STATE_TO_SERIES_MAIN_STATE[(int) $entity['docState']])) {
            return ['created' => 0, 'existing' => 0];
        }

        $created = 0;
        $existing = 0;
        foreach ($this->boundDocTypes() as $docTypeKey => [$docType, $typeBinding]) {
            if ($typeBinding !== $binding) {
                continue;
            }
            $this->ensureSeries($docTypeKey, $docType, $binding, $entityId, (string) $entity['code'], (int) $entity['docState'])
                ? $created++
                : $existing++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /** @return array{created: int, existing: int} */
    public function provisionForCashDesk(int $cashDeskId): array
    {
        return $this->provisionForEntity('cash_desk', $cashDeskId);
    }

    /**
     * Typy dokladu se známým `series_binding`.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>  key → [docType, binding]
     */
    private function boundDocTypes(): array
    {
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return [];
        }

        $out = [];
        foreach ($docTypes as $key => $docType) {
            if (!is_string($key) || !is_array($docType)) {
                continue;
            }
            $binding = $docType['series_binding'] ?? null;
            if (!is_string($binding) || !isset(NumberSeriesDocument::BINDINGS[$binding])) {
                continue;
            }
            $out[$key] = [$docType, $binding];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $docType
     * @return bool true = řada vytvořena, false = už existovala
     */
    private function ensureSeries(string $docTypeKey, array $docType, string $binding, int $entityId, string $code, int $entityState = 40): bool
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series
             WHERE doc_type = %s AND ' . $binding . ' = %i AND docState != %i
             LIMIT 1',
            $docTypeKey,
            $entityId,
            90,
        );
        if ($row !== null) {
            return false;
        }

        $typeName = (string) ($docType['name:cs'] ?? $docType['name'] ?? $docTypeKey);
        $code     = trim($code);
        $name     = $code !== '' ? "{$typeName} — {$code}" : $typeName;
        $pattern  = (string) ($docType['doc_number_pattern_default'] ?? '%D%C%y%5');

        $this->db->insertRow('docs_core_number_series', [
            'doc_type'           => $docTypeKey,
            $binding             => $entityId,
            'name'               => mb_substr($name, 0, 100),
            'doc_number_code'    => $code !== '' ? $code : null,
            'doc_number_pattern' => $pattern,
            'reset_scope'        => 'fiscal_year',
            // Řada dědí stav entity (40/3, 70/4). Přechod pokladny 40 → 70
            // s už existující řadou 40 se tu neřeší (lookup výše ji najde).
            'docState'           => $entityState,
            'docStateMain'       => self::ENTITY_STATE_TO_SERIES_MAIN_STATE[$entityState] ?? 3,
        ]);
        // Řádek vzniká mimo Document — kód řady (`cash-<kód pokladny>`,
        // #166 D17) doplní služba hned po vložení. Běží i líně uprostřed
        // apply dokladu: bez vlastní transakce, souběh řeší dalším kódem.
        (new NumberSeriesCodeBackfill($this->db->getDibiConnection()))->run();
        return true;
    }
}
