<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;
use Shipard\Module\Docs\Core\Prints\TitleVariantResolver;

/** Blok `document` — typ, směr, titulek, číslo, režim DPH, měna a autor dokladu. */
final class DocDocumentBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $variant = TitleVariantResolver::resolve($context->titleContext());

        return ['document' => self::describe($context, [
            'titleVariant' => $variant,
            'title'        => $context->translator->t('title.' . $variant),
        ])];
    }

    /**
     * Popis dokladu bez titulku — titulek (`$title`, klíče za `tradeDir`)
     * dodá volající: tisk dokladu ho volí podle varianty, tisk nad
     * dokladem (Kontace) má vlastní.
     *
     * @param array<string, mixed> $title
     * @return array<string, mixed>
     */
    public static function describe(DocPrintContext $context, array $title): array
    {
        $head    = $context->head;
        $foreign = $context->foreignCurrency();

        return [
            'type'            => $context->docType(),
            'tradeDir'        => $context->tradeDir(),
            ...$title,
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
            // „Vystavil“ v zápatí (#93 D4): `{name}` nebo null.
            'author'          => $context->author,
        ];
    }
}
