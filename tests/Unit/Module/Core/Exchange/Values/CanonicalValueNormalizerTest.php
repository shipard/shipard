<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Values;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\World\Validations\Iban;
use Shipard\Tests\Unit\Module\World\Validations\ValueCasesFixtureTest;

/**
 * Normalizace canonicalu (#118 D1–D4, D14): každá cesta, idempotence,
 * oprava IBAN ↔ číslo účtu oběma směry s pojistkou, auditní blok
 * `_resolve.valueRepairs` bez duplicit.
 */
class CanonicalValueNormalizerTest extends TestCase
{
    /** Strana v tiskových tvarech, jak je čte model; platné identifikátory. */
    private const PRINTED_SUPPLIER = [
        'name'      => 'Dřevomat Hana s.r.o.',
        'country'   => 'CZ',
        'companyId' => '430 75 894',
        'taxId'     => 'cz 4307 5894',
        'vatId'     => 'cz 4307 5894',
        'address'   => ['street' => 'Pilařská', 'houseNumber' => '1204/7', 'city' => 'Olomouc', 'zip' => '779 00', 'country' => 'CZ'],
        'contact'   => ['name' => null, 'email' => ' Odbyt@Drevomat-Hana.example ', 'phone' => '577 123 456', 'web' => 'https://WWW.Drevomat-hana.example/'],
        'bankAccount' => ['accountNumber' => '0101968643 / 0100', 'iban' => 'cz68 0100 0000 0001 0196 8643', 'bic' => ' kombczpp ', 'currency' => 'CZK'],
    ];

    /** @return array<string, mixed> */
    private function canonical(array $supplier = self::PRINTED_SUPPLIER): array
    {
        return [
            'docType'  => 'invoiceReceived',
            'supplier' => $supplier,
            'customer' => null,
            'payment'  => ['method' => 'bankTransfer', 'paymentReference' => ' inv-2026 -0388 ', 'specificSymbol' => '12 34', 'constantSymbol' => null],
            'rows'     => [
                ['rowKind' => 'item', 'paymentReference' => '2026 0388', 'specificSymbol' => ' 9 '],
                ['rowKind' => 'text'],
            ],
        ];
    }

    public function testNormalizesPartyContactBankAndSymbols(): void
    {
        $out = CanonicalValueNormalizer::normalize($this->canonical());
        $s = $out['supplier'];

        $this->assertSame('cz', $s['country']);
        $this->assertSame('43075894', $s['companyId']);
        $this->assertSame('CZ43075894', $s['taxId']);
        $this->assertSame('CZ43075894', $s['vatId']);
        $this->assertSame('77900', $s['address']['zip']);
        $this->assertSame('cz', $s['address']['country']);
        $this->assertSame('Pilařská', $s['address']['street'], 'ostatní pole adresy beze změny');
        $this->assertSame('odbyt@drevomat-hana.example', $s['contact']['email']);
        $this->assertSame('+420577123456', $s['contact']['phone']);
        $this->assertSame('www.drevomat-hana.example', $s['contact']['web']);
        $this->assertNull($s['contact']['name']);
        $this->assertSame('101968643/0100', $s['bankAccount']['accountNumber']);
        $this->assertSame('CZ6801000000000101968643', $s['bankAccount']['iban']);
        $this->assertSame('KOMBCZPP', $s['bankAccount']['bic']);
        $this->assertSame('CZK', $s['bankAccount']['currency']);

        $this->assertNull($out['customer']);
        $this->assertSame('INV-2026-0388', $out['payment']['paymentReference']);
        $this->assertSame('1234', $out['payment']['specificSymbol']);
        $this->assertNull($out['payment']['constantSymbol']);
        $this->assertSame('20260388', $out['rows'][0]['paymentReference']);
        $this->assertSame('9', $out['rows'][0]['specificSymbol']);
        $this->assertSame(['rowKind' => 'text'], $out['rows'][1]);
        $this->assertArrayNotHasKey('_resolve', $out, 'bez opravy žádný auditní blok');
    }

