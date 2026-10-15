<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalMessages;

/** Zprávy sady z korpusu, mapování na buňky, výběr `--only` / `--limit`. */
final class EvalMessagesTest extends TestCase
{
    private static function datasetDir(): string
    {
        return dirname(__DIR__, 6) . '/tests/datasets/cz/vat-payer';
    }

    public function testMessagesComeFromTheSetWithExpectedCanonicalAndCell(): void
    {
        $messages = EvalMessages::fromDataset(DatasetReader::open(self::datasetDir()));

        $this->assertCount(20, $messages);
        $first = $messages[0];
        $this->assertSame('MSG-CORPUS-VP-0301', $first->messageId, 'pořadí sady podle receivedAt');
        $this->assertSame('0301-domestic-rounding', $first->stem);
        $this->assertSame('domestic-rounding', $first->variant);
        $this->assertSame('pdf', $first->form);
        $this->assertSame('invoiceReceived', $first->expectedPrimaryType);
        $this->assertSame('shpd.docs.document', $first->expectedCanonical['format']);
        $this->assertArrayHasKey('_resolve', $first->expectedCanonical);
        foreach ($messages as $m) {
            $this->assertContains($m->form, ['pdf', 'pdfIsdoc']);
        }
    }

    public function testOnlyAndLimit(): void
    {
        $messages = EvalMessages::fromDataset(DatasetReader::open(self::datasetDir()));
        $ids = static fn (array $list): array => array_map(static fn (EvalMessage $m): string => $m->messageId, $list);

        $this->assertSame(['0101', 'eu-goods'], EvalMessages::parseOnly(' 0101, eu-goods ,'));
        $this->assertSame([], EvalMessages::parseOnly(null));

        $this->assertSame(['MSG-CORPUS-VP-0101'], $ids(EvalMessages::filter($messages, ['0101'], null)));
        $this->assertSame(['MSG-CORPUS-VP-0601', 'MSG-CORPUS-VP-0602'], $ids(EvalMessages::filter($messages, ['eu-goods'], null)));
        $this->assertSame(['MSG-CORPUS-VP-0101'], $ids(EvalMessages::filter($messages, ['0101-domestic-goods'], null)));
        $this->assertSame(['MSG-CORPUS-VP-0902'], $ids(EvalMessages::filter($messages, ['MSG-CORPUS-VP-0902'], null)));
        $this->assertSame([], $ids(EvalMessages::filter($messages, ['01'], null)), 'prefix jen celé číslo buňky');
        $this->assertCount(3, EvalMessages::filter($messages, [], 3));
        $this->assertSame(['MSG-CORPUS-VP-0601'], $ids(EvalMessages::filter($messages, ['eu-goods'], 1)), 'limit až po výběru');
        $this->assertCount(20, EvalMessages::filter($messages, [], null));
    }
}
