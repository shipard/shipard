<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use PHPUnit\Framework\Attributes\DataProvider;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Tests\Integration\Exchange\RoundTrip\FixtureUnavailable;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripCase;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripCases;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripEnvironment;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripFixtures;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocument;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocumentComparator;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Pojistka před přestavbou ukládání dokladů (#166 etapa 1,
 * tasks/doc-saving-safety-net.md): invariant „co uloží `DocDocument`, to
 * výměnný formát přenese beze změny“.
 *
 * Jeden průchod (U2): doklad A přes `TableGateway` (cesta formuláře) →
 * načtení z databáze → export (`DocumentExporter`) → v exportu jen jiné
 * číslo dokladu se `sequenceNumber: null` → `DocumentApplier::apply` →
 * doklad B → porovnání všech sloupců hlavičky, řádků a rekapitulace
 * podle definic tabulek ({@see SavedDocumentComparator}).
 *
 * Případy matice ({@see RoundTripCases}) mají očekávání `holds`, nebo `gap`
 * = známá mezera s přesným rozdílem. Jak číst selhání:
 *   - „mezera `<id>` je zavřená — přepni na `holds`“: rozdíl zmizel, případ
 *     v {@see RoundTripCases} přepni a mezeru odškrtni v tasku;
 *   - „mezera `<id>` se změnila“: rozdíl je jiný než zapsaný — výpis nese
 *     obě verze; buď regrese, nebo záměrná změna (pak přepiš očekávání);
 *   - selhání případu `holds`: výměnný formát přestal doklad přenášet.
 *
 * Applier ani export se kvůli selhání neopravují tady (U6) — nález se
 * zapíše jako mezera a zavírá ho etapa 3 nebo 5.
 *
 * Test po sobě uklízí podle prefixu `IT-RT` (i zbytky spadlého běhu)
 * a vrací čítače řad; běží jen nad zdrojem dat s třídou `free`.
 */
class DocumentRoundTripMatrixTest extends IntegrationTestCase
{
    use RoundTripEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRoundTripEnvironment();
    }

    protected function onTearDown(): void
    {
        $this->shutdownRoundTripEnvironment();
    }

    /**
     * @return iterable<string, array{0: RoundTripCase}>
     */
    public static function cases(): iterable
    {
        foreach (RoundTripCases::all() as $case) {
            yield $case->id => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testSavedDocumentSurvivesExportAndApply(RoundTripCase $case): void
    {
        try {
            $data = ($case->build)($this->fixtures);
        } catch (FixtureUnavailable $e) {
            $this->markTestSkipped("{$case->id}: {$e->getMessage()}");
        }

        // 1–2. Doklad A cestou formuláře a jeho stav v databázi.
        $idA = $this->fixtures->saveDocument($data, $case->docState);
        $a = SavedDocument::load($this->db, $idA);

        // 3–4. Export; mění se jen číslo, které by kolidovalo.
        $canonical = $this->export($idA);
        if (isset($canonical['applyOptions']['importNumber'])) {
            $canonical['applyOptions']['importNumber'] = [
                'docNumber'      => RoundTripFixtures::PREFIX . '-B-' . $idA,
                'sequenceNumber' => null,
            ];
        }

        // 5–6. Apply → doklad B → porovnání.
        $result = $this->applier->apply($canonical);
        $actual = $result->success
            ? ['diff' => $this->comparator()->compare($a, SavedDocument::load($this->db, (int) $result->savedId))]
            : ['applyError' => self::applyError($result)];

        if ($case->expectation === RoundTripCase::HOLDS) {
            $this->assertSame(
                ['diff' => []],
                $actual,
                "{$case->id} ({$case->description}): doklad se po exportu a apply liší.\n" . self::dump($actual),
            );
            return;
        }

        $this->assertNotSame(
            ['diff' => []],
            $actual,
            "mezera `{$case->id}` je zavřená — přepni na `holds`",
        );
        // Přísně (null ≠ '' ≠ 0), jen bez ohledu na pořadí klíčů.
        $this->assertSame(
            self::sorted((array) $case->gap),
            self::sorted($actual),
            "mezera `{$case->id}` se změnila.\nZapsáno:\n" . self::dump((array) $case->gap) . "\nSkutečnost:\n" . self::dump($actual),
        );
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private static function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    private function comparator(): SavedDocumentComparator
    {
        return new SavedDocumentComparator($this->tables, $this->fixtures->referenceLabel(...));
    }

    /**
     * Chyba apply v tvaru známé mezery: kód výsledku a seřazené kódy chyb
     * z `_resolve.issues` (bez varování a informací).
     *
     * @return array{code: ?string, issues: list<string>, message?: string}
     */
    private static function applyError(ApplyResult $result): array
    {
        $codes = [];
        foreach ((array) ($result->canonical['_resolve']['issues'] ?? []) as $issue) {
            if (is_array($issue) && ($issue['severity'] ?? null) === 'error') {
                $codes[] = (string) ($issue['code'] ?? '');
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);
        $error = ['code' => $result->errorCode, 'issues' => $codes];
        if ($codes === [] && $result->errorMessage !== null) {
            // Bez kódu chyby nese příčinu jen zpráva (např. pád uložení).
            $error['message'] = $result->errorMessage;
        }
        return $error;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function dump(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
