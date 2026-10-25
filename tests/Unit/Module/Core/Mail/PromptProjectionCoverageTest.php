<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Compare\Projection;

/**
 * Ukázka dokladu v `prompt_template` profilu `czech_general` pokrývá celou
 * papírovou projekci korpusu (`tests/corpus/projection.jsonc`, #103 D107).
 * Prompt zakazuje klíče mimo ukázku, takže pole, které v ukázce chybí, model
 * systematicky vynechává, i když je na dokladu vidí (web dodavatele a období
 * plnění v evaluacích 2026-10-23). Cesty projekce dává `Projection::nodePaths()`
 * — žádný druhý parser. Strana `customer` sdílí tvar se `supplier` a v ukázce
 * je `null`, kontroluje se proto přes `supplier`. Výjimky jen výslovně
 * v EXCEPTIONS, každá s důvodem.
 *
 * Oprava: doplň pole do ukázky dokladu za větou „VRAŤ POUZE JSON v této
 * struktuře“ (a bumpni `prompt_version`), nebo zapiš výjimku s důvodem.
 */
final class PromptProjectionCoverageTest extends TestCase
{
    private const MARKER = 'VRAŤ POUZE JSON v této struktuře';

    /** Uzly projekce, které ukázka záměrně nemá — platí i pro potomky uzlu. */
    private const EXCEPTIONS = [
        '_resolve' => 'štítek (contentTag) a výjimky řádků doplňuje server při zpracování návrhu — model je nevrací, ukázka ho záměrně nemá (docs/test-corpus.md § 8)',
    ];

    private static function profilePath(): string
    {
        return dirname(__DIR__, 5) . '/modules/core/mail/profiles/czech_general.jsonc';
    }

    private static function projectionPath(): string
    {
        return dirname(__DIR__, 5) . '/tests/corpus/projection.jsonc';
    }

