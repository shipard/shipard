<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;

/**
 * Soubor výsledků: shody po normalizaci s výstupní hodnotou (#103 D53)
 * a čtení obou tvarů `normalized` (nový `{path, actual, similarity?}`,
 * starší samotná cesta), počet odchylek volání (D44).
 */
final class EvalCallResultTest extends TestCase
{
    /** F6b D56: `tags` ve výstupu hned za `tag` (null = LLM štítky se nevolaly), cena štítků jako helper. */
    public function testTagsUsageIsPartOfTheCallRecord(): void
    {
        $without = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', 'invoiceReceived', 'it.hosting');
        $this->assertNull($without->toArray()['tags']);
        $this->assertSame(0.0, $without->tagsCostUsd());
        $keys = array_keys($without->toArray());
        $this->assertSame(array_search('tag', $keys, true) + 1, array_search('tags', $keys, true));

        $tags = ['tokensIn' => 120, 'tokensOut' => 30, 'model' => 'claude-haiku-5-5', 'costUsd' => 0.0003];
        $with = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', 'invoiceReceived', 'it.hosting', [], $tags);
        $this->assertSame($tags, $with->toArray()['tags']);
        $this->assertSame(0.0003, $with->tagsCostUsd());
        $this->assertSame(0.0, (new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', null, null, [], ['costUsd' => null] + $tags))->tagsCostUsd(), 'bez ceny (model mimo katalog)');
    }

    /** #103 D76: cesta výpočtu v souboru (`path`), starší běh bez klíče = `ai`; `notCarried` mimo odchylky, jen v počtech. */
    public function testPathRoundTripAndNotCarriedIsNeitherHitNorDeviation(): void
    {
        $outcomes = [
            new FieldOutcome('docNumber', FieldState::Match, 'FV-1', 'FV-1'),
            new FieldOutcome('vat.place', FieldState::NotCarried, 'cz', null, null, 'ISDOC nemá místo plnění'),
            new FieldOutcome('rows[0].discountPct', FieldState::NotCarried, null, 2.0, null, 'jen částka'),
        ];
        $isdoc = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.0, 0, 0, 5, 'isdoc', 'invoiceReceived', 'it.hosting', $outcomes, null, EvalCallResult::PATH_ISDOC);
        $out = $isdoc->toArray();
        $this->assertSame('isdoc', $out['path']);
        $this->assertSame(array_search('model', array_keys($out), true) + 1, array_search('path', array_keys($out), true), 'path hned za model');
        $this->assertSame(['match' => 1, 'matchNormalized' => 0, 'missing' => 0, 'extra' => 0, 'wrong' => 0, 'notCarried' => 2], $out['counts']);
        $this->assertSame([], $out['deviations']);
        $this->assertSame([], $out['normalized']);
        $this->assertTrue($isdoc->isClean(), 'notCarried doklad nešpiní');
        $this->assertSame([], $isdoc->deviations());

        $default = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', null, null);
        $this->assertSame('ai', $default->path);
        $this->assertSame('ai', $default->toArray()['path']);
        $this->assertSame('ai', EvalCallResult::pathOf(['status' => 'ok']), 'běh před D76');
        $this->assertSame('ai', EvalCallResult::pathOf(['path' => 'nonsense']));
        $this->assertSame('isdoc', EvalCallResult::pathOf(['path' => 'isdoc']));
    }

    public function testNormalizedCarriesActualValueAndSimilarity(): void
    {
        $result = new EvalCallResult(
            'MSG-1', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 100, 50, 900, 'claude-sonnet-5-5', 'invoiceReceived', 'premises.rent',
            [
                new FieldOutcome('docNumber', FieldState::Match, 'FV-1', 'FV-1'),
                new FieldOutcome('supplier.bankAccount.iban', FieldState::MatchNormalized, 'CZ6508000000192000145399', 'CZ65 0800 0000 1920 0014 5399'),
                new FieldOutcome('supplier.name', FieldState::MatchNormalized, 'Větrník Energie s.r.o.', 'Vetrnik Energie s.r.o.', 0.9286),
                new FieldOutcome('dates.dueDate', FieldState::Missing, '2026-07-31', null),
            ],
        );
        $out = $result->toArray();

        $this->assertSame([
            ['path' => 'supplier.bankAccount.iban', 'actual' => 'CZ65 0800 0000 1920 0014 5399'],
            ['path' => 'supplier.name', 'actual' => 'Vetrnik Energie s.r.o.', 'similarity' => 0.9286],
        ], $out['normalized'], 'shoda po normalizaci nese skutečný tvar výstupu, podobnost jen u textu');
        $this->assertSame(['match' => 1, 'matchNormalized' => 2, 'missing' => 1, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0], $out['counts']);
        $this->assertCount(1, $out['deviations']);
    }

    public function testNormalizedEntriesAcceptBothShapes(): void
    {
        $entries = EvalCallResult::normalizedEntries([
            'status' => 'ok',
            'normalized' => [
                'supplier.contact.phone',
                ['path' => 'supplier.bankAccount.iban', 'actual' => 'CZ65 0800 0000 1920 0014 5399'],
                ['path' => 'supplier.name', 'actual' => 'Vetrnik', 'similarity' => 0.93],
                ['path' => 'totals.totalRounding', 'actual' => null],
                ['nonsense' => true],
            ],
        ]);

        $this->assertSame([
            ['path' => 'supplier.contact.phone', 'actual' => null, 'actualKnown' => false, 'similarity' => null],
            ['path' => 'supplier.bankAccount.iban', 'actual' => 'CZ65 0800 0000 1920 0014 5399', 'actualKnown' => true, 'similarity' => null],
            ['path' => 'supplier.name', 'actual' => 'Vetrnik', 'actualKnown' => true, 'similarity' => 0.93],
            ['path' => 'totals.totalRounding', 'actual' => null, 'actualKnown' => true, 'similarity' => null],
        ], $entries, 'starý tvar = cesta bez výstupu; záznam bez cesty se přeskočí; explicitní null výstup je známý');
        $this->assertSame([], EvalCallResult::normalizedEntries(['status' => 'failed']));
    }

    /** #103 D114: informativní listy mají vlastní počty a odchylky; `counts`, `deviations` ani `isClean()` je nevidí, `deviationCount()` ze souboru také ne. */
    public function testInformativeLeavesGoToTheirOwnCountsAndDeviations(): void
    {
        $outcomes = [
            new FieldOutcome('docNumber', FieldState::Match, 'FV-1', 'FV-1'),
            new FieldOutcome('notes.onDocument', FieldState::Wrong, 'Děkujeme', 'Thanks', 0.3, null, true),
            new FieldOutcome('rows[0].item.description', FieldState::MatchNormalized, 'a  b', 'a b', 1.0, null, true),
            new FieldOutcome('dates.dueDate', FieldState::Missing, '2026-01-01', null),
        ];
        $result = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', 'invoiceReceived', 'it.hosting', $outcomes);
        $out = $result->toArray();
        $keys = array_keys($out);
        $this->assertSame(array_search('counts', $keys, true) + 1, array_search('informativeCounts', $keys, true));
        $this->assertSame(array_search('deviations', $keys, true) + 1, array_search('informativeDeviations', $keys, true));
        $this->assertSame(['match' => 1, 'matchNormalized' => 0, 'missing' => 1, 'extra' => 0, 'wrong' => 0, 'notCarried' => 0], $out['counts']);
        $this->assertSame(['match' => 0, 'matchNormalized' => 1, 'missing' => 0, 'extra' => 0, 'wrong' => 1], $out['informativeCounts']);
        $this->assertSame(['dates.dueDate'], array_column($out['deviations'], 'path'));
        $this->assertSame(
            [['path' => 'notes.onDocument', 'state' => 'wrong', 'expected' => 'Děkujeme', 'actual' => 'Thanks', 'similarity' => 0.3, 'informative' => true]],
            $out['informativeDeviations'],
        );
        $this->assertSame([['path' => 'rows[0].item.description', 'actual' => 'a b', 'similarity' => 1.0, 'informative' => true]], $out['normalized']);
        $this->assertFalse($result->isClean(), 'chybí splatnost');
        $this->assertSame(1, EvalCallResult::deviationCount($out));

        $clean = new EvalCallResult('M', 1, EvalCallResult::STATUS_OK, null, null, 0.01, 1, 1, 1, 'm', 'invoiceReceived', 'it.hosting', array_slice($outcomes, 0, 3));
        $this->assertTrue($clean->isClean(), 'špatná poznámka doklad nešpiní');
        $this->assertSame([], $clean->deviations());
        $this->assertSame(['notes.onDocument'], array_map(static fn (FieldOutcome $o): string => $o->path, $clean->informativeDeviations()));
        $this->assertSame(0, EvalCallResult::deviationCount($clean->toArray()));
    }

    public function testDeviationCount(): void
    {

        $this->assertSame(2, EvalCallResult::deviationCount(['status' => 'ok', 'deviations' => [['path' => 'a'], ['path' => 'b']]]));
        $this->assertSame(0, EvalCallResult::deviationCount(['status' => 'ok']));
        $this->assertSame(7, EvalCallResult::deviationCount(['status' => 'failed', 'counts' => ['match' => 0, 'matchNormalized' => 0, 'missing' => 5, 'extra' => 0, 'wrong' => 2]]));
        $this->assertNull(EvalCallResult::deviationCount(['status' => 'failed', 'errorType' => 'schema_error']), 'selhané volání před D44 bez counts');
    }
}
