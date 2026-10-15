<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\AccountingRules;

/**
 * Strážce předpisu podle varianty osnovy (#122 D4) — programová kontrola
 * nad jsonc, bez DS:
 *
 *   1. efektivní předpis každé varianty (default, npo): každý záznam
 *      `accounts` má v řetězu masek aspoň jednu, která se v seedu osnovy
 *      té varianty dohledá jako analytika (číslo začíná maskou, délka > 3
 *      = level 4 dle AccountDocument::deriveStructure); hlava řetězu
 *      (první maska) musí projít aspoň v jedné variantě — mrtvá hlava
 *      řetězu je chyba předpisu,
 *   2. npo drží tabulku D2 kategorie → masky (změna přepisu je vědomá)
 *      a ostatní nákup (`costs`, purchase.other) jde na 549,
 *   3. npo: žádná maska efektivního předpisu nemíří na 548 / 648 — v NNO
 *      jsou to Manka a škody / Zúčtování fondů, ne „ostatní“,
 *   4. přepis nezná neexistující kategorii (effective() nevyhodí výjimku
 *      nad reálným souborem) a default dostane obecný předpis beze změny.
 *
 * Repair: maska bez účtu → doplň analytiku do seedu osnovy (obě varianty
 * zvlášť), nebo masce dej řetěz s fallbackem na syntetiku (vzor 3249/324);
 * nová kategorie s maskou, která v NNO znamená něco jiného → přepis v
 * `chartOverrides.npo` a řádek v tabulce D2 níže.
 */
class AccountingRulesChartVariantsTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private const VARIANTS = [
        'default' => 'accountChartDefault',
        'npo'     => 'accountChartNpo',
    ];

    /** Tabulka D2 (#122): kategorie → řetěz masek v efektivním předpisu npo. */
    private const NPO_MASKS = [
        'rounding.cost'     => ['549'],
        'rounding.revenue'  => ['649'],
        'vat.nondeductible' => ['549'],
        'bank.fee'          => ['549301', '549'],
        'bank.interest.out' => ['544'],
        'bank.interest.in'  => ['644'],
        'fx.loss'           => ['545'],
        'fx.gain'           => ['645'],
    ];

    /** Syntetiky, na které efektivní předpis npo nesmí mířit. */
    private const NPO_FORBIDDEN_PREFIXES = ['548', '648'];

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return JsoncParser::parseFile(self::MODULES . '/economy/accounting/config/accountingRules.cz.jsonc');
    }

    /** @return list<string> čísla analytických účtů seedu (level 4) */
    private function analytics(string $chart): array
    {
        $numbers = [];
        foreach (JsoncParser::parseFile(self::MODULES . "/economy/accounting/config/{$chart}.jsonc") as $entry) {
            $number = (string) $entry['number'];
            if (strlen($number) > 3) {
                $numbers[] = $number;
            }
        }
        return $numbers;
    }

    /** @param list<string> $analytics */
    private static function resolves(string $mask, array $analytics): bool
    {
        foreach ($analytics as $number) {
            if (str_starts_with($number, $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function masksOf(array $entry): array
    {
        $raw = $entry['accountMask'] ?? [];
        return array_values(array_filter(
            array_map(strval(...), is_array($raw) ? $raw : [$raw]),
            static fn (string $m): bool => $m !== '',
        ));
    }

    /** @param array<string, mixed> $entry */
    private static function describe(array $entry): string
    {
        $query = isset($entry['query']) ? ' ' . json_encode($entry['query'], JSON_UNESCAPED_UNICODE) : '';
        return (string) ($entry['cat'] ?? '?') . $query . ' [' . implode(', ', self::masksOf($entry)) . ']';
    }

    public function testEveryMaskOfEffectiveRulesResolvesInSeedChartOfItsVariant(): void
    {
        $rules = $this->rules();
        $failures = [];
        $chainHeadResolved = [];

        foreach (self::VARIANTS as $variant => $chart) {
            $analytics = $this->analytics($chart);
            $effective = AccountingRules::effective($rules, $variant);
            $this->assertArrayNotHasKey('chartOverrides', $effective, $variant);

            foreach ($effective['accounts'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $masks = self::masksOf($entry);
                $this->assertNotEmpty($masks, "{$variant}: záznam bez masky " . self::describe($entry));

                $resolved = false;
                foreach ($masks as $i => $mask) {
                    if (self::resolves($mask, $analytics)) {
                        $resolved = true;
                        if ($i === 0) {
                            $chainHeadResolved[$masks[0]] = true;
                        }
                        break;
                    }
                }
                $chainHeadResolved[$masks[0]] ??= false;
                if (!$resolved) {
                    $failures[] = "{$variant}: " . self::describe($entry);
                }
            }
        }

        $this->assertSame([], $failures, "Masky bez účtu v seedu osnovy:\n" . implode("\n", $failures));

        $deadHeads = array_keys(array_filter($chainHeadResolved, static fn (bool $ok): bool => !$ok));
        $this->assertSame([], $deadHeads, 'Hlava řetězu masek se nedohledá v žádné osnově: ' . implode(', ', $deadHeads));
    }

    public function testNpoOverridesMatchDecisionTable(): void
    {
        $effective = AccountingRules::effective($this->rules(), 'npo');

        $byCategory = [];
        foreach ($effective['accounts'] as $entry) {
            if (is_array($entry) && isset(self::NPO_MASKS[$entry['cat'] ?? ''])) {
                $byCategory[$entry['cat']][] = $entry;
            }
        }
        foreach (self::NPO_MASKS as $cat => $masks) {
            $this->assertCount(1, $byCategory[$cat] ?? [], "npo: kategorie {$cat} má mít jeden záznam");
            $this->assertSame($masks, self::masksOf($byCategory[$cat][0]), "npo: masky kategorie {$cat} (tabulka D2)");
            $this->assertArrayNotHasKey('query', $byCategory[$cat][0], "npo: {$cat} bez query");
        }

        $otherPurchase = null;
        foreach ($effective['accounts'] as $entry) {
            if (is_array($entry) && ($entry['cat'] ?? null) === 'costs'
                && ($entry['query']['operation'] ?? null) === 'purchase.other') {
                $otherPurchase = $entry;
            }
        }
        $this->assertNotNull($otherPurchase, 'npo: costs purchase.other chybí');
        $this->assertSame(['549'], self::masksOf($otherPurchase), 'npo: ostatní nákup jde na 549 (548 = Manka a škody)');
    }

    public function testNpoEffectiveRulesNeverPointAt548Or648(): void
    {
        $effective = AccountingRules::effective($this->rules(), 'npo');
        $offenders = [];
        foreach ($effective['accounts'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (self::masksOf($entry) as $mask) {
                foreach (self::NPO_FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($mask, $prefix)) {
                        $offenders[] = self::describe($entry);
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'npo: masky na 548 / 648: ' . implode('; ', $offenders));
    }

    public function testOverridesKnowOnlyExistingCategoriesAndDefaultIsUntouched(): void
    {
        $rules = $this->rules();
        $this->assertIsArray($rules['chartOverrides'] ?? null, 'předpis má sekci chartOverrides');
        $this->assertSame(['npo'], array_keys($rules['chartOverrides']));

        $general = $rules;
        unset($general['chartOverrides']);
        $this->assertSame($general, AccountingRules::effective($rules, 'default'));
        $this->assertSame($general, AccountingRules::effective($rules, 'none'));
        $this->assertSame($general, AccountingRules::effective($rules, null));

        // Neznámá kategorie v přepisu by tu vyhodila LogicException.
        $npo = AccountingRules::effective($rules, 'npo');
        $this->assertCount(count($general['accounts']), $npo['accounts'], 'npo: přepis nahrazuje 1:1 (každá kategorie D2 má jeden záznam, costs tři)');
    }
}
