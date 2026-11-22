<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordCode;
use Shipard\Module\Economy\Codebooks\VatRegistrationCodeGenerator;

/** Pravidla kódu registrace DPH (#166 D17, U3). */
class VatRegistrationCodeGeneratorTest extends TestCase
{
    use VatRegistrationDbTrait;

    public function testFirstRegistrationOfCountryGetsTheCountry(): void
    {
        $this->assertSame('cz', VatRegistrationCodeGenerator::compose('cz', true, []));
        $this->assertSame('cz', VatRegistrationCodeGenerator::compose('CZ', true, []), 'země malými písmeny');
    }

    public function testNextRegistrationOfCountryIsNumberedFromTwo(): void
    {
        $this->assertSame('cz-2', VatRegistrationCodeGenerator::compose('cz', false, ['cz' => true]));
        $this->assertSame('cz-3', VatRegistrationCodeGenerator::compose('cz', false, ['cz' => true, 'cz-2' => true]));
        // I když je holá země volná (první registrace má vlastní kód), další ji nedostane.
        $this->assertSame('cz-2', VatRegistrationCodeGenerator::compose('cz', false, ['hlavni' => true]));
    }

    public function testTakenCountryCodeGetsSuffix(): void
    {
        $this->assertSame('cz-2', VatRegistrationCodeGenerator::compose('cz', true, ['cz' => true]));
        $this->assertSame('cz-2', VatRegistrationCodeGenerator::compose('cz', true, ['CZ' => true, 'cz' => true]));
    }

    public function testBrokenRowStillGetsValidCode(): void
    {
        $this->assertTrue(RecordCode::isValid(VatRegistrationCodeGenerator::compose('', true, [])));
    }

    public function testNewRegistrationOverDatabase(): void
    {
        $empty = new VatRegistrationCodeGenerator($this->registrationsDb());
        $this->assertSame('cz', $empty->generate(['country' => 'cz']));

        $generator = new VatRegistrationCodeGenerator($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => 'cz', 'docState' => 40],
        ]));
        $this->assertSame('cz-2', $generator->generate(['country' => 'cz']));
        $this->assertSame('sk', $generator->generate(['country' => 'sk']), 'jiná země má vlastní první registraci');
    }

    public function testExistingRegistrationsOverDatabase(): void
    {
        // Registrace v koši má nejnižší id, první je ale první nesmazaná.
        $generator = new VatRegistrationCodeGenerator($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => null, 'docState' => 90],
            ['id' => 2, 'country' => 'cz', 'code' => null, 'docState' => 40],
            ['id' => 3, 'country' => 'cz', 'code' => null, 'docState' => 70],
        ]));

        $this->assertSame('cz', $generator->generate(['id' => 2, 'country' => 'cz', 'docState' => 40]));
        $this->assertSame('cz-2', $generator->generate(['id' => 3, 'country' => 'cz', 'docState' => 70]));
        $this->assertSame('cz-2', $generator->generate(['id' => 1, 'country' => 'cz', 'docState' => 90]));
    }

    public function testCodeHeldByDeletedRegistrationIsTaken(): void
    {
        $generator = new VatRegistrationCodeGenerator($this->registrationsDb([
            ['id' => 1, 'country' => 'cz', 'code' => 'cz', 'name' => 'Stará', 'docState' => 90],
        ]));

        $this->assertSame('cz-2', $generator->generate(['country' => 'cz']));
        $this->assertSame(['id' => 1, 'name' => 'Stará', 'deleted' => true], $generator->holder('CZ'));
        $this->assertNull($generator->holder('cz', 1));
    }
}
