<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\TransitAccountsProvisioner;

/**
 * Konzistence předpisu pro pokladnu (#59 D8) — programová kontrola nad
 * jsonc, bez DS:
 *
 *   1. maska card.transit a účet pokladny 211100 existují v obou seed rozvrzích,
 *   2. invno/invni: saldo krok má {"$ne": 0} a existuje právě jeden krok
 *      accountSrc cashDesk s query payment_method 0,
 *   3. cash/cashreg: každý pohyb z rowOperations povolený pro typ má krok
 *      předpisu a každý krok odkazuje na povolený pohyb (parita),
 *   4. cash: každý řádkový / DPH krok nese headQuery cash_dir (bez něj by
 *      účtoval obě strany).
 */
class CashAccountingRulesTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
    }

    /** @return list<array<string, mixed>> */
    private function stepsOf(string $docType): array
    {
        foreach ($this->rules()['documents'] as $doc) {
            if (($doc['docType'] ?? null) === $docType) {
                return array_values($doc['accounting']);
            }
        }
        $this->fail("Předpis nemá blok docType {$docType}");
    }

    /** @return array<string, list<string>> docType → povolené operace */
    private function allowedOperations(): array
    {
        $ops = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');
        $out = [];
        foreach ($ops as $code => $entry) {
            foreach (array_keys($entry['docTypes'] ?? []) as $docType) {
                $out[$docType][] = (string) $code;
            }
        }
        return $out;
    }

    /**
     * Karta / brána / dobírka = pohledávka 311 za plátcem (#72 D1/D3): žádný
     * krok ani kategorie card.transit, saldokontní hlavičkové kroky prodejních
     * typů nesou partnerSrc balance a pokrývají metody 2/3/5; výdej kartou je
     * závazek za plátcem; 261400 v seedech osnovy zůstává (nic ho neúčtuje).
     */
    public function testCardPaymentsBookReceivableForBalancePartnerNotTransit(): void
    {
        $rules = $this->rules();
        $this->assertArrayNotHasKey('card.transit', $rules['categories']);
        foreach ($rules['accounts'] as $entry) {
            $this->assertNotSame('card.transit', $entry['cat'] ?? null, 'card.transit nemá masku');
            $this->assertNotContains('261400', (array) ($entry['accountMask'] ?? []), '261400 se v předpisu neúčtuje');
        }
        foreach ($rules['documents'] as $doc) {
            foreach ($doc['accounting'] as $step) {
                $this->assertNotSame('card.transit', $step['cat'] ?? null, "{$doc['docType']}: krok card.transit");
                if (isset($step['partnerSrc'])) {
                    $this->assertSame('balance', $step['partnerSrc']);
                    $this->assertSame('head', $step['src'], 'partnerSrc jen na hlavičkovém kroku');
                    $this->assertContains($step['cat'], ['receivables', 'payables'], 'partnerSrc jen na saldokontním kroku');
                }
            }
        }

        $balanceSteps = fn(string $docType, string $cat) => array_values(array_filter(
            $this->stepsOf($docType),
            fn($s) => ($s['cat'] ?? null) === $cat && ($s['partnerSrc'] ?? null) === 'balance',
        ));

        $this->assertCount(1, $balanceSteps('invno', 'receivables'));
        $this->assertCount(1, $balanceSteps('invni', 'payables'));

        $cashreg = $balanceSteps('cashreg', 'receivables');
        $this->assertCount(1, $cashreg, 'prodejka: jeden saldokontní krok pro převod/kartu/dobírku/bránu');
        $this->assertSame([1, 2, 3, 5], $cashreg[0]['query']['payment_method']['$in']);
        $this->assertSame(0, $cashreg[0]['side']);

        $cashIn = $balanceSteps('cash', 'receivables');
        $this->assertCount(1, $cashIn, 'příjmový PD kartou = pohledávka za plátcem');
        $this->assertSame(['cash_dir' => 1, 'payment_method' => ['$in' => [2, 3, 5]]], $cashIn[0]['query']);
        $this->assertSame(0, $cashIn[0]['side']);
        $cashOut = $balanceSteps('cash', 'payables');
        $this->assertCount(1, $cashOut, 'výdajový PD kartou = závazek za plátcem');
        $this->assertSame(['cash_dir' => 2, 'payment_method' => 2], $cashOut[0]['query']);
        $this->assertSame(1, $cashOut[0]['side']);

        foreach (['accountChartDefault', 'accountChartNpo'] as $chart) {
            $numbers = array_flip(array_map(
                fn($e) => (string) $e['number'],
                JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc"),
            ));
            $this->assertArrayHasKey('261400', $numbers, "{$chart}: 261400 v osnově zůstává pro historii");
            $this->assertArrayHasKey('211100', $numbers, "{$chart} nemá 211100 (výchozí účet pokladny)");
            $this->assertArrayHasKey('315', $numbers, "{$chart}: 315 pro saldokonto Pohledávky (#72 D6)");
        }
    }

    /**
     * Převody peněz (Task D): kategorie cash.transit míří na 261100 (jediný
     * tranzit v předpisu — karty od #72 jdou na 311), pohyby transfer.* mají v rowOperations vlajky
     * rowSide 0 + rowPaymentId bez partnera a bez identityRequired, směr per
     * cash_dir; v bloku cash má každý směr právě jeden krok cash.transit
     * na správné straně (příjem DAL, výdej MD — pokladna z head kroku naopak).
     */
    public function testCashTransfersGoThroughTransitAccountSeparatedFromCards(): void
    {
        $rules = $this->rules();
        $this->assertArrayHasKey('cash.transit', $rules['categories']);

        $masks = [];
        foreach ($rules['accounts'] as $entry) {
            if (($entry['cat'] ?? null) === 'cash.transit') {
                $masks[] = (string) $entry['accountMask'];
            }
        }
        $this->assertSame(['261100'], $masks, 'cash.transit má jedinou pevnou analytiku 261100');

        $ops = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');
        foreach (['transfer.in' => 1, 'transfer.out' => 2] as $op => $dir) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame(0, $ops[$op]['rowSide'], "{$op}: strana z předpisu");
            $this->assertSame(1, $ops[$op]['rowPaymentId'], "{$op}: payment_reference řádku (nepovinný)");
            $this->assertArrayNotHasKey('rowPartner', $ops[$op], "{$op}: převod je bez partnera");
            $this->assertArrayNotHasKey('identityRequired', $ops[$op], "{$op}: VS není povinný");
            $this->assertSame(['cash'], array_keys($ops[$op]['docTypes']), "{$op}: jen pokladní doklad (T1)");
            $this->assertSame($dir, $ops[$op]['docTypes']['cash']['cashDir']);

            $steps = array_values(array_filter(
                $this->stepsOf('cash'),
                fn($s) => ($s['cat'] ?? null) === 'cash.transit' && ($s['operation'] ?? null) === $op,
            ));
            $this->assertCount(1, $steps, "cash: jeden krok cash.transit pro {$op}");
            $this->assertSame(['cash_dir' => $dir], $steps[0]['headQuery']);
            $this->assertSame('rows', $steps[0]['src']);
            $this->assertSame($dir === 1 ? 1 : 0, $steps[0]['side'], 'příjem DAL 261100, výdej MD 261100');
        }
    }

    /**
     * Každá analytika 261xxx, na kterou předpis míří maskou (clearing
     * 261200/261300, převody 261100), musí být v obou seed
     * rozvrzích — programově, bez ručního seznamu (vzor
     * VatAnalyticsCompletenessTest). Nová maska 261 bez účtu test shodí.
     */
    public function testEvery261MaskOfRulesHasAccountInBothSeedCharts(): void
    {
        $masks = [];
        foreach ($this->rules()['accounts'] as $entry) {
            foreach ((array) ($entry['accountMask'] ?? []) as $mask) {
                if (str_starts_with((string) $mask, '261')) {
                    $masks[(string) $mask] = (string) ($entry['cat'] ?? '');
                }
            }
        }
        $this->assertNotEmpty($masks, 'předpis má mít aspoň jednu masku 261');
        // Numerické klíče PHP přetypuje na int → zpět na string.
        foreach (array_keys($masks) as $mask) {
            $this->assertSame(6, strlen((string) $mask), "maska {$mask} má být plná analytika (prefix 261 by chytil vše)");
        }

        foreach (['accountChartDefault', 'accountChartNpo'] as $chart) {
            $numbers = array_flip(array_map(
                fn($e) => (string) $e['number'],
                JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc"),
            ));
            foreach ($masks as $mask => $cat) {
                $this->assertArrayHasKey((string) $mask, $numbers, "{$chart} nemá účet {$mask} (kategorie {$cat})");
            }
        }
    }

    /**
     * TransitAccountsProvisioner (Task E) nese definice inline — drift proti
     * seedům hlídá tenhle test (vzor ClearingInfrastructureProvisionerTest).
     */
    public function testTransitAccountsProvisionerMatchesBothSeedCharts(): void
    {
        foreach (['accountChartDefault', 'accountChartNpo'] as $chart) {
            $byNumber = [];
            foreach (JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc") as $entry) {
                $byNumber[(string) $entry['number']] = $entry;
            }
            foreach (TransitAccountsProvisioner::ACCOUNTS as $acc) {
                $number = $acc['number'];
                $this->assertArrayHasKey($number, $byNumber, "{$chart}: účet {$number} chybí");
                $this->assertSame($byNumber[$number]['name'], $acc['name'], "{$chart}: name {$number} se rozešel se seedem");
                $this->assertSame($byNumber[$number]['short_name'], $acc['short_name'], "{$chart}: short_name {$number}");
                $this->assertSame((int) $byNumber[$number]['account_kind'], $acc['account_kind'], "{$chart}: account_kind {$number}");
            }
        }

        // provisioner pokrývá každou pevnou masku 261xxx předpisu mimo clearing (accbal)
        $covered = array_column(TransitAccountsProvisioner::ACCOUNTS, 'number');
        foreach ($this->rules()['accounts'] as $entry) {
            foreach ((array) ($entry['accountMask'] ?? []) as $mask) {
                $mask = (string) $mask;
                if (str_starts_with($mask, '261') && !str_starts_with((string) ($entry['cat'] ?? ''), 'bank.')) {
                    $this->assertContains($mask, $covered, "maska {$mask} ({$entry['cat']}) nemá bezpodmínečný provisioner");
                }
            }
        }
    }

    /**
     * Zálohy na pokladním dokladu (Task E, E3): advance.received/given jsou
     * kontační bez DPH s povinným partnerem (partnerRequired, ne
     * identityRequired), odpočty *.advanceDeduction zůstávají položkové s DPH
     * jako na faktuře (bez rowSide) a předpis cash má pro každý z nich krok
     * na správné straně (odpočet s reverseSign jako v invno/invni).
     */
    public function testCashAdvancesMirrorInvoiceDeductionsAndCarryPartner(): void
    {
        $ops = JsoncParser::parseFile(self::MODULES . '/docs/core/config/rowOperations.jsonc');

        foreach (['advance.received' => 1, 'advance.given' => 2] as $op => $dir) {
            $this->assertSame(0, $ops[$op]['rowSide'], "{$op}: kontační bez DPH");
            $this->assertSame(1, $ops[$op]['rowPartner']);
            $this->assertSame(1, $ops[$op]['rowPaymentId']);
            $this->assertSame(1, $ops[$op]['partnerRequired'], "{$op}: partner povinný");
            $this->assertArrayNotHasKey('identityRequired', $ops[$op], "{$op}: VS nepovinný");
            $this->assertSame(['cash'], array_keys($ops[$op]['docTypes']));
            $this->assertSame($dir, $ops[$op]['docTypes']['cash']['cashDir']);
        }
        foreach (['sale.advanceDeduction' => [1, 'invno'], 'purchase.advanceDeduction' => [2, 'invni']] as $op => [$dir, $invoice]) {
            $this->assertArrayNotHasKey('rowSide', $ops[$op], "{$op}: položkový layout s DPH i na pokladně");
            $this->assertSame($dir, $ops[$op]['docTypes']['cash']['cashDir']);
            $this->assertArrayHasKey($invoice, $ops[$op]['docTypes']);
        }

        $steps = $this->stepsOf('cash');
        $stepFor = function (string $op) use ($steps): array {
            $found = array_values(array_filter($steps, fn($s) => ($s['operation'] ?? null) === $op));
            $this->assertCount(1, $found, "cash: jeden krok pro {$op}");
            return $found[0];
        };

        $received = $stepFor('advance.received');
        $this->assertSame(['advances.received', 1, 1], [$received['cat'], $received['side'], $received['headQuery']['cash_dir']]);
        $this->assertArrayNotHasKey('reverseSign', $received);

        $given = $stepFor('advance.given');
        $this->assertSame(['advances.given', 0, 2], [$given['cat'], $given['side'], $given['headQuery']['cash_dir']]);

        // odpočty: tatáž kategorie, opačná strana a reverseSign jako na faktuře
        foreach (['sale.advanceDeduction' => ['invno', 1], 'purchase.advanceDeduction' => ['invni', 2]] as $op => [$invoice, $dir]) {
            $cash = $stepFor($op);
            $inv = array_values(array_filter($this->stepsOf($invoice), fn($s) => ($s['operation'] ?? null) === $op));
            $this->assertCount(1, $inv);
            $this->assertSame($inv[0]['cat'], $cash['cat'], "{$op}: kategorie jako faktura");
            $this->assertSame($inv[0]['side'], $cash['side'], "{$op}: strana jako faktura");
            $this->assertSame(1, $cash['reverseSign']);
            $this->assertSame($dir, $cash['headQuery']['cash_dir']);
        }
    }

    public function testInvoicesBookCashPaymentOnCashDesk(): void
    {
        foreach (['invno' => 'receivables', 'invni' => 'payables'] as $docType => $balanceCat) {
            $steps = $this->stepsOf($docType);

            $balance = array_values(array_filter($steps, fn($s) => ($s['cat'] ?? null) === $balanceCat && ($s['src'] ?? null) === 'head'));
            $this->assertCount(1, $balance, "{$docType}: jeden saldo krok");
            $this->assertSame(['payment_method' => ['$ne' => 0]], $balance[0]['query'], "{$docType}: saldo jen mimo Hotovost");

            $cashDesk = array_values(array_filter($steps, fn($s) => ($s['accountSrc'] ?? null) === 'cashDesk'));
            $this->assertCount(1, $cashDesk, "{$docType}: jeden krok pokladny");
            $this->assertSame(['payment_method' => 0], $cashDesk[0]['query']);
            $this->assertSame($balance[0]['side'], $cashDesk[0]['side'], 'pokladna na téže straně jako saldo');
        }
    }

    public function testCashAndCashRegisterOperationsMatchRowOperations(): void
    {
        $allowed = $this->allowedOperations();

        foreach (['cash', 'cashreg'] as $docType) {
            $inSteps = [];
            foreach ($this->stepsOf($docType) as $step) {
                foreach (array_merge((array) ($step['operations'] ?? []), isset($step['operation']) ? [$step['operation']] : []) as $op) {
                    $inSteps[$op] = true;
                }
            }
            $this->assertEqualsCanonicalizing(
                $allowed[$docType],
                array_keys($inSteps),
                "{$docType}: pohyby předpisu ≠ pohyby povolené v rowOperations",
            );
        }
    }

    public function testCashRowAndVatStepsCarryHeadQueryDirection(): void
    {
        foreach ($this->stepsOf('cash') as $i => $step) {
            if (in_array($step['src'] ?? null, ['rows', 'vat'], true)) {
                $this->assertContains(
                    $step['headQuery']['cash_dir'] ?? null,
                    [1, 2],
                    "cash krok #{$i} ({$step['src']}) bez headQuery cash_dir",
                );
            } else {
                $dir = $step['headQuery']['cash_dir'] ?? $step['query']['cash_dir'] ?? null;
                $this->assertContains($dir, [1, 2], "cash head krok #{$i} bez směru");
            }
        }

        // protistrana: pro každý směr právě jeden krok pokladny a jeden
        // saldokontní krok za plátcem (příjem pohledávka, výdej závazek — #72)
        foreach ([1, 2] as $dir) {
            $desk = array_filter($this->stepsOf('cash'), fn($s) => ($s['accountSrc'] ?? null) === 'cashDesk' && ($s['query']['cash_dir'] ?? null) === $dir);
            $payer = array_filter($this->stepsOf('cash'), fn($s) => ($s['partnerSrc'] ?? null) === 'balance' && ($s['query']['cash_dir'] ?? null) === $dir);
            $this->assertCount(1, $desk, "cash_dir {$dir}: krok pokladny");
            $this->assertCount(1, $payer, "cash_dir {$dir}: saldokontní krok za plátcem");
            $this->assertSame($dir === 1 ? 0 : 1, array_values($desk)[0]['side'], 'příjem MD, výdej DAL');
            $this->assertSame($dir === 1 ? 'receivables' : 'payables', array_values($payer)[0]['cat']);
        }
    }
}
