<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\Texts\PrintTextSlot;

/**
 * Výřez kompilované konfigurace pro testy textů na tiscích: tři tisky nad
 * doklady (faktura se všemi sloty, pokladní doklad jen se dvěma, Kontace bez
 * slotů) a jeden tisk nad jinou tabulkou — ten cílení na typ a řadu nemá.
 */
trait PrintTextConfigFixture
{
    /** @param array<string, mixed> $override cfgItem → hodnota (null = cfgItem chybí) */
    private function config(array $override = []): ConfigRuntime
    {
        $items = $override + [
            ConfigCompiler::PRINTS_ITEM => [
                'economy.assets.card' => [
                    'name' => 'Karta majetku', 'table' => 'economy_assets_assets',
                    'textSlots' => ['footer'], 'order' => 50,
                ],
                'docs.cashDocs.cash' => [
                    'name' => 'Pokladní doklad', 'table' => 'docs_core_heads',
                    'filter' => ['doc_type' => ['cash']], 'textSlots' => ['header', 'footer'], 'order' => 20,
                ],
                'docs.invoicesOut.invoice' => [
                    'name' => 'Faktura', 'table' => 'docs_core_heads',
                    'filter' => ['doc_type' => ['invno']], 'textSlots' => PrintTextSlot::ids(), 'order' => 10,
                ],
                'economy.accounting.docJournal' => [
                    'name' => 'Kontace', 'table' => 'docs_core_heads', 'order' => 900,
                ],
            ],
            PrintTextSlot::CFG_ITEM => [
                'header'       => ['name' => 'Začátek dokumentu', 'description' => 'Nahoře na první straně.', 'order' => 10],
                'beforeRows'   => ['name' => 'Před řádky', 'description' => 'Nad tabulkou řádků.', 'order' => 20],
                'afterRows'    => ['name' => 'Za řádky', 'description' => 'Pod tabulkou řádků.', 'order' => 30],
                'footer'       => ['name' => 'Konec dokumentu', 'description' => 'Pod poznámkami.', 'order' => 40],
                'emailSubject' => ['name' => 'Předmět e-mailu', 'description' => 'Nahradí předmět.', 'order' => 50],
                'emailBody'    => ['name' => 'Text e-mailu', 'description' => 'Nahradí text.', 'order' => 60],
            ],
            'docs.core.docTypes' => [
                'invno'  => ['name' => 'Faktura vydaná'],
                'invpo'  => ['name' => 'Zálohová faktura vydaná'],
                'cash'   => ['name' => 'Pokladní doklad'],
                'cmnbkp' => ['name' => 'Účetní doklad'],
            ],
            'world.base.documentLanguages' => [
                'cs' => ['name' => 'čeština'], 'en' => ['name' => 'angličtina'],
                'sk' => ['name' => 'slovenština'], 'de' => ['name' => 'němčina'],
            ],
            'core.system.docStatesArchive' => [
                '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active'],
                '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 2, 'viewGroup' => 'active'],
            ],
        ];

        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $config;
    }
}
