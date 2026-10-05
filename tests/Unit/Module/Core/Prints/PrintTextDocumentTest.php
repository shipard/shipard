<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Prints\PrintTextDocument;

/**
 * Validace textu na tiscích při uložení (#90 D47): text se zkompiluje
 * v sandboxu, cílení musí dávat smysl pro vybrané tisky.
 */
class PrintTextDocumentTest extends TestCase
{
    use PrintTextConfigFixture;

    /** @param list<int> $existingSeries id řad, které „jsou v databázi“ */
    private function document(bool $withConfig = true, array $existingSeries = [3, 7]): PrintTextDocument
    {
        $db = $this->createStub(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturn(array_map(static fn (int $id): array => ['id' => $id], $existingSeries));

        $doc = new PrintTextDocument();
        $doc->setDb($db);
        if ($withConfig) {
            $doc->setConfig($this->config());
        }
        return $doc;
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function data(array $override = []): array
    {
        return $override + [
            'name' => 'Dovolená',
            'slot' => 'footer',
            'text' => 'Příští týden máme **dovolenou**.',
        ];
    }

    /** @return array<string, list<string>> sloupec → kódy chyb */
    private static function errors(ValidationResult $result): array
    {
        $errors = [];
        foreach ($result->getErrors() as $error) {
            $errors[$error->column][] = $error->code;
        }
        return $errors;
    }

    public function testMinimalTextIsValid(): void
    {
        $data = self::data();

        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testFullyTargetedTextIsValid(): void
    {
        $data = self::data([
            'prints'        => ['docs.invoicesOut.invoice'],
            'doc_types'     => ['invno'],
            'number_series' => [3, '7'],
            'language'      => 'de',
            'valid_from'    => '2026-10-05',
            'valid_to'      => '2026-10-05',
            'text'          => 'Doklad {{ data.document.number }} je splatný {{ data.dates.due|date }}.',
        ]);

        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testRequiredFields(): void
    {
        $data = ['name' => '  ', 'slot' => '', 'text' => " \n "];

        $this->assertSame(
            ['name' => ['required'], 'slot' => ['required'], 'text' => ['required']],
            self::errors($this->document()->validate($data)),
        );
    }

    public function testUnknownSlotIsRejected(): void
    {
        $data = self::data(['slot' => 'sidebar']);

        $this->assertSame(['slot' => ['required']], self::errors($this->document()->validate($data)));
    }

    /** @return array<string, array{string, string}> */
    public static function unusableTexts(): array
    {
        return [
            'zakázaný tag'        => ['{% for row in data.rows %}{{ row.text }}{% endfor %}', 'Tag "for" is not allowed'],
            'zakázaný filtr'      => ['{{ data.document.number|raw }}', 'Filter "raw" is not allowed'],
            'zakázaná funkce'     => ["{{ include('@docs.core/_layout/header.html.twig') }}", 'Function "include" is not allowed'],
            'neuzavřená proměnná' => ['Doklad {{ data.document.number', 'řádek 1'],
        ];
    }

    #[DataProvider('unusableTexts')]
    public function testTextMustCompileInUserTextSandbox(string $text, string $messagePart): void
    {
        $data   = self::data(['text' => $text]);
        $result = $this->document()->validate($data);

        $this->assertSame(['text' => ['invalid_template']], self::errors($result));
        $message = $result->getErrors()[0]->message;
        $this->assertStringStartsWith('Text nejde použít: ', $message);
        $this->assertStringContainsString($messagePart, $message);
    }

    public function testValidityMustNotEndBeforeItStarts(): void
    {
        $data = self::data(['valid_from' => '2026-10-06', 'valid_to' => '2026-10-05']);
        $this->assertSame(['valid_to' => ['invalid_range']], self::errors($this->document()->validate($data)));

        // Jeden kraj stačí; platnost na jediný den je v pořádku.
        foreach ([['valid_from' => '2026-10-06'], ['valid_to' => '2026-10-05'], ['valid_from' => '2026-10-05', 'valid_to' => '2026-10-05']] as $range) {
            $data = self::data($range);
            $this->assertSame([], self::errors($this->document()->validate($data)));
        }
    }

    public function testLanguageMustBeAPrintLanguage(): void
    {
        $data = self::data(['language' => 'pl']);
        $this->assertSame(['language' => ['invalid']], self::errors($this->document()->validate($data)));

        $data = self::data(['language' => '']);
        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testPrintsMustExistAndSupportTheSlot(): void
    {
        $data   = self::data(['slot' => 'emailBody', 'prints' => ['docs.cashDocs.cash', 'docs.none.print']]);
        $result = $this->document()->validate($data);

        $this->assertSame(['prints' => ['invalid', 'invalid']], self::errors($result));
        $messages = array_map(static fn ($e): string => $e->message, $result->getErrors());
        $this->assertSame([
            'Tisk „Pokladní doklad“ toto umístění textu nepodporuje',
            'Neznámý tisk „docs.none.print“',
        ], $messages);
    }

    public function testDocTypeAndSeriesAreAllowedOnlyForDocumentPrints(): void
    {
        $data = self::data(['prints' => ['economy.assets.card'], 'doc_types' => ['invno'], 'number_series' => [3]]);

        $this->assertSame(
            ['doc_types' => ['not_applicable'], 'number_series' => ['not_applicable']],
            self::errors($this->document()->validate($data)),
        );
    }

    public function testDocTypeMustBePrintedBySelectedPrints(): void
    {
        // Faktura tiskne jen `invno` — text omezený na pokladní doklad by nikdy neplatil.
        $data = self::data(['prints' => ['docs.invoicesOut.invoice'], 'doc_types' => ['invno', 'cash']]);

        $this->assertSame(['doc_types' => ['invalid']], self::errors($this->document()->validate($data)));
    }

    public function testNumberSeriesMustExist(): void
    {
        $data   = self::data(['number_series' => [3, 99]]);
        $result = $this->document(existingSeries: [3])->validate($data);

        $this->assertSame(['number_series' => ['invalid']], self::errors($result));
        $this->assertSame('Číselná řada 99 neexistuje', $result->getErrors()[0]->message);

        $data = self::data(['number_series' => ['abc']]);
        $this->assertSame(['number_series' => ['invalid']], self::errors($this->document()->validate($data)));
    }

    public function testListColumnsMustBeLists(): void
    {
        $data = self::data(['prints' => 'docs.invoicesOut.invoice', 'doc_types' => ['a' => 'invno']]);

        $this->assertSame(
            ['prints' => ['invalid'], 'doc_types' => ['invalid']],
            self::errors($this->document()->validate($data)),
        );
    }

    public function testStoredJsonListsAreValidatedLikeArrays(): void
    {
        $data = self::data(['prints' => '["docs.invoicesOut.invoice"]', 'doc_types' => '["invno"]', 'number_series' => null]);

        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testWithoutCompiledPrintsTargetingIsNotChecked(): void
    {
        // Unit test / zdroj bez přeložené konfigurace: text se ověří, tisky ne.
        $data = self::data(['prints' => ['whatever'], 'doc_types' => ['x']]);
        $this->assertSame([], self::errors($this->document(withConfig: false)->validate($data)));

        $data = self::data(['text' => '{{ data.x|raw }}']);
        $this->assertSame(['text' => ['invalid_template']], self::errors($this->document(withConfig: false)->validate($data)));
    }

    public function testBeforeSaveEncodesListsAndNormalizesEmptyValues(): void
    {
        $data = self::data([
            'name'          => '  Dovolená  ',
            'prints'        => ['docs.invoicesOut.invoice', 'docs.cashDocs.cash'],
            'doc_types'     => [],
            'number_series' => ['7', 3],
            'language'      => '',
            'order_pos'     => '',
        ]);

        $this->document()->beforeSave($data);

        $this->assertSame('Dovolená', $data['name']);
        $this->assertSame('["docs.invoicesOut.invoice","docs.cashDocs.cash"]', $data['prints']);
        // Prázdný výběr = bez omezení.
        $this->assertNull($data['doc_types']);
        $this->assertSame('[7,3]', $data['number_series']);
        $this->assertNull($data['language']);
        $this->assertSame(0, $data['order_pos']);
    }

    public function testBeforeSaveLeavesUntouchedColumnsAlone(): void
    {
        $data = ['id' => 5, 'docState' => 40];

        $this->document()->beforeSave($data, self::data());

        $this->assertSame(['id' => 5, 'docState' => 40], $data);
    }
}