    /**
     * Ukázka `document.extracted_json` z šablony: první JSON objekt za
     * značkou. Chybějící značka nebo neparsovatelná ukázka = selhání
     * se zprávou, nikdy přeskočení.
     *
     * @return array<string, mixed>
     */
    public static function extractSample(string $prompt): array
    {
        $at = strpos($prompt, self::MARKER);
        if ($at === false) {
            self::fail('Šablona nemá značku „' . self::MARKER . '“ — ukázku dokladu nejde najít.');
        }
        $start = strpos($prompt, '{', $at);
        $end = $start === false ? null : self::closingBrace($prompt, $start);
        if ($start === false || $end === null) {
            self::fail('Za značkou „' . self::MARKER . '“ není uzavřený JSON objekt.');
        }
        try {
            $sample = json_decode(substr($prompt, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail('Ukázka za značkou „' . self::MARKER . '“ není platný JSON: ' . $e->getMessage());
        }
        $doc = is_array($sample) ? ($sample['document']['extracted_json'] ?? null) : null;
        if (!is_array($doc)) {
            self::fail('Ukázka za značkou „' . self::MARKER . '“ nemá document.extracted_json.');
        }
        return $doc;
    }

    /** Pozice `}` uzavírající objekt otevřený na `$start`; závorky v řetězcích se nepočítají. */
    private static function closingBrace(string $text, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Cesty klíčů ukázky (`rows[].item.ean`), sjednocené přes prvky polí —
     * klíč stačí v kterémkoli řádku.
     *
     * @param array<string, mixed> $doc
     * @return array<string, true>
     */
    private static function samplePaths(array $doc): array
    {
        $out = [];
        $walk = static function (mixed $value, string $path) use (&$walk, &$out): void {
            if ($path !== '') {
                $out[$path] = true;
            }
            if (!is_array($value)) {
                return;
            }
            if ($value !== [] && array_is_list($value)) {
                foreach ($value as $item) {
                    $walk($item, $path . '[]');
                }
                return;
            }
            foreach ($value as $key => $sub) {
                $walk($sub, $path === '' ? (string) $key : "{$path}.{$key}");
            }
        };
        $walk($doc, '');
        return $out;
    }

    private static function isExcepted(string $path): bool
    {
        foreach (array_keys(self::EXCEPTIONS) as $node) {
            if ($path === $node || str_starts_with($path, $node . '.') || str_starts_with($path, $node . '[')) {
                return true;
            }
        }
        return false;
    }

    public function testSampleCoversEveryProjectionPath(): void
    {
        $profile = JsoncParser::parseFile(self::profilePath());
        $sample = self::extractSample((string) $profile['prompt_template']);
        $present = self::samplePaths($sample);
        // Odběratel je v ukázce null (účtenka ho nemá) — strana sdílí tvar
        // Party se supplier, cesty customer.* se tedy hledají u supplier.*.
        $customerShown = is_array($sample['customer'] ?? null);

        $missing = [];
        foreach (Projection::load(self::projectionPath())->nodePaths() as $path) {
            if (self::isExcepted($path)) {
                continue;
            }
            $probe = !$customerShown && str_starts_with($path, 'customer.')
                ? 'supplier.' . substr($path, strlen('customer.'))
                : $path;
            if (!isset($present[$probe])) {
                $missing[] = $path;
            }
        }
        $this->assertSame(
            [],
            $missing,
            "Ukázka dokladu v prompt_template nepokrývá cesty papírové projekce — doplň je do ukázky, "
                . "nebo výjimku s důvodem do EXCEPTIONS:\n  " . implode("\n  ", $missing),
        );
    }

    public function testExceptionsExistInProjectionWithAReason(): void
    {
        $paths = Projection::load(self::projectionPath())->nodePaths();
        foreach (self::EXCEPTIONS as $node => $reason) {
            $this->assertContains($node, $paths, "{$node} v projekci není — výjimka je zastaralá");
            $this->assertNotSame('', trim($reason), "výjimka {$node} bez důvodu");
        }
    }

    public function testSampleTotalsAgreeWithRows(): void
    {
        // Ukázka je zároveň vzor formátu: řádky, rekapitulace a součty musí
        // dál sedět (tasks/prompt-projection-coverage.md → Pasti).
        $profile = JsoncParser::parseFile(self::profilePath());
        $sample = self::extractSample((string) $profile['prompt_template']);

        $rowsTotal = round(array_sum(array_column($sample['rows'], 'totalPrice')), 2);
        $recapBase = round(array_sum(array_column($sample['vatRecap'], 'base')), 2);
        $recapTax = round(array_sum(array_column($sample['vatRecap'], 'tax')), 2);
        $totals = $sample['totals'];

        $this->assertSame('fromBase', $sample['vat']['mode']);
        $this->assertEqualsWithDelta($rowsTotal, $recapBase, 0.001, 'součet řádků = základ rekapitulace');
        $this->assertEqualsWithDelta($recapBase, $totals['totalBase'], 0.001);
        $this->assertEqualsWithDelta($recapTax, $totals['totalVat'], 0.001);
        $this->assertEqualsWithDelta(
            $totals['totalBase'] + $totals['totalVat'] + $totals['totalRounding'],
            $totals['totalAmount'],
            0.001,
        );
    }

    public function testSampleRowsFromUnitPriceAreArithmeticallyConsistent(): void
    {
        // Ukázka je vzor i pro aritmetiku řádku: u priceCalcMode fromUnitPrice
        // musí quantity × unitPrice zaokrouhlené na haléře dát totalPrice
        // (tasks/prompt-field-rules.md § 2). Řádek 1 měl do v4.9.0
        // 10 × 1 033,06 = 10 330,60, ale totalPrice 10 330,58 — řetězec
        // řádky → rekapitulace → součty seděl, jednotková cena ne.
        $profile = JsoncParser::parseFile(self::profilePath());
        $sample = self::extractSample((string) $profile['prompt_template']);

        $checked = 0;
        foreach ($sample['rows'] as $i => $row) {
            if (($row['priceCalcMode'] ?? null) !== 'fromUnitPrice') {
                continue;
            }
            $checked++;
            $expected = round((float) $row['quantity'] * (float) $row['unitPrice'], 2);
            $this->assertEqualsWithDelta(
                $expected,
                (float) $row['totalPrice'],
                0.001,
                sprintf(
                    'řádek %d (orderPos %s): %s × %s = %.2f, ale totalPrice je %s',
                    $i + 1,
                    $row['orderPos'] ?? '?',
                    $row['quantity'],
                    $row['unitPrice'],
                    $expected,
                    $row['totalPrice'],
                ),
            );
        }
        $this->assertGreaterThan(0, $checked, 'ukázka nemá žádný řádek s priceCalcMode fromUnitPrice');
    }

    public function testExtractorFailsWithoutMarker(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('nemá značku');
        self::extractSample('Nyní analyzuj zprávu a vrať JSON.');
    }

    public function testExtractorFailsOnInvalidSample(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('není platný JSON');
        self::extractSample(self::MARKER . ":\n\n{\"document\": {\"extracted_json\": {\"a\": }}}\n");
    }

    public function testExtractorFailsWithoutExtractedJson(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('nemá document.extracted_json');
        self::extractSample(self::MARKER . ":\n\n{\"document\": null}\n");
    }

    public function testExtractorReadsFirstObjectAndIgnoresBracesInStrings(): void
    {
        $doc = self::extractSample(
            self::MARKER . " (žádný úvodní text):\n\n"
            . "{\"document\": {\"extracted_json\": {\"docText\": \"a } b { \\\" c\", \"docNumber\": \"<supplier's number>\"}}}\n\n"
            . "Ukázka registry dokumentu:\n\n{\"doc_type\": \"insurance\"}",
        );
        $this->assertSame(['docText' => 'a } b { " c', 'docNumber' => "<supplier's number>"], $doc);
    }
}