    public function testIsIdempotent(): void
    {
        $once = CanonicalValueNormalizer::normalize($this->canonical());
        $this->assertSame($once, CanonicalValueNormalizer::normalize($once));

        $broken = $this->canonical();
        $broken['supplier']['bankAccount']['iban'] = 'CZ68 0100 0000 0001 0196 8644';
        $once = CanonicalValueNormalizer::normalize($broken);
        $twice = CanonicalValueNormalizer::normalize($once);
        $this->assertSame($once, $twice, 'druhý běh nic nepřidá, záznam opravy zachová');
        $this->assertCount(1, $twice['_resolve']['valueRepairs']);
    }

    public function testRepairsIbanFromValidAccountNumber(): void
    {
        // Doklad 0701: model vložil nulu do IBAN, číslo účtu přečetl správně.
        $canonical = $this->canonical();
        $canonical['supplier']['bankAccount'] = ['accountNumber' => '8360405366/0600', 'iban' => 'CZ06 0600 0000 0008 3604 0536', 'bic' => null];

        $out = CanonicalValueNormalizer::normalize($canonical);

        $this->assertSame('CZ0606000000008360405366', $out['supplier']['bankAccount']['iban']);
        $this->assertSame('8360405366/0600', $out['supplier']['bankAccount']['accountNumber']);
        $this->assertSame([[
            'path'  => 'supplier.bankAccount.iban',
            'from'  => 'CZ0606000000000836040536',
            'to'    => 'CZ0606000000008360405366',
            'basis' => 'accountNumber',
        ]], $out['_resolve']['valueRepairs']);
    }

    public function testRepairsAccountNumberFromValidIban(): void
    {
        $canonical = $this->canonical();
        $canonical['supplier']['bankAccount'] = ['accountNumber' => '2308417558/2010', 'iban' => 'CZ8520100000002308417557'];

        $out = CanonicalValueNormalizer::normalize($canonical);

        $this->assertSame('2308417557/2010', $out['supplier']['bankAccount']['accountNumber']);
        $this->assertSame([[
            'path'  => 'supplier.bankAccount.accountNumber',
            'from'  => '2308417558/2010',
            'to'    => '2308417557/2010',
            'basis' => 'iban',
        ]], $out['_resolve']['valueRepairs']);
    }

    public function testSuspectIbanIsReplacedFromAccountNumberThatPassesAllChecks(): void
    {
        // IBAN je platný (D30), ale číslo uvnitř neprojde mod 11; zapsané číslo
        // účtu projde vším a nese jiný účet → IBAN se dopočte z něj (zdroj opravy
        // je vždy hodnota, která projde vším; cíl smí být i „jen podezřelý“).
        $canonical = $this->canonical();
        $canonical['supplier']['bankAccount'] = ['accountNumber' => '2308417557/2010', 'iban' => 'CZ4701000000001234567890'];

        $out = CanonicalValueNormalizer::normalize($canonical);

        $this->assertSame('CZ8520100000002308417557', $out['supplier']['bankAccount']['iban']);
        $this->assertSame('2308417557/2010', $out['supplier']['bankAccount']['accountNumber']);
        $this->assertSame('accountNumber', $out['_resolve']['valueRepairs'][0]['basis']);
    }

    public function testNoRepairWhenBothValidOrBothInvalidOrDerivedValueInvalid(): void
    {
        $cases = [
            'oba platné, nesedí'          => ['accountNumber' => '19-2000145399/0800', 'iban' => 'CZ8520100000002308417557'],
            'oba neplatné'                => ['accountNumber' => '1234567890/0100', 'iban' => 'CZ6508000000001234567890'],
            'mod 97 sedí, účet uvnitř ne' => ['accountNumber' => '1234567890/0100', 'iban' => 'CZ4701000000001234567890'],
            'podezřelé číslo, IBAN téhož účtu (D30)' => ['accountNumber' => '2308417558/2010', 'iban' => 'CZ' . Iban::checkDigits('CZ', '20100000002308417558') . '20100000002308417558'],
            'podezřelé číslo + IBAN s chybnou kontrolní číslicí: z podezřelého se nedopočítává' => ['accountNumber' => '2308417558/2010', 'iban' => 'CZ8520100000002308417558'],
            'oba platné a sedí'           => ['accountNumber' => '8360405366/0600', 'iban' => 'CZ0606000000008360405366'],
            'jen IBAN'                    => ['accountNumber' => null, 'iban' => 'CZ0606000000000836040536'],
            'jen účet'                    => ['accountNumber' => '2308417558/2010', 'iban' => null],
            'zahraniční IBAN'             => ['accountNumber' => '2308417558/2010', 'iban' => 'DE72100700000532013000'],
        ];
        foreach ($cases as $label => $bank) {
            $canonical = $this->canonical();
            $canonical['supplier']['bankAccount'] = $bank;
            $out = CanonicalValueNormalizer::normalize($canonical);
            $this->assertSame($bank, $out['supplier']['bankAccount'], $label);
            $this->assertArrayNotHasKey('_resolve', $out, $label);
        }
    }

