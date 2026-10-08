<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\WorkOrders\KindDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Druh zakázky (D14): název a známý typ povinné; typ je po prvním
 * potvrzení druhu jen ke čtení. Periodický druh (D3): typ dokladu a řada
 * povinné mimo Koncept, řada typu dokladu; neperiodický druh fakturační
 * pole vyprázdní.
 */
class KindDocumentTest extends TestCase
{
    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true],
    ];

    /** Řady dokladů: 1 = FV, 2 = zálohová FV. */
    public const DOC_SERIES = [
        1 => ['id' => 1, 'doc_type' => 'invno', 'docState' => 40],
        2 => ['id' => 2, 'doc_type' => 'invpo', 'docState' => 40],
    ];

    /** @param array<string, mixed>|null $stored uložený řádek druhu */
    private function doc(?array $stored = null): KindDocument
    {
        $doc = new class($stored) extends KindDocument {
            public function __construct(private readonly ?array $stored)
            {
            }

            protected function loadKindRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function loadDocSeries(int $seriesId): ?array
            {
                return KindDocumentTest::DOC_SERIES[$seriesId] ?? null;
            }
        };
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([[WorkOrderTypes::CFG_ITEM, self::TYPES]]);
        $doc->setConfig($config);
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        return $doc;
    }

    /** @return list<string> column:code */
    private function codes(KindDocument $doc, array $data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    public function testNameAndKnownTypeRequired(): void
    {
        $this->assertSame(['name:required', 'type:required'], $this->codes($this->doc(), ['name' => ' ']));
        $this->assertSame(['type:invalid'], $this->codes($this->doc(), ['name' => 'Servis', 'type' => 'leasing']));
        $this->assertSame([], $this->codes($this->doc(), ['name' => 'Servis', 'type' => 'project']));
    }

    public function testTypeIsLockedOnceTheKindLeftDraft(): void
    {
        $confirmed = ['id' => 5, 'type' => 'project', 'docState' => 40];
        $periodicData = ['id' => 5, 'name' => 'Servis', 'type' => 'periodic', 'docState' => 80, 'inv_doc_type' => 'invno', 'inv_number_series' => 1];
        $this->assertSame(['type:typeLocked'], $this->codes($this->doc($confirmed), $periodicData));
        // Stejný typ projde, i v opravě.
        $this->assertSame([], $this->codes($this->doc($confirmed), ['id' => 5, 'name' => 'Servis', 'type' => 'project', 'docState' => 80]));
        // Koncept typ změnit smí.
        $draft = ['id' => 5, 'type' => 'project', 'docState' => 10];
        $this->assertSame([], $this->codes($this->doc($draft), ['id' => 5, 'name' => 'Servis', 'type' => 'periodic', 'docState' => 10]));
    }

    public function testBeforeSaveTrimsAndNullsEmptyNotice(): void
    {
        $data = ['name' => ' Servis ', 'type' => 'project', 'notice' => '  '];
        $this->doc()->beforeSave($data, null);
        $this->assertSame('Servis', $data['name']);
        $this->assertNull($data['notice']);
    }

    // --- výchozí hodnoty fakturace (D3) ----------------------------------------

    public function testPeriodicKindNeedsDocTypeAndSeriesOutsideDraft(): void
    {
        $draft = ['name' => 'Smlouvy', 'type' => 'periodic', 'docState' => 10];
        $this->assertSame([], $this->codes($this->doc(), $draft));

        $confirm = ['name' => 'Smlouvy', 'type' => 'periodic', 'docState' => 40];
        $this->assertSame(['inv_doc_type:required', 'inv_number_series:required'], $this->codes($this->doc(), $confirm));

        $complete = $confirm + ['inv_doc_type' => 'invpo', 'inv_number_series' => 2, 'inv_due_days' => 14, 'inv_timing' => 'end'];
        $this->assertSame([], $this->codes($this->doc(), $complete));
    }

    public function testPeriodicKindRejectsForeignDocTypeMismatchedSeriesAndBadValues(): void
    {
        $base = ['name' => 'Smlouvy', 'type' => 'periodic', 'docState' => 10];
        $this->assertSame(['inv_doc_type:invalid'], $this->codes($this->doc(), $base + ['inv_doc_type' => 'invni']));
        $this->assertSame(
            ['inv_number_series:series_type_mismatch'],
            $this->codes($this->doc(), $base + ['inv_doc_type' => 'invno', 'inv_number_series' => 2]),
        );
        $this->assertSame(['inv_number_series:not_found'], $this->codes($this->doc(), $base + ['inv_number_series' => 77]));
        $this->assertSame(
            ['inv_due_days:invalid', 'inv_timing:invalid'],
            $this->codes($this->doc(), $base + ['inv_due_days' => -1, 'inv_timing' => 'middle']),
        );
    }

    public function testNonPeriodicKindDropsInvoicingDefaults(): void
    {
        $data = ['name' => 'Projekty', 'type' => 'project', 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_due_days' => 30];
        $this->assertSame([], $this->codes($this->doc(), $data));
        $this->doc()->beforeSave($data, null);
        $this->assertNull($data['inv_doc_type']);
        $this->assertNull($data['inv_number_series']);
        $this->assertNull($data['inv_due_days']);

        $kept = ['name' => 'Smlouvy', 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 1];
        $this->doc()->beforeSave($kept, null);
        $this->assertSame('invno', $kept['inv_doc_type']);
        $this->assertSame(1, $kept['inv_number_series']);
    }
}
