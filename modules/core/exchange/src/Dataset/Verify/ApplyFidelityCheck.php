<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;

/**
 * Kontrola 1 — věrnost apply (tasks/testing-phase3.md D27): export
 * potvrzeného dokladu (`DocumentExporter` → canonical, přes adaptér
 * {@see SavedDocumentProjection}) ≟ `canonicalJson` použitého návrhu na
 * polích papírové projekce sady (`expected/projection.jsonc`).
 *
 * `EXCEPTIONS` = pole, která apply mění záměrně — každé s důvodem a odkazem
 * na kód, který změnu dělá. **Výjimka se nepřidává bez schválení**: kandidáti
 * z běhu se sepíší a člověk rozhodne (chyba applieru se nesmí schovat do
 * výjimky). `STRUCTURAL` jsou podstromy, které export z principu nenese.
 */
final class ApplyFidelityCheck implements VerifyCheck
{
    public const ID = 'applyFidelity';

    /** @var array<string, string> cesta uzlu projekce → důvod (odkaz na kód) */
    public const EXCEPTIONS = [];

    /** @var array<string, string> */
    public const STRUCTURAL = [
        '_resolve' => 'export doklad nenese `_resolve` — štítek per řádek hlídá kontrola položek a účtů přes položku',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Věrnost apply (doklad ≟ návrh)';
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        $docs = array_values(array_filter($ctx->appliedDocuments(), static fn (SetDocument $d): bool => $d->canonical !== null));
        if ($docs === []) {
            return CheckResult::skipped(self::ID, $this->label(), 'žádný použitý doklad s návrhem');
        }

        $db = $ctx->wiring->db;
        $exporter = new DocumentExporter($db, $ctx->wiring->config);
        $adapter = new SavedDocumentProjection($db);
        $comparator = new CanonicalComparator($ctx->expectations->projection);
        $ignore = array_keys(self::EXCEPTIONS + self::STRUCTURAL);

        $differences = [];
        foreach ($docs as $doc) {
            $records = $exporter->exportByIds([(int) $doc->docId]);
            if ($records === []) {
                $differences[] = "{$doc->messageId}: doklad #{$doc->docId} nejde exportovat";
                continue;
            }
            $actual = $adapter->build($records[0]->data, (array) $doc->canonical, (int) $doc->docId);
            foreach ($comparator->compare((array) $doc->canonical, $actual, $ignore) as $difference) {
                $differences[] = "{$doc->messageId} ({$doc->docNumber}): {$difference}";
            }
        }

        $notes = [count($docs) . ' dokladů, výjimek věrnosti: ' . count(self::EXCEPTIONS)];
        return CheckResult::fromDifferences(self::ID, $this->label(), $differences, $notes);
    }
}