    public function testRepairCountryComesFromIbanPrefixThenParty(): void
    {
        // Strana bez země, IBAN s prefixem CZ → oprava podle cz pravidel.
        $canonical = $this->canonical(['name' => 'X', 'bankAccount' => ['accountNumber' => '8360405366/0600', 'iban' => 'CZ0606000000000836040536']]);
        $out = CanonicalValueNormalizer::normalize($canonical);
        $this->assertSame('CZ0606000000008360405366', $out['supplier']['bankAccount']['iban']);

        // IBAN bez tvaru → země strany (sk) rozhodne.
        $canonical = $this->canonical(['name' => 'X', 'country' => 'sk', 'bankAccount' => ['accountNumber' => '2600000003/1100', 'iban' => 'SK24 1100']]);
        $out = CanonicalValueNormalizer::normalize($canonical);
        $this->assertSame('SK2411000000002600000003', $out['supplier']['bankAccount']['iban']);
        $this->assertSame('accountNumber', $out['_resolve']['valueRepairs'][0]['basis']);

        // Strana bez země a IBAN bez tvaru → nic se nehádá.
        $canonical = $this->canonical(['name' => 'X', 'bankAccount' => ['accountNumber' => '2600000003/1100', 'iban' => 'SK24 1100']]);
        $out = CanonicalValueNormalizer::normalize($canonical);
        $this->assertSame('SK241100', $out['supplier']['bankAccount']['iban']);
        $this->assertArrayNotHasKey('_resolve', $out);
    }

    public function testPartyWithoutCountryGetsGenericRules(): void
    {
        $canonical = $this->canonical([
            'name' => 'Quillstack Inc.', 'companyId' => ' 6947 ',
            'address' => ['zip' => '94105-1234'],
            'contact' => ['phone' => '(415) 555-0199', 'email' => 'Billing@Quillstack.example'],
            'bankAccount' => ['accountNumber' => '12 34 56', 'iban' => null],
        ]);
        $s = CanonicalValueNormalizer::normalize($canonical)['supplier'];

        $this->assertSame('6947', $s['companyId'], 'bez země se nuly nedoplňují');
        $this->assertSame('94105-1234', $s['address']['zip']);
        $this->assertSame('4155550199', $s['contact']['phone'], 'bez země bez předvolby');
        $this->assertSame('billing@quillstack.example', $s['contact']['email']);
        $this->assertSame('123456', $s['bankAccount']['accountNumber']);
        $this->assertArrayNotHasKey('country', $s);
    }

    public function testAddressCountryDrivesZipAndPartyCountryDrivesPhone(): void
    {
        $canonical = $this->canonical([
            'name' => 'X', 'country' => 'cz', 'companyId' => '6947',
            'address' => ['zip' => '941 05', 'country' => 'US'],
            'contact' => ['phone' => '577 123 456'],
        ]);
        $s = CanonicalValueNormalizer::normalize($canonical)['supplier'];

        $this->assertSame('941 05', $s['address']['zip'], 'PSČ podle země adresy (us bez pravidla)');
        $this->assertSame('us', $s['address']['country']);
        $this->assertSame('+420577123456', $s['contact']['phone'], 'telefon podle země strany');
        $this->assertSame('00006947', $s['companyId']);

        // Jen země adresy → platí pro stranu.
        $canonical = $this->canonical(['name' => 'X', 'companyId' => '6947', 'address' => ['zip' => '760 01', 'country' => 'cz']]);
        $s = CanonicalValueNormalizer::normalize($canonical)['supplier'];
        $this->assertSame('00006947', $s['companyId']);
        $this->assertSame('76001', $s['address']['zip']);
    }

