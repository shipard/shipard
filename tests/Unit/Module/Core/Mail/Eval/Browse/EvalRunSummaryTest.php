<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalDatasetMessages;
use Shipard\Module\Core\Mail\Eval\Browse\EvalMessageAcrossRuns;
use Shipard\Module\Core\Mail\Eval\Browse\EvalRunSummary;

/** Řádek tabulky běhů, doklad napříč běhy a PDF zprávy nad stálými fixturami v repu (#103 D53). */
final class EvalRunSummaryTest extends TestCase
{
    private static function catalog(): EvalCatalog
    {
        return new EvalCatalog(dirname(__DIR__, 7));
    }

    public function testSummaryOfARealRun(): void
    {
        $catalog = self::catalog();
        $dataset = $catalog->dataset('cz-vat-payer');
        $hash = $catalog->currentHash($dataset);
        $summary = EvalRunSummary::fromRun($catalog->run($dataset, '2026-10-16-baseline.json'), $hash);

        $this->assertSame('2026-10-16-baseline.json', $summary['file']);
        $this->assertSame('baseline', $summary['variant']);
        $this->assertSame('claude-sonnet-5-5', $summary['model']);
        $this->assertSame('claude-sonnet-5-5', $summary['tagsModel']);
        $this->assertSame('v4.8.0', $summary['promptVersion']);
        $this->assertSame(3, $summary['repeat']);
        $this->assertSame(20, $summary['messages']);
        $this->assertSame(60, $summary['callsTotal']);
        $this->assertSame(0, $summary['failed']);
        $this->assertFalse($summary['currentSet'], 'běhy z 16. 10. jsou nad sadou před #119 krokem 1 (podoba druhých dokladů variant 5, 6, 8 a 9 je `pdf`, hash sady se změnil); nový běh nad aktuální sadou je věc člověka');
        $run = $catalog->run($dataset, '2026-10-16-baseline.json');
        $this->assertTrue(EvalRunSummary::fromRun($run, $run['set']['hash'])['currentSet'], 'shodný hash = aktuální sada');
        $this->assertIsFloat($summary['hit']['avg']);
        $this->assertLessThanOrEqual($summary['hit']['avg'], $summary['hit']['min']);
        $this->assertGreaterThanOrEqual($summary['hit']['avg'], $summary['hit']['max']);
        $this->assertIsFloat($summary['costPerDocument']);
        $this->assertIsNumeric($summary['timePerDocumentMs']);
        $this->assertNull($summary['tagsUsd'], 'běh před F6b bez ceny štítků');
        $this->assertNull($summary['costTotalUsd']);
        $this->assertNull($summary['costTotalPerDocument']);
        $this->assertSame(0.0007, EvalRunSummary::fromRun(['metrics' => ['tags.usd' => 0.0007, 'cost.totalUsd' => 1.5, 'cost.totalPerDocument' => 0.05]], null)['tagsUsd']);

        $old = EvalRunSummary::fromRun($catalog->run($dataset, '2026-10-15-baseline.json'), $hash);
        $this->assertFalse($old['currentSet'], 'běh × 1 z 15. 10. je nad starší verzí sady');
        $this->assertSame(1, $old['repeat']);
        $this->assertNull($old['hit']['min'], 'bez opakování není rozpětí');

        $tags = EvalRunSummary::fromRun($catalog->run($dataset, '2026-10-16-sonnet-5-5-haiku-tags.json'), $hash);
        $this->assertSame('claude-haiku-5-5', $tags['tagsModel']);
        $this->assertSame('claude-sonnet-5-5', $tags['model']);
    }

    public function testMessageAcrossRuns(): void
    {
        $catalog = self::catalog();
        $dataset = $catalog->dataset('cz-vat-payer');
        $rows = EvalMessageAcrossRuns::build($catalog->runs($dataset), 'MSG-CORPUS-VP-0301', $catalog->currentHash($dataset));

        $this->assertGreaterThanOrEqual(13, count($rows));
        $byFile = array_column($rows, null, 'file');
        $baseline = $byFile['2026-10-16-baseline.json'];
        $this->assertSame([1, 2, 3], array_column($baseline['repeats'], 'repeat'));
        $this->assertSame(4, $baseline['repeats'][0]['deviations'], '0301 × 1: čtyři odchylky');
        $this->assertSame('ok', $baseline['repeats'][0]['status']);
        $this->assertFalse($baseline['currentSet'], 'běhy z 16. 10. jsou nad sadou před #119 krokem 1 (podoba druhých dokladů variant 5, 6, 8 a 9 je `pdf`, hash sady se změnil); nový běh nad aktuální sadou je věc člověka');
        $this->assertFalse($byFile['2026-10-15-baseline.json']['currentSet']);
        $current = array_column(EvalMessageAcrossRuns::build($catalog->runs($dataset), 'MSG-CORPUS-VP-0301', $baseline['setHash'] ?? $catalog->run($dataset, '2026-10-16-baseline.json')['set']['hash']), null, 'file');
        $this->assertTrue($current['2026-10-16-baseline.json']['currentSet'], 'shodný hash = aktuální sada');
        $this->assertCount(1, $byFile['2026-10-15-baseline.json']['repeats']);
        $this->assertSame([], EvalMessageAcrossRuns::build($catalog->runs($dataset), 'MSG-NOPE', null)[0]['repeats'], 'doklad bez volání má prázdná opakování');
    }

    public function testPdfOfAMessageComesFromItsSidecarDirectory(): void
    {
        $dataset = self::catalog()->dataset('cz-vat-payer');
        $messages = new EvalDatasetMessages($dataset->reader());
        $message = $messages->find('MSG-CORPUS-VP-0301');
        $this->assertNotNull($message);
        $this->assertSame('mail/0001-msg-corpus-vp-0301.jsonc', $message->file);

        $pdf = $messages->pdfPath($message);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith($dataset->dir . '/mail/0001-msg-corpus-vp-0301.files/', $pdf);
        $this->assertStringEndsWith('.pdf', $pdf);
        $this->assertSame('%PDF', (string) file_get_contents($pdf, false, null, 0, 4));
        $this->assertNull($messages->find('MSG-NOPE'));
        $this->assertSame('domestic-rounding', EvalDatasetMessages::cell($message)['variant']);
    }
}
