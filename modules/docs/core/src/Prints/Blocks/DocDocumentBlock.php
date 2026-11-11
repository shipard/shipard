<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\Blocks;

use Shipard\Module\Docs\Core\Prints\DocPrintContext;
use Shipard\Module\Docs\Core\Prints\TitleVariantResolver;

/**
 * Blok `document` — typ, směr, titulek, číslo, režim DPH, měna a autor
 * dokladu; u tisku dokladu navíc `correction` (#92 D8).
 */
final class DocDocumentBlock implements DocPrintBlock
{
    public function build(DocPrintContext $context): array
    {
        $variant = TitleVariantResolver::resolve($context->titleContext());

        return ['document' => [
            ...self::describe($context, [
                'titleVariant' => $variant,
                'title'        => $context->translator->t('title.' . $variant),
            ]),
            'correction' => self::correction($context),
        ]];
    }

    /**
     * Údaje opravného dokladu (#92 D8): číslo původního dokladu a důvod
     * opravy; `null` u běžného dokladu. Souhrnný opravný doklad číslo nemá.
     * Datum plnění původního dokladu se netiskne.
     *
     * @return array{originalNumber: ?string, reason: ?string}|null
     */
    private static function correction(DocPrintContext $context): ?array
    {
        if (!$context->corrective()) {
            return null;
        }
        return [
            'originalNumber' => DocPrintContext::text($context->head['corrected_doc_number'] ?? null),
            'reason'         => DocPrintContext::text($context->head['correction_reason'] ?? null),
        ];
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
