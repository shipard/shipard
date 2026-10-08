<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Text dokladu z předpisu zakázky (D12, Q6): `{období}` (alias `{period}`)
 * se nahradí popiskem období; prázdný text = „{název zakázky} {období}“.
 * Ořez na délku `docs_core_heads.doc_text`.
 */
final class DocTextTemplate
{
    public const MAX_LENGTH = 200;
    public const PLACEHOLDERS = ['{období}', '{period}'];

    public static function render(?string $template, string $title, string $periodLabel): string
    {
        $template = trim((string) $template);
        if ($template === '') {
            $template = trim($title . ' ' . $periodLabel);
        } else {
            $template = str_replace(self::PLACEHOLDERS, $periodLabel, $template);
        }
        return mb_substr($template, 0, self::MAX_LENGTH);
    }
}
