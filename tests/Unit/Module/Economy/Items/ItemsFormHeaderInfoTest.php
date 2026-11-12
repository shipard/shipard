<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Items\ItemsForm;

class ItemsFormHeaderInfoTest extends TestCase
{
    private function createForm(): ItemsForm
    {
        // Bez DB / config — testujeme jen větve, které data berou přímo
        // ze `$data`. Resolve item_kind přes DB má vlastní bez-DB větev
        // (kindId=0 / db=null → vrátí '').
        return new ItemsForm('economy_items');
    }

    public function testEmptyNameReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'code'       => 'ITEM-0001',
            'valid_from' => '2024-05-14',
        ]));
    }

    public function testWhitespaceOnlyNameReturnsNull(): void
    {
        $form = $this->createForm();

        $this->assertNull($form->buildHeaderInfo([
            'name' => '   ',
        ]));
    }

    public function testMinimalRecordOnlyName(): void
    {
        $form = $this->createForm();

        // Položka jen s názvem — žádný druh/kód/platnost. Subtitle bude prázdný
        // (info=[]), ale title + ikona se zobrazí.
        $info = $form->buildHeaderInfo([
            'name' => 'Mléko 1 litr',
        ]);

        $this->assertNotNull($info);
        $this->assertSame('Mléko 1 litr', $info->title);
        $this->assertSame([], $info->info);
        $this->assertSame('box', $info->icon);
        $this->assertSame([], $info->summary);
    }

    public function testCodeInInfo(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name' => 'Mléko 1 litr',
            'code' => 'ITEM-0001',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Kód', 'value' => 'ITEM-0001']],
            $info->info,
        );
    }

    public function testEmptyCodeOmitted(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name' => 'Mléko 1 litr',
            'code' => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testValidityRangeBothDates(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'valid_from' => '2024-05-14',
            'valid_to'   => '2024-12-31',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Platí', 'value' => '2024-05-14 – 2024-12-31']],
            $info->info,
        );
    }

    public function testValidityOnlyFrom(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'valid_from' => '2024-05-14',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Platí', 'value' => 'od 2024-05-14']],
            $info->info,
        );
    }

    public function testValidityOnlyTo(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name'     => 'Mléko 1 litr',
            'valid_to' => '2024-12-31',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Platí', 'value' => 'do 2024-12-31']],
            $info->info,
        );
    }

    public function testValidityBothNullOmitted(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'valid_from' => null,
            'valid_to'   => null,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testValidityBothEmptyStringOmitted(): void
    {
        $form = $this->createForm();

        // DB může vrátit DATE pole jako prázdný string místo null.
        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'valid_from' => '',
            'valid_to'   => '',
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testValidityMalformedDateKeepsValue(): void
    {
        $form = $this->createForm();

        // Hodnota, která datem není, se vrací beze změny (DisplayDate, #133 D73).
        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'valid_from' => 'not-a-date',
            'valid_to'   => '2024-12-31',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [['label' => 'Platí', 'value' => 'not-a-date – 2024-12-31']],
            $info->info,
        );
    }

    public function testKindResolverWithoutDbReturnsEmpty(): void
    {
        $form = $this->createForm();

        // Bez DB nemůžeme resolvovat kind_name → Druh se vynechá.
        $info = $form->buildHeaderInfo([
            'name'      => 'Mléko 1 litr',
            'item_kind' => 42,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testKindIdZeroSkipped(): void
    {
        $form = $this->createForm();

        $info = $form->buildHeaderInfo([
            'name'      => 'Mléko 1 litr',
            'item_kind' => 0,
        ]);

        $this->assertNotNull($info);
        $this->assertSame([], $info->info);
    }

    public function testOrderingDruhKodPlati(): void
    {
        $form = $this->createForm();

        // Bez DB → Druh se vynechá. Ověříme aspoň pořadí Kód → Platí.
        $info = $form->buildHeaderInfo([
            'name'       => 'Mléko 1 litr',
            'code'       => 'ITEM-0001',
            'valid_from' => '2024-05-14',
            'valid_to'   => '2024-12-31',
        ]);

        $this->assertNotNull($info);
        $this->assertSame(
            [
                ['label' => 'Kód',   'value' => 'ITEM-0001'],
                ['label' => 'Platí', 'value' => '2024-05-14 – 2024-12-31'],
            ],
            $info->info,
        );
    }
}
