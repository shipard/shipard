<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashRegister;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\EscPos\ReceiptElement;
use Shipard\Core\Prints\EscPos\ReceiptMarkupParser;
use Shipard\Tests\Fixtures\EscPos\EscPosFixtures;

/**
 * Účtenka prodejky pro účtenkovou tiskárnu (#177 U34): šablona
 * `page.escpos.twig` nad fixture `PrintData` — stejné údaje daňového dokladu
 * jako A4 prodejka. Výstup čte skutečný parser značek, takže test hlídá
 * i platnost šablony.
 */
final class ReceiptPosTemplateTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    /** @return list<ReceiptElement> */
    private static function elements(string $fixture, ?string $language = null, ?\Closure $modify = null): array
    {
        return (new ReceiptMarkupParser())->parse(EscPosFixtures::receiptMarkup($fixture, $language, $modify));
    }

    /**
     * Prvky jako řádky textu: `row` = „levý | pravý“, příkaz = `[typ]`.
     *
     * @return list<string>
     */
    private static function texts(string $fixture, ?string $language = null, ?\Closure $modify = null): array
    {
        return array_map(static function (ReceiptElement $e): string {
            return match ($e->type) {
                ReceiptElement::TEXT => str_replace(self::NBSP, ' ', $e->text),
                ReceiptElement::ROW  => str_replace(self::NBSP, ' ', $e->text . ($e->right === null ? '' : ' | ' . $e->right)),
                ReceiptElement::FEED => "[feed {$e->lines}]",
                default              => "[{$e->type}]",
            };
        }, self::elements($fixture, $language, $modify));
    }

    public function testVatPayerReceiptCarriesDataOfTheTaxDocument(): void
    {
        $this->assertSame([
            'Tiskárna Vzorová s.r.o.',
            'Dlouhá 1, 760 01 Zlín',
            'IČ: 00000019, DIČ: CZ00000019',
            '[line]',
            'Prodejka – daňový doklad',
            'Číslo dokladu | IT-PRINT-REC',
            'Datum vystavení | 30. 9. 2026',
            'Datum zdanitelného plnění | 30. 9. 2026',
            'Pokladna | Hlavní pokladna',
            '[line]',
            'Tonery do tiskárny',
            '  2 × 500,00 (DPH 21 %) | 1 210,00',
            'Kancelářský papír A4, 500 listů, 80 g/m² – bílý',
            '  3 bal × 100,00 (DPH 12 %) | 336,00',
            '[line]',
            'Rekapitulace DPH',
            'Základní 21 %: Základ 1 000,00, DPH 210,00 | 1 210,00',
            'Snížená 12 %: Základ 300,00, DPH 36,00 | 336,00',
            '[line]',
            'Celkem | 1 546,00 CZK',
            'Způsob úhrady | Hotovost',
            'Vystavil | Jana Nováková',
            '[feed 1]',
            'Děkujeme za nákup',
            '[feed 3]',
            '[cut]',
        ], self::texts('receiptPosVatPayer'));
    }

    public function testStylesOfTheKeyLines(): void
    {
        $elements = self::elements('receiptPosVatPayer');
        $find     = static function (string $text) use ($elements): ReceiptElement {
            foreach ($elements as $element) {
                if ($element->text === $text) {
                    return $element;
                }
            }
            self::fail("Element '{$text}' not found");
        };

        $supplier = $find('Tiskárna Vzorová s.r.o.');
        $this->assertSame(['center', true], [$supplier->align, $supplier->bold]);

        $title = $find('Prodejka – daňový doklad');
        $this->assertSame(['center', 2, 1], [$title->align, $title->width, $title->height]);

        $total = $find('Celkem');
        $this->assertSame([true, 2, 1], [$total->bold, $total->width, $total->height]);

        // Druhý řádek položky a rekapitulace malým písmem.
        $this->assertTrue($find('Rekapitulace DPH')->small);
        $details = array_values(array_filter($elements, static fn (ReceiptElement $e): bool => str_starts_with($e->text, '  ')));
        $this->assertCount(2, $details);
        foreach ($details as $detail) {
            $this->assertTrue($detail->small);
        }
    }

    public function testNonVatPayerHasNoVatDataAndShowsRounding(): void
    {
        $texts = self::texts('receiptPosNonVatPayer');

        $this->assertContains('Prodejka', $texts);
        $this->assertContains('IČ: 00000019', $texts);
        $this->assertContains('  2 × 499,80 | 999,60', $texts);
        $this->assertContains('Celkem | 1 000,00 CZK', $texts);
        $this->assertContains('Způsob úhrady | Kartou', $texts);
        $this->assertContains('Zaokrouhlení | 0,40 CZK', $texts);

        $all = implode("\n", $texts);
        foreach (['DIČ', 'DPH', 'Datum zdanitelného plnění', 'Rekapitulace', 'Vystavil'] as $absent) {
            $this->assertStringNotContainsString($absent, $all, $absent);
        }
    }

    public function testCancelledReceiptStartsWithWatermark(): void
    {
        $elements = self::elements('receiptPosCancelled');

        $this->assertSame('STORNO', $elements[0]->text);
        $this->assertSame(['center', true, 2, 2], [$elements[0]->align, $elements[0]->bold, $elements[0]->width, $elements[0]->height]);
        $this->assertSame(ReceiptElement::FEED, $elements[1]->type);
        $this->assertSame('Tiskárna Vzorová s.r.o.', $elements[2]->text);
        // Platná prodejka řádek nemá.
        $this->assertNotContains('STORNO', self::texts('receiptPosVatPayer'));
    }

    public function testCustomerIsPrintedOnlyWhenTheDocumentHasOne(): void
    {
        $texts = self::texts('receiptPosCustomer');

        $at = array_search('Odběratel', $texts, true);
        $this->assertNotFalse($at);
        $this->assertSame(
            ['[line]', 'Odběratel', 'Papírnictví U Brány s.r.o.', 'Náměstí 5, 765 02 Otrokovice', 'IČ: 00000035, DIČ: CZ00000035', '[line]'],
            array_slice($texts, $at - 1, 6),
        );
        $this->assertNotContains('Odběratel', self::texts('receiptPosVatPayer'));

        // Odběratel vlevo, dodavatel na střed.
        $elements = self::elements('receiptPosCustomer');
        $this->assertSame('left', $elements[$at]->align);
        $this->assertSame('left', $elements[$at + 1]->align);
        $this->assertTrue($elements[$at + 1]->bold);
    }

    public function testReceiptPaidByBankTransferHasPaymentData(): void
    {
        $texts = self::texts('receiptPosCustomer');

        $this->assertContains('Datum splatnosti | 14. 10. 2026', $texts);
        $this->assertContains('K úhradě | 1 210,00 CZK', $texts);
        $this->assertContains('Způsob úhrady | Převodem', $texts);
        $this->assertContains('Bankovní účet | 19-2000145399/0800', $texts);
        $this->assertContains('IBAN | CZ65 0800 0000 1920 0014 5399', $texts);
        $this->assertContains('Variabilní symbol | 1426000001', $texts);

        // Hotově: nic k úhradě, žádná splatnost ani účet.
        $cash = implode("\n", self::texts('receiptPosVatPayer'));
        foreach (['K úhradě', 'Datum splatnosti', 'IBAN', 'Bankovní účet', 'Variabilní symbol'] as $absent) {
            $this->assertStringNotContainsString($absent, $cash, $absent);
        }
    }

    public function testRowVariants(): void
    {
        $texts = self::texts('receiptPosVatPayer', null, static function (array $envelope): array {
            $item = $envelope['data']['rows'][0];
            $envelope['data']['rows'] = [
                ['kind' => 'text', 'description' => "Poznámka\nna dva řádky"],
                ['discountPct' => 10, 'unit' => ['id' => 1, 'label' => 'ks']] + $item,
                ['quantity' => null, 'description' => 'Doprava'] + $item,
                ['vat' => null, 'description' => 'Mimo DPH'] + $item,
            ];
            return $envelope;
        });

        $this->assertContains('Poznámka na dva řádky', $texts);
        $this->assertContains('Tonery do tiskárny sleva 10 %', $texts);
        $this->assertContains('  2 ks × 500,00 (DPH 21 %) | 1 210,00', $texts);
        // Řádek bez množství netiskne cenu za jednotku, řádek bez kódu sazbu.
        $this->assertContains('   (DPH 21 %) | 1 210,00', $texts);
        $this->assertContains('  2 × 500,00 | 1 210,00', $texts);
    }

    public function testAdvancesAndForeignCurrency(): void
    {
        $texts = self::texts('receiptPosVatPayer', null, static function (array $envelope): array {
            $envelope['data']['advances'] = ['base' => 100, 'vat' => 21, 'total' => 121];
            $envelope['data']['document'] = [
                'currency' => 'EUR', 'foreignCurrency' => true, 'exchangeRate' => 25.5,
            ] + $envelope['data']['document'];
            $envelope['data']['totals']['totalDom'] = 39423;
            return $envelope;
        });

        $this->assertContains('Celkem | 1 546,00 EUR', $texts);
        $this->assertContains('Uhrazeno zálohami | -121,00 EUR', $texts);
        $this->assertContains('K úhradě v CZK | 39 423,00 CZK', $texts);
        $this->assertContains('Kurz | 1 EUR = 25,5 CZK', $texts);
    }

    public function testSnapshotWithoutOptionalKeysStillRenders(): void
    {
        // Starší doklady a import: snapshot strany nemusí nést adresu ani registraci.
        $texts = self::texts('receiptPosVatPayer', null, static function (array $envelope): array {
            $envelope['data']['supplier'] = ['name' => 'A & B <s.r.o.> "x"'];
            $envelope['data']['cashDesk'] = null;
            return $envelope;
        });

        // Hodnoty escapuje Twig — parser je přečte zpět beze změny.
        $this->assertSame('A & B <s.r.o.> "x"', $texts[0]);
        $this->assertSame('[line]', $texts[1]);
        $this->assertStringNotContainsString('Pokladna', implode("\n", $texts));
    }

    public function testAddressWithoutDisplayLineIsComposed(): void
    {
        $texts = self::texts('receiptPosVatPayer', null, static function (array $envelope): array {
            $envelope['data']['supplier']['address'] = [
                'street' => 'Dlouhá', 'house_number' => '1', 'city' => 'Zlín', 'zip' => '76001', 'country' => 'cz',
            ];
            return $envelope;
        });

        $this->assertSame('Dlouhá 1, 760 01 Zlín', $texts[1]);
    }

    public function testReceiptIsPrintedInEveryPrintLanguage(): void
    {
        $thanks = [
            'cs' => 'Děkujeme za nákup',
            'en' => 'Thank you for your purchase',
            'sk' => 'Ďakujeme za nákup',
            'de' => 'Vielen Dank für Ihren Einkauf',
        ];
        foreach ($thanks as $language => $text) {
            $texts = self::texts('receiptPosVatPayer', $language);

            $this->assertContains($text, $texts, $language);
            $this->assertSame('[cut]', end($texts), $language);
        }

        $english = self::texts('receiptPosVatPayer', 'en');
        $this->assertContains('Receipt No. | IT-PRINT-REC', $english);
        $this->assertContains('Issue date | 30 Sep 2026', $english);
        $this->assertContains('  2 × 500.00 (VAT 21%) | 1,210.00', $english);
    }
}
