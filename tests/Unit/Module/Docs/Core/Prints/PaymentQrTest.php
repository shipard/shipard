<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\Prints\PaymentQr\CzIbanCalculator;
use Shipard\Module\Docs\Core\Prints\PaymentQr\PaymentQrInput;
use Shipard\Module\Docs\Core\Prints\PaymentQr\PaymentQrResolver;
use Shipard\Module\Docs\Core\Prints\PaymentQr\SpaydGenerator;

/**
 * QR platba (#90 D17): SPAYD payload, dopočet českého IBAN a volba
 * standardu podle země odběratele.
 */
class PaymentQrTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): PaymentQrInput
    {
        return new PaymentQrInput(...($overrides + [
            'iban'             => 'CZ65 0800 0000 1920 0014 5399',
            'bic'              => 'GIBACZPX',
            'accountNumber'    => '19-2000145399/0800',
            'amount'           => 1210.0,
            'currency'         => 'eur',
            'paymentReference' => '2026000123',
            'specificSymbol'   => null,
            'constantSymbol'   => null,
            'dueDate'          => '2026-10-14',
            'message'          => '2026000123',
        ]));
    }

    // ── SpaydGenerator ──────────────────────────────────────────────────────

    public function testFullPayload(): void
    {
        $generator = new SpaydGenerator();

        $this->assertSame('spayd', $generator->standard());
        $this->assertSame(
            'SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*AM:1210.00*CC:EUR'
            . '*X-VS:2026000123*DT:20261014*MSG:2026000123',
            $generator->generate($this->input()),
        );
        $this->assertSame([], $generator->skippedFields());
    }

    public function testAmountHasAlwaysTwoDecimals(): void
    {
        $generator = new SpaydGenerator();

        $this->assertStringContainsString('*AM:0.50*', (string) $generator->generate($this->input(['amount' => 0.5])));
        $this->assertStringContainsString('*AM:1234567.90*', (string) $generator->generate($this->input(['amount' => 1234567.9])));
        $this->assertStringContainsString('*AM:10.01*', (string) $generator->generate($this->input(['amount' => 10.005])));
    }

    public function testAllSymbolsAndMissingOptionalFields(): void
    {
        $generator = new SpaydGenerator();

        $this->assertSame(
            'SPD*1.0*ACC:CZ6508000000192000145399*AM:100.00*CC:CZK*X-VS:1*X-SS:22*X-KS:0308',
            $generator->generate($this->input([
                'bic' => null, 'amount' => 100.0, 'currency' => 'CZK',
                'paymentReference' => '1', 'specificSymbol' => '22', 'constantSymbol' => '0308',
                'dueDate' => null, 'message' => null,
            ])),
        );
    }

    public function testNonNumericOrLongSymbolsAreSkippedAndReported(): void
    {
        $generator = new SpaydGenerator();

        $payload = (string) $generator->generate($this->input([
            'paymentReference' => 'FV-2026/123',
            'specificSymbol'   => '12345678901',
            'constantSymbol'   => '0308',
        ]));

        $this->assertStringNotContainsString('X-VS', $payload);
        $this->assertStringNotContainsString('X-SS', $payload);
        $this->assertStringContainsString('*X-KS:0308*', $payload);
        $this->assertSame(['paymentReference', 'specificSymbol'], $generator->skippedFields());

        // Další běh hlášení nepřenáší.
        $generator->generate($this->input());
        $this->assertSame([], $generator->skippedFields());
    }

    public function testValuesAreEscaped(): void
    {
        $payload = (string) (new SpaydGenerator())->generate($this->input(['message' => 'A*B 100%']));

        $this->assertStringEndsWith('*MSG:A%2AB 100%25', $payload);
    }

    public function testMessageIsTruncatedToSixtyCharacters(): void
    {
        $payload = (string) (new SpaydGenerator())->generate($this->input(['message' => str_repeat('ž', 70)]));

        $this->assertStringEndsWith('*MSG:' . str_repeat('ž', 60), $payload);
    }

    public function testInvalidBicIsLeftOut(): void
    {
        $payload = (string) (new SpaydGenerator())->generate($this->input(['bic' => 'banka']));

        $this->assertStringContainsString('ACC:CZ6508000000192000145399*AM', $payload);
    }

    public function testIbanIsComputedFromCzechAccountNumber(): void
    {
        $generator = new SpaydGenerator();

        $this->assertStringStartsWith(
            'SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*',
            (string) $generator->generate($this->input(['iban' => null])),
        );
        $this->assertStringStartsWith(
            'SPD*1.0*ACC:CZ5508000000001234567899*',
            (string) $generator->generate($this->input(['iban' => '', 'bic' => null, 'accountNumber' => '1234567899/0800'])),
        );
    }

    public function testNoAccountMeansNoPayload(): void
    {
        $generator = new SpaydGenerator();

        $this->assertNull($generator->generate($this->input(['iban' => null, 'accountNumber' => null])));
        $this->assertNull($generator->generate($this->input(['iban' => null, 'accountNumber' => 'DE-účet 123'])));
        $this->assertNull($generator->generate($this->input(['iban' => 'není IBAN'])), 'vadný IBAN se nedopočítává');
    }

    // ── CzIbanCalculator ────────────────────────────────────────────────────

    /** @return array<string, array{string, ?string}> */
    public static function accountNumbers(): array
    {
        return [
            's předčíslím'        => ['19-2000145399/0800', 'CZ6508000000192000145399'],
            'bez předčíslí'       => ['1234567899/0800', 'CZ5508000000001234567899'],
            's mezerami'          => [' 19 - 2000145399 / 0800 ', 'CZ6508000000192000145399'],
            'krátké číslo'        => ['123457/0100', 'CZ7001000000000000123457'],
            'bez kódu banky'      => ['2000145399', null],
            'písmena'             => ['ABC/0800', null],
            'dlouhé předčíslí'    => ['1234567-2000145399/0800', null],
            'IBAN místo čísla'    => ['CZ6508000000192000145399', null],
        ];
    }

    #[DataProvider('accountNumbers')]
    public function testCzIban(string $accountNumber, ?string $expected): void
    {
        $this->assertSame($expected, CzIbanCalculator::fromAccountNumber($accountNumber));
    }

    // ── PaymentQrResolver ───────────────────────────────────────────────────

    public function testResolverReturnsSpaydForEveryCountryInV1(): void
    {
        $resolver = new PaymentQrResolver();

        foreach (['cz', 'sk', 'de', null] as $country) {
            $this->assertInstanceOf(SpaydGenerator::class, $resolver->forCustomerCountry($country));
        }
    }
}
