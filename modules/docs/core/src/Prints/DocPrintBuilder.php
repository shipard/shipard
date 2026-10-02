<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Utils\Slug;
use Shipard\Module\Docs\Core\Prints\Blocks\DocAdvancesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDatesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDocumentBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPartiesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPaymentBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPrintBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocRowsBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocTotalsBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocVatRecapBlock;

/**
 * Data tisku dokladu nad `docs_core_heads` (#90 D12) — faktura vydaná,
 * zálohová faktura. Skládá sdílené bloky; další tisk dokladu (pokladní
 * doklad, dobropis) dědí a v `blocks()` blok přidá nebo vynechá.
 *
 * Kontrakt `data` popisuje `docs/prints.md`; při nekompatibilní změně
 * zvýšit `VERSION` (#90 D15).
 */
class DocPrintBuilder implements PrintBuilder
{
    public const VERSION = 1;

    public function build(PrintRequest $request): PrintBuildResult
    {
        $context = DocPrintContext::load($request);

        $data = [];
        foreach ($this->blocks() as $block) {
            $data = array_replace($data, $block->build($context));
        }

        $variant = (string) $data['document']['titleVariant'];
        $number  = (string) $data['document']['number'];

        return new PrintBuildResult(
            data: $data,
            title: trim($data['document']['title'] . ' ' . $number),
            fileName: Slug::make($context->translator->t('fileName.' . $variant), fallback: 'document')
                . ($number !== '' ? '-' . Slug::make($number, fallback: 'x') : '')
                . '.pdf',
            messages: $context->messages(),
            version: static::VERSION,
        );
    }

    /**
     * Bloky v pořadí klíčů `data`. Rekapitulace DPH musí zůstat za řádky —
     * poznámky DPH sbírá ze značek přidělených oběma.
     *
     * @return list<DocPrintBlock>
     */
    protected function blocks(): array
    {
        return [
            new DocDocumentBlock(),
            new DocDatesBlock(),
            new DocPartiesBlock(),
            new DocPaymentBlock(),
            new DocRowsBlock(),
            new DocVatRecapBlock(),
            new DocAdvancesBlock(),
            new DocTotalsBlock(),
        ];
    }
}
