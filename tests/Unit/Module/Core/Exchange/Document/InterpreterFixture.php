<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;

/**
 * Interpretace a vstup návrhu nad týmiž resolvery a mocky jako pokyn testu
 * (#166 D51, D53): {@see TestableDocumentApplier} si je staví z resolverů,
 * které test předal — obě cesty tak čtou stejný „zdroj dat“.
 */
trait InterpreterFixture
{
    /**
     * Část interpretace, kterou si applier testu postavil (`vatInterpretation`,
     * `recordMatching`, `pendingRecords`, …) — testy pravidel návrhu ji
     * volají přímo.
     */
    private static function partOf(DocumentApplier $applier, string $property): mixed
    {
        return self::testable($applier)->{$property};
    }

    private static function interpreterOf(DocumentApplier $applier): DocumentInterpreter
    {
        return self::testable($applier)->interpreter;
    }

    /**
     * Vstup návrhu nad applierem testu: interpretace → založení chybějících
     * záznamů → pokyn (tento applier) → učení. SQL, které zapíše, je
     * v `TestableDocumentApplier::$sqlCalls`.
     */
    private static function proposalOf(DocumentApplier $applier): DocumentProposal
    {
        return self::testable($applier)->proposal;
    }

    private static function testable(DocumentApplier $applier): TestableDocumentApplier
    {
        if (!$applier instanceof TestableDocumentApplier) {
            throw new \LogicException('Test návrhu potřebuje TestableDocumentApplier — staví interpretaci nad resolvery testu.');
        }
        return $applier;
    }
}
