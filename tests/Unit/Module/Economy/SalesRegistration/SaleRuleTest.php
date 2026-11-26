<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\SalesRegistration\SaleRule;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;

/**
 * Pravidla, kdy doklad do evidence spadá (#171 D8a, D23): tvar dat typu,
 * shoda hlavičky a pohybu řádku. Nad skutečnými daty `cz.eet2` hlídá
 * i to, co do evidence spadat nemá (brána, převod, výdej).
 */
class SaleRuleTest extends TestCase
{
    use SalesRegistrationConfig;

    public function testHeaderRuleMatchesTypePaymentMethodAndDirection(): void
    {
        $rule = SaleRule::fromArray(['docTypes' => ['cashreg', 'invno'], 'paymentMethods' => [0, 2]]);

        $this->assertNotNull($rule);
        $this->assertFalse($rule->isRowRule());
        $this->assertTrue($rule->matchesHead('cashreg', 0, 0));
        $this->assertTrue($rule->matchesHead('invno', 2, 0));
        $this->assertFalse($rule->matchesHead('invno', 1, 0), 'převodem');
        $this->assertFalse($rule->matchesHead('invno', 5, 0), 'platební bránou');
        $this->assertFalse($rule->matchesHead('invni', 0, 0), 'faktura přijatá');
        $this->assertTrue($rule->matchesOperation(null), 'pravidlo nad hlavičkou bere každý řádek');
    }

    public function testRowRuleMatchesOperationsWithPrefixWildcard(): void
    {
        $rule = SaleRule::fromArray([
            'docTypes'      => ['cash'],
            'cashDir'       => 1,
            'rowOperations' => ['sale.*', 'payment.receivable', 'advance.received'],
        ]);

        $this->assertNotNull($rule);
        $this->assertTrue($rule->isRowRule());
        $this->assertTrue($rule->matchesHead('cash', 0, 1));
        $this->assertTrue($rule->matchesHead('cash', 2, 1), 'bez paymentMethods jakýkoli způsob úhrady');
        $this->assertFalse($rule->matchesHead('cash', 0, 2), 'výdej');

        foreach (['sale.services', 'sale.goods', 'sale.advanceDeduction', 'payment.receivable', 'advance.received'] as $operation) {
            $this->assertTrue($rule->matchesOperation($operation), $operation);
        }
        foreach (['transfer.in', 'acc.entry', 'sale', 'sales.goods', 'payment.receivable.x', 'purchase.goods', '', null] as $operation) {
            $this->assertFalse($rule->matchesOperation($operation), var_export($operation, true));
        }
    }

    public function testMalformedRuleIsRejected(): void
    {
        foreach ([
            'není pole'              => 'cashreg',
            'bez typů dokladů'       => ['paymentMethods' => [0]],
            'prázdné typy'           => ['docTypes' => []],
            'typ není text'          => ['docTypes' => [1]],
            'způsob úhrady textem'   => ['docTypes' => ['invno'], 'paymentMethods' => ['0']],
            'prázdné způsoby úhrady' => ['docTypes' => ['invno'], 'paymentMethods' => []],
            'směr textem'            => ['docTypes' => ['cash'], 'cashDir' => '1'],
            'prázdné pohyby'         => ['docTypes' => ['cash'], 'rowOperations' => []],
        ] as $label => $raw) {
            $this->assertNull(SaleRule::fromArray($raw), $label);
        }
    }

    /** Skutečná data cz.eet2 — D8a. */
    public function testEet2RulesCoverCashAndCardAndLeaveOutTheRest(): void
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');
        $this->assertNotNull($type);

        foreach (['cashreg', 'invno'] as $docType) {
            $this->assertFalse($type->ruleFor($docType, 0, 0)?->isRowRule(), "{$docType} hotově — celá částka");
            $this->assertNotNull($type->ruleFor($docType, 2, 0), "{$docType} kartou");
            foreach ([1, 3, 4, 5] as $method) {
                $this->assertNull($type->ruleFor($docType, $method, 0), "{$docType}, způsob úhrady {$method}");
            }
        }
        $this->assertTrue($type->ruleFor('cash', 0, 1)?->isRowRule(), 'pokladní doklad příjem — jen vyhovující řádky');
        $this->assertNull($type->ruleFor('cash', 0, 2), 'pokladní doklad výdej');
        $this->assertNull($type->ruleFor('invni', 0, 0), 'faktura přijatá');
        $this->assertNull($type->ruleFor('invpo', 0, 0), 'zálohová faktura — až Kontaktní platbou (fáze 3)');

        $this->assertSame(['cashreg', 'invno', 'cash'], $type->ruleDocTypes());
    }

    public function testEet2TimeZoneAndAmountMask(): void
    {
        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig())->get('cz.eet2');

        $this->assertSame('Europe/Prague', $type->saleTimeZone()->getName());
        foreach (['0.00', '0.50', '123.40', '-0.50', '-121.00', '99999999.99', '-99999999.99'] as $amount) {
            $this->assertTrue($type->isValidAmount($amount), $amount);
        }
        foreach (['-0.00', '12.3', '12', '012.00', '100000000.00', '1 000.00', '12,00', ''] as $amount) {
            $this->assertFalse($type->isValidAmount($amount), var_export($amount, true));
        }
        $this->assertStringContainsString('20', (string) $type->cashRegisterIdHint);
    }

    public function testTypeWithMalformedRuleIsNotOffered(): void
    {
        $types = self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc');
        $types['cz.eet2']['rules'][] = ['docTypes' => 'invno'];

        $registry = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig(['world.cz.salesRegistrationTypes' => $types]));

        $this->assertNull($registry->get('cz.eet2'), 'tiše vynechané pravidlo by znamenalo neodeslané tržby');
    }

    public function testTypeWithoutRulesAndZoneStillLoads(): void
    {
        $types = self::repoJsonc('modules/world/cz/config/salesRegistrationTypes.jsonc');
        unset($types['cz.eet2']['rules'], $types['cz.eet2']['timeZone'], $types['cz.eet2']['amount']);

        $type = SalesRegistrationTypes::fromConfig($this->salesRegistrationConfig(['world.cz.salesRegistrationTypes' => $types]))->get('cz.eet2');

        $this->assertNotNull($type);
        $this->assertSame([], $type->rules);
        $this->assertNull($type->ruleFor('cashreg', 0, 0));
        $this->assertSame(date_default_timezone_get(), $type->saleTimeZone()->getName());
        $this->assertTrue($type->isValidAmount('cokoli'));
    }
}