    public function testNullsMissingKeysAndNonStringsStayUntouched(): void
    {
        $canonical = [
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'X', 'companyId' => null, 'vatId' => 123, 'address' => null, 'contact' => ['phone' => null], 'bankAccount' => null],
            'customer' => ['name' => 'Y'],
            'rows' => [null, 'text'],
        ];
        $this->assertSame($canonical, CanonicalValueNormalizer::normalize($canonical));
        $this->assertSame(['docType' => 'x'], CanonicalValueNormalizer::normalize(['docType' => 'x']));
    }

    public function testAllPartiesAreNormalizedAndRepairsKeepExistingRecords(): void
    {
        $canonical = $this->canonical();
        $canonical['customer'] = ['name' => 'Y', 'country' => 'SK', 'companyId' => '36339008', 'address' => ['zip' => '811 01']];
        $canonical['balanceParty'] = ['name' => 'Z', 'country' => 'cz', 'bankAccount' => ['accountNumber' => '2308417558/2010', 'iban' => 'CZ8520100000002308417557']];
        $canonical['_resolve'] = ['contentTag' => ['tag' => 'it.software'], 'valueRepairs' => [['path' => 'supplier.bankAccount.iban', 'from' => 'a', 'to' => 'b', 'basis' => 'accountNumber']]];

        $out = CanonicalValueNormalizer::normalize($canonical);

        $this->assertSame('sk', $out['customer']['country']);
        $this->assertSame('81101', $out['customer']['address']['zip']);
        $this->assertSame('2308417557/2010', $out['balanceParty']['bankAccount']['accountNumber']);
        $this->assertSame(['tag' => 'it.software'], $out['_resolve']['contentTag'], 'cizí bloky _resolve zůstávají');
        $this->assertSame(['supplier.bankAccount.iban', 'balanceParty.bankAccount.accountNumber'], array_column($out['_resolve']['valueRepairs'], 'path'));
    }

    public function testNormalizeShapesDoesNotRepair(): void
    {
        $canonical = $this->canonical();
        $canonical['supplier']['bankAccount'] = ['accountNumber' => '8360405366/0600', 'iban' => 'cz06 0600 0000 0008 3604 0536'];
        $out = CanonicalValueNormalizer::normalizeShapes($canonical);
        $this->assertSame('CZ0606000000000836040536', $out['supplier']['bankAccount']['iban']);
        $this->assertArrayNotHasKey('_resolve', $out);
    }

    public function testSharedFixtureRepairCases(): void
    {
        $cases = JsoncParser::parseFile(ValueCasesFixtureTest::FIXTURE)['repair'];
        foreach ($cases as $case) {
            $label = json_encode($case, JSON_UNESCAPED_UNICODE);
            $bank = ['accountNumber' => $case['accountNumber'], 'iban' => $case['iban']];
            $out = CanonicalValueNormalizer::normalize(['supplier' => ['name' => 'X', 'country' => $case['country'], 'bankAccount' => $bank]]);
            if ($case['out'] === null) {
                $this->assertSame($bank, $out['supplier']['bankAccount'], $label);
                $this->assertArrayNotHasKey('_resolve', $out, $label);
                continue;
            }
            $field = $case['out']['basis'] === 'iban' ? 'accountNumber' : 'iban';
            $this->assertSame($case['out'][$field], $out['supplier']['bankAccount'][$field], $label);
            $this->assertSame($case['out']['basis'], $out['_resolve']['valueRepairs'][0]['basis'], $label);
            $this->assertSame("supplier.bankAccount.{$field}", $out['_resolve']['valueRepairs'][0]['path'], $label);
        }
    }
}
