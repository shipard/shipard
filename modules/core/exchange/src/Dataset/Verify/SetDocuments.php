<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Dibi\Connection;

/**
 * Načtení zpráv sady ze zdroje dat v pořadí `received_at` (`0V01` založí
 * dodavatele, `0V02` ho najde) s posledním návrhem a dokladem, který z nich
 * vznikl — i z dřívějšího běhu, kontroly jedou nad všemi doklady sady.
 */
final class SetDocuments
{
    public const SKIP_FOREIGN_CURRENCY = 'kurz — #121';

    /**
     * @param list<string> $messageIds `messageId` zpráv sady
     * @return list<SetDocument>
     */
    public static function load(Connection $db, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $messages = $db->fetchAll(
            'SELECT [id], [message_id], [received_at] FROM [core_mail_incoming_messages]'
            . ' WHERE [message_id] IN %in ORDER BY [received_at], [message_id], [id]',
            $messageIds,
        );
        if ($messages === []) {
            return [];
        }
        $ndxs = array_map(static fn ($m): int => (int) $m['id'], $messages);
        $docs = [];
        foreach ($db->fetchAll(
            'SELECT [id], [docState], [doc_number], [source_message] FROM [docs_core_heads]'
            . ' WHERE [source_message] IN %in AND [docState] <> 90 ORDER BY [id]',
            $ndxs,
        ) as $doc) {
            $docs[(int) $doc['source_message']] ??= $doc;
        }

        $out = [];
        foreach ($messages as $m) {
            $ndx = (int) $m['id'];
            $analysis = $db->fetch(
                'SELECT [id], [resolution], [canonical_json] FROM [core_mail_message_analyses]'
                . ' WHERE [message] = %i AND [status] = 2 ORDER BY [analyzed_at] DESC, [id] DESC LIMIT 1',
                $ndx,
            );
            $canonical = null;
            if ($analysis !== null && is_string($analysis['canonical_json']) && $analysis['canonical_json'] !== '') {
                $decoded = json_decode($analysis['canonical_json'], true);
                $canonical = is_array($decoded) ? $decoded : null;
            }
            $received = $m['received_at'];
            $doc = $docs[$ndx] ?? null;
            $out[] = new SetDocument(
                messageId: (string) $m['message_id'],
                messageNdx: $ndx,
                receivedAt: $received instanceof \DateTimeInterface ? $received->format('Y-m-d H:i:s') : (string) $received,
                analysisNdx: $analysis !== null ? (int) $analysis['id'] : null,
                resolution: $analysis !== null && $analysis['resolution'] !== null ? (int) $analysis['resolution'] : null,
                canonical: $canonical,
                docId: $doc !== null ? (int) $doc['id'] : null,
                docState: $doc !== null ? (int) $doc['docState'] : null,
                docNumber: $doc !== null && $doc['doc_number'] !== null ? (string) $doc['doc_number'] : null,
            );
        }
        return $out;
    }

    /**
     * Zprávy, které se nepoužijí: návrh v jiné než domácí měně bez kurzu
     * (#103 D30, do #121). Doklad, který už existuje, přeskočený není.
     *
     * @param list<SetDocument> $documents
     * @return array<string, string> messageId → důvod
     */
    public static function skippedForCurrency(array $documents, string $homeCurrency): array
    {
        $home = strtoupper(trim($homeCurrency));
        $out = [];
        foreach ($documents as $doc) {
            if ($doc->isApplied()) {
                continue;
            }
            $currency = $doc->currency();
            if ($currency !== null && $currency !== $home) {
                $out[$doc->messageId] = self::SKIP_FOREIGN_CURRENCY;
            }
        }
        return $out;
    }
}
