<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;

/**
 * Plán uložení pokynu (#166 D62): projekce nalezených referencí z bloků
 * `_resolve` — bez voleb uživatele, bez zakládání. Rozhodování nad volbami
 * patří interpretaci (`RecordMatching::reconcile()` staví na témže základu).
 */
final class PayloadResolvePlanTest extends TestCase
{
    private const MATCHED = ['status' => 'matched', 'matchedBy' => 'ref'];

    public function testPlanProjectsMatchedReferences(): void
    {
        $issues = [];
        $plan = PayloadResolve::plan([
            'supplier'     => ['matchedId' => 42] + self::MATCHED,
            'customer'     => ['matchedId' => 1, 'matchedBy' => 'selfParty'] + self::MATCHED,
            'supplierBank' => ['matchedId' => 7] + self::MATCHED,
            'partner'      => ['matchedId' => 43] + self::MATCHED,
            'dimensions'   => ['costCenter' => ['value' => '@A', 'status' => 'matched', 'matchedId' => 3]],
            'rows'         => [
                [
                    'index'   => 0,
                    'item'    => ['matchedId' => 18] + self::MATCHED,
                    'unit'    => ['status' => 'matched', 'matchedId' => 9, 'matchedBy' => 'id'],
                    'vatCode' => ['status' => 'matched', 'createPayload' => ['code' => 'cz-110', 'pct' => 21.0]],
                    'account' => ['number' => '518100', 'matchedId' => 55, 'status' => 'matched'],
                    'partner' => ['matchedId' => 44] + self::MATCHED,
                ],
                ['index' => 1, 'unit' => ['status' => 'notFound']],
            ],
        ], $issues);

        $this->assertSame([], $issues);
        $this->assertNull($plan['errorCode']);
        $this->assertSame([42, 1, 7, 43], [$plan['resolvedSupplier'], $plan['resolvedCustomer'], $plan['resolvedSupplierBank'], $plan['resolvedHeadPartner']]);
        $this->assertSame(['costCenter' => 3], $plan['resolvedHeadDimensions']);
        $this->assertSame([18, null], $plan['resolvedRowItems']);
        $this->assertSame([9, null], $plan['resolvedRowUnits']);
        $this->assertSame([55, null], $plan['resolvedRowAccounts']);
        $this->assertSame([44, null], $plan['resolvedRowPartners']);
        $this->assertSame('cz-110', $plan['resolvedRowVatCodes'][0]['createPayload']['code']);
        // Pokyn nic nezakládá a řádky nevynechává.
        $this->assertSame([[], null, [], [], []], [$plan['partyCreates'], $plan['bankCreate'], $plan['rowItemCreates'], $plan['rowSkips'], $plan['rowItemPins']]);
    }

    /** Nenalezená reference už nese nález — plán k ní nic dalšího nehlásí. */
    public function testReferenceErrorLeavesIdEmptyWithoutAnotherIssue(): void
    {
        $issues = [];
        $plan = PayloadResolve::plan([
            'supplier' => PayloadResolve::REFERENCE_ERROR_BLOCK,
            'rows'     => [['index' => 0, 'item' => PayloadResolve::REFERENCE_ERROR_BLOCK]],
        ], $issues);

        $this->assertSame([], $issues);
        $this->assertNull($plan['errorCode']);
        $this->assertNull($plan['resolvedSupplier']);
        $this->assertSame([null], $plan['resolvedRowItems']);
    }

    /** Strana vlastní firmy na zdroji bez vlastní osoby — doklad uložit nejde. */
    public function testBlockWithoutMatchThatIsNotAReferenceIsUnresolved(): void
    {
        $issues = [];
        $plan = PayloadResolve::plan(['customer' => ['status' => 'notFound']], $issues);

        $this->assertSame('unresolved_required', $plan['errorCode']);
        $this->assertSame([['error', 'customer', 'unresolved_required']], array_map(
            static fn (array $issue): array => [$issue['severity'], $issue['path'], $issue['code']],
            $issues,
        ));
    }

    public function testBasePlanLeavesSkippedRowsOutAndDecidesNothing(): void
    {
        $plan = PayloadResolve::basePlan([
            'supplier' => ['matchedId' => 42] + self::MATCHED,
            'rows'     => [
                ['index' => 0, 'item' => ['matchedId' => 18] + self::MATCHED],
                ['index' => 1, 'unit' => ['status' => 'matched', 'matchedId' => 9]],
            ],
        ], [0]);

        $this->assertNull($plan['resolvedSupplier'], 'strany rozhoduje volající');
        $this->assertSame([1 => null], $plan['resolvedRowItems']);
        $this->assertSame([1 => 9], $plan['resolvedRowUnits']);
        $this->assertSame([0], $plan['rowSkips']);
    }
}
