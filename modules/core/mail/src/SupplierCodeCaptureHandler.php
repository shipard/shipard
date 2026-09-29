<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;

/**
 * Uzavření smyčky AI extrakce (D8): při potvrzení dokladu vzniklého z AI
 * extrakce (přechod 10 Koncept → 40 V pořádku, lineage `aiExtraction`)
 * zapíše naučená mapování dodavatelských kódů do
 * `economy_items_supplier_codes` — pro canonical řádky s extrahovaným
 * `item.supplierCode`, kterým finální řádek dokladu přiřadil položku.
 *
 * Doplňuje apply-time zápis v DocumentApplier::writeSupplierCodeMappings:
 * ten pokryje řádky vyřešené resolverem už při apply, tento handler řádky,
 * kde uživatel položku přiřadil ručně až v Konceptu. Překryv je neškodný —
 * unique index (person, supplier_code) + INSERT IGNORE.
 *
 * Párování canonical → finální řádky je poziční (`order_pos` = pořadí
 * canonical řádku + 1, viz DocumentApplier::transformRows). Uživatel mohl
 * řádky v Konceptu přeuspořádat — proto konzervativní guard: když se
 * popisy obou stran liší, řádek se přeskočí (best-effort, žádná škoda).
 *
 * Capture nikdy neblokuje vystavení: běží po commitu a dispatcher výjimky
 * z onStateChanged loguje a polyká.
 *
 * Ne-final kvůli testům — Connection::query je final, testy přepisují
 * executeSql subclassingem (vzor DocumentApplier).
 */
class SupplierCodeCaptureHandler extends AbstractDocumentEventHandler
{
    private const STATE_DRAFT = 10;
    private const STATE_CONFIRMED = 40;

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        if ($oldState !== self::STATE_DRAFT || $newState !== self::STATE_CONFIRMED) {
            return;
        }
        $docId = (int) $data['id'];

        // Lineage + partner čteme z DB — $data nemusí nést všechny sloupce.
        $head = $this->db->fetch(
            'SELECT [partner], [source_kind], [source_message]
             FROM [docs_core_heads] WHERE [id] = %i',
            $docId,
        );
        if ($head === null) {
            return;
        }
        $partnerId = (int) ($head['partner'] ?? 0);
        $messageNdx = (int) ($head['source_message'] ?? 0);
        if (($head['source_kind'] ?? null) !== 'aiExtraction' || $messageNdx <= 0 || $partnerId <= 0) {
            return;
        }

        $canonicalRows = $this->loadCanonicalRows($messageNdx);
        if ($canonicalRows === []) {
            return;
        }

        $finalRows = $this->loadFinalRowsByOrderPos($docId);

        foreach (array_values($canonicalRows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $supplierCode = trim((string) ($item['supplierCode'] ?? ''));
            if ($supplierCode === '') {
                continue;
            }

            $final = $finalRows[$i + 1] ?? null;
            if ($final === null) {
                continue;
            }

            // Poziční guard: liší-li se popisy (oba vyplněné), řádky si
            // po editaci Konceptu už neodpovídají → přeskočit. Text musí
            // vzniknout STEJNĚ jako v DocumentApplier::transformRows(), jinak
            // guard nesedí nikdy a kódy se tiše přestanou učit (#84).
            $canonicalText = CanonicalRowText::compose($row) ?? '';
            $finalText = trim((string) ($final['description'] ?? ''));
            if ($canonicalText !== '' && $finalText !== '' && $canonicalText !== $finalText) {
                continue;
            }

            $supplierName = trim((string) ($item['name'] ?? ''));
            $this->executeSql(
                'INSERT IGNORE INTO [economy_items_supplier_codes]
                 ([person], [item], [supplier_code], [supplier_name], [created])
                 VALUES (%i, %i, %s, %sN, NOW())',
                $partnerId,
                (int) $final['item'],
                $supplierCode,
                $supplierName !== '' ? $supplierName : null,
            );
        }
    }

    /**
     * Canonical návrhu = poslední úspěšná analýza zdrojové zprávy
     * (konvence MAX(analyzed_at), viz MessageProposalApplier).
     *
     * @return array<int, mixed>
     */
    private function loadCanonicalRows(int $messageNdx): array
    {
        $analysis = $this->db?->fetch(
            'SELECT [canonical_json] FROM [core_mail_message_analyses]
             WHERE [message] = %i AND [status] = %i
             ORDER BY [analyzed_at] DESC, [id] DESC
             LIMIT 1',
            $messageNdx,
            2,
        );
        if ($analysis === null) {
            return [];
        }
        $canonical = json_decode((string) ($analysis['canonical_json'] ?? ''), true);
        if (!is_array($canonical) || !is_array($canonical['rows'] ?? null)) {
            return [];
        }
        return $canonical['rows'];
    }

    /**
     * @return array<int, array<string, mixed>>  Řádky s položkou, klíč = order_pos.
     */
    private function loadFinalRowsByOrderPos(int $docId): array
    {
        $rows = $this->db?->fetchAll(
            'SELECT [order_pos], [item], [description]
             FROM [docs_core_rows]
             WHERE [doc_head] = %i AND [item] IS NOT NULL',
            $docId,
        ) ?? [];

        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $out[(int) $arr['order_pos']] = $arr;
        }
        return $out;
    }

    /**
     * Wrapper nad Connection::query (final, nelze mockovat) — testy
     * přepisují subclassingem, stejný vzor jako DocumentApplier/ItemApplier.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db?->query(...$args);
    }
}
