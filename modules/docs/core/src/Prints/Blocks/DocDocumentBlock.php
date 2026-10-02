<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;
use Shipard\Module\Docs\Core\Prints\TitleVariantResolver;

/** Blok `document` — typ, směr, titulek, číslo, režim DPH a měna dokladu. */
final class DocDocumentBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $head    = $context->head;
        $variant = TitleVariantResolver::resolve($context->titleContext());
        $foreign = $context->foreignCurrency();

        return ['document' => [
            'type'            => $context->docType(),
            'tradeDir'        => $context->tradeDir(),
            'titleVariant'    => $variant,
            'title'           => $context->translator->t('title.' . $variant),
            'number'          => (string) ($head['doc_number'] ?? ''),
            'text'            => DocPrintContext::text($head['doc_text'] ?? null),
            'notice'          => DocPrintContext::text($head['doc_notice'] ?? null),
            'isTaxDocument'   => $context->isTaxDocument(),
            'vatPayer'        => $context->vatPayer(),
            'vatMode'         => $context->vatMode(),
            'currency'        => $context->currency(),
            'homeCurrency'    => $context->homeCurrency(),
            'exchangeRate'    => $foreign ? DocPrintContext::number($head['exchange_rate'] ?? null) : null,
            'foreignCurrency' => $foreign,
        ]];
    }
}
