<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\Prints\CashDocPrintBuilder;
use Shipard\Module\Docs\Core\Prints\DocPrintBuilder;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Strana tisku dokladu (#94 D4): jazyk živě z osoby partnera, země
 * z partnerského snapshotu dokladu podle směru obchodu.
 */
class DocPrintPartyTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems([
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'invni' => ['trade_dir' => 2],
                'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir'],
                'acc'   => ['trade_dir' => 0],
            ],
        ]);
    }

    /** Spojení, které na dotaz po jazyku osoby vrací `$language`. */
    private function db(?string $language, ?int &$askedPerson = null): DataSourceConnection
    {
        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($language, &$askedPerson): ?string {
                $askedPerson = (int) $args[1];
                return $language;
            },
        );
        return $db;
    }

    private static function snapshot(string $country): string
    {
        return (string) json_encode(['name' => 'Firma', 'address' => ['city' => 'Město', 'country' => $country]]);
    }

    public function testIssuedDocumentTakesCountryFromCustomerSnapshot(): void
    {
        $head = [
            'doc_type' => 'invno', 'partner' => 7,
            'supplier_snapshot' => self::snapshot('cz'),
            'customer_snapshot' => self::snapshot('at'),
        ];

        $party = (new DocPrintBuilder())->printParty($head, $this->db(null, $asked), $this->config());

        $this->assertNotNull($party);
        $this->assertSame('at', $party->country);
        $this->assertNull($party->personLanguage);
        $this->assertSame(7, $asked);
    }

    public function testReceivedDocumentTakesCountryFromSupplierSnapshot(): void
    {
        $head = [
            'doc_type' => 'invni', 'partner' => 9,
            'supplier_snapshot' => self::snapshot('sk'),
            'customer_snapshot' => self::snapshot('cz'),
        ];

        $party = (new DocPrintBuilder())->printParty($head, $this->db('de'), $this->config());

        $this->assertSame('sk', $party?->country);
        $this->assertSame('de', $party?->personLanguage);
    }

    public function testCashDocumentFollowsItsOwnDirection(): void
    {
        // Příjmový doklad (cash_dir 1) = výstup → partner je odběratel.
        $head = [
            'doc_type' => 'cash', 'cash_dir' => 1, 'partner' => 3,
            'supplier_snapshot' => self::snapshot('cz'),
            'customer_snapshot' => self::snapshot('sk'),
        ];

        $party = (new CashDocPrintBuilder())->printParty($head, $this->db(null), $this->config());

        $this->assertSame('sk', $party?->country);
    }

    public function testPersonLanguageIsReadLiveNotFromDocument(): void
    {
        // Tentýž doklad, na osobě se mezitím změnil jazyk — doklad se nemění.
        $head = ['doc_type' => 'invno', 'partner' => 7, 'customer_snapshot' => self::snapshot('cz')];
        $builder = new DocPrintBuilder();

        $this->assertNull($builder->printParty($head, $this->db(null), $this->config())?->personLanguage);
        $this->assertSame('en', $builder->printParty($head, $this->db('en'), $this->config())?->personLanguage);
    }

    public function testDocumentWithoutPartnerHasNoParty(): void
    {
        $head = ['doc_type' => 'cash', 'cash_dir' => 1, 'partner' => null, 'supplier_snapshot' => self::snapshot('cz')];

        $this->assertNull((new DocPrintBuilder())->printParty($head, $this->db('en', $asked), $this->config()));
        $this->assertNull($asked, 'bez partnera se na osobu neptá');
    }

    public function testDocumentWithoutPartnerSnapshotHasNoParty(): void
    {
        $head = ['doc_type' => 'invno', 'partner' => 7, 'supplier_snapshot' => self::snapshot('cz'), 'customer_snapshot' => null];

        $this->assertNull((new DocPrintBuilder())->printParty($head, $this->db('en'), $this->config()));
    }

    public function testDocumentWithoutTradeDirectionHasNoParty(): void
    {
        $head = ['doc_type' => 'acc', 'partner' => 7, 'customer_snapshot' => self::snapshot('sk')];

        $this->assertNull((new DocPrintBuilder())->printParty($head, $this->db('en'), $this->config()));
    }

    public function testSnapshotWithoutAddressGivesPartyWithoutCountry(): void
    {
        $head = ['doc_type' => 'invno', 'partner' => 7, 'customer_snapshot' => json_encode(['name' => 'Firma'])];

        $party = (new DocPrintBuilder())->printParty($head, $this->db('sk'), $this->config());

        $this->assertNull($party?->country);
        $this->assertSame('sk', $party?->personLanguage);
    }
}
