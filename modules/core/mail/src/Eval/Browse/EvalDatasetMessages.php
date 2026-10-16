<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalMessages;

/**
 * Zprávy sady pro prohlížeč: výběr podle `messageId` (jen z výpisu),
 * buňka korpusu, projekce sady a cesta k PDF zprávy — první příloha PDF
 * v sidecar složce záznamu (`mail/0001-x.jsonc` → `mail/0001-x.files/`),
 * vždy přes `DatasetReader::resolvePath()`, takže nevede mimo sadu.
 */
final class EvalDatasetMessages
{
    /** @var list<EvalMessage> */
    private readonly array $messages;

    public function __construct(private readonly DatasetReader $reader)
    {
        $this->messages = EvalMessages::fromDataset($reader);
    }

    /** @return list<EvalMessage> v pořadí sady (receivedAt, messageId) */
    public function all(): array
    {
        return $this->messages;
    }

    public function find(string $messageId): ?EvalMessage
    {
        foreach ($this->messages as $message) {
            if ($message->messageId === $messageId) {
                return $message;
            }
        }
        return null;
    }

    /** @return array{messageId: string, receivedAt: string, stem: ?string, variant: ?string, form: ?string, primaryType: ?string} */
    public static function cell(EvalMessage $message): array
    {
        return [
            'messageId' => $message->messageId,
            'receivedAt' => $message->receivedAt,
            'stem' => $message->stem,
            'variant' => $message->variant,
            'form' => $message->form,
            'primaryType' => $message->expectedPrimaryType,
        ];
    }

    public function projection(): Projection
    {
        return Projection::load($this->reader->resolvePath('expected/projection.jsonc'));
    }

    /** Absolutní cesta k PDF zprávy, null bez přílohy PDF nebo bez souboru v sidecar složce. */
    public function pdfPath(EvalMessage $message): ?string
    {
        if ($message->file === null) {
            return null;
        }
        $record = $this->reader->readJsonc($message->file);
        $sidecar = SeedContext::sidecarDir($message->file);
        foreach ((array) ($record['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment) || !is_string($attachment['file'] ?? null)) {
                continue;
            }
            $name = basename($attachment['file']);
            $isPdf = ($attachment['mimeType'] ?? null) === 'application/pdf' || str_ends_with(strtolower($name), '.pdf');
            if (!$isPdf) {
                continue;
            }
            try {
                return $this->reader->resolvePath($sidecar . '/' . $name);
            } catch (DatasetException) {
                return null;
            }
        }
        return null;
    }
}
