<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Shipard\Core\Document\AbstractDocumentEventHandler;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Učení pravidel obsahových štítků (tasks/content-tag-enrichment.md, D22):
 * při potvrzení dokladu vzniklého z AI extrakce nebo ISDOC importu
 * (přechod 10 Koncept → 40 V pořádku, lineage `aiExtraction` / `isdoc` —
 * #81 D2, ISDOC prochází touž obsahovou eskalací) s LLM štítkem zapíše
 * pravidlo IČO dodavatele → štítek do `core_exchange_tag_rules` (origin
 * `learned`, platné okamžitě — další doklad téhož IČO jde bez LLM).
 *
 * Upsert logika (jedno pravidlo per IČO):
 *  - žádné pravidlo → INSERT learned,
 *  - existující se STEJNÝM štítkem → jen statistiky (hit_count/last_hit_at),
 *  - existující learned s JINÝM štítkem → pravidlo SMAZAT + log — dodavatel
 *    s pestrým sortimentem (hobbymarket), pravidlo by škodilo,
 *  - existující user/seed s jiným štítkem → no-op + log (learning ruční
 *    a seedovaná pravidla nikdy nemění).
 *
 * Učí se JEN z LLM štítků (`tagSource: 'llm'` v `_resolve.contentTag`) —
 * rule-sourced štítek už pravidlo má, nic nového by nevzniklo.
 *
 * Capture nikdy neblokuje vystavení: běží po commitu a dispatcher výjimky
 * z onStateChanged loguje a polyká. Ne-final kvůli testům —
 * Connection::query je final, testy přepisují executeSql subclassingem
 * (vzor SupplierCodeCaptureHandler).
 */
class ContentTagRuleCaptureHandler extends AbstractDocumentEventHandler
{
    private const STATE_DRAFT = 10;
    private const STATE_CONFIRMED = 40;

    /** Lineage dokladů, ze kterých se pravidla učí (`docs_core_heads.source_kind`). */
    private const LEARNING_SOURCE_KINDS = ['aiExtraction', 'isdoc'];

    public function onStateChanged(string $tableId, array $data, int $oldState, int $newState): void
    {
        if ($this->db === null || empty($data['id'])) {
            return;
        }
        if ($oldState !== self::STATE_DRAFT || $newState !== self::STATE_CONFIRMED) {
            return;
        }
        $docId = (int) $data['id'];

        // Lineage čteme z DB — $data nemusí nést všechny sloupce.
        $head = $this->db->fetch(
            'SELECT [source_kind], [source_message]
             FROM [docs_core_heads] WHERE [id] = %i',
            $docId,
        );
        if ($head === null) {
            return;
        }
        $messageNdx = (int) ($head['source_message'] ?? 0);
        if (!in_array($head['source_kind'] ?? null, self::LEARNING_SOURCE_KINDS, true) || $messageNdx <= 0) {
            return;
        }

        $canonical = $this->loadCanonical($messageNdx);
        if ($canonical === null) {
            return;
        }

        $block = $canonical['_resolve']['contentTag'] ?? null;
        if (!is_array($block) || ($block['tagSource'] ?? null) !== 'llm') {
            return; // učí se jen z LLM štítků
        }
        $tag = trim((string) ($block['tag'] ?? ''));
        if ($tag === '') {
            return;
        }

        $companyId = $this->supplierCompanyId($canonical);
        if ($companyId === null) {
            return;
        }

        $existing = $this->db->fetch(
            'SELECT [id], [tag], [origin] FROM [core_exchange_tag_rules]
             WHERE [company_id] = %s',
            $companyId,
        );

        if ($existing === null) {
            $this->executeSql(
                'INSERT INTO [core_exchange_tag_rules]
                 ([company_id], [tag], [origin], [confirmed], [hit_count], [created])
                 VALUES (%s, %s, %s, 1, 0, NOW())',
                $companyId,
                $tag,
                'learned',
            );
            return;
        }

        if ((string) $existing['tag'] === $tag) {
            // Shoda — pravidlo potvrzeno dalším dokladem, jen statistiky.
            $this->executeSql(
                'UPDATE [core_exchange_tag_rules]
                 SET [hit_count] = [hit_count] + 1, [last_hit_at] = NOW(), [modified] = NOW()
                 WHERE [id] = %i',
                (int) $existing['id'],
            );
            return;
        }

        if ((string) ($existing['origin'] ?? '') === 'learned') {
            // Konflikt štítků u learned pravidla → dodavatel s pestrým
            // sortimentem, pravidlo by škodilo — smazat.
            $this->executeSql(
                'DELETE FROM [core_exchange_tag_rules] WHERE [id] = %i',
                (int) $existing['id'],
            );
            ErrorLogger::info('ContentTagRuleCaptureHandler: conflicting learned rule deleted', [
                'companyId' => $companyId,
                'ruleTag'   => (string) $existing['tag'],
                'newTag'    => $tag,
            ]);
            return;
        }

        // user/seed pravidla learning nikdy nemění.
        ErrorLogger::info('ContentTagRuleCaptureHandler: conflict with user/seed rule ignored', [
            'companyId' => $companyId,
            'origin'    => (string) ($existing['origin'] ?? ''),
            'ruleTag'   => (string) $existing['tag'],
            'newTag'    => $tag,
        ]);
    }

    /**
     * Canonical návrhu = poslední úspěšná analýza zdrojové zprávy
     * (konvence MAX(analyzed_at), viz MessageProposalApplier).
     *
     * @return array<string, mixed>|null
     */
    private function loadCanonical(int $messageNdx): ?array
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
            return null;
        }
        $canonical = json_decode((string) ($analysis['canonical_json'] ?? ''), true);
        return is_array($canonical) ? $canonical : null;
    }

    /**
     * Normalizované IČO protistrany z canonicalu — stejná selfParty logika
     * jako RowEnrichmentPipeline::supplierCompanyId().
     *
     * @param array<string, mixed> $canonical
     */
    private function supplierCompanyId(array $canonical): ?string
    {
        $counterKey = ($canonical['selfParty'] ?? null) === 'supplier' ? 'customer' : 'supplier';
        $party = is_array($canonical[$counterKey] ?? null) ? $canonical[$counterKey] : [];
        $normalized = ContentTagResolver::normalizeCompanyId((string) ($party['companyId'] ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Wrapper nad Connection::query (final, nelze mockovat) — testy
     * přepisují subclassingem, stejný vzor jako SupplierCodeCaptureHandler.
     */
    protected function executeSql(mixed ...$args): void
    {
        $this->db?->query(...$args);
    }
}
