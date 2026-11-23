<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentInterpreter;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow\ShadowRunner;

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
            $part('sourceRefs'),
        );
    }

    /**
     * Vstup návrhu nad applierem testu (#166 D53): tytéž mocky, zakládání
     * týmž `PendingRecords`; zápisy (učení kódů, vazba na zprávu) jdou přes
     * `executeSql()` applieru, takže je testovací podtřída zachytává dál.
     */
    private static function proposalOf(DocumentApplier $applier): DocumentProposal
    {
        $part = static fn (string $property): mixed
            => (new \ReflectionProperty(DocumentApplier::class, $property))->getValue($applier);
        $executeSql = new \ReflectionMethod($applier, 'executeSql');

        return new DocumentProposal(
            $part('db'),
            $part('schemaValidator'),
            self::interpreterOf($applier),
            $applier,
            $part('pendingRecords'),
            static function (mixed ...$args) use ($applier, $executeSql): void {
                $executeSql->invoke($applier, ...$args);
            },
        );
    }

    /**
     * Stínové porovnání nad applierem testu ({@see ShadowRunner}): mocky nic
     * nezapisují, izolace proto není potřeba.
     *
     * @param \Closure(): list<array<string, mixed>> $transformed data předaná k uložení
     * @param \Closure(): void $reset
     */
    private static function shadowOf(DocumentApplier $applier, \Closure $transformed, \Closure $reset): ShadowRunner
    {
        return new ShadowRunner(
            $applier,
            self::interpreterOf($applier),
            self::proposalOf($applier),
            $transformed,
            $reset,
            static fn (\Closure $work): mixed => $work(),
        );
    }
}
