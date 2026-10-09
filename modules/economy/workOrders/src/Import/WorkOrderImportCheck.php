<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Import;

/**
 * Výsledek kontroly payloadu `shpd.workOrders.workOrder.v1`
 * ({@see WorkOrderImportVerifier}): nálezy s cestou do payloadu
 * (`rows.2.vatCode`) a to, co si verifier při kontrole dohledal a applier
 * potřebuje k zápisu — řadu s druhem, id nadřazené zakázky podle čísla,
 * id jednotek řádků, fiskální rok data zahájení pro čítač a id zakázky,
 * která už importované číslo nese (applier ji přeskočí).
 *
 * Nálezy mají `severity` `error` (zápis se nekoná) nebo `warning`
 * (jde do odpovědi jako varování — `counter_not_synced`).
 */
final class WorkOrderImportCheck
{
    /**
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     * @param array<string, mixed>|null $series řada zakázek s typem druhu (id, kind, type, reset_scope, docState)
     * @param array<string, mixed>|null $kind druh (id, type, inv_*)
     * @param array<int, int> $unitIds index řádku payloadu → id jednotky
     * @param int|null $existingId zakázka se stejným číslem v libovolném stavu
     */
    public function __construct(
        public readonly array $issues,
        public readonly ?array $series,
        public readonly ?array $kind,
        public readonly ?int $parentId,
        public readonly array $unitIds,
        public readonly ?int $fiscalYearId,
        public readonly ?int $existingId = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<array{severity: string, path: string, code: string, message: string}> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn(array $i): bool => $i['severity'] === 'error'));
    }

    /**
     * Varování ve tvaru odpovědi applieru.
     *
     * @return list<array{code: string, message: string, path: string}>
     */
    public function warnings(): array
    {
        $out = [];
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'warning') {
                $out[] = ['code' => $issue['code'], 'message' => $issue['message'], 'path' => $issue['path']];
            }
        }
        return $out;
    }
}
