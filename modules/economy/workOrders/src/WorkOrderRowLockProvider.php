<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentLockReason;

/**
 * Zámek řádků předpisu podle stavu zakázky (`documentLockProviders` nad
 * `economy_work_orders_rows`, tasks/work-orders-rows-readonly.md): řádek je
 * zamčený, když zakázka — uložená i nově zadaná v `work_order` — je ve stavu
 * s `readOnly` v `economy.workOrders.docStates` (V pořádku, Ukončeno,
 * Zrušeno, Smazáno). Potvrzená zakázka je celá jen ke čtení, řádky se mění
 * přes V opravě (docs/work-orders.md §5.4); stavy se čtou z cfgItem, žádná
 * čísla natvrdo.
 *
 * Jádro provider vynucuje na všech zápisových cestách řádku: uložení
 * z dialogu (TableGateway → chyba `_form` / `locked`), mazání (gateway
 * i generické CRUD → `DOCUMENT_LOCKED`), CRUD update / patch. Přesun řádků
 * hlídá `/subtable/…/move` podle stavu rodiče (`DOCUMENT_READONLY`).
 * Import mód výjimku nemá — import zapisuje řádky před potvrzením zakázky
 * (docs/work-orders.md §6). Bez zkompilovaného cfgItem (DS před
 * ds-upgrade) nezamyká nic.
 *
 * DB přístup je v protected metodě (přepsatelné v testech).
 */
class WorkOrderRowLockProvider extends AbstractDocumentLockProvider
{
    public const SOURCE = 'work_order_state';

    /** tableId `economy_work_orders_heads` */
    public const SUBJECT_TABLE_ID = 460;

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($this->db === null) {
            return [];
        }
        $cfgData = $this->config?->cfgItem(WorkOrderDocument::DOC_STATES_CFG_ITEM);
        if (!is_array($cfgData) || $cfgData === []) {
            return [];
        }
        $states = DocStateConfig::fromCfgItem($cfgData);

        $ids = [];
        foreach ([$data['work_order'] ?? null, $original['work_order'] ?? null] as $value) {
            $id = (int) ($value ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $reasons = [];
        foreach ($this->loadWorkOrders(array_keys($ids)) as $id => $workOrder) {
            $docState = (int) ($workOrder['docState'] ?? 0);
            if (!$states->isReadOnly($docState)) {
                continue;
            }
            // Smazaný koncept číslo nemá — label z názvu.
            $label = trim((string) ($workOrder['number'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($workOrder['title'] ?? '')) ?: "#{$id}";
            }
            $stateName = (string) ($states->getState($docState)['stateName'] ?? $docState);
            $reasons[] = new DocumentLockReason(
                source: self::SOURCE,
                title: "Zakázka {$label} je jen ke čtení — řádky uprav přes V opravě.",
                message: "Řádky předpisu se řídí stavem zakázky ({$stateName}): dej zakázku V opravě,"
                    . ' uprav řádky a vrať ji V pořádku. Koncept faktury se starými řádky pak Přegeneruj.',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $id,
                params: ['label' => $label, 'state' => $stateName, 'docState' => $docState],
            );
        }
        return $reasons;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    /**
     * Zakázky podle id: number, title, docState.
     *
     * @param list<int> $ids
     * @return array<int, array{number: ?string, title: ?string, docState: int}>
     */
    protected function loadWorkOrders(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [number], [title], [docState] FROM [' . WorkOrderDocument::TABLE . '] WHERE [id] IN %in',
            $ids,
        ) as $row) {
            $out[(int) $row['id']] = [
                'number'   => $row['number'] !== null ? (string) $row['number'] : null,
                'title'    => $row['title'] !== null ? (string) $row['title'] : null,
                'docState' => (int) $row['docState'],
            ];
        }
        return $out;
    }
}
