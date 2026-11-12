<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Mcp;

use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Module\Core\Mail\MessageProposalApplier;

/**
 * Zápisový MCP nástroj: z analyzované došlé pošty založí KONCEPT dokladu
 * (faktury) z dokumentového návrhu zprávy. Vždy `autoCreateMode='safe'`
 * + `targetDocState=10` (Koncept) — AI nikdy nefinalizuje ani nezakládá
 * master data. Reference vyžadující rozhodnutí → koncept se nezaloží a
 * nástroj nahlásí, co dořešit ručně. Založený koncept nese `warnings` —
 * varování applieru (např. `vat_special_place`, #89 D18), aby na ně
 * asistent uživatele upozornil; apply neblokují.
 *
 * Sdílí apply jádro s HTTP endpointem přes {@see MessageProposalApplier}.
 * Závislost jde konstruktorem (nullable — bez ConfigRuntime degraduje na
 * graceful obálku, nepadá).
 */
final class MailDraftDocumentTool implements McpTool
{
    public function __construct(private readonly ?MessageProposalApplier $applier) {}

    public function isReadOnly(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'mail_draft_document';
    }

    public function description(): string
    {
        return 'Z analyzované došlé pošty založí KONCEPT dokladu (faktury) z '
            . 'dokumentového návrhu zprávy. Doklad vznikne jako koncept k '
            . 'revizi — AI ho NIKDY nefinalizuje ani neúčtuje. Existující '
            . 'dodavatele a položky napáruje; nové NEzakládá — pokud reference '
            . 'vyžaduje rozhodnutí, koncept nezaloží a nahlásí, co je třeba '
            . 'dořešit ručně v aplikaci. Založený koncept může nést `warnings` '
            . '(varování k dokladu, např. k DPH) — vždy je uživateli sděl, koncept '
            . 'podle nich má zkontrolovat. `message_id` získáš z `mail_list_pending` '
            . '(zprávy s `has_open_proposal`). Nepoužívej na zprávy bez analýzy.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => ['type' => 'integer', 'description' => 'ID zprávy z mail_list_pending'],
            ],
            'required' => ['message_id'],
        ];
    }

    public function call(array $arguments, McpInvocationContext $ctx): array
    {
        if ($this->applier === null) {
            return [
                'summary'    => 'Zakládání konceptů není v tomto kontextu dostupné.',
                'items'      => [],
                'pagination' => null,
            ];
        }

        if (!array_key_exists('message_id', $arguments)) {
            throw new \InvalidArgumentException('Missing required parameter: message_id');
        }
        $messageId = (int) $arguments['message_id'];
        $messageRef = ['type' => 'mail_message', 'id' => $messageId];

        $outcome = $this->applier->apply(
            $messageId, $ctx->auth->userId, null,
            ['autoCreateMode' => 'safe', 'targetDocState' => 10],
        );

        if ($outcome->ok) {
            if ($outcome->idempotent) {
                return [
                    'summary' => "Z pošty #{$messageId}: doklad už byl z návrhu založen dříve.",
                    'items'   => [[
                        'message_ref'  => $messageRef,
                        'drafted'      => false,
                        'skipped'      => true,
                        'reason'       => 'Doklad už byl z tohoto návrhu založen.',
                        'document_ref' => ['type' => 'document', 'id' => (int) ($outcome->savedDocId ?? 0)],
                    ]],
                    'pagination' => null,
                ];
            }
            $warnings = $this->issuesFrom($outcome->canonical, 'warning');
            $count = count($warnings);
            return [
                'summary' => "Z pošty #{$messageId}: založen koncept dokladu"
                    . ($count > 0 ? " — varování k dokladu: {$count}." : '.'),
                'items'   => [[
                    'message_ref'  => $messageRef,
                    'drafted'      => true,
                    'document_ref' => ['type' => 'document', 'id' => (int) ($outcome->savedDocId ?? 0)],
                    'warnings'     => $warnings,
                ]],
                'pagination' => null,
            ];
        }

        if ($outcome->errorCode === 'unresolved_required') {
            return [
                'summary' => "Z pošty #{$messageId}: návrh čeká na ruční dořešení referencí.",
                'items'   => [[
                    'message_ref'      => $messageRef,
                    'drafted'          => false,
                    'needs_resolution' => true,
                    'reason'           => 'Reference vyžadují rozhodnutí — dořeš v aplikaci.',
                    'unresolved'       => $this->issuesFrom($outcome->canonical, 'error'),
                ]],
                'pagination' => null,
            ];
        }

        // Ostatní chyby (NO_PROPOSAL, INVALID_STATE, AI_OUTPUT_INVALID, …).
        return [
            'summary' => "Z pošty #{$messageId}: koncept nezaložen.",
            'items'   => [[
                'message_ref' => $messageRef,
                'drafted'     => false,
                'skipped'     => true,
                'reason'      => $this->skipReason($outcome->errorCode, $outcome->errorMessage),
            ]],
            'pagination' => null,
        ];
    }

    /**
     * Kurátorovaný seznam z `_resolve.issues` jedné závažnosti — cesta +
     * lidský popis: `error` = nevyřešené reference (co chybí), `warning` =
     * varování k založenému konceptu (na co uživatele upozornit).
     *
     * @param array<string, mixed>|null $canonical
     * @return list<array{path: string, message: string}>
     */
    private function issuesFrom(?array $canonical, string $severity): array
    {
        $issues = $canonical['_resolve']['issues'] ?? null;
        if (!is_array($issues)) {
            return [];
        }
        $out = [];
        foreach ($issues as $issue) {
            if (!is_array($issue) || ($issue['severity'] ?? null) !== $severity) {
                continue;
            }
            $out[] = [
                'path'    => (string) ($issue['path'] ?? ''),
                'message' => (string) ($issue['message'] ?? ''),
            ];
        }
        return $out;
    }

    private function skipReason(?string $errorCode, ?string $errorMessage): string
    {
        return match ($errorCode) {
            'NO_PROPOSAL'       => 'Poslední analýza žádný dokument nenavrhla.',
            'AI_OUTPUT_INVALID' => 'AI extrakce selhala — použij reanalýzu.',
            'INVALID_STATE'     => 'Návrh není otevřený (už vyřešen, nebo zpráva mimo zpracování).',
            'NOT_FOUND'         => 'Zpráva neexistuje.',
            default             => $errorMessage ?? ($errorCode ?? 'Apply selhal.'),
        };
    }
}
