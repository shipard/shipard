<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Posting;

use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Module\Docs\Core\DocRowOperationRules;

/**
 * Zámek účetního dokladu majetku (`documentLockProviders` pro
 * `docs_core_heads`, docs/assets.md D53): doklad s řádkem `asset.*` spravuje
 * Majetek — ruční oprava, přechod stavu ani smazání nejdou; zaúčtování se
 * ruší akcí „Zrušit zaúčtování období“. Platí ve všech stavech, tedy i pro
 * stornovaný doklad (neoživí se ručně).
 *
 * Zápis služby majetku ({@see AssetPostingDocuments}) se pozná podle markeru
 * `_systemOperations` v datech — ten provider pouští. Výjimka je záměrně
 * tady, ne v `Document::isLockExempt()`: ostatní providery (zámek fiskálního
 * měsíce, období DPH) platí pro službu dál. Marker z formuláře ani CRUD
 * přijít nemůže — není to sloupec tabulky.
 *
 * DB přístup je v protected metodě (přepsatelné v testech).
 */
class AssetPostingDocLockProvider extends AbstractDocumentLockProvider
{
    public const SOURCE = 'asset_posting';

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($original === null || empty($original['id'])) {
            return [];
        }
        if (!empty($data[DocRowOperationRules::SYSTEM_OPERATIONS_KEY])) {
            return [];
        }
        if ((string) ($original['doc_type'] ?? '') !== AssetPostingSeries::DOC_TYPE) {
            return [];
        }
        if (!$this->hasAssetRows((int) $original['id'])) {
            return [];
        }

        return [new DocumentLockReason(
            source: self::SOURCE,
            title: 'Doklad spravuje Majetek',
            message: 'Účetní doklad vznikl zaúčtováním majetku — ručně ho nejde opravit ani stornovat.'
                . ' Zaúčtování se ruší v Majetku akcí Zrušit zaúčtování období.',
        )];
    }

    /** Má doklad řádek se systémovou operací majetku (`rowAsset` + `system`)? */
    protected function hasAssetRows(int $docHeadId): bool
    {
        if ($this->db === null) {
            return false;
        }
        $operations = [];
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        foreach (is_array($cfg) ? $cfg : [] as $operation => $entry) {
            if (is_array($entry) && !empty($entry['rowAsset']) && !empty($entry['system'])) {
                $operations[] = (string) $operation;
            }
        }
        $row = $operations !== []
            ? $this->db->fetch(
                'SELECT [id] FROM [docs_core_rows] WHERE [doc_head] = %i AND [operation] IN %in LIMIT 1',
                $docHeadId,
                $operations,
            )
            : $this->db->fetch(
                'SELECT [id] FROM [docs_core_rows] WHERE [doc_head] = %i AND [operation] LIKE %s LIMIT 1',
                $docHeadId,
                'asset.%',
            );

        return $row !== null && $row !== false;
    }
}
