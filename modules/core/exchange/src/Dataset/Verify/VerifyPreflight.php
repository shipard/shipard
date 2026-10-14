<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/**
 * Preflight `dataset-verify` (tasks/testing-phase3.md D26) — nic nemění,
 * při nálezu příkaz končí exit 2. Pojistka proti ostrým datům: zdroj dat
 * s jakoukoli zprávou mimo sadu nebo dokladem bez zdrojové zprávy ze sady
 * se nepustí, žádný `--force`. Země sady vs. zdroje dat a schémata hlídá
 * `DatasetPreflight`.
 */
final class VerifyPreflight
{
    public const PRODUCTION = 'production';
    private const LIST_LIMIT = 10;

    /** @return list<string> */
    public static function serverModeProblems(string $mode): array
    {
        return $mode === self::PRODUCTION
            ? ['server je v režimu production — dataset-verify používá návrhy a mění zdroj dat']
            : [];
    }

    /**
     * @param list<string>     $setMessageIds `messageId` zpráv sady
     * @param array<int, string> $dsMessages   id zprávy → `message_id` (všechny zprávy zdroje dat)
     * @param array<int, int|null> $dsDocs     id dokladu → `source_message` (všechny doklady, i Koš)
     * @return list<string>
     */
    public static function membershipProblems(array $setMessageIds, array $dsMessages, array $dsDocs): array
    {
        $set = array_fill_keys($setMessageIds, true);
        $foreignMessages = [];
        $setNdx = [];
        foreach ($dsMessages as $ndx => $messageId) {
            if (isset($set[$messageId])) {
                $setNdx[(int) $ndx] = true;
            } else {
                $foreignMessages[] = "zpráva #{$ndx} ({$messageId}) není ze sady";
            }
        }
        $foreignDocs = [];
        foreach ($dsDocs as $docId => $source) {
            if ($source === null) {
                $foreignDocs[] = "doklad #{$docId} nemá zdrojovou zprávu";
            } elseif (!isset($setNdx[(int) $source])) {
                $foreignDocs[] = "doklad #{$docId} vznikl ze zprávy #{$source} mimo sadu";
            }
        }
        return [...self::capped($foreignMessages), ...self::capped($foreignDocs)];
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function capped(array $items): array
    {
        if (count($items) <= self::LIST_LIMIT) {
            return $items;
        }
        $rest = count($items) - self::LIST_LIMIT;
        return [...array_slice($items, 0, self::LIST_LIMIT), "… a {$rest} dalších"];
    }
}
