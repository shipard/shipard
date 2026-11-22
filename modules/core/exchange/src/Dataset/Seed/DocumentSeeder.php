<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Seed;

use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;

/**
 * `docs/*.jsonc` přes `DocumentApplier` (s dispatcherem — doklady ve 40 se
 * zaúčtují). Čísla se zachovávají režimem `applyOptions.importNumber`;
 * v merge režimu je existující číslo dokladu chyba záznamu.
 */
final class DocumentSeeder extends ApplierSeeder
{
    public function __construct(
        private readonly DocumentApplier $applier,
    ) {}

    public function section(): string
    {
        return 'docs';
    }

    protected function apply(array $canonical): ApplyResult
    {
        // Sekce `docs/` datové sady je pokyn (#166 U10, U11): doklad se uloží,
        // jak ho sada říká — nic se neodvozuje.
        return $this->applier->apply($canonical, ApplyMode::Instruction);
    }

    protected function forMerge(array $canonical): array
    {
        // Doklad nemá merge strategii — vkládá se vždy nový; partner /
        // položky se resolvují proti existujícímu obsahu (strict = bez
        // autocreate, sada osoby a položky nese sama).
        return $canonical;
    }

    protected function mergeConflict(SeedContext $ctx, array $canonical): ?string
    {
        $number = $canonical['applyOptions']['importNumber']['docNumber'] ?? null;
        if (!is_string($number) || $number === '') {
            return null;
        }
        $row = $ctx->db->fetch(
            'SELECT [id] FROM [docs_core_heads] WHERE [doc_number] = %s AND [docState] <> 90 LIMIT 1',
            $number,
        );
        return $row !== null ? "doklad s číslem '{$number}' už v DS existuje (#{$row['id']})" : null;
    }
}
