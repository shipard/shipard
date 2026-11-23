<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use PHPUnit\Framework\Attributes\DataProvider;
use Shipard\Tests\Integration\Exchange\RoundTrip\FixtureUnavailable;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripEnvironment;
use Shipard\Tests\Integration\Exchange\RoundTrip\RoundTripFixtures as F;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocument;
use Shipard\Tests\Integration\Exchange\RoundTrip\SavedDocumentComparator;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Invariant „minimální pokyn = doklad z formuláře bez zásahu uživatele“
 * (#166 D40, U10, tasks/doc-defaults-shared.md).
 *
 * **Formulář:** nový doklad se otevře (výchozí hodnoty sloupců, prefill
 * z vieweru a hook formuláře), uživatel vybere jen partnera a uloží; na
 * novém řádku vybere jen položku (u účetního dokladu účet, stranu a částku)
 * a uloží; doklad potvrdí. Cesta jde přes skutečný `FormController`
 * ({@see \Shipard\Tests\Integration\Exchange\RoundTrip\FormPath}).
 *
 * **Pokyn:** `{docType, partner, rows: [{item}]}` — jen to, co uživatel ve
 * formuláři sám zadal — s cílovým stavem V pořádku.
 *
 * Oba doklady se musí shodnout ve všech porovnávaných sloupcích hlavičky,
 * řádků i rekapitulace ({@see SavedDocumentComparator}) — kromě
 * variabilního symbolu, který se odvozuje z čísla dokladu. Rozdíl znamená, že
 * se výchozí hodnoty formuláře a pokynu rozešly — obě cesty je mají brát ze
 * společných tříd `docs.core` (`Defaults\DocHeadDefaults`, `DocRowDefaults`).
 *
 * Test po sobě uklízí (doklady partnerů fixtur, čítače řad) a běží jen nad
 * zdrojem dat s třídou `free`.
 */
class DocumentFormInstructionInvariantTest extends IntegrationTestCase
{
    use RoundTripEnvironment;

    private const ENVELOPE = ['format' => 'shpd.docs.document', 'formatVersion' => '1.0'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRoundTripEnvironment();
    }

    protected function onTearDown(): void
    {
        $this->shutdownRoundTripEnvironment();
    }

    /** @return iterable<string, array{0: string}> */
    public static function docTypes(): iterable
    {
        yield 'faktura vydaná' => ['invno'];
        yield 'faktura přijatá' => ['invni'];
        yield 'pokladní doklad' => ['cash'];
        yield 'účetní doklad' => ['cmnbkp'];
    }

    #[DataProvider('docTypes')]
    public function testMinimalInstructionEqualsUntouchedForm(string $docType): void
    {
        try {
            [$formId, $instruction] = match ($docType) {
                'invno'  => $this->invoice('invno', 'invoiceIssued', 'customer'),
                'invni'  => $this->invoice('invni', 'invoiceReceived', 'supplier'),
                'cash'   => $this->cashDocument(),
                'cmnbkp' => $this->accountingDocument(),
            };
        } catch (FixtureUnavailable $e) {
            $this->markTestSkipped("{$docType}: {$e->getMessage()}");
        }

        $result = $this->applier->apply($instruction);
        $this->assertTrue(
            $result->success,
            "Pokyn selhal: {$result->errorCode} — {$result->errorMessage}\n"
                . json_encode($result->canonical['_resolve']['issues'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $form = SavedDocument::load($this->db, $formId);
        $instructed = SavedDocument::load($this->db, (int) $result->savedId);
        $this->assertSame(40, (int) $form->head['docState'], 'doklad z formuláře je V pořádku');

        $diff = (new SavedDocumentComparator($this->tables, $this->fixtures->referenceLabel(...)))->compare($form, $instructed);
        // Variabilní symbol se odvozuje z čísla dokladu a to má každý doklad
        // své (číslo samo porovnání vynechává, viz RoundTripColumns).
        unset($diff['head.payment_reference']);
        $this->assertSame(
            [],
            $diff,
            "{$docType}: minimální pokyn dal jiný doklad než formulář bez zásahu uživatele (formulář → pokyn):\n"
                . json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
        // Odpověď říká, co pokyn doplnil sám (D44).
        $this->assertNotSame([], $result->canonical['_resolve']['defaults'] ?? []);
    }

    /**
     * Faktura: partner + jeden řádek s položkou.
     *
     * @return array{0: int, 1: array<string, mixed>} id dokladu z formuláře, pokyn
     */
    private function invoice(string $docType, string $canonicalType, string $partnerSide): array
    {
        $f = $this->fixtures;
        $partner = $f->person($partnerSide);
        $item = $f->pricedItem();

        $head = $this->formPath->open('docs_core_heads', ['doc_type' => $docType, 'number_series' => $f->seriesId($docType)]);
        $head = $this->formPath->change('docs_core_heads', $head, 'partner', $partner);
        $id = $this->formPath->save('docs_core_heads', $head);
        $this->formRow($id, $item);
        $this->formPath->transition('docs_core_heads', $id, 40);

        return [$id, self::ENVELOPE + [
            'docType'      => $canonicalType,
            $partnerSide   => '@' . $f->personNumber($partner),
            'rows'         => [['item' => '@' . $f->itemCode($item)]],
            'applyOptions' => ['targetDocState' => 40],
        ]];
    }

    /**
     * Pokladní doklad — příjem: pokladnu (řadu) dává záložka vieweru, směr
     * vybírá uživatel; obojí proto nese i pokyn.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function cashDocument(): array
    {
        $f = $this->fixtures;
        $desk = $f->cashDeskId();
        $partner = $f->person('customer');
        $item = $f->pricedItem();

        $head = $this->formPath->open('docs_core_heads', ['doc_type' => 'cash', 'number_series' => $f->seriesId('cash', $desk)]);
        $head = $this->formPath->change('docs_core_heads', $head, 'cash_dir', 1);
        $head = $this->formPath->change('docs_core_heads', $head, 'partner', $partner);
        $id = $this->formPath->save('docs_core_heads', $head);
        $this->formRow($id, $item);
        $this->formPath->transition('docs_core_heads', $id, 40);

        $deskCode = (string) $this->db->fetchSingle('SELECT [code] FROM [economy_codebooks_cash_desks] WHERE [id] = %i', $desk);
        return [$id, self::ENVELOPE + [
            'docType'       => 'cashDocument',
            'cashDesk'      => '@' . $deskCode,
            'cashDirection' => 1,
            'partner'       => '@' . $f->personNumber($partner),
            'rows'          => [['item' => '@' . $f->itemCode($item)]],
            'applyOptions'  => ['targetDocState' => 40],
        ]];
    }

    /**
     * Účetní doklad: řádky jsou kontace — uživatel zadá účet, stranu
     * a částku; pohyb doplní formulář i pokyn (položkový obsah kontace nemá).
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function accountingDocument(): array
    {
        $f = $this->fixtures;
        $partner = $f->person('customer');
        $debit = $f->accountId('518');
        $credit = $f->accountId('321');

        $head = $this->formPath->open('docs_core_heads', ['doc_type' => 'cmnbkp', 'number_series' => $f->seriesId('cmnbkp')]);
        $head = $this->formPath->change('docs_core_heads', $head, 'partner', $partner);
        $id = $this->formPath->save('docs_core_heads', $head);
        foreach ([[$debit, 0], [$credit, 1]] as [$account, $side]) {
            $row = $this->formPath->open('docs_core_rows', ['doc_head' => $id]);
            $row = $this->formPath->change('docs_core_rows', $row, 'account', $account);
            $row = $this->formPath->change('docs_core_rows', $row, 'acc_side', $side);
            $row = $this->formPath->change('docs_core_rows', $row, 'total_price', 1000.0);
            $this->formPath->save('docs_core_rows', $row);
        }
        $this->formPath->transition('docs_core_heads', $id, 40);

        $number = fn (int $accountId): string => '@' . $this->db->fetchSingle(
            'SELECT [number] FROM [economy_accounting_accounts] WHERE [id] = %i',
            $accountId,
        );
        return [$id, self::ENVELOPE + [
            'docType'      => 'accountingDocument',
            'partner'      => '@' . $f->personNumber($partner),
            'rows'         => [
                ['account' => $number($debit), 'accSide' => 'debit', 'totalPrice' => 1000.0],
                ['account' => $number($credit), 'accSide' => 'credit', 'totalPrice' => 1000.0],
            ],
            'applyOptions' => ['targetDocState' => 40],
        ]];
    }

    /** Nový řádek: formulář se otevře, uživatel vybere položku a uloží. */
    private function formRow(int $headId, int $item): void
    {
        $row = $this->formPath->open('docs_core_rows', ['doc_head' => $headId]);
        $row = $this->formPath->change('docs_core_rows', $row, 'item', $item);
        $this->formPath->save('docs_core_rows', $row);
    }
}
