<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Prints\Texts\PrintTextSlot;
use Shipard\Module\Core\Prints\PrintTextChoices;
use Shipard\Module\Core\Prints\PrintTextTargeting;

class PrintTextChoicesTest extends TestCase
{
    use PrintTextConfigFixture;

    public function testSlotsAreNamedAndOrderedForTheForm(): void
    {
        $slots = (new PrintTextChoices($this->config()))->slots();

        $this->assertSame(PrintTextSlot::ids(), array_keys($slots));
        $this->assertSame(['name' => 'Za řádky', 'description' => 'Pod tabulkou řádků.'], $slots['afterRows']);
    }

    public function testWithoutConfigSlotsFallBackToIdsAndPrintsAreUnknown(): void
    {
        $choices = new PrintTextChoices(null);

        $this->assertSame(PrintTextSlot::ids(), array_keys($choices->slots()));
        $this->assertSame('footer', $choices->slotName('footer'));
        $this->assertFalse($choices->knowsPrints());
        $this->assertSame([], $choices->prints());
        $this->assertSame(['cs' => 'CS', 'en' => 'EN', 'sk' => 'SK', 'de' => 'DE'], $choices->languages());
    }

    public function testPrintsAreFilteredBySlotAndOrderedLikeThePrintMenu(): void
    {
        $choices = new PrintTextChoices($this->config());

        $this->assertTrue($choices->knowsPrints());
        // Kontace sloty nemá — v nabídce textů není vůbec.
        $this->assertSame(
            ['docs.invoicesOut.invoice', 'docs.cashDocs.cash', 'economy.assets.card'],
            array_keys($choices->prints()),
        );
        $this->assertSame(
            ['docs.invoicesOut.invoice', 'docs.cashDocs.cash'],
            array_keys($choices->prints('header')),
        );
        $this->assertSame(['docs.invoicesOut.invoice'], array_keys($choices->prints('emailBody')));
        $this->assertSame('Faktura', $choices->prints('emailBody')['docs.invoicesOut.invoice']['name']);
    }

    public function testTargetingNeedsAllSelectedPrintsOverOneTableWithTypeAndSeries(): void
    {
        $choices = new PrintTextChoices($this->config());

        $documents = $choices->targeting(['docs.invoicesOut.invoice', 'docs.cashDocs.cash'], 'footer');
        $this->assertInstanceOf(PrintTextTargeting::class, $documents);
        $this->assertSame('docs_core_heads', $documents->table);
        $this->assertSame('doc_type', $documents->docTypeColumn);
        $this->assertSame('number_series', $documents->seriesColumn);

        // Karta majetku typ dokladu ani řadu nemá.
        $this->assertNull($choices->targeting(['economy.assets.card'], 'footer'));
        $this->assertNull($choices->targeting(['docs.invoicesOut.invoice', 'economy.assets.card'], 'footer'));
    }

    public function testTargetingWithoutSelectedPrintsFollowsPrintsOfTheSlot(): void
    {
        $choices = new PrintTextChoices($this->config());

        // Slot `footer` má i kartu majetku — cílení jde (u ní text s omezením neplatí).
        $this->assertSame('docs_core_heads', $choices->targeting([], 'footer')?->table);
        $this->assertSame('docs_core_heads', $choices->targeting([], 'emailBody')?->table);
        // Vybraný tisk slot nepodporuje → žádný dotčený tisk.
        $this->assertNull($choices->targeting(['docs.cashDocs.cash'], 'emailBody'));

        $onlyAssets = new PrintTextChoices($this->config([ConfigCompiler::PRINTS_ITEM => [
            'economy.assets.card' => ['name' => 'Karta', 'table' => 'economy_assets_assets', 'textSlots' => ['footer']],
        ]]));
        $this->assertNull($onlyAssets->targeting([], 'footer'));
    }

    public function testDocTypesAreThoseThePrintsActuallyPrint(): void
    {
        $choices   = new PrintTextChoices($this->config());
        $targeting = PrintTextTargeting::forTable('docs_core_heads');

        $this->assertSame(
            ['invno' => 'Faktura vydaná'],
            $choices->docTypes($targeting, ['docs.invoicesOut.invoice'], 'footer'),
        );
        // Bez výběru všechny tisky slotu; pořadí podle číselníku typů.
        $this->assertSame(
            ['invno' => 'Faktura vydaná', 'cash' => 'Pokladní doklad'],
            $choices->docTypes($targeting, [], 'footer'),
        );
        $this->assertSame(['invno' => 'Faktura vydaná'], $choices->docTypes($targeting, [], 'emailBody'));
    }

    public function testPrintWithoutTypeFilterPrintsEveryDocType(): void
    {
        $choices = new PrintTextChoices($this->config([ConfigCompiler::PRINTS_ITEM => [
            'docs.core.any' => ['name' => 'Opis', 'table' => 'docs_core_heads', 'textSlots' => ['footer']],
        ]]));

        $this->assertSame(
            ['invno', 'invpo', 'cash', 'cmnbkp'],
            array_keys($choices->docTypes(PrintTextTargeting::forTable('docs_core_heads'), [], 'footer')),
        );
    }

    public function testDocTypesWithoutTheirConfigAreEmpty(): void
    {
        // Modul dokladů není aktivní — cfgItem typů chybí.
        $choices = new PrintTextChoices($this->config(['docs.core.docTypes' => null]));

        $this->assertSame([], $choices->docTypes(PrintTextTargeting::forTable('docs_core_heads'), [], 'footer'));
    }

    public function testLanguagesAreNamedFromDocumentLanguages(): void
    {
        $this->assertSame(
            ['cs' => 'čeština', 'en' => 'angličtina', 'sk' => 'slovenština', 'de' => 'němčina'],
            (new PrintTextChoices($this->config()))->languages(),
        );
    }

    public function testTargetingMapKnowsOnlyDocuments(): void
    {
        $this->assertNull(PrintTextTargeting::forTable('economy_assets_assets'));
        $this->assertSame('docs_core_number_series', PrintTextTargeting::forTable('docs_core_heads')?->seriesTable);
    }
}
