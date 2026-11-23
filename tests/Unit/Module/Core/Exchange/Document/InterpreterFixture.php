<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;

/**
 * Interpretace nad týmiž resolvery a mocky jako applier testu (#166 D51):
 * applier si třídy interpretace staví sám, test si z nich složí
 * {@see DocumentInterpreter} — obě cesty tak čtou stejný „zdroj dat“.
 */
trait InterpreterFixture
{
    private static function interpreterOf(DocumentApplier $applier): DocumentInterpreter
    {
        $part = static fn (string $property): mixed
            => (new \ReflectionProperty(DocumentApplier::class, $property))->getValue($applier);

        return new DocumentInterpreter(
            $part('db'),
            $part('config'),
            $part('documentValidator'),
            $part('payload'),
            $part('payloadResolve'),
            $part('vatInterpretation'),
            $part('recapInterpretation'),
            $part('recordMatching'),
            $part('proposalDefaults'),
            $part('proposalReport'),
            $part('unitResolver'),
        );
    }
}
