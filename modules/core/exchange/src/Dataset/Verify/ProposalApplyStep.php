<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Mail\MessageProposalApplier;

/**
 * Použije otevřené návrhy zpráv sady jako doklady V pořádku (tasks/testing-phase3.md):
 * `MessageProposalApplier::apply()` — sdílené jádro Použít (REST i MCP) —
 * s `targetDocState` 40 a `autoCreateMode` liberal (dodavatele zakládá apply,
 * D21). Zadání jmenuje i `createMissingEntities` a `rejectOnIssues`;
 * ty `DocumentApplier` nečte (chyba blokuje vždy, varování nikdy), applier
 * zpráv je nepředává — nic dalšího se neposílá. Zprávy jdou v pořadí
 * `received_at`; doklad v cizí měně dostane kurz z lístků sady (#121 D6,
 * konec přeskakování D30 — #103 D74); selhání apply je výsledek, ne pád.
 */
final class ProposalApplyStep
{
    public const APPLY_OPTIONS = ['targetDocState' => 40, 'autoCreateMode' => 'liberal'];

    public function __construct(private readonly MessageProposalApplier $applier) {}

    /** @param list<SetDocument> $documents v pořadí received_at */
    public function run(array $documents): ApplyReport
    {
        $applied = [];
        $failed = [];
        foreach ($documents as $doc) {
            if (!$doc->hasOpenProposal()) {
                continue;
            }
            try {
                $outcome = $this->applier->apply($doc->messageNdx, null, null, self::APPLY_OPTIONS);
            } catch (\Throwable $e) {
                $failed[] = ['messageId' => $doc->messageId, 'code' => 'EXCEPTION', 'message' => $e->getMessage()];
                continue;
            }
            if ($outcome->ok) {
                $applied[] = $doc->messageId;
            } else {
                $failed[] = [
                    'messageId' => $doc->messageId,
                    'code'      => (string) ($outcome->errorCode ?? 'ERROR'),
                    'message'   => self::describe($outcome->errorMessage, $outcome->canonical),
                ];
            }
        }
        return new ApplyReport(true, $applied, $failed);
    }

    /**
     * Věta chyby + problémy `_resolve.issues` (validace, nerozhodnuté
     * reference), aby selhání bylo čitelné bez logu.
     *
     * @param array<string, mixed>|null $canonical
     */
    private static function describe(?string $message, ?array $canonical): string
    {
        $parts = [$message ?? 'Apply failed'];
        foreach ((array) ($canonical['_resolve']['issues'] ?? []) as $issue) {
            if (!is_array($issue) || ($issue['severity'] ?? null) !== 'error') {
                continue;
            }
            $parts[] = trim((string) ($issue['path'] ?? '') . ' ' . (string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? ''));
        }
        return implode('; ', $parts);
    }
}
