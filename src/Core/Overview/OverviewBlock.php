<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

/**
 * Blok Přehledu firmy (#120 D2): jeden příspěvek do jedné sekce obrazovky
 * „jak na tom firma je". Registrace přes `overviewBlocks` v `module.jsonc`
 * (ConfigCompiler → cfgItem `core.overview.blocks`), instance staví
 * {@see OverviewBlockRegistry} bezparametrickým konstruktorem, sběr řídí
 * {@see OverviewCollector}.
 *
 * Blok **skládá** existující výpočty (saldokonto, výpisy, reporty) — nic
 * nedopočítává; chybí-li zdroji číslo, položku neukáže (docs/overview.md).
 */
interface OverviewBlock
{
    /**
     * Zda blok na tomto DS dává smysl — typicky přítomnost klíčové tabulky
     * (`$ctx->hasTable()`). `false` = blok se do výsledku vůbec nedostane,
     * bez chyby a bez zprávy (DS bez modulu).
     */
    public function isAvailable(OverviewContext $ctx): bool;

    /** Položky a zprávy bloku; výjimka = sekce `error`, ostatní bloky běží dál. */
    public function collect(OverviewContext $ctx): OverviewBlockResult;
}
