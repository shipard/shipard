<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Zprávy sady z `mail/*.jsonc` + `expected/messages.json` v pořadí sady
 * (`receivedAt`, `messageId`) a výběr `--only` / `--limit`.
 */
final class EvalMessages
{
    public const string MESSAGES_FILE = 'expected/messages.json';

    private function __construct()
    {
    }

    /**
     * @return list<EvalMessage>
     * @throws EvalException zpráva bez ideální analýzy
     */
    public static function fromDataset(DatasetReader $reader): array
    {
        $cells = [];
        if ($reader->fileExists(self::MESSAGES_FILE)) {
            foreach ($reader->readJsonc(self::MESSAGES_FILE) as $record) {
                if (is_array($record) && is_string($record['messageId'] ?? null)) {
                    $cells[$record['messageId']] = $record;
                }
            }
        }

        $out = [];
        foreach ($reader->listFiles('mail') as $rel) {
            $message = $reader->readJsonc($rel);
            $messageId = $message['messageId'] ?? null;
            if (!is_string($messageId) || $messageId === '') {
                throw new EvalException("{$rel}: zpráva bez messageId");
            }
            $canonical = null;
            foreach ((array) ($message['analyses'] ?? []) as $analysis) {
                if (is_array($analysis) && is_array($analysis['canonicalJson'] ?? null)) {
                    $canonical = $analysis['canonicalJson'];
                }
            }
            if ($canonical === null) {
                throw new EvalException("{$rel}: zpráva {$messageId} nemá ideální analýzu (canonicalJson) — evaluace nemá s čím porovnat");
            }
            $cell = $cells[$messageId] ?? [];
            $attachment = $message['attachments'][0]['file'] ?? null;
            $stem = is_string($cell['stem'] ?? null) ? $cell['stem'] : (is_string($attachment) ? pathinfo($attachment, PATHINFO_FILENAME) : null);
            $out[] = new EvalMessage(
                messageId: $messageId,
                receivedAt: (string) ($message['receivedAt'] ?? ''),
                expectedCanonical: $canonical,
                expectedPrimaryType: is_string($message['primaryType'] ?? null) ? $message['primaryType'] : null,
                stem: $stem,
                variant: is_string($cell['variant'] ?? null) ? $cell['variant'] : null,
                form: is_string($cell['form'] ?? null) ? $cell['form'] : null,
            );
        }
        usort($out, static fn (EvalMessage $a, EvalMessage $b): int => [$a->receivedAt, $a->messageId] <=> [$b->receivedAt, $b->messageId]);
        return $out;
    }

    /** `--only 0101,0902` → tokeny. @return list<string> */
    public static function parseOnly(?string $option): array
    {
        if ($option === null || trim($option) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $option)), static fn (string $t): bool => $t !== ''));
    }

    /**
     * Token `--only` = messageId, stem (`0101-domestic-goods`), jeho číselný
     * prefix (`0101`) nebo varianta (`domestic-goods`); `--limit` až po výběru.
     *
     * @param list<EvalMessage> $messages
     * @param list<string>      $only
     * @return list<EvalMessage>
     */
    public static function filter(array $messages, array $only, ?int $limit): array
    {
        if ($only !== []) {
            $messages = array_values(array_filter($messages, static function (EvalMessage $m) use ($only): bool {
                foreach ($only as $token) {
                    if ($token === $m->messageId || $token === $m->stem || $token === $m->variant
                        || ($m->stem !== null && str_starts_with($m->stem, $token . '-'))) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if ($limit !== null && $limit > 0) {
            $messages = array_slice($messages, 0, $limit);
        }
        return $messages;
    }
}
