<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Prints\PrintTextsViewer;

class PrintTextsViewerTest extends TestCase
{
    use PrintTextConfigFixture;

    private function viewer(): PrintTextsViewer
    {
        $viewer = new PrintTextsViewer($this->createStub(DataSourceConnection::class), 'core_prints_texts');
        $viewer->setConfig($this->config());
        return $viewer;
    }

    /** @return array<string, array{array<string, mixed>, string, bool}> */
    public static function validity(): array
    {
        $week = ['docState' => 40, 'valid_from' => '2026-10-05', 'valid_to' => '2026-10-11'];
        return [
            'den před platností'       => [$week, '2026-10-04', false],
            'první den platnosti'      => [$week, '2026-10-05', true],
            'poslední den platnosti'   => [$week, '2026-10-11', true],
            'den po platnosti'         => [$week, '2026-10-12', false],
            'bez omezení'              => [['docState' => 40, 'valid_from' => null, 'valid_to' => null], '2026-10-05', true],
            'jen od'                   => [['docState' => 40, 'valid_from' => '2026-10-05', 'valid_to' => null], '2030-01-01', true],
            'jen do'                   => [['docState' => 40, 'valid_from' => null, 'valid_to' => '2026-10-05'], '2026-10-06', false],
            'koncept neplatí'          => [['docState' => 10] + $week, '2026-10-06', false],
            'v opravě neplatí'         => [['docState' => 80] + $week, '2026-10-06', false],
            'v archivu neplatí'        => [['docState' => 70] + $week, '2026-10-06', false],
            'datum z databáze (objekt)' => [
                ['docState' => 40, 'valid_from' => new \DateTimeImmutable('2026-10-05'), 'valid_to' => new \DateTimeImmutable('2026-10-05')],
                '2026-10-05',
                true,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('validity')]
    public function testAppliesOnCountsBothEndsOfValidityAndOnlyConfirmedTexts(array $row, string $day, bool $expected): void
    {
        $this->assertSame($expected, PrintTextsViewer::appliesOn($row, new \DateTimeImmutable($day)));
    }

    public function testRowShowsSlotTargetAndValidity(): void
    {
        $row = $this->viewer()->renderRow([
            'id' => 4, 'name' => 'Dovolená', 'slot' => 'afterRows', 'docState' => 40,
            'prints' => '["docs.invoicesOut.invoice","docs.cashDocs.cash"]', 'doc_types' => '["invno"]',
            'number_series' => null, 'language' => 'de',
            'valid_from' => '2020-01-01', 'valid_to' => '2099-12-31', 'order_pos' => 0,
        ]);

        $this->assertSame(4, $row['id']);
        $this->assertSame('Dovolená', $row['t1']);
        $this->assertSame(
            [['text' => 'Za řádky', 'class' => 'primary'], ['text' => 'Platí dnes', 'class' => 'success']],
            $row['i1'],
        );
        $this->assertSame('Faktura, Pokladní doklad · němčina · jen vybrané typy a řady', $row['t2']['text']);
        $this->assertSame('1. 1. 2020 – 31. 12. 2099', $row['t3']);
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testUntargetedDraftRow(): void
    {
        $row = $this->viewer()->renderRow([
            'id' => 5, 'name' => 'Rozpracovaný', 'slot' => 'emailBody', 'docState' => 10,
            'prints' => null, 'doc_types' => null, 'number_series' => null, 'language' => null,
            'valid_from' => null, 'valid_to' => null, 'order_pos' => 0,
        ]);

        $this->assertSame([['text' => 'Text e-mailu', 'class' => 'primary']], $row['i1']);
        $this->assertSame('Všechny tisky', $row['t2']['text']);
        $this->assertNull($row['t3']);
        $this->assertSame('concept', $row['stateStyle']);
    }
}
