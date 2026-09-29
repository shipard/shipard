<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\SupplierCodeCaptureHandler;

/**
 * Testable handler — Connection::query je final, executeSql se přepisuje
 * subclassingem (vzor TestableDocumentApplier).
 */
class TestableSupplierCodeCaptureHandler extends SupplierCodeCaptureHandler
{
    /** @var list<array> */
    public array $sqlCalls = [];

    protected function executeSql(mixed ...$args): void
    {
        $this->sqlCalls[] = $args;
    }
}

class SupplierCodeCaptureHandlerTest extends TestCase
{
    /**
     * Canonical návrhu žije na řádku poslední úspěšné analýzy zprávy
     * (message-centric model) — mock routuje fetch podle tabulky:
     * docs_core_heads → hlavička, core_mail_message_analyses → canonical.
     *
     * @param array<string, mixed>|null $head
     * @param array<string, mixed>|null $canonical
     * @param list<array<string, mixed>> $finalRows
     */
    private function handler(?array $head, ?array $canonical, array $finalRows): TestableSupplierCodeCaptureHandler
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql) use ($head, $canonical): ?Row {
                if (str_contains($sql, 'docs_core_heads')) {
                    return $head !== null ? new Row($head) : null;
                }
                return $canonical !== null
                    ? new Row(['canonical_json' => json_encode($canonical)])
                    : null;
            },
        );
        $db->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new Row($row),
            $finalRows,
        ));

        $handler = new TestableSupplierCodeCaptureHandler();
        $handler->setDb($db);
        return $handler;
    }

    /**
     * @return array<string, mixed>
     */
    private function aiHead(): array
    {
        return ['partner' => 42, 'source_kind' => 'aiExtraction', 'source_message' => 678];
    }

    public function testConfirmWithLineageCapturesSupplierCodes(): void
    {
        // Finální řádek nese text složený applierem (CanonicalRowText, #84):
        // guard skládá canonical text tímtéž helperem, proto sedí.
        $handler = $this->handler(
            $this->aiHead(),
            ['rows' => [
                ['item' => ['supplierCode' => 'KONZ-001', 'name' => 'Konzultace', 'description' => 'Hodinová sazba']],
                ['item' => ['name' => 'Doprava']], // bez supplierCode → no-op
            ]],
            [
                ['order_pos' => 1, 'item' => 18, 'description' => 'Konzultace — Hodinová sazba'],
                ['order_pos' => 2, 'item' => 19, 'description' => 'Doprava'],
            ],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);

        $this->assertCount(1, $handler->sqlCalls);
        [$sql, $person, $item, $code, $name] = $handler->sqlCalls[0];
        $this->assertStringContainsString('INSERT IGNORE', $sql); // idempotence přes unique index
        $this->assertStringContainsString('economy_items_supplier_codes', $sql);
        $this->assertSame(42, $person);
        $this->assertSame(18, $item);
        $this->assertSame('KONZ-001', $code);
        $this->assertSame('Konzultace', $name);
    }

    public function testNoOpForNonAiExtractionDocument(): void
    {
        $handler = $this->handler(
            ['partner' => 42, 'source_kind' => 'manual', 'source_message' => 0],
            ['rows' => [['item' => ['supplierCode' => 'X-1', 'name' => 'X']]]],
            [['order_pos' => 1, 'item' => 18, 'description' => 'X']],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);
    }

    public function testNoOpForOtherTransitions(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');
        $handler = new TestableSupplierCodeCaptureHandler();
        $handler->setDb($db);

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 80, 40);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 90);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 0, 40);
        $this->assertSame([], $handler->sqlCalls);
    }

    public function testSkipsRowWithoutMatchingFinalRow(): void
    {
        // Finální řádek s order_pos 1 nemá položku (není ve fetchAll výsledku,
        // ten filtruje item IS NOT NULL) → žádný zápis.
        $handler = $this->handler(
            $this->aiHead(),
            ['rows' => [['item' => ['supplierCode' => 'KONZ-001', 'name' => 'Konzultace']]]],
            [],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);
    }

    public function testSkipsOnDescriptionMismatch(): void
    {
        // Uživatel řádky v Konceptu přeuspořádal/přepsal → poziční match už
        // neplatí, guard na popis zápis zablokuje.
        $handler = $this->handler(
            $this->aiHead(),
            ['rows' => [['item' => ['supplierCode' => 'KONZ-001', 'name' => 'Konzultace', 'description' => 'Hodinová sazba']]]],
            [['order_pos' => 1, 'item' => 18, 'description' => 'Úplně jiný řádek']],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);
    }

    public function testSkipsWhenFinalRowCarriesOnlyItemDescription(): void
    {
        // Past z tasku #84: kdyby applier text skládal a guard ne (nebo
        // naopak), texty by si nikdy neodpovídaly a kódy by se tiše
        // přestaly učit. Finální text = samotný item.description (stará
        // skladba) → dnes mismatch → přeskočit.
        $handler = $this->handler(
            $this->aiHead(),
            ['rows' => [['item' => ['supplierCode' => 'KONZ-001', 'name' => 'Konzultace', 'description' => 'Hodinová sazba']]]],
            [['order_pos' => 1, 'item' => 18, 'description' => 'Hodinová sazba']],
        );

        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);
    }

    public function testMissingHeadOrAnalysisIsNoOp(): void
    {
        // Chybějící hlavička dokladu.
        $handler = $this->handler(null, null, []);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);

        // Hlavička s lineage, ale zpráva bez úspěšné analýzy (canonical chybí).
        $handler = $this->handler($this->aiHead(), null, []);
        $handler->onStateChanged('docs_core_heads', ['id' => 555], 10, 40);
        $this->assertSame([], $handler->sqlCalls);
    }
}
