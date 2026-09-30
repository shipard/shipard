<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Texty hlášení plánu odpisů z cfgItem `economy.assets.planMessages`
 * (lokalizované kompilací konfigurace). Engine sám texty nezná — vrací
 * kódy a parametry.
 *
 * Bez zkompilované konfigurace vrací kód hlášení: degradace, ne pád.
 */
final class PlanMessageTexts
{
    public const CFG_ITEM = 'economy.assets.planMessages';

    public function __construct(private readonly ?ConfigRuntime $config)
    {
    }

    public function text(PlanMessage $message): string
    {
        $texts = $this->config?->cfgItem(self::CFG_ITEM);
        $texts = is_array($texts) ? $texts : [];

        $reason = $message->params['reason'] ?? null;
        $template = (is_string($reason) ? ($texts["{$message->code}.{$reason}"]['text'] ?? null) : null)
            ?? $texts[$message->code]['text']
            ?? null;
        if (!is_string($template)) {
            return $message->code;
        }

        $replace = [];
        foreach ($message->params as $name => $value) {
            $replace['{' . $name . '}'] = self::format($value);
        }
        return strtr($template, $replace);
    }

    private static function format(mixed $value): string
    {
        if (is_float($value)) {
            return Amounts::money($value);
        }
        if (is_string($value) && Period::isDate($value)) {
            return (new \DateTimeImmutable($value))->format('j. n. Y');
        }
        return (string) $value;
    }
}
