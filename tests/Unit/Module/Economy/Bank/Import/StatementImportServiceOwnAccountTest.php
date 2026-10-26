<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\StatementImportService;

/**
 * Párování vlastního účtu z hlavičky výpisu (#118 D23, D25): klíč účtu
 * z čísla / IBAN / ID v ebankingu, a odkaz bez kódu banky (hlavička GPC
 * nese jen předčíslí a číslo) proti tuzemskému číslu vlastních účtů —
 * přijme se jen jediná shoda, IBAN ani ID v ebankingu se nehádají.
 */
class StatementImportServiceOwnAccountTest extends TestCase
{
    private const OWN = [
        'id' => 1, 'currency' => 'czk', 'code' => 'CZK',
        'account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399', 'ebanking_id' => null,
    ];

    /**
     * @param list<array<string, mixed>> $accounts
     * @return array<string, mixed>
     */
    private function match(array $accounts, string $ref): array
    {
        $service = new class ($this->createMock(Connection::class), $this->createMock(ConfigRuntime::class), $accounts) extends StatementImportService {
            /** @param list<array<string, mixed>> $accounts */
            public function __construct(Connection $db, ConfigRuntime $config, private readonly array $accounts)
            {
                parent::__construct($db, $config);
            }

            protected function loadOwnAccounts(): array
            {
                return $this->accounts;
            }
        };
        return (new \ReflectionMethod(StatementImportService::class, 'matchBankAccount'))->invoke($service, $ref, null);
    }

    public function testKeyMatchesNumberIbanAndEbankingId(): void
    {
        $accounts = [self::OWN, ['id' => 2, 'currency' => 'eur', 'code' => 'EUR', 'account_number' => '', 'iban' => 'DE72100700000532013000', 'ebanking_id' => 'fio-77']];
        $this->assertSame(1, $this->match($accounts, '19-2000145399/0800')['id']);
        $this->assertSame(1, $this->match($accounts, 'CZ65 0800 0000 1920 0014 5399')['id'], 'IBAN z CAMT na číslo účtu');
        $this->assertSame(2, $this->match($accounts, 'de72100700000532013000')['id']);
        $this->assertSame(2, $this->match($accounts, 'fio-77')['id'], 'ID v ebankingu jako raw klíč');
    }

    public function testBareReferenceMatchesTheSingleDomesticAccount(): void
    {
        $this->assertSame(1, $this->match([self::OWN], '19-2000145399')['id'], 'hlavička GPC bez kódu banky (D25)');
        $this->assertSame(1, $this->match([self::OWN], '000019-0002000145399')['id'], 'vedoucí nuly');
        $this->assertSame(1, $this->match([self::OWN], ' 19 - 2000145399 ')['id'], 'mezery');

        $noPrefix = ['account_number' => '2308417557/2010', 'iban' => ''] + self::OWN;
        $this->assertSame(1, $this->match([$noPrefix], '2308417557')['id']);
    }

    public function testBareReferenceRequiresExactlyOneMatch(): void
    {
        $other = ['id' => 2, 'account_number' => '19-2000145399/0100', 'iban' => ''] + self::OWN;
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('není v žádném vlastním bankovním spojení');
        $this->match([self::OWN, $other], '19-2000145399');
    }

    public function testBareReferenceDoesNotGuessFromIbanOnlyAccount(): void
    {
        $ibanOnly = ['account_number' => '', 'iban' => 'CZ6508000000192000145399'] + self::OWN;
        $this->expectException(ImportException::class);
        $this->match([$ibanOnly], '19-2000145399');
    }

    public function testBareReferenceDoesNotMatchAnotherNumber(): void
    {
        $this->expectException(ImportException::class);
        $this->match([self::OWN], '2000145399');
    }

    public function testReferenceWithBankCodeNeverFallsBack(): void
    {
        $this->expectException(ImportException::class);
        $this->match([self::OWN], '19-2000145399/0100');
    }
}
