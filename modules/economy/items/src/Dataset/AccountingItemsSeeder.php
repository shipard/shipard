<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Items\Dataset;

use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsGenerator;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\AccountingItemsUnavailableException;

/**
 * Účetní položky z nabídky osnovy podle `provision.accountingItems`
 * v manifestu sady (#103 D22) — tatáž orchestrace jako průvodce
 * nastavením ({@see AccountingItemsGenerator}). Nabídka je jednorázová
 * akce, kterou si sada vyžádala; `ds-upgrade` položky nevrací
 * (docs/ds-setup.md D18).
 *
 * Výsledky per kód: vytvořeno a už existující = `ok` (opakovaný seed je
 * tichý), účet mimo osnovu = `skipped` s varováním, neznámý kód = chyba
 * sady, selhání zápisu = chyba. Nedostupná nabídka = jedna chyba.
 */
class AccountingItemsSeeder implements SectionSeeder
{
    public const SECTION = 'accountingItems';

    public function __construct(
        private readonly AccountingItemsOffer $offer,
    ) {}

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $request = $ctx->reader->getManifest()->accountingItemsRequest();
        if ($request === null) {
            return;
        }

        try {
            $result = $this->generator($ctx)->generate($request);
        } catch (AccountingItemsUnavailableException $e) {
            $report->failed(self::SECTION, self::describe($e));
            return;
        }

        foreach ($result['created'] as $item) {
            $report->ok(self::SECTION);
        }
        foreach ($result['skipped'] as $skip) {
            switch ($skip['reason']) {
                case 'already_exists':
                    $report->ok(self::SECTION);
                    break;
                case 'account_not_found':
                    $report->skipped(self::SECTION, "položka {$skip['code']}: účet "
                        . ($skip['accountNumber'] ?? '?') . ' v osnově není');
                    break;
                default:
                    $report->failed(self::SECTION, "kód '{$skip['code']}' v nabídce osnovy '"
                        . ($this->offer->variant() ?? '?') . "' není ({$skip['reason']})");
            }
        }
        foreach ($result['failed'] as $f) {
            $report->failed(self::SECTION, "položka {$f['code']}: {$f['message']}");
        }
        if ($result['created'] !== []) {
            $report->warning(sprintf(
                'účetní položky z nabídky osnovy: založeno %d (%s)',
                count($result['created']),
                $request === AccountingItemsGenerator::ALL ? 'všechny' : 'podle manifestu',
            ));
        }
    }

    public static function describe(AccountingItemsUnavailableException $e): string
    {
        return match ($e->reason) {
            AccountingItemsUnavailableException::CHART_UNDECIDED =>
                'economy.accountChart není rozhodnuto — nabídka účetních položek není dostupná (doplň setup/settings.jsonc)',
            AccountingItemsUnavailableException::CHART_NONE =>
                "osnova 'none' nemá nabídku účetních položek",
            AccountingItemsUnavailableException::ACCOUNTING_INACTIVE =>
                'modul economy.accounting není aktivní — položky nemají kam dostat účet',
            AccountingItemsUnavailableException::SEED_MISSING =>
                "soubor nabídky pro osnovu '" . ($e->variant ?? '?') . "' nenalezen",
            AccountingItemsUnavailableException::ITEM_KIND_MISSING =>
                "druh položky 'accounting' chybí — spusť ds-upgrade",
            AccountingItemsUnavailableException::UNIT_MISSING =>
                "jednotka 'pcs' chybí — spusť ds-upgrade",
            default => $e->getMessage(),
        };
    }

    /** Seam pro testy. */
    protected function generator(SeedContext $ctx): AccountingItemsGenerator
    {
        $materializer = new AccountingItemMaterializer(
            db: $ctx->dsConnection,
            offer: $this->offer,
            language: $ctx->dsConfig->getDefaultLanguage(),
            config: $ctx->config,
            tables: $ctx->tables,
            dsConfig: $ctx->dsConfig,
            documentRegistry: $ctx->registry,
            eventDispatcher: $ctx->dispatcher,
        );
        return new AccountingItemsGenerator($this->offer, $materializer, $ctx->tables);
    }
}
