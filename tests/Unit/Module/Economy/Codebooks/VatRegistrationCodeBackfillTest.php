<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\VatRegistrationCodeBackfill;

/** Doplnění kódu existujícím registracím DPH (#166 D17, U5). */
class VatRegistrationCodeBackfillTest extends TestCase
{
    use VatRegistrationDbTrait;

    public function testFillsCodesByTheRuleLiveBeforeTrash(): void
    {
        $store = new \ArrayObject();
        $filled = (new VatRegistrationCodeBackfill($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => null, 'docState' => 90],
            ['id' => 2, 'country' => 'cz', 'code' => null, 'docState' => 40],
            ['id' => 3, 'country' => 'sk', 'code' => null, 'docState' => 40],
            ['id' => 4, 'country' => 'cz', 'code' => null, 'docState' => 70],
        ], $store)))->run();

        $this->assertSame(4, $filled);
        $this->assertSame([1 => 'cz-3', 2 => 'cz', 3 => 'sk', 4 => 'cz-2'], self::codesOf($store));
    }

    public function testSecondRunFillsNothing(): void
    {
        $store = new \ArrayObject();
        $backfill = new VatRegistrationCodeBackfill($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => null, 'docState' => 40],
            ['id' => 2, 'country' => 'cz', 'code' => 'cz-oss', 'docState' => 40],
        ], $store));

        $this->assertSame(1, $backfill->run());
        $this->assertSame([1 => 'cz', 2 => 'cz-oss'], self::codesOf($store));
        $this->assertSame(0, $backfill->run());
        $this->assertCount(1, $store['updates']);
    }

    public function testConcurrentWriterTakingTheCodeIsNotAnError(): void
    {
        $store = new \ArrayObject(['collide' => ['cz']]);
        $filled = (new VatRegistrationCodeBackfill($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => null, 'docState' => 40],
        ], $store)))->run();

        $this->assertSame(1, $filled);
        $this->assertSame([1 => 'cz-2'], self::codesOf($store));
    }
}
